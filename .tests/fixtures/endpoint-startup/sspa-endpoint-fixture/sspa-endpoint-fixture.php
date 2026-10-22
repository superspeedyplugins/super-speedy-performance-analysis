<?php
/**
 * Plugin Name: SSPA test fixture - endpoint start-up
 * Description: Test fixture for cases 140 and 141. One GET REST route and one GET admin-ajax action with known costs: 40 ms slept on plugins_loaded (start-up) and 30 ms slept in the handler, both only on the fixture's own requests.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

function sspa_endpoint_fixture_request() {
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- test fixture keyed on its own route and action.
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    $action = isset($_REQUEST['action']) ? (string) $_REQUEST['action'] : '';
    return false !== strpos($uri, 'sspa-endpoint-fixture/v1/') || 'zz_endpoint_fixture' === $action;
}

// Known start-up cost: runs before the handler, after the MU observer.
add_action('plugins_loaded', static function () {
    if (sspa_endpoint_fixture_request()) {
        usleep(40000);
    }
});

function sspa_endpoint_fixture_work($term) {
    global $wpdb;
    usleep(30000); // known handler cost, off CPU
    return array('term' => $term, 'posts' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish'"));
}

add_action('rest_api_init', static function () {
    register_rest_route('sspa-endpoint-fixture/v1', '/startup/(?P<term>[a-z0-9-]+)(?:/(?P<lang>[a-z]{2}))?', array(
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => static function ($request) {
            return rest_ensure_response(sspa_endpoint_fixture_work((string) $request['term']));
        },
    ));
    // A write route, so the UI can be checked to offer profiling for GET and HEAD only.
    register_rest_route('sspa-endpoint-fixture/v1', '/write', array(
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => static function () {
            return rest_ensure_response(array('written' => false));
        },
    ));
});

$sspa_endpoint_fixture_ajax = static function () {
    $term = isset($_GET['term']) ? sanitize_key(wp_unslash($_GET['term'])) : '';
    wp_send_json_success(sspa_endpoint_fixture_work($term));
};
add_action('wp_ajax_nopriv_zz_endpoint_fixture', $sspa_endpoint_fixture_ajax);
add_action('wp_ajax_zz_endpoint_fixture', $sspa_endpoint_fixture_ajax);
