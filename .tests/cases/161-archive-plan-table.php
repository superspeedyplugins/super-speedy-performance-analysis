<?php
// An archive whose plan reads its table with no usable index is reported against that table,
// with no PHP warning from the analysis.
//
// Before 0.65.0 the archive profile kept only five fields of the plan (scan, filesort, key,
// est_rows, est_rows_total) and the analysis handed that reduced copy to
// SSPA_Explain::summarise(), which reads 'table' whenever 'scan' is set. Every archive with a
// full-table-scan plan raised "Undefined array key "table"" at class-sspa-explain.php:208, and
// its finding said "no usable index on the table" instead of naming the table. Its 'temporary'
// flag was dropped the same way, so "builds a temporary table" was never reported for an
// archive. Seen on the 1,000,000-product benchmark of 13 November 2026 (the shop archive).
//
// Drives a real ad hoc run of the WooCommerce shop through the run controller. 400 published
// products make the shop's own main query (post_type = 'product', ORDER BY menu_order,
// post_title) a full scan of wp_posts with a filesort, the same plan shape as the benchmark.
function sspa_161_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
}

global $wpdb;

if (!class_exists('WC_Product_Simple') || !function_exists('wc_get_page_permalink')) {
    sspa_161_t(false, 'WooCommerce is active on the suite site');
    return;
}

// --- Fixture on the way in: exactly 400 published simple products named SSPA t161 ---
foreach ($wpdb->get_col($wpdb->prepare("SELECT ID FROM %i WHERE post_type = 'product' AND post_title LIKE %s", $wpdb->posts, 'SSPA t161 %')) as $id) {
    wp_delete_post((int) $id, true);
}
for ($i = 1; $i <= 400; $i++) {
    $product = new WC_Product_Simple();
    $product->set_name('SSPA t161 product ' . $i);
    $product->set_regular_price('10');
    $product->set_status('publish');
    $product->save();
}

// The plan this case depends on, read the same way PA reads it. If the optimiser ever stops
// choosing a full scan here the case says so instead of passing for the wrong reason.
$shop_sql = (new WP_Query(array('post_type' => 'product', 'post_status' => 'publish', 'orderby' => 'menu_order title', 'order' => 'ASC', 'posts_per_page' => 16)))->request;
$direct = SSPA_Explain::explain($shop_sql);
sspa_161_t(is_array($direct) && !empty($direct['scan']) && $direct['table'] === $wpdb->posts, 'precondition: the shop query plan is a full scan of ' . $wpdb->posts . ': ' . wp_json_encode(is_array($direct) ? array($direct['access_type'], $direct['table'], $direct['est_rows']) : null));

// Every PHP warning raised from PA's EXPLAIN code while the run profiles and analyses.
$warnings = array();
set_error_handler(static function ($errno, $errstr, $errfile, $errline) use (&$warnings) {
    if (false !== strpos((string) $errfile, 'class-sspa-explain.php')) {
        $warnings[] = $errstr . ' at ' . basename($errfile) . ':' . $errline;
        return true;
    }
    return false;
}, E_WARNING | E_NOTICE);

$url = wc_get_page_permalink('shop');
$run_id = SSPA_Run_Controller::start(array('type' => 'adhoc', 'url' => $url, 'trigger' => 'cli', 'user_id' => 1));
if (is_wp_error($run_id)) {
    restore_error_handler();
    sspa_161_t(false, 'ad hoc run of ' . $url . ': ' . $run_id->get_error_message());
    return;
}
$deadline = time() + 240;
do {
    SSPA_Run_Controller::process_batch($run_id);
    $status = SSPA_Run_Controller::status($run_id);
} while ($status && in_array($status['status'], array('queued', 'crawling', 'analysing'), true) && time() < $deadline);
restore_error_handler();
echo "Run $run_id of $url: " . ($status ? $status['status'] : 'no status') . "\n";
sspa_161_t($status && 'done' === $status['status'], 'the run finished as done');

sspa_161_t(!$warnings, 'no PHP warning from class-sspa-explain.php during profiling and analysis: ' . ($warnings ? implode(' | ', array_unique($warnings)) : 'none'));

// The archive profile names the table its worst step reads.
$profile = SSPA_Report::archive_profile($run_id);
$entry = null;
foreach (is_wp_error($profile) ? array() : (array) $profile['archives'] as $e) {
    if (!empty($e['main']) && in_array('product', (array) $e['post_type'], true)) { $entry = $e; break; }
}
sspa_161_t(is_array($entry) && !empty($entry['explain']['scan']), 'the archive profile has the shop main query with a scan plan');
sspa_161_t(is_array($entry) && isset($entry['explain']['table']) && $entry['explain']['table'] === $wpdb->posts, 'its plan names the table scanned (' . $wpdb->posts . '): ' . (is_array($entry) ? wp_json_encode($entry['explain']) : 'none'));
sspa_161_t(is_array($entry) && array_key_exists('temporary', (array) $entry['explain']), 'its plan carries the temporary-table flag');

// The finding the user reads names that table.
$findings = $wpdb->get_results($wpdb->prepare('SELECT evidence FROM %i WHERE run_id = %d AND finding_type = %s', SSPA_Schema::table('findings'), $run_id, 'archive_query_unindexed'), ARRAY_A);
$note = null;
foreach ($findings as $f) {
    $evidence = json_decode($f['evidence'], true);
    if (is_array($evidence) && in_array('scan', (array) $evidence['reasons'], true)) { $note = (string) $evidence['plan_note']; break; }
}
sspa_161_t(null !== $note, 'an archive_query_unindexed finding was raised for the scan: ' . count($findings) . ' finding(s)');
sspa_161_t(null !== $note && false !== strpos($note, 'no usable index on ' . $wpdb->posts . ' '), 'its query plan note names ' . $wpdb->posts . ': ' . var_export($note, true));
echo "Site: $url\n";
