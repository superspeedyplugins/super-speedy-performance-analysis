<?php
// Retention of the boot timer's real capture shape through the run exporter, with no upload.
global $wpdb;
$failures = 0;
$check = function ($ok, $label) use (&$failures) {
    echo ($ok ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$ok) { $failures++; }
};
$now = gmdate('Y-m-d H:i:s');
// Clear only this case's last fixture at startup, and retain this run at exit.
$previous_runs = $wpdb->get_col($wpdb->prepare('SELECT id FROM %i WHERE plugin_set_hash = %s', SSPA_Schema::table('runs'), md5('boot-retention')));
foreach ($previous_runs as $previous) {
    $wpdb->delete(SSPA_Schema::table('profiles'), array('run_id' => $previous));
    $wpdb->delete(SSPA_Schema::table('runs'), array('id' => $previous));
}
$boot = array(
    'segments' => array('plugin_includes' => 12.5, 'init_callbacks' => 8.25),
    'includes' => array('woocommerce' => 12.5),
    'components' => array('woocommerce' => 20.75),
    'hooks' => array('init' => array('ms' => 8.25, 'components' => array('woocommerce' => 8.25))),
    'top_callbacks' => array(array('hook' => 'init', 'component' => 'woocommerce', 'label' => 'WC_Test->init', 'ms' => 8.25)),
    'render' => array('timed_ms' => 0, 'untimed_ms' => null, 'components' => array(), 'top' => array()),
    'assets' => array('woocommerce' => array('scripts' => 2, 'styles' => 1)),
);
$wpdb->insert(SSPA_Schema::table('runs'), array(
    'run_uuid' => wp_generate_uuid4(), 'blog_id' => 1, 'run_type' => 'baseline',
    'measurement_version' => 1, 'trigger_source' => 'test', 'status' => 'done',
    'plugin_set' => wp_json_encode(array('components' => array(array('type' => 'plugin', 'slug' => 'woocommerce', 'version' => '10.1.0')))),
    'plugin_set_hash' => md5('boot-retention'), 'started' => $now, 'finished' => $now,
));
$run_id = (int) $wpdb->insert_id;
$wpdb->insert(SSPA_Schema::table('profiles'), array(
    'run_id' => $run_id, 'page_key' => 'home', 'url' => home_url('/'), 'method' => 'GET',
    'variant' => 'anon', 'plugin_set_hash' => '', 'object_cache_mode' => 'normal',
    'samples' => '[]', 'response_code' => 200, 'page_gen_ms' => 30,
    'profile_blob' => gzcompress(wp_json_encode(array('boot' => $boot)), 6), 'created' => $now,
));
$profile_id = (int) $wpdb->insert_id;
$check($run_id > 0 && $profile_id > 0, 'fixture uses the installed run/profile schema');
$records = function ($payload) {
    return is_array($payload) ? array_values(array_filter($payload['evidence'], function ($e) { return 'sspa/boot-profile' === $e['type']; })) : array();
};
$payload = SSPA_Community_Exporter::build($run_id, null, null, 'manual');
$check(!is_wp_error($payload), 'manual preview builds without submitting');
$items = $records($payload);
$check(count($items) === 1, 'run payload retains one boot profile');
if ($items) {
    $data = $items[0]['data'];
    $check($data['includes'][0]['ms'] === 12.5 && $data['hooks'][0]['components'][0]['ms'] === 8.25, 'include and hook timings survive projection exactly');
    $check($data['includes'][0]['component_version'] === '10.1.0', 'capture-time inventory supplies component version');
    $check($data['top_callbacks'][0]['callback'] === 'WC_Test->init' && $data['assets'][0]['scripts'] === 2, 'callback identity and asset counts survive');
    $check($data['render']['untimed_ms'] === null && $data['coverage']['registration'] === 'not_captured', 'missing timing and registration coverage remain explicit');
    $pages = array_values(array_filter($payload['evidence'], function ($e) { return 'sspa/page-profile' === $e['type']; }));
    $check($pages[0]['evidence_uuid'] === $data['page_profile_uuid'], 'boot evidence links to its measured page');
    $json = SSPA_Community_Schema::encode($payload);
    $check(!is_wp_error($json) && is_array(json_decode($json, true)), 'complete payload is serialisable');
    if (getenv('SSPA_BOOT_PAYLOAD_FILE')) { file_put_contents(getenv('SSPA_BOOT_PAYLOAD_FILE'), $json); }
}
// No old consent is upgraded and no outbox/network worker is invoked.
$old = get_option('sspa_share_consent_version', 0);
update_option('sspa_share_consent_version', 4, false);
$older = SSPA_Community_Exporter::build($run_id);
$check(!is_wp_error($older) && !$records($older) && $older['client']['consent_version'] === 4, 'old automatic consent excludes new boot disclosure');
update_option('sspa_share_consent_version', $old, false);

$boot['hooks']['private-customer-hook'] = array('ms' => 1, 'components' => array('woocommerce' => 1));
$boot['top_callbacks'][] = array('hook' => 'private-customer-hook', 'component' => 'woocommerce', 'label' => '/var/private/customer.php:20', 'ms' => 1);
$wpdb->update(SSPA_Schema::table('profiles'), array('profile_blob' => gzcompress(wp_json_encode(array('boot' => $boot)), 6)), array('id' => $profile_id));
$redacted = SSPA_Community_Exporter::build($run_id, null, null, 'manual');
$text = wp_json_encode($redacted);
$check(!is_wp_error($redacted) && count($records($redacted)) === 1 && false === strpos($text, 'private-customer-hook') && false === strpos($text, 'customer.php'), 'custom hook and callback paths are not exported');
$boot['includes']['woocommerce'] = -1;
$wpdb->update(SSPA_Schema::table('profiles'), array('profile_blob' => gzcompress(wp_json_encode(array('boot' => $boot)), 6)), array('id' => $profile_id));
$invalid = SSPA_Community_Exporter::build($run_id, null, null, 'manual');
$check(is_wp_error($invalid), 'invalid captured timings surface an export error');
// Disarm deliberate fault injection; leave the valid fixture available for preview.
$boot['includes']['woocommerce'] = 12.5;
$wpdb->update(SSPA_Schema::table('profiles'), array('profile_blob' => gzcompress(wp_json_encode(array('boot' => $boot)), 6)), array('id' => $profile_id));
// Retain the fixture and evidence for inspection. No traffic tables or sharing opt-in changed.
if ($failures) { exit(1); }
