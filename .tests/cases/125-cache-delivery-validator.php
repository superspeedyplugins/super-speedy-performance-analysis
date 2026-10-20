<?php
// Cache check: fixed targets, request plan and the browser-result validator.
//
// Renumbered from the August branch's case 59. The server legs here are real requests to
// this site. The BROWSER results are a hand-written payload, so this case proves only the
// validator and the storage contract: that a browser HTTP-cache read retaining an upstream
// HIT header is not counted as a page-cache HIT, and that malformed or off-target results
// are refused. The real browser leg is proved by .tests/browser/cache-performance.e2e.cjs
// against Cache Enabler (case 126) and against no page cache (case 127).

function sspa_cd_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
}

if (!class_exists('SSPA_Cache_Delivery')) {
    sspa_cd_t(false, 'the cache check entry point exists');
    return;
}

$targets = SSPA_Cache_Delivery::targets();
$keys = array_column($targets, 'page_key');
sspa_cd_t(array('home', 'shop', 'product_category', 'product') === $keys, 'the fixed target set contains home, shop, one populated category and one ordinary product');
sspa_cd_t(4 === count(array_unique(array_column($targets, 'url'))), 'the fixed target set contains four distinct canonical URLs');
sspa_cd_t(!array_filter($targets, function ($target) {
    $path = (string) wp_parse_url($target['url'], PHP_URL_PATH);
    return 0 === strpos($path, '/wp-admin') || false !== strpos($path, '/checkout') || false !== strpos($path, '/cart') || false !== strpos($path, '/my-account');
}), 'the target resolver cannot select private, checkout or basket routes');
sspa_cd_t(!array_filter($targets, function ($target) { return isset($target['facts']); }), 'rendering the targets reads no product or category facts');

$prepared = SSPA_Cache_Delivery::prepare();
sspa_cd_t(!is_wp_error($prepared) && 36 === $prepared['request_count'] && 8 === $prepared['browser_requests'] && 28 === $prepared['server_requests'], 'preparation declares 8 browser and 28 server requests');
if (is_wp_error($prepared)) {
    return;
}
sspa_cd_t(!isset($prepared['basket_cart_hash']) && false === strpos(wp_json_encode($prepared), 'cart_hash'), 'the browser is never given the basket cookie value');
$tampered = SSPA_Cache_Delivery::probe_server_request($prepared['assessment_id'], 'custom_endpoint', 'anonymous', 1);
sspa_cd_t(is_wp_error($tampered) && 'sspa_cache_delivery_target' === $tampered->get_error_code(), 'the server probe cannot be pointed at an arbitrary URL or endpoint');
$audience = SSPA_Cache_Delivery::probe_server_request($prepared['assessment_id'], 'home', 'administrator', 1);
sspa_cd_t(is_wp_error($audience) && 'sspa_cache_delivery_request' === $audience->get_error_code(), 'the server probe refuses an audience outside the fixed three');

$GLOBALS['sspa_cd_http_args'] = array();
$capture_http_args = function ($args, $url) {
    $GLOBALS['sspa_cd_http_args'][] = array('args' => $args, 'url' => $url);
    return $args;
};
add_filter('http_request_args', $capture_http_args, 10, 2);
$server_results = array();
foreach ($targets as $target) {
    foreach (SSPA_Cache_Delivery::server_legs() as $leg) {
        $server_results[] = SSPA_Cache_Delivery::probe_server_request($prepared['assessment_id'], $target['page_key'], $leg[0], $leg[1]);
    }
}
remove_filter('http_request_args', $capture_http_args, 10);
$first = $server_results[0];
sspa_cd_t(!is_wp_error($first) && 200 === $first['http_status'], 'the server evidence path performs a real anonymous canonical GET');
sspa_cd_t(array_key_exists('ttfb_ms', $first) && isset($first['total_ms'], $first['body']['sha256'], $first['body']['bytes']), 'the server request records separate timing and privacy-safe body identity');
$first_args = $GLOBALS['sspa_cd_http_args'][0]['args'];
$sent_headers = array_change_key_case((array) $first_args['headers'], CASE_LOWER);
sspa_cd_t(!empty($first_args['sslverify']) && empty($first_args['cookies'])
    && !isset($sent_headers['authorization']) && !isset($sent_headers['cookie'])
    && !isset($sent_headers['cache-control']) && !isset($sent_headers['pragma']),
    'the anonymous request keeps TLS verification on and sends no credentials or cache-bypass headers');

