<?php
// Browser journey preparation: the endpoint fixture plugin and its Fast-style trim MU plugin,
// installed and active, so the journey has a real trimmed REST endpoint to observe and profile.
// Skipped when the journey targets another endpoint (SSPA_ENDPOINT_ROUTE set), such as a real
// Super Speedy Search Fast REST route on a site that has Search configured.
require_once __DIR__ . '/../lib/native-guard.php';
if (getenv('SSPA_ENDPOINT_ROUTE')) {
    echo "RETAINED: journey targets " . getenv('SSPA_ENDPOINT_ROUTE') . ", fixture not installed\n";
    return;
}
$fixtures = SSPA_PLUGIN_DIR . '.tests/fixtures/endpoint-startup/';
wp_mkdir_p(WP_PLUGIN_DIR . '/sspa-endpoint-fixture');
copy($fixtures . 'sspa-endpoint-fixture/sspa-endpoint-fixture.php', WP_PLUGIN_DIR . '/sspa-endpoint-fixture/sspa-endpoint-fixture.php');
copy($fixtures . 'zz-sspa-test-endpoint-trim.php', WPMU_PLUGIN_DIR . '/zz-sspa-test-endpoint-trim.php');
wp_clean_plugins_cache(false);
$activated = activate_plugin('sspa-endpoint-fixture/sspa-endpoint-fixture.php');
if (is_wp_error($activated)) { throw new RuntimeException($activated->get_error_message()); }
$active = SSPA_Traffic_Collection::active();
if ($active) { SSPA_Traffic_Collection::stop((int) $active['id'], true); }
$run = SSPA_Run_Controller::active_run_id();
if ($run) { SSPA_Run_Controller::cancel($run); }
$enabled = SSPA_Helper_Files::enable_profiler();
if (is_wp_error($enabled)) { throw new RuntimeException($enabled->get_error_message()); }
echo "RETAINED: endpoint fixture active; no window running\n";
