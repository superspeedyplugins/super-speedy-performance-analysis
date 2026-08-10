<?php
// Deep analysis end to end: plant the bad plugin, baseline it, then let the deep SWEEP
// measure its true cost by virtual exclusion on every profiled page (in every cache mode
// when an object cache is present) - and prove the live site was never touched.

function sspa_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

global $wpdb;

// --- Plant the same bad plugin as 07 (its footer work costs ~1.5s+ on home) ---
$bad_dir = WP_PLUGIN_DIR . '/sspa-bad-plugin';
if (!is_dir($bad_dir)) {
    mkdir($bad_dir);
}
$bad_code = <<<'PHP'
<?php
/**
 * Plugin Name: SSPA Bad Plugin (test fixture)
 * Version: 2.4.1
 */
add_action('wp_footer', function () {
    global $wpdb;
    for ($i = 1; $i <= 60; $i++) {
        $wpdb->get_var("SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_id = " . $i);
    }
    $wpdb->get_results("SELECT meta_id, post_id, meta_key FROM {$wpdb->postmeta} LIMIT 600");
    $wpdb->get_results("SELECT p1.ID FROM {$wpdb->posts} p1, {$wpdb->posts} p2, {$wpdb->posts} p3 ORDER BY rand() LIMIT 5");
});
PHP;
file_put_contents($bad_dir . '/sspa-bad-plugin.php', $bad_code);
activate_plugin('sspa-bad-plugin/sspa-bad-plugin.php');
wp_cache_flush(); // apache must see the fresh active_plugins despite Redis alloptions caching
sleep(3); // opcache

$plugins_before = get_option('active_plugins');
sspa_t(in_array('sspa-bad-plugin/sspa-bad-plugin.php', $plugins_before, true), 'bad plugin active');

// --- Baseline spot run so deep analysis has findings to chew on ---
$run_id = SSPA_Run_Controller::start(array('type' => 'spot', 'page_keys' => array('home'), 'user_id' => 1));
$deadline = time() + 180;
do {
    SSPA_Run_Controller::process_batch($run_id);
    $status = SSPA_Run_Controller::status($run_id);
} while ($status && in_array($status['status'], array('crawling', 'analysing'), true) && time() < $deadline);
sspa_t($status && 'done' === $status['status'], 'baseline spot run done');

// --- Deep run targeting the suspect ---
$deep_id = SSPA_Run_Controller::start(array('type' => 'deep', 'suspects' => array('sspa-bad-plugin'), 'user_id' => 1));
if (is_wp_error($deep_id)) {
    echo 'FAIL: deep start: ' . $deep_id->get_error_message() . "\n";
    return;
}
$deadline = time() + 300;
do {
    SSPA_Run_Controller::process_batch($deep_id);
    $status = SSPA_Run_Controller::status($deep_id);
} while ($status && in_array($status['status'], array('crawling', 'analysing'), true) && time() < $deadline);
sspa_t($status && 'done' === $status['status'], 'deep run done: ' . ($status ? $status['status'] : 'null'));

// --- The measured impacts must be real, attributed, and cover every cache mode ---
$impacts = $wpdb->get_results($wpdb->prepare(
    'SELECT * FROM ' . SSPA_Schema::table('plugin_impacts') . " WHERE test_run_id = %d AND plugin = 'sspa-bad-plugin'",
    $deep_id
), ARRAY_A);
sspa_t(count($impacts) >= 1, 'plugin_impacts row(s) written for sspa-bad-plugin (' . count($impacts) . ')');

// Phase 1 screens in 'normal' mode; the impacted plugin graduates to phase 2, which
// with Redis + our db.php shim adds disabled + prime measurements.
$expected_modes = (wp_using_ext_object_cache() && 'ours' === SSPA_Helper_Files::dropin_status())
    ? array('disabled', 'normal', 'prime')
    : array('normal');
$modes_seen = array_unique(array_map(function ($i) {
    return $i['object_cache_mode'];
}, $impacts));
sort($modes_seen);
$expected_sorted = $expected_modes;
sort($expected_sorted);
sspa_t($modes_seen === $expected_sorted, 'cache modes covered: ' . implode(',', $modes_seen) . ' (expected ' . implode(',', $expected_sorted) . ')');

// The bad plugin's cost is raw SQL in wp_footer - it must be measured in EVERY mode.
foreach ($impacts as $impact) {
    $mode = $impact['object_cache_mode'];
    sspa_t('single_out' === $impact['method'], "[$mode] method single_out");
    sspa_t('measured' === $impact['confidence'], "[$mode] confidence measured");
    sspa_t((float) $impact['delta_ttfb_ms'] > 200, "[$mode] generation delta credible (+" . $impact['delta_ttfb_ms'] . 'ms)');
    sspa_t((float) $impact['delta_sql_ms'] > 100, "[$mode] SQL delta credible (+" . $impact['delta_sql_ms'] . 'ms)');
    sspa_t((int) $impact['delta_queries'] > 50, "[$mode] query-count delta credible (+" . $impact['delta_queries'] . ')');
    sspa_t((float) $impact['noise_floor_ms'] >= 30, "[$mode] noise floor recorded (" . $impact['noise_floor_ms'] . 'ms)');
    sspa_t((int) $impact['baseline_run_id'] === (int) $run_id, "[$mode] baseline run linked");
    // A verdict belongs to the version it was measured against, so the sweep must stamp
    // the version the deep run itself recorded - not whatever is installed when read.
    sspa_t('2.4.1' === $impact['plugin_version'], "[$mode] measured plugin version recorded (" . var_export($impact['plugin_version'], true) . ')');
}