$browser = array();
foreach ($targets as $target) {
    for ($request = 1; $request <= 2; $request++) {
        // Hand-written: the validator, not the browser, is under test here.
        $browser[] = array(
            'page_key' => $target['page_key'],
            'request_number' => (string) $request,
            'url' => $target['url'],
            'http_status' => '200',
            'ttfb_ms' => (string) (80 + $request),
            'total_ms' => (string) (100 + $request),
            'response_bytes' => '1234',
            'transfer_bytes' => '900',
            'delivery_source' => 'product' === $target['page_key'] ? 'browser_http_cache' : 'network',
            'body_sha256' => hash('sha256', $target['page_key']),
            'headers' => array('x-cache' => 'HIT', 'age' => '12'),
            'features' => array('product_loop' => '1', 'express_payment' => '0'),
            'error' => '',
        );
    }
}
$off_target = $browser;
$off_target[0]['url'] = home_url('/wp-admin/');
$refused = SSPA_Cache_Delivery::complete($prepared['assessment_id'], $off_target);
sspa_cd_t(is_wp_error($refused) && 'sspa_cache_delivery_browser_result' === $refused->get_error_code(), 'a browser result outside the fixed targets is refused');

$completed = SSPA_Cache_Delivery::complete($prepared['assessment_id'], $browser);
sspa_cd_t(!is_wp_error($completed) && 'sspa/cache-optimisation-analysis@3' === $completed['schema'], 'completion persists the version 3 cache optimisation contract');
if (is_wp_error($completed)) {
    return;
}
sspa_cd_t('anonymous_browser' === $completed['delivery_path_observations']['browser']['visitor_state'], 'browser timings are labelled as anonymous visitor-path evidence');
$product_verdict = current(array_filter($completed['page_verdicts'], function ($verdict) { return 'product' === $verdict['page_key']; }));
sspa_cd_t('cache_layer_unidentified' === $product_verdict['verdict'], 'a browser HTTP-cache response retaining an upstream HIT header is not mislabelled as a page-cache HIT');
$shop_row = current(array_filter($completed['delivery_path_observations']['browser']['requests'], function ($row) { return 'shop' === $row['page_key']; }));
sspa_cd_t(true === $shop_row['features']['product_loop'] && false === $shop_row['features']['express_payment'], 'form-encoded feature flags are read as 1 and 0, never as truthy strings');
sspa_cd_t(array('anonymous', 'basket', 'customer') === array_keys($completed['audiences']), 'the report carries one summary per audience');
sspa_cd_t(!in_array('authenticated_customer_not_measured', $completed['limitations'], true) && in_array('customer_audience_is_a_synthetic_account_without_orders', $completed['limitations'], true), 'the limitations describe the measured audiences rather than missing ones');

$encoded = wp_json_encode($completed);
sspa_cd_t(false === strpos($encoded, 'cookie_value') && false === strpos($encoded, '<html'), 'persisted evidence contains no cookie values or response HTML');

$stored = SSPA_Cache_Delivery::report();
sspa_cd_t(!is_wp_error($stored) && $prepared['assessment_id'] === $stored['assessment_id'], 'the completed check is readable without naming a run');
$exported = SSPA_Cache_Recon::export_data($completed['run_id']);
sspa_cd_t(!is_wp_error($exported) && 'sspa/cache-optimisation-analysis@3' === $exported['schema'], 'the existing export surface returns version 3 for the run the check was shown with');
$compat = SSPA_Cache_Recon::export_v2($completed['run_id']);
sspa_cd_t(is_wp_error($compat) || 'sspa/shared-cache-safety-report@2' === $compat['schema'], 'the cache-scan compatibility surface remains frozen at version 2');
if ($completed['run_id']) {
    $markdown = SSPA_Markdown_Export::build('run', $completed['run_id']);
    sspa_cd_t(!is_wp_error($markdown) && false !== strpos($markdown['markdown'], '## Cache performance') && false !== strpos($markdown['markdown'], 'Visitors with a basket'), 'the normal Markdown report carries the per-audience cache check');
}

$inventory = $completed['software_inventory']['code_snippets'];
sspa_cd_t(isset($inventory['in_use'], $inventory['scopes']) && array_key_exists('total_count', $inventory) && array_key_exists('active_count', $inventory), 'Code Snippets inventory exposes only use, counts and scopes');
sspa_cd_t(false === strpos(wp_json_encode($inventory), 'name') && false === strpos(wp_json_encode($inventory), 'source'), 'Code Snippets inventory exposes no snippet names or source');
