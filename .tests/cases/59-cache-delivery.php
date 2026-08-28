<?php
// Live-safe cache delivery qualification. The test drives the production target resolver,
// a real anonymous HTTP request, the evidence normaliser and the persisted version 3 report.

function sspa_cd_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
}

if (!class_exists('SSPA_Cache_Delivery')) {
    sspa_cd_t(false, 'the cache delivery assessment entry point exists');
    return;
}

$targets = SSPA_Cache_Delivery::targets();
$keys = array_column($targets, 'page_key');
sspa_cd_t(array('home', 'shop', 'product_category', 'product') === $keys, 'the fixed target set contains home, shop, one populated category and one ordinary product');
sspa_cd_t(4 === count(array_unique(array_column($targets, 'url'))), 'the fixed target set contains four distinct canonical URLs');
sspa_cd_t(!array_filter($targets, function ($target) {
    $path = (string) wp_parse_url($target['url'], PHP_URL_PATH);
    return 0 === strpos($path, '/wp-admin') || false !== strpos($path, '/checkout') || false !== strpos($path, '/cart');
}), 'the target resolver cannot select private, checkout or basket routes');

$run_id = SSPA_Report::latest_done_run_id();
sspa_cd_t($run_id > 0, 'a completed analysis run is available for qualification evidence');
if (!$run_id) {
    return;
}

$prepared = SSPA_Cache_Delivery::prepare($run_id);
sspa_cd_t(!is_wp_error($prepared) && 16 === $prepared['request_count'], 'preparation declares eight browser and eight server requests');
if (is_wp_error($prepared)) {
    return;
}
$tampered = SSPA_Cache_Delivery::probe_server_request($prepared['assessment_id'], 'custom_endpoint', 1);
sspa_cd_t(is_wp_error($tampered) && 'sspa_cache_delivery_target' === $tampered->get_error_code(), 'the server probe cannot be pointed at an arbitrary URL or endpoint');

$server_results = array();
$GLOBALS['sspa_cd_http_args'] = array();
$capture_http_args = function ($args, $url) {
    $GLOBALS['sspa_cd_http_args'][] = array('args' => $args, 'url' => $url);
    return $args;
};
add_filter('http_request_args', $capture_http_args, 10, 2);
foreach ($targets as $target) {
    for ($request = 1; $request <= 2; $request++) {
        $server_results[] = SSPA_Cache_Delivery::probe_server_request($prepared['assessment_id'], $target['page_key'], $request);
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
    'the real delivery request keeps TLS verification on and sends no credentials or cache-bypass headers');

$browser = array();
foreach ($targets as $target) {
    for ($request = 1; $request <= 2; $request++) {
        $browser[] = array(
            'page_key' => $target['page_key'],
            'request_number' => $request,
            'url' => $target['url'],
            'http_status' => 200,
            'ttfb_ms' => 80 + $request,
            'total_ms' => 100 + $request,
            'response_bytes' => 1234,
            'transfer_bytes' => 900,
            'delivery_source' => 'product' === $target['page_key'] ? 'browser_http_cache' : 'network',
            'body_sha256' => hash('sha256', $target['page_key']),
            'headers' => array('x-cache' => 'HIT', 'age' => '12'),
            'markers' => array(),
            'error' => '',
        );
    }
}

$completed = SSPA_Cache_Delivery::complete($prepared['assessment_id'], $browser);
sspa_cd_t(!is_wp_error($completed) && 'sspa/cache-optimisation-analysis@3' === $completed['schema'], 'completion persists the version 3 cache optimisation contract');
sspa_cd_t('anonymous_browser' === $completed['delivery_path_observations']['browser']['visitor_state'], 'browser timings are labelled as anonymous visitor-path evidence');
$product_verdict = current(array_filter($completed['page_verdicts'], function ($verdict) { return 'product' === $verdict['page_key']; }));
sspa_cd_t('cache_layer_unidentified' === $product_verdict['verdict'], 'a browser HTTP-cache response retaining an upstream HIT header is not mislabelled as a page-cache HIT');
sspa_cd_t(in_array('authenticated_customer_not_measured', $completed['limitations'], true), 'anonymous evidence does not imply logged-in customer performance');

$encoded = wp_json_encode($completed);
sspa_cd_t(false === strpos($encoded, 'cookie_value') && false === strpos($encoded, '<html'), 'persisted delivery evidence contains no cookie values or response HTML');

$stored = SSPA_Cache_Delivery::report($run_id);
sspa_cd_t(!is_wp_error($stored) && $prepared['assessment_id'] === $stored['assessment_id'], 'the completed report is readable for the analysis run');
$exported = SSPA_Cache_Recon::export_data($run_id);
sspa_cd_t(!is_wp_error($exported) && 'sspa/cache-optimisation-analysis@3' === $exported['schema'] && isset($exported['assessment']['shared_cache_status']), 'the existing export surface returns version 3 with its version 2 compatibility assessment');
$compat = SSPA_Cache_Recon::export_v2($run_id);
sspa_cd_t(!is_wp_error($compat) && 'sspa/shared-cache-safety-report@2' === $compat['schema'], 'the cache-scan compatibility surface remains frozen at version 2');
$markdown = SSPA_Markdown_Export::build('run', $run_id);
sspa_cd_t(!is_wp_error($markdown) && false !== strpos($markdown['markdown'], '## Page-cache delivery') && false !== strpos($markdown['markdown'], 'separate measurements'), 'the normal Markdown report carries the same separated delivery evidence');

$inventory = $completed['software_inventory']['code_snippets'];
sspa_cd_t(isset($inventory['in_use'], $inventory['scopes']) && array_key_exists('total_count', $inventory) && array_key_exists('active_count', $inventory), 'Code Snippets inventory exposes only use, counts and scopes');
sspa_cd_t(false === strpos(wp_json_encode($inventory), 'name') && false === strpos(wp_json_encode($inventory), 'source'), 'Code Snippets inventory exposes no snippet names or source');
