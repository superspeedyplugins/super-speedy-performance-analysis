<?php
// Plugins tab journey fixture: one plugin with a known, page-specific cost and a completed
// baseline of four pages, so the journey can run a real Plugin Impact Analysis from the
// Overview button. Clears this journey's previous impact runs on the way IN; nothing is
// removed at the end, so the finished site can be inspected.
require_once dirname(__DIR__) . '/lib/native-guard.php';
global $wpdb;

$slug = 'zz-slow-shop';
$dir = WP_PLUGIN_DIR . '/' . $slug;
if (!is_dir($dir) && !mkdir($dir)) {
    throw new RuntimeException('cannot create ' . $dir);
}
file_put_contents($dir . '/' . $slug . '.php', <<<'CODE'
<?php
/**
 * Plugin Name: zz-slow-shop (test fixture)
 * Version: 1.0.0
 */
// 120 ms on the shop and product category archives only, nothing anywhere else.
add_action('wp_footer', function () {
    if (function_exists('is_shop') && (is_shop() || is_product_category())) {
        global $wpdb;
        $wpdb->get_var('SELECT SLEEP(0.12)');
    }
});
CODE
);
wp_clean_plugins_cache();
$activated = activate_plugin($slug . '/' . $slug . '.php');
if (is_wp_error($activated)) {
    throw new RuntimeException($activated->get_error_message());
}
wp_cache_flush();

// Entry reset: previous Plugin Impact Analysis runs and the "summary seen" marker.
foreach ($wpdb->get_col($wpdb->prepare('SELECT id FROM %i WHERE run_type = %s', SSPA_Schema::table('runs'), 'deep')) as $run_id) {
    $wpdb->delete(SSPA_Schema::table('plugin_impacts'), array('test_run_id' => (int) $run_id));
    $wpdb->delete(SSPA_Schema::table('run_jobs'), array('run_id' => (int) $run_id));
    $wpdb->delete(SSPA_Schema::table('runs'), array('id' => (int) $run_id));
}
$wpdb->query($wpdb->prepare('DELETE FROM %i', SSPA_Schema::table('plugin_impacts')));
delete_metadata('user', 0, 'sspa_impact_summary_seen', '', true);
sleep(2); // opcache sees the new plugin file

$run_id = SSPA_Run_Controller::start(array('type' => 'spot', 'page_keys' => array('home', 'shop', 'product-cat', 'product-single'), 'user_id' => 1));
if (is_wp_error($run_id)) {
    throw new RuntimeException($run_id->get_error_message());
}
$deadline = time() + 600;
do {
    SSPA_Run_Controller::process_batch($run_id);
    $status = SSPA_Run_Controller::status($run_id);
} while ($status && in_array($status['status'], array('crawling', 'analysing'), true) && time() < $deadline);
if (!$status || 'done' !== $status['status']) {
    throw new RuntimeException('baseline did not finish: ' . wp_json_encode($status));
}
echo wp_json_encode(array('baseline_run' => (int) $run_id, 'plugin' => $slug)) . "\n";
