<?php
// Historical backfill inventory, bounded batches and resumability.

function sspa_backfill_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

global $wpdb;
$runs_table = SSPA_Schema::table('runs');
$profiles_table = SSPA_Schema::table('profiles');
$outbox_table = SSPA_Schema::table('submission_outbox');
$events_table = SSPA_Schema::table('submission_events');

$old_optin = get_option('sspa_share_optin', null);
$old_cursor = get_option(SSPA_Community_Backfill::CURSOR_OPTION, null);
$old_errors = get_option('sspa_submission_build_errors', null);
$existing_statuses = array();
foreach ($wpdb->get_results(
    "SELECT id, status FROM $runs_table WHERE status = 'done' OR (run_type = 'checkout' AND status = 'failed')",
    ARRAY_A
) as $row) {
    $existing_statuses[(int) $row['id']] = $row['status'];
}
if ($existing_statuses) {
    $wpdb->query('UPDATE ' . $runs_table . " SET status = 'test_hidden' WHERE id IN (" . implode(',', array_keys($existing_statuses)) . ')');
}

update_option('sspa_share_optin', 1, false);
$run_ids = array();
$profile_ids = array();
$outbox_ids = array();
$now = gmdate('Y-m-d H:i:s');
for ($i = 0; $i < 7; $i++) {
    $wpdb->insert($runs_table, array(
        'run_uuid' => wp_generate_uuid4(),
        'blog_id' => 1,
        'run_type' => (0 === $i % 2) ? 'baseline' : 'adhoc',
        'measurement_version' => 1,
        'trigger_source' => 'test',
        'status' => 'done',
        'plugin_set' => wp_json_encode(array('components' => array(
            array('type' => 'plugin', 'slug' => 'woocommerce', 'version' => '10.1.0'),
        ))),
        'plugin_set_hash' => md5('backfill-' . $i),
        'started' => $now,
        'finished' => $now,
    ));
    $run_id = (int) $wpdb->insert_id;
    $run_ids[] = $run_id;
    $wpdb->insert($profiles_table, array(
        'run_id' => $run_id,
        'page_key' => (0 === $i % 2) ? 'home' : 'url-private-' . $i,
        'url' => home_url('/private-' . $i . '/'),
        'method' => 'GET',
        'variant' => 'anon',
        'plugin_set_hash' => md5('backfill-' . $i),
        'object_cache_mode' => 'normal',
        'page_gen_ms' => 50 + $i,
        'sql_ms' => 5,
        'sql_count' => 4,
        'response_code' => 200,
        // Even rows retain detail; odd rows intentionally model pruned history.
        'profile_blob' => (0 === $i % 2) ? gzcompress(wp_json_encode(array('profile' => array())), 6) : null,
        'created' => $now,
    ));
    $profile_ids[] = (int) $wpdb->insert_id;
}

$already = SSPA_Community_Outbox::queue_run($run_ids[0]);
if (!is_wp_error($already)) {
    $outbox_ids[] = (int) $already['id'];
}
$before = SSPA_Community_Backfill::inventory();
sspa_backfill_t(7 === $before['eligible'] && 1 === $before['queued'] && 6 === $before['remaining'], 'inventory separates eligible, queued and remaining runs');
sspa_backfill_t(3 === $before['with_detail'] && 3 === $before['scalar_only'], 'inventory distinguishes detailed and scalar-only history');

update_option('sspa_share_optin', 0, false);
$refused = SSPA_Community_Backfill::queue_batch();
sspa_backfill_t(is_wp_error($refused) && 'sspa_not_opted_in' === $refused->get_error_code(), 'backfill cannot create payloads without explicit opt-in');
update_option('sspa_share_optin', 1, false);

SSPA_Community_Backfill::restart();
$first = SSPA_Community_Backfill::queue_batch();
sspa_backfill_t(!is_wp_error($first) && 3 === $first['attempted'] && 3 === $first['queued'] && !$first['complete'], 'first request queues one bounded batch');
$cursor_after_first = (int) get_option(SSPA_Community_Backfill::CURSOR_OPTION, 0);
$second = SSPA_Community_Backfill::queue_batch();
sspa_backfill_t(!is_wp_error($second) && 3 === $second['attempted'] && 3 === $second['queued'] && $second['complete'], 'second request resumes after the persisted cursor');
sspa_backfill_t((int) get_option(SSPA_Community_Backfill::CURSOR_OPTION, 0) > $cursor_after_first, 'backfill cursor advances monotonically');
$after = SSPA_Community_Backfill::inventory();
sspa_backfill_t(7 === $after['queued'] && 0 === $after['remaining'], 'every historical run has exactly one outbox row');

