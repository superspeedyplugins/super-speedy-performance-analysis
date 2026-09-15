<?php
require SSPA_PLUGIN_DIR . '.tests/lib/fleet.php';
wp_set_current_user(1);
// Render the real control without WooCommerce in this dedicated scenario. The suite
// also renders it with WooCommerce loaded; this assertion observes either supported state.
ob_start();
include SSPA_PLUGIN_DIR . 'includes/admin/tabs/traffic.php';
$traffic = ob_get_clean();
preg_match('/<button[^>]*id="sspa-traffic-start"[^>]*>/', $traffic, $button);
sspa_fleet_assert(isset($button[0]) && !preg_match('/\sdisabled(?:=|\s|>)/', $button[0]), 'single-site traffic Start is enabled (WooCommerce ' . (class_exists('WooCommerce') ? 'active' : 'absent') . ')');

global $wpdb;
$wpdb->insert(SSPA_Schema::table('runs'), array('blog_id' => get_current_blog_id(), 'status' => 'done', 'run_type' => 'spot', 'run_uuid' => wp_generate_uuid4(), 'trigger_source' => 'test', 'measurement_version' => 1, 'started' => gmdate('Y-m-d H:i:s'), 'finished' => gmdate('Y-m-d H:i:s'), 'notes' => '{}', 'plugin_set' => '{}'));
$sspa_93_run = (int) $wpdb->insert_id;
if (!$sspa_93_run || $wpdb->last_error) throw new RuntimeException('Run fixture failed: ' . $wpdb->last_error);
foreach (array(100, 300, 500, 900) as $i => $ms) {
    $saved = SSPA_Profile_Store::save($sspa_93_run, array('page_key' => 'review-' . $i, 'url' => home_url('/'), 'variant' => 'anon', 'plugin_set_hash' => '', 'blocked_by' => null, 'samples' => array(array('wall_ms' => $ms, 'code' => 200, 'error' => null, 'cached' => false, 'capture' => array('overview' => array('gen_ms' => $ms), 'sql' => array('total_ms' => 0), 'http' => array('total_ms' => 0))))));
    if (!$saved || $wpdb->last_error) throw new RuntimeException('Profile fixture failed: ' . $wpdb->last_error);
}
ob_start();
include SSPA_PLUGIN_DIR . 'includes/admin/tabs/history.php';
$history = ob_get_clean();
sspa_fleet_assert(strpos($history, 'Mean generation (ms)') !== false && strpos($history, 'Median generation (ms)') === false, 'History labels arithmetic mean accurately');
preg_match('/<tr>\s*<td><a[^>]*data-run-id="' . $sspa_93_run . '".*?<\/tr>/s', $history, $row);
sspa_fleet_assert(isset($row[0]) && strpos($row[0], '>450.0</td>') !== false, 'uneven real profiles display mean 450.0 (median would be 400.0)');

require_once SSPA_PLUGIN_DIR . 'profiler/class-sspa-archive-queries.php';
$fixture = get_page_by_path('sspa-review-archive-fixture', OBJECT, 'post');
$fixture_id = $fixture ? $fixture->ID : wp_insert_post(array('post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Archive cap regression fixture', 'post_name' => 'sspa-review-archive-fixture'));
if (is_wp_error($fixture_id) || !$fixture_id) throw new RuntimeException('Archive fixture could not be created');
wp_set_post_categories($fixture_id, array(1));
$recorder = new SSPA_Archive_Queries();
$recorder->arm();
$args = array('post_type' => 'post', 'posts_per_page' => 1, 'cache_results' => false, 'tax_query' => array(array('taxonomy' => 'category', 'field' => 'term_id', 'terms' => array(1))));
for ($i = 0; $i < 50; $i++) $query = new WP_Query($args);
$report = (clone $recorder)->report(array());
sspa_fleet_assert($report['captured'] === 50 && !$report['truncated'], '50 actual qualifying WP_Query calls fill the cap without truncation');
new WP_Query(array('p' => 1, 'post_type' => 'post', 'cache_results' => false));
$report = (clone $recorder)->report(array());
sspa_fleet_assert(!$report['truncated'], 'nonqualifying query after the cap does not claim truncation');
$last = new WP_Query($args);
$report = (clone $recorder)->report(array());
sspa_fleet_assert($report['captured'] === 50 && $report['truncated'], '51 actual qualifying WP_Query calls expose truncation and retain 50');
sspa_fleet_assert(count($query->posts) === 1, 'archive fixture returns a real post');
sspa_fleet_assert(wp_list_pluck($last->posts, 'ID') === wp_list_pluck($query->posts, 'ID'), 'query results are unchanged across the recorder cap');
remove_filter('posts_clauses', array($recorder, 'note_clauses'), PHP_INT_MAX);
remove_filter('the_posts', array($recorder, 'record'), PHP_INT_MAX);
sspa_fleet_done();
