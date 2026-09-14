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
    // Captured requests only: an unprofiled anonymous request must not read an sspa_ option,
    // which case 52 asserts.
    if (isset($GLOBALS['sspa_capture']) && get_option('sspa_fleet_http_error')) {
        status_header(503);
        echo 'Synthetic measured upstream failure';
        exit;
    }
});
// A real loopback failure for the fallback journey: while the flag is on, the server's own
// profiler requests (the ones carrying the token header) are refused at the HTTP layer, as
// a host firewall would refuse them. Browser access is untouched, and nothing forces the
// transport, so the plugin's own decision is what gets exercised.
add_filter('pre_http_request', static function ($pre, $args, $url) {
    if (false !== $pre) return $pre;
    $headers = isset($args['headers']) && is_array($args['headers']) ? array_change_key_case($args['headers'], CASE_LOWER) : array();
    if (!isset($headers[strtolower(SSPA_Token::HEADER)]) || !get_option('sspa_fleet_loopback_blocked')) return $pre;
    return new WP_Error('http_request_failed', 'cURL error 7: Failed to connect (synthetic loopback block)');
}, 5, 3);
add_filter('sspa_transport', static function ($transport, $type) {
    return get_option('sspa_fleet_browser_transport') && $type !== 'deep' && $type !== 'checkout' ? 'browser' : $transport;
}, 10, 2);