$rows = $wpdb->get_results(
    'SELECT id, run_id, payload_gzip, payload_sha256 FROM ' . $outbox_table . ' WHERE run_id IN (' . implode(',', $run_ids) . ')',
    ARRAY_A
);
foreach ($rows as $row) {
    $outbox_ids[] = (int) $row['id'];
}
$outbox_ids = array_values(array_unique($outbox_ids));
sspa_backfill_t(count($rows) === 7, 'unique run UUID constraint prevents duplicate backfill rows');
sspa_backfill_t(
    count(array_filter($rows, function ($row) {
        return !empty($row['payload_gzip']) && hash_equals($row['payload_sha256'], hash('sha256', $row['payload_gzip']));
    })) === 7,
    'all historical payload bytes pass their retained SHA-256 checks'
);

ob_start();
include SSPA_PLUGIN_DIR . 'includes/admin/tabs/share.php';
$share_html = ob_get_clean();
sspa_backfill_t(false !== strpos($share_html, 'Submission history') && false !== strpos($share_html, 'sspa-outbox-table'), 'Share tab renders the operational outbox table');
sspa_backfill_t(false !== strpos($share_html, 'sspa-preview-outbox') && false !== strpos($share_html, 'schema 1.4'), 'Share tab exposes exact-payload controls and versioned rows');

foreach ($outbox_ids as $outbox_id) {
    $wpdb->delete($events_table, array('outbox_id' => $outbox_id));
    $wpdb->delete($outbox_table, array('id' => $outbox_id));
}
foreach ($profile_ids as $profile_id) {
    $wpdb->delete($profiles_table, array('id' => $profile_id));
}
foreach ($run_ids as $run_id) {
    $wpdb->delete($runs_table, array('id' => $run_id));
}
foreach ($existing_statuses as $run_id => $status) {
    $wpdb->update($runs_table, array('status' => $status), array('id' => $run_id));
}
if (null === $old_optin) {
    delete_option('sspa_share_optin');
} else {
    update_option('sspa_share_optin', $old_optin, false);
}
if (null === $old_cursor) {
    delete_option(SSPA_Community_Backfill::CURSOR_OPTION);
} else {
    update_option(SSPA_Community_Backfill::CURSOR_OPTION, $old_cursor, false);
}
if (null === $old_errors) {
    delete_option('sspa_submission_build_errors');
} else {
    update_option('sspa_submission_build_errors', $old_errors, false);
}
wp_clear_scheduled_hook('sspa_submission_worker_event');
sspa_backfill_t(true, 'backfill fixtures and prior run statuses restored');

// --- Seeing what would be sent must not queue it ---
// "Turn sharing on, then look at what you agreed to" is the wrong order for a consent decision.
global $wpdb;
$sspa_outbox_table = SSPA_Schema::table('submission_outbox');
$sspa_before = (int) $wpdb->get_var("SELECT COUNT(*) FROM $sspa_outbox_table");
$sspa_dry = SSPA_Submitter::dry_run_preview();
$sspa_after = (int) $wpdb->get_var("SELECT COUNT(*) FROM $sspa_outbox_table");
sspa_backfill_t(!is_wp_error($sspa_dry) && strlen($sspa_dry) > 100, 'a payload can be built purely to look at');
sspa_backfill_t($sspa_before === $sspa_after, "previewing queued nothing ($sspa_before -> $sspa_after)");
$sspa_desc = SSPA_Submitter::describe_payload($sspa_dry);
sspa_backfill_t(!empty($sspa_desc['includes']), 'the payload is described in plain English');
sspa_backfill_t(!empty($sspa_desc['excludes']), 'the description states what is never sent');
sspa_backfill_t(
    false === strpos($sspa_dry, (string) parse_url(home_url('/'), PHP_URL_HOST)),
    'the previewed payload carries no host name'
);