sspa_t(
    '2.4.1' === SSPA_Run_Controller::component_version($deep_id, 'sspa-bad-plugin'),
    'deep run snapshot resolves the measured version'
);

// Update the plugin underneath us. The live inventory must move on while the recorded
// measurement does not - that contrast is the whole point of stamping the version.
file_put_contents($bad_dir . '/sspa-bad-plugin.php', str_replace('Version: 2.4.1', 'Version: 9.9.9', $bad_code));
wp_cache_flush();
$sspa_live = null;
foreach (SSPA_Community_Exporter::component_inventory_snapshot() as $sspa_component) {
    if ('sspa-bad-plugin' === $sspa_component['slug']) {
        $sspa_live = $sspa_component['version'];
    }
}
sspa_t('9.9.9' === $sspa_live, 'live inventory sees the updated version (' . var_export($sspa_live, true) . ')');
$sspa_stored = $wpdb->get_var($wpdb->prepare(
    'SELECT plugin_version FROM ' . SSPA_Schema::table('plugin_impacts') . " WHERE test_run_id = %d AND plugin = 'sspa-bad-plugin' LIMIT 1",
    $deep_id
));
sspa_t('2.4.1' === $sspa_stored, 'recorded impact still names the version it measured (' . var_export($sspa_stored, true) . ')');

// --- The Plugins tab must say WHEN a measured verdict was taken ---
// A verdict from an old sweep reads as current unless the cell dates it, which is how a
// plugin that has since been fixed keeps showing its old cost.
$sspa_table_run = SSPA_Plugins_Table::latest_run_id();
$sspa_table = SSPA_Plugins_Table::render($sspa_table_run, 'code_owner');
sspa_t(false !== strpos($sspa_table, 'sspa-bad-plugin'), 'plugins table lists the measured plugin');
sspa_t(false !== strpos($sspa_table, 'measured '), 'plugins table dates the measured impact');
sspa_t(false !== strpos($sspa_table, '2.4.1'), 'plugins table names the measured version');
sspa_t(
    substr_count($sspa_table, '<th>') === substr_count(explode('</thead>', $sspa_table)[0], '<th>'),
    'plugins table renders one header row'
);

// The measured-impact cell must report worst and typical, never a sum. Adding per-page deltas
// together produced a number nobody experiences and that grew simply by measuring more pages.
// Asserted here, not in the scoped case, because this fixture's impact is guaranteed measurable
// (asserted above) - a guard on 'is it measured' would silently skip these.
$sspa_bad_row = '';
foreach (explode('<tr>', $sspa_table) as $sspa_r) {
    if (false !== strpos($sspa_r, '<code>sspa-bad-plugin</code>')) {
        $sspa_bad_row = $sspa_r;
    }
}
sspa_t('' !== $sspa_bad_row, 'the measured plugin has a row in the table');
sspa_t(false !== strpos($sspa_bad_row, 'sspa-badge-measured'), 'its verdict is a measured one');
sspa_t(false !== strpos($sspa_bad_row, 'typically'), 'the verdict reports a typical figure');
sspa_t(false !== strpos($sspa_bad_row, 'up to '), 'the verdict reports the worst page');
sspa_t(false !== strpos($sspa_bad_row, 'measurable on '), 'the verdict reports its coverage');
sspa_t(false === strpos($sspa_bad_row, 'across '), 'the summed-across-pages wording is gone');

// The attribution switch is a table swap, so both modes render from this one method. Caller
// mode is recomputed from the capture blobs rather than read from component_stats, so the
// thing worth asserting is that the recompute produced rows at all - the two modes agree on
// a fixture that runs its own queries, and only diverge when one component calls another.
$sspa_caller = SSPA_Plugins_Table::render($sspa_table_run, 'caller');
sspa_t(false !== strpos($sspa_caller, '<table'), 'caller mode renders a table');
sspa_t(false !== strpos($sspa_caller, 'sspa-bad-plugin'), 'caller-mode recompute produced component rows');
sspa_t(
    substr_count($sspa_caller, '<tr>') === substr_count($sspa_table, '<tr>'),
    'both modes render the same component count on a fixture that calls nothing else'
);

// --- Deep run stored its measurement profiles with plugin-set hashes ---
$hashed = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . SSPA_Schema::table('profiles') . " WHERE run_id = %d AND plugin_set_hash != ''",
    $deep_id
));
sspa_t($hashed >= 1, 'excluded-set measurement profiles stored with ps hash');

// --- The live site was NEVER touched ---
sspa_t(get_option('active_plugins') === $plugins_before, 'active_plugins untouched throughout deep run');
$leftover_iso = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'sspa\\_isolation\\_%'");
sspa_t(0 === $leftover_iso, 'isolation payload options cleaned up');
sspa_t(false === get_option('sspa_deep_' . $deep_id), 'deep plan option cleaned up');

// --- Clean up ---
deactivate_plugins('sspa-bad-plugin/sspa-bad-plugin.php');
unlink($bad_dir . '/sspa-bad-plugin.php');
rmdir($bad_dir);
sspa_t(!file_exists($bad_dir), 'bad plugin removed');
