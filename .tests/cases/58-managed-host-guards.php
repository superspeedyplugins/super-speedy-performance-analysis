<?php
// Managed-host safety regressions: refuse a platform-owned cache-off response and probe the
// exact product/checkout target before queueing hours of work or creating an order.

function sspa_mhg_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
}

$guarded = SSPA_Crawler::evaluate_sample(array(
    'wall_ms' => 1,
    'code' => 200,
    'headers' => array(
        'x-sspa-profiled' => 'managed-cache-token',
        'x-sspa-object-cache' => 'platform-managed',
    ),
    'body' => '',
    'error' => null,
    'cookies_present' => false,
), 'managed-cache-token', array('oc' => '0'));
sspa_mhg_t(
    'object_cache_disable_unsupported' === $guarded['error'] && empty($guarded['capture']),
    'a request-time managed-cache guard is refused rather than stored as cache disabled'
);

$products = function_exists('wc_get_products') ? wc_get_products(array('limit' => 1, 'status' => 'publish')) : array();
$target = $products ? get_permalink($products[0]->get_id()) : home_url('/product/sspa-waf-probe/');
$flow_url = home_url('/?sspa_flow_probe=1');
$block_targets = function ($pre, $args, $url) use ($target, $flow_url) {
    $path = wp_parse_url($url, PHP_URL_PATH);
    $query = wp_parse_url($url, PHP_URL_QUERY);
    $target_path = wp_parse_url($target, PHP_URL_PATH);
    // Plain permalinks share '/' with home. Match the product's routing arguments too,
    // while allowing the profiling request to append its own signed arguments.
    parse_str((string) wp_parse_url($target, PHP_URL_QUERY), $target_query);
    parse_str((string) $query, $request_query);
    $matches_target = $path === $target_path;
    foreach ($target_query as $key => $value) {
        if (!isset($request_query[$key]) || $request_query[$key] !== $value) {
            $matches_target = false;
        }
    }
    if (!$matches_target && false === strpos((string) $query, 'sspa_flow_probe=1')) {
        return $pre;
    }
    if (empty($args['headers'][SSPA_Token::HEADER])) {
        return $pre;
    }
    return array(
        'headers' => array(),
        'body' => 'Jetpack Protect fixture',
        'response' => array('code' => 403, 'message' => 'Forbidden'),
        'cookies' => array(),
        'filename' => null,
    );
};
add_filter('pre_http_request', $block_targets, 10, 3);

$home = SSPA_Run_Controller::loopback_preflight(home_url('/'));
$exact = SSPA_Run_Controller::loopback_preflight($target);
sspa_mhg_t($home['healthy'], 'the ordinary home-page loopback still passes');
sspa_mhg_t(!$exact['healthy'] && false !== strpos($exact['reason'], 'HTTP 403'), 'the exact product path pre-flight catches its 403');

$deep = SSPA_Run_Controller::start(array(
    'type' => 'deep',
    'url' => $target,
    'suspects' => array('woocommerce'),
    'cache_modes' => false,
    // Earlier coverage deliberately retains a foreign/QM drop-in. Exercise the supported
    // temporary swap so this test reaches target preflight rather than its prerequisite.
    'swap_dropin' => true,
    'user_id' => 1,
));
sspa_mhg_t(is_wp_error($deep) && 'sspa_target_preflight_blocked' === $deep->get_error_code(), 'Plugin Impact Analysis refuses to queue a blocked target');
if (!is_wp_error($deep) || 'sspa_target_preflight_blocked' !== $deep->get_error_code()) {
    echo 'DIAGNOSTIC: deep outcome ' . (is_wp_error($deep) ? $deep->get_error_code() : 'queued') . "\n";
}

$before = function_exists('wc_get_orders') ? count(wc_get_orders(array('limit' => -1, 'return' => 'ids'))) : 0;
$checkout = SSPA_Run_Controller::start(array('type' => 'checkout', 'user_id' => 1));
$after = function_exists('wc_get_orders') ? count(wc_get_orders(array('limit' => -1, 'return' => 'ids'))) : 0;
sspa_mhg_t(is_wp_error($checkout) && 'sspa_checkout_preflight_failed' === $checkout->get_error_code(), 'checkout refuses to start when its exact entry point is blocked');
sspa_mhg_t($before === $after, 'blocked checkout pre-flight creates no order');

remove_filter('pre_http_request', $block_targets, 10);
