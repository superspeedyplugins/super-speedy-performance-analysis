<?php
/**
 * Plugin Name: Synthetic PA Browser Regression
 * Version: 1.0.0
 */
// Bounded real SQL work, excluded by the actual Plugin Impact Analysis engine.
add_action('template_redirect', static function () {
    if (!get_option('sspa_fleet_enabled')) return;
    global $wpdb;
    $wpdb->get_var('SELECT SLEEP(0.08)');
    $wpdb->get_results("SELECT ID, post_title FROM {$wpdb->posts} WHERE post_title LIKE 'Synthetic PA browser%' LIMIT 5");
    if (get_option('sspa_fleet_http_error') && isset($GLOBALS['sspa_capture'])) {
        status_header(503);
        echo 'Synthetic measured upstream failure';
        exit;
    }
});
add_filter('sspa_transport', static function ($transport, $type) {
    return get_option('sspa_fleet_browser_transport') && $type !== 'deep' && $type !== 'checkout' ? 'browser' : $transport;
}, 10, 2);
