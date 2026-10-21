<?php
/**
 * Plugin Name: SSPA regression - quote request observer
 * Description: Test fixture only, on the Performance Analysis regression site. Records every
 * WordPress HTTP API request to a quote-request route, /wp-json/ssp-contact/v1/quote-request on
 * any host (superspeedyplugins.com or the local receiver in SSPA_QUOTE_REQUEST_TEST_ENDPOINT),
 * and the answer it received. The plugin's own update checks to superspeedyplugins.com are a
 * different path and are not recorded. It
 * observes only: it never answers a request, so a request reaches the real receiver, and one to
 * any external host is still refused by the regression boundary (local-delivery.php).
 *
 * Option sspa_test_quote_log: every such request, in order.
 */
defined('ABSPATH') || exit;

$sspa_test_quote_is_route = static function ($url) {
    return false !== strpos((string) wp_parse_url($url, PHP_URL_PATH), '/ssp-contact/v1/quote-request');
};

add_filter('pre_http_request', static function ($preempt, $args, $url) use ($sspa_test_quote_is_route) {
    if (!$sspa_test_quote_is_route($url)) {
        return $preempt;
    }
    $headers = isset($args['headers']) ? (array) $args['headers'] : array();
    $body = isset($args['body']) && is_string($args['body']) ? $args['body'] : '';
    $decoded = json_decode($body, true);
    $log = get_option('sspa_test_quote_log', array());
    $log = is_array($log) ? $log : array();
    $log[] = array(
        'at' => microtime(true),
        'method' => strtoupper((string) $args['method']),
        'url' => $url,
        'user_agent' => (string) $args['user-agent'],
        'content_type' => isset($headers['Content-Type']) ? (string) $headers['Content-Type'] : '',
        'body_bytes' => strlen($body),
        'keys' => is_array($decoded) ? array_keys($decoded) : array(),
        'markdown_sha256' => is_array($decoded) && isset($decoded['markdown']) ? hash('sha256', (string) $decoded['markdown']) : '',
        'request_uuid' => is_array($decoded) && isset($decoded['request_uuid']) ? (string) $decoded['request_uuid'] : '',
        'code' => null,
    );
    update_option('sspa_test_quote_log', $log, false);
    return $preempt;
}, 1, 3);

add_action('http_api_debug', static function ($response, $context, $class, $args, $url) use ($sspa_test_quote_is_route) {
    if ('response' !== $context || !$sspa_test_quote_is_route($url)) {
        return;
    }
    $log = get_option('sspa_test_quote_log', array());
    if (!is_array($log) || !$log) {
        return;
    }
    $last = count($log) - 1;
    $log[$last]['code'] = is_wp_error($response) ? $response->get_error_code() : (int) wp_remote_retrieve_response_code($response);
    update_option('sspa_test_quote_log', $log, false);
}, 10, 5);
