<?php
// The basket audience presents WooCommerce's basket cookies and creates nothing: no
// WooCommerce session row and therefore no basket. The server legs run in their fixed order,
// carry the right cookies, and read cURL's time to first byte. Real requests to this site.

function sspa_bc_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
}

global $wpdb;
if (!class_exists('WooCommerce')) {
    sspa_bc_t(false, 'WooCommerce is active on the suite site');
    return;
}
$sessions = $wpdb->prefix . 'woocommerce_sessions';
$count = function () use ($wpdb, $sessions) {
    return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $sessions));
};

$prepared = SSPA_Cache_Delivery::prepare_check();
sspa_bc_t(!is_wp_error($prepared), 'a check can be prepared');
if (is_wp_error($prepared)) {
    return;
}
$id = $prepared['assessment_id'];

$early = SSPA_Cache_Delivery::probe_server_request($id, 'shop', 'customer', 1);
sspa_bc_t(is_wp_error($early) && 'sspa_cache_delivery_order' === $early->get_error_code(), 'the customer request is refused before the anonymous and basket requests for that page');

$sent = array();
$capture = function ($args, $url) use (&$sent) {
    $sent[] = array('url' => $url, 'cookies' => array_keys((array) $args['cookies']));
    return $args;
};
add_filter('http_request_args', $capture, 10, 2);
$anonymous = array(
    SSPA_Cache_Delivery::probe_server_request($id, 'shop', 'anonymous', 1),
    SSPA_Cache_Delivery::probe_server_request($id, 'shop', 'anonymous', 2),
);
$before = $count();
$basket = array(
    SSPA_Cache_Delivery::probe_server_request($id, 'shop', 'basket', 1),
    SSPA_Cache_Delivery::probe_server_request($id, 'shop', 'basket', 2),
);
$after = $count();
$customer = SSPA_Cache_Delivery::probe_server_request($id, 'shop', 'customer', 1);
remove_filter('http_request_args', $capture, 10);

sspa_bc_t(!array_filter(array_merge($anonymous, $basket, array($customer)), 'is_wp_error'), 'the anonymous, basket and customer requests ran in order');
sspa_bc_t(200 === $basket[0]['http_status'] && 200 === $basket[1]['http_status'], 'the shop answers the basket requests with HTTP 200');
sspa_bc_t($before === $after, "the basket requests created no WooCommerce session or basket ($before rows before, $after after)");
sspa_bc_t(array() === $sent[0]['cookies'] && array() === $sent[1]['cookies'], 'the anonymous requests carry no cookies');
sspa_bc_t(array('woocommerce_items_in_cart', 'woocommerce_cart_hash') === $sent[2]['cookies'] && $sent[2]['cookies'] === $sent[3]['cookies'], 'the basket requests carry exactly WooCommerce\'s two basket cookies');
sspa_bc_t(in_array(LOGGED_IN_COOKIE, $sent[4]['cookies'], true), 'the customer request carries a WordPress logged-in cookie');
sspa_bc_t(!empty($customer['private_markers']['logged_in_body_class']) && empty($anonymous[0]['private_markers']['logged_in_body_class']), 'the customer response is a logged-in page and the anonymous one is not');
sspa_bc_t(array() === $basket[0]['application_cookie_names'], 'WooCommerce expiring the two basket cookies is not reported as new application state');
sspa_bc_t('curl_starttransfer_time' === $anonymous[0]['ttfb_source'] && $anonymous[0]['ttfb_ms'] > 0 && $anonymous[0]['ttfb_ms'] <= $anonymous[0]['total_ms'], 'server time to first byte comes from cURL and is no later than completion');
$pending = get_option(SSPA_Cache_Delivery::PENDING_OPTION);
sspa_bc_t(false === strpos(wp_json_encode($pending['server_results']), (string) $pending['basket_cart_hash']), 'the basket cookie value is not copied into the request evidence');
