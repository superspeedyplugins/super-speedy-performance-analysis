<?php
// Intercept community requests in real HTTP loopbacks as well as the CLI controller.
defined('ABSPATH') || exit;
add_filter('pre_http_request', static function($pre, $args, $url) {
    if (!get_option('sspa_w1_http_watch') || !preg_match('~(^|\.)superspeedy\.org$~', (string) wp_parse_url($url, PHP_URL_HOST))) {
        return $pre;
    }
    $requests = get_option('sspa_w1_http_requests', array());
    $requests[] = array('host' => wp_parse_url($url, PHP_URL_HOST), 'path' => wp_parse_url($url, PHP_URL_PATH));
    update_option('sspa_w1_http_requests', $requests, false);
    return new WP_Error('sspa_w1_http_intercepted', 'Unexpected community request with sharing disabled');
}, PHP_INT_MAX, 3);
