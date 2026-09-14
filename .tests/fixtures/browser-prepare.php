<?php
require __DIR__ . '/../lib/fleet.php';
$collection = SSPA_Traffic_Collection::active();
if ($collection) SSPA_Traffic_Collection::stop((int) $collection['id'], true);
$active = SSPA_Run_Controller::active_run_id();
if ($active) SSPA_Run_Controller::cancel($active);
if (!username_exists('sspa-browser-customer')) wp_insert_user(array('user_login'=>'sspa-browser-customer','user_pass'=>'Synthetic-local-customer-76!','user_email'=>'browser-customer@example.invalid','role'=>'customer'));
update_option('sspa_share_optin', false);
update_option('sspa_fleet_enabled', true);
update_option('sspa_fleet_browser_transport', false);
// update_option() with false on a missing option writes nothing (false equals the "missing"
// read), which left this row absent on fresh sites and made case 52's result depend on which
// browser test had run first. Write an explicit stored scalar.
update_option('sspa_fleet_http_error', '0', true);
$plugin = WP_PLUGIN_DIR . '/sspa-browser-fixture';
wp_mkdir_p($plugin);
copy(__DIR__ . '/browser-runtime.php', $plugin . '/sspa-browser-fixture.php');
activate_plugin('sspa-browser-fixture/sspa-browser-fixture.php');
wp_cache_flush();
$ids = array();
// These are real captures; no successful report rows or timing values are fabricated.
for ($i = 0; $i < 2; $i++) {
    $id = SSPA_Run_Controller::start(array('type' => 'spot', 'page_keys' => array('home'), 'trigger' => 'manual', 'user_id' => 1));
    if (is_wp_error($id)) throw new RuntimeException($id->get_error_message());
    ob_start(); $status = sspa_fleet_complete($id); ob_end_clean();
    if ($status['status'] !== 'done') throw new RuntimeException('Measured browser fixture did not complete');
    $ids[] = $id;
}
update_option('sspa_fleet_browser_runs', $ids, false);
global $wpdb;
$profile = $wpdb->get_row($wpdb->prepare('SELECT id,page_key,page_gen_ms,sql_count FROM ' . SSPA_Schema::table('profiles') . ' WHERE run_id=%d AND page_key=%s', end($ids), 'home'), ARRAY_A);
echo wp_json_encode(array('runs' => $ids, 'home' => $profile, 'site' => home_url('/'), 'version' => SSPA_VERSION));
