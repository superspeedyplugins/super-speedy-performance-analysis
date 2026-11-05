<?php
/**
 * Super Speedy Filters' own-server token (Filters 6.67.4, PA 0.61.5): profiling loopbacks carry the
 * X-SSPSSF-Own-Server header so Filters' public rate limiter does not count them as a visitor's.
 *
 * - The header is added for an own-origin URL only, never for another host.
 * - Against a Filters without the token API (or no Filters) nothing is added: the loopbacks are counted.
 * - With Filters active and its limiter on, 20 real profiling loopbacks to a rate-limited listing are all
 *   answered (Filters 6.67.4 exempts no address, so without the header the 11th would be 429).
 *
 * Runs in the normal suite (run-tests.sh) and directly on a Filters site:
 *   wp eval-file wp-content/plugins/super-speedy-performance-analysis/.tests/cases/120-filters-own-server-token.php [listing-path]
 */
function sspa_t120($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
    if (!$ok) { $GLOBALS['sspa_t120_fail'] = true; }
}
$GLOBALS['sspa_t120_fail'] = false;

$own = home_url('/');
$foreign = 'https://example.invalid/';
$has_filters_api = function_exists('sspssf_rate_limit_own_server_token') && function_exists('sspssf_rate_limit_own_server_header');

$has_pa_api = method_exists('SSPA_Loopback_Route', 'with_own_server_token');
sspa_t120($has_pa_api, 'SSPA_Loopback_Route::with_own_server_token() exists');
$own_headers = $has_pa_api ? SSPA_Loopback_Route::with_own_server_token($own, array('Accept' => 'text/html')) : array('Accept' => 'text/html');
$foreign_headers = $has_pa_api ? SSPA_Loopback_Route::with_own_server_token($foreign, array('Accept' => 'text/html')) : array('Accept' => 'text/html');
sspa_t120(!isset($foreign_headers['X-SSPSSF-Own-Server']) && count($foreign_headers) === 1, 'no token on a request to another host');
if ($has_filters_api) {
    sspa_t120(isset($own_headers['X-SSPSSF-Own-Server']) && hash_equals(sspssf_rate_limit_own_server_token(), $own_headers['X-SSPSSF-Own-Server']),
        'an own-origin loopback carries the Filters token');
} else {
    sspa_t120(!isset($own_headers['X-SSPSSF-Own-Server']) && count($own_headers) === 1,
        'without the Filters token API nothing is added (older Filters, or none): loopbacks are simply counted');
}

if ($has_filters_api && function_exists('ssf_public_rate_limit_table_name')) {
    global $wpdb, $argv;
    $path = '/shop/';
    foreach ((array) ($GLOBALS['args'] ?? array()) as $arg) {
        if (is_string($arg) && strpos($arg, '/') === 0) { $path = $arg; }
    }
    $o = (array) get_option('fww_settings');
    $o['request_rate_limit_enabled'] = 1;
    unset($o['request_rate_limit_catalog_burst'], $o['request_rate_limit_catalog_refill_seconds']);
    update_option('fww_settings', $o);
    $wpdb->query('DELETE FROM ' . ssf_public_rate_limit_table_name());
    $codes = array();
    for ($i = 0; $i < 20; $i++) {
        $r = SSPA_Crawler::request(home_url($path), array('timeout' => 30, 'redirection' => 0));
        $codes[] = is_wp_error($r) ? $r->get_error_code() : (int) wp_remote_retrieve_response_code($r);
    }
    sspa_t120(!in_array(429, $codes, true) && count(array_unique($codes)) === 1 && $codes[0] === 200,
        '20 real profiling loopbacks to ' . $path . ' with the limiter on are all answered 200 (' . implode(' ', $codes) . ')');
    $rows = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . ssf_public_rate_limit_table_name());
    sspa_t120($rows === 0, "no rate-limit bucket row written for the profiler ({$rows})");
} else {
    echo "NOT COVERED: Super Speedy Filters with the token API is not active here, so the end-to-end burst did not run\n";
}
if ($GLOBALS['sspa_t120_fail']) { exit(1); }
