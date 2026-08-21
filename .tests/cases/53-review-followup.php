<?php
defined('ABSPATH') || exit;

$GLOBALS['sspa_53_fails'] = 0;
function sspa_53_t($ok, $message) {
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $message . "\n";
    if (!$ok) {
        $GLOBALS['sspa_53_fails']++;
    }
}

wp_set_current_user(1);
global $wpdb;
$old_runs = $wpdb->get_col($wpdb->prepare(
    "SELECT id FROM %i WHERE notes = %s",
    SSPA_Schema::table('runs'),
    'sspa-case-53'
));
foreach ($old_runs as $old_run_id) {
    $wpdb->delete(SSPA_Schema::table('community_outbox'), array('run_id' => (int) $old_run_id));
    $wpdb->delete(SSPA_Schema::table('runs'), array('id' => (int) $old_run_id));
    delete_option(SSPA_Digests::OPTION_PREFIX . (int) $old_run_id);
}
ob_start();
SSPA_Admin_Page::show();
$admin_html = ob_get_clean();
sspa_53_t(8 === substr_count($admin_html, 'class="tab-contents"'), 'all eight tab containers remain in the page');
sspa_53_t(7 === substr_count($admin_html, 'data-sspa-tab-loaded="0"'), 'seven initially hidden tabs are deferred');
sspa_53_t(false === strpos($admin_html, 'sspa-outbox-table'), 'hidden Share history does not render on initial load');

$wpdb->insert(SSPA_Schema::table('runs'), array(
    'run_uuid' => wp_generate_uuid4(), 'blog_id' => get_current_blog_id(), 'run_type' => 'baseline',
    'status' => 'done', 'started' => gmdate('Y-m-d H:i:s'), 'finished' => gmdate('Y-m-d H:i:s'),
    'notes' => 'sspa-case-53',
));
$run_id = (int) $wpdb->insert_id;
$queued = SSPA_Community_Outbox::queue_run($run_id, 'manual');
SSPA_Community_Outbox::begin_attempt($queued);
SSPA_Community_Outbox::failed($queued['id'], new WP_Error('bad_request', 'bad request'), true, 400);
$after_failure = SSPA_Community_Outbox::get($queued['id']);
sspa_53_t('retry' === $after_failure['state'] && !empty($after_failure['next_attempt']), 'grace-period permanent failure retains a future retry deadline');

$wpdb->insert(SSPA_Schema::table('runs'), array(
    'run_uuid' => wp_generate_uuid4(), 'blog_id' => get_current_blog_id(), 'run_type' => 'baseline',
    'status' => 'done', 'started' => gmdate('Y-m-d H:i:s'), 'finished' => gmdate('Y-m-d H:i:s'),
    'notes' => 'sspa-case-53',
));
$run_id_2 = (int) $wpdb->insert_id;
$queued_2 = SSPA_Community_Outbox::queue_run($run_id_2, 'manual');
SSPA_Community_Outbox::begin_attempt($queued_2);
$pause = SSPA_Community_Outbox::pause($queued_2['id']);
sspa_53_t(is_wp_error($pause) && 'sspa_outbox_in_flight' === $pause->get_error_code(), 'an in-flight submission cannot be paused underneath its worker');

$wpdb->insert(SSPA_Schema::table('runs'), array(
    'run_uuid' => wp_generate_uuid4(), 'blog_id' => get_current_blog_id(), 'run_type' => 'baseline',
    'status' => 'running', 'started' => gmdate('Y-m-d H:i:s'), 'notes' => 'sspa-case-53',
));
$cancel_run = (int) $wpdb->insert_id;
update_option(SSPA_Digests::OPTION_PREFIX . $cancel_run, array('fixture' => true), false);
SSPA_Run_Controller::cancel($cancel_run);
sspa_53_t(false === get_option(SSPA_Digests::OPTION_PREFIX . $cancel_run), 'cancelling a run discards its digest snapshot');

// Missing/corrupt capture evidence is unknown, never silently verified.
$wpdb->insert(SSPA_Schema::table('runs'), array(
    'run_uuid' => wp_generate_uuid4(), 'blog_id' => get_current_blog_id(), 'run_type' => 'cache_impact',
    'status' => 'analysing', 'started' => gmdate('Y-m-d H:i:s'), 'notes' => 'sspa-case-53',
));
$cache_run = (int) $wpdb->insert_id;
$wpdb->insert(SSPA_Schema::table('profiles'), array(
    'run_id' => $cache_run, 'page_key' => 'home', 'url' => home_url('/'), 'method' => 'GET',
    'variant' => 'anon', 'object_cache_mode' => 'disabled', 'profile_blob' => 'not-gzip',
    'created' => gmdate('Y-m-d H:i:s'),
));
$finish_cache = new ReflectionMethod('SSPA_Run_Controller', 'finish_cache');
$finish_cache->setAccessible(true);
$finish_cache->invoke(null, $cache_run);
$cache_notes = json_decode((string) SSPA_Run_Controller::run_row($cache_run)['notes'], true);
sspa_53_t('unknown' === ($cache_notes['verification'] ?? '') && array_key_exists('verified', $cache_notes) && null === $cache_notes['verified'], 'missing cache evidence is reported as unknown, not verified');

// Confidence must include noise in the comparison cell as well as the baseline.
$wpdb->insert(SSPA_Schema::table('runs'), array(
    'run_uuid' => wp_generate_uuid4(), 'blog_id' => get_current_blog_id(), 'run_type' => 'deep',
    'status' => 'analysing', 'started' => gmdate('Y-m-d H:i:s'), 'notes' => 'sspa-case-53',
));
$deep_run = (int) $wpdb->insert_id;
$profile_common = array('run_id' => $deep_run, 'page_key' => 'home', 'url' => home_url('/'), 'method' => 'GET', 'variant' => 'anon', 'object_cache_mode' => 'normal', 'response_code' => 200, 'created' => gmdate('Y-m-d H:i:s'));
$wpdb->insert(SSPA_Schema::table('profiles'), array_merge($profile_common, array(
    'plugin_set_hash' => '', 'page_gen_ms' => 200, 'sql_ms' => 10, 'sql_count' => 10,
    'samples' => wp_json_encode(array(array('gen_ms' => 200), array('gen_ms' => 200), array('gen_ms' => 200))),
)));
$wpdb->insert(SSPA_Schema::table('profiles'), array_merge($profile_common, array(
    'plugin_set_hash' => '53noisy', 'page_gen_ms' => 100, 'sql_ms' => 5, 'sql_count' => 5,
    'samples' => wp_json_encode(array(array('gen_ms' => 0), array('gen_ms' => 100), array('gen_ms' => 200))),
)));
$sweep_deltas = new ReflectionMethod('SSPA_Run_Controller', 'sweep_deltas');
$sweep_deltas->setAccessible(true);
$deltas = $sweep_deltas->invoke(null, $deep_run, array('53noisy' => 'fixture-plugin'));
sspa_53_t(!empty($deltas) && empty($deltas[0]['measured']) && $deltas[0]['gate'] > 100, 'deep confidence includes comparison-cell variance');
$wpdb->update(SSPA_Schema::table('runs'), array('status' => 'done', 'finished' => gmdate('Y-m-d H:i:s')), array('id' => $deep_run));

if ($GLOBALS['sspa_53_fails']) {
    exit(1);
}
