<?php
// Deep analysis end to end: plant the bad plugin, baseline it, then let deep analysis
// measure its true cost by virtual exclusion - and prove the live site was never touched.

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
$deep_id = SSPA_Run_Controller::start(array('type' => 'deep', 'suspects' => array('sspa-bad-plugin'), 'bisect' => false, 'user_id' => 1));
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

// --- The measured impact must be real and attributed ---
$impact = $wpdb->get_row($wpdb->prepare(
    'SELECT * FROM ' . SSPA_Schema::table('plugin_impacts') . " WHERE test_run_id = %d AND plugin = 'sspa-bad-plugin'",
    $deep_id
), ARRAY_A);
sspa_t($impact !== null, 'plugin_impacts row written for sspa-bad-plugin');
if ($impact) {
    sspa_t('single_out' === $impact['method'], 'method single_out');
    sspa_t('measured' === $impact['confidence'], 'confidence measured');
    sspa_t((float) $impact['delta_ttfb_ms'] > 200, 'measured generation delta credible (+' . $impact['delta_ttfb_ms'] . 'ms)');
    sspa_t((float) $impact['delta_sql_ms'] > 100, 'measured SQL delta credible (+' . $impact['delta_sql_ms'] . 'ms)');
    sspa_t((int) $impact['delta_queries'] > 50, 'measured query-count delta credible (+' . $impact['delta_queries'] . ')');
    sspa_t((float) $impact['noise_floor_ms'] >= 30, 'noise floor recorded (' . $impact['noise_floor_ms'] . 'ms)');
    sspa_t((int) $impact['baseline_run_id'] === (int) $run_id, 'baseline run linked');
}

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
