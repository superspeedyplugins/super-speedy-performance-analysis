<?php
// Full baseline run end to end: catalogue -> loopbacks -> captures -> profiles -> stats.

function sspa_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

global $wpdb;

$plugins_before = get_option('active_plugins');

// Start from a clean scratch table so the drained-captures assertion tests THIS run
// (orphans from previous failed runs are the hourly cron's job, not this test's).
$wpdb->query('DELETE FROM ' . SSPA_Schema::table('captures'));

$run_id = SSPA_Run_Controller::start(array('type' => 'baseline', 'trigger' => 'manual', 'user_id' => 1));
if (is_wp_error($run_id)) {
    echo 'FAIL: start(): ' . $run_id->get_error_message() . "\n";
    return;
}
sspa_t(true, "run $run_id started");

$deadline = time() + 600;
do {
    SSPA_Run_Controller::process_batch($run_id);
    $status = SSPA_Run_Controller::status($run_id);
} while ($status && 'crawling' === $status['status'] && time() < $deadline);

sspa_t($status && 'done' === $status['status'], 'run completed: ' . ($status ? $status['status'] : 'null'));
sspa_t($status && $status['total'] >= 10, 'catalogue found >= 10 pages (' . $status['total'] . ')');

$profiles_table = SSPA_Schema::table('profiles');
$profiles = $wpdb->get_results($wpdb->prepare("SELECT * FROM $profiles_table WHERE run_id = %d", $run_id), ARRAY_A);
sspa_t(count($profiles) === $status['total'], 'one profile per job (' . count($profiles) . '/' . $status['total'] . ')');

$by_key = array();
foreach ($profiles as $p) {
    $by_key[$p['page_key']] = $p;
}

// Home page invariants: real generation time, queries with rows, sane composition.
$home = isset($by_key['home']) ? $by_key['home'] : null;
sspa_t($home !== null, 'home profiled');
if ($home) {
    sspa_t($home['page_gen_ms'] > 0, 'home page_gen_ms > 0 (' . $home['page_gen_ms'] . ')');
    sspa_t($home['sql_count'] > 10, 'home ran queries (' . $home['sql_count'] . ')');
    sspa_t($home['rows_returned_total'] !== null && $home['rows_returned_total'] > 0, 'row counts captured via shim (' . $home['rows_returned_total'] . ')');
    sspa_t((float) $home['sql_ms'] <= (float) $home['page_gen_ms'] * 1.1, 'sql_ms <= page_gen_ms');
    sspa_t((int) $home['response_code'] === 200, 'home responded 200');
    sspa_t($home['peak_mem_bytes'] > 10 * 1024 * 1024, 'peak memory captured (' . size_format((int) $home['peak_mem_bytes']) . ')');
}

// Admin variant must authenticate (not bounce to login).
$admin_dash = isset($by_key['admin-dashboard']) ? $by_key['admin-dashboard'] : null;
sspa_t($admin_dash !== null, 'admin dashboard profiled');
if ($admin_dash) {
    sspa_t((int) $admin_dash['response_code'] === 200 && empty($admin_dash['blocked_by']), 'admin auth cookie accepted (code ' . $admin_dash['response_code'] . ')');
    sspa_t($admin_dash['sql_count'] > 10, 'admin dashboard queries captured');
}

// Baseline noise-floor endpoint.
$baseline = isset($by_key['baseline']) ? $by_key['baseline'] : null;
sspa_t($baseline !== null && $baseline['ttfb_ms'] > 0, 'noise-floor baseline measured');

// Woo pages present (sample data was imported).
sspa_t(isset($by_key['shop']), 'shop page profiled');
sspa_t(isset($by_key['product-single']), 'product page profiled');
sspa_t(isset($by_key['admin-orders']), 'orders list profiled');

// Component stats extracted, WooCommerce attributed.
$stats_table = SSPA_Schema::table('component_stats');
$components = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT component FROM $stats_table WHERE run_id = %d", $run_id));
sspa_t(count($components) >= 2, 'component stats extracted (' . implode(', ', array_slice($components, 0, 8)) . ')');
sspa_t(in_array('woocommerce', $components, true), 'woocommerce component attributed');
sspa_t(in_array('core', $components, true), 'core component attributed');

// Blob stored and unpackable.
$blob = $wpdb->get_var($wpdb->prepare("SELECT profile_blob FROM $profiles_table WHERE run_id = %d AND page_key = 'home'", $run_id));
$capture = $blob ? json_decode(gzuncompress($blob), true) : null;
sspa_t(is_array($capture) && $capture['schema'] === 1, 'profile blob stored + unpacks');
sspa_t(is_array($capture) && $capture['overview']['capture_mode'] === 'full', 'capture mode full (shim active): ' . ($capture ? $capture['overview']['capture_mode'] : '?'));
sspa_t(is_array($capture) && !empty($capture['sql']['queries'][0]['fp']), 'queries carry fingerprints');

// Scratch space cleaned up as ingested; nothing left behind.
$captures_table = SSPA_Schema::table('captures');
$leftover = (int) $wpdb->get_var("SELECT COUNT(*) FROM $captures_table");
sspa_t($leftover === 0, "captures table drained ($leftover left)");

// The live site was never touched.
sspa_t(get_option('active_plugins') === $plugins_before, 'active_plugins untouched by the run');
sspa_t(!file_exists(WP_CONTENT_DIR . '/db.php.sspa-hold'), 'no db.php hold left behind');
