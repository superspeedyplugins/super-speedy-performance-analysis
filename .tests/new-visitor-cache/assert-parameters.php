<?php
// Case 153. The ad-click and campaign parameter check, driven through its own abilities against
// the fixtures: zz-click-cookie sets _fbc only when fbclid is present; zz-tracking-cookie sets _fbp
// for every cookie-less request; the zz-page-cache key ignores utm_*, bypasses gclid/gbraid/wbraid
// and keys every other parameter, and refuses to store a response carrying Set-Cookie.
//
// Before this build no code path added these parameters to a test request. Now each must be
// classified from real responses: utm_source ignored, gclid bypass, msclkid separate key, and
// fbclid (whose response sets _fbc, so it is never stored) heads the list as both.

function sspa_nv_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
    return (bool) $ok;
}
wp_set_current_user(1);
$out = getenv('SSPA_NV_OUTPUT') ?: SSPA_PLUGIN_DIR . '.data/new-visitor-cache/parameters';
wp_mkdir_p($out);
if (!sspa_nv_t(is_plugin_active('zz-click-cookie/zz-click-cookie.php') && file_exists(WP_CONTENT_DIR . '/advanced-cache.php'), 'the click-cookie and page-cache fixtures are installed (run through .tests/new-visitor-cache/run.sh)')) {
    return;
}

$profiled = 0;
$plain = array();
add_filter('http_request_args', function ($args, $url) use (&$profiled, &$plain) {
    if (false !== strpos($url, 'wp-cron')) {
        return $args;
    }
    if (false !== strpos($url, 'sspa_nc=')) {
        $profiled++;
    } else {
        $plain[] = array('url' => $url, 'cookies' => array_keys((array) $args['cookies']));
    }
    return $args;
}, 10, 2);

$started = SSPA_Abilities_Control::exec_run_parameter_check(array());
if (!sspa_nv_t(!is_wp_error($started), 'run-parameter-check starts')) {
    return;
}
sspa_nv_t(18 === count($started['parameters']) && 52 === $started['profiled_requests'] && 54 === $started['cache_key_requests'], 'it announces 18 parameters, 52 profiled requests and 54 cache-key requests');
$result = null;
$calls = 0;
for ($i = 0; $i < 40; $i++) {
    $calls++;
    $result = SSPA_Abilities_Control::exec_advance_parameter_check(array('check_id' => $started['check_id']));
    if (is_wp_error($result) || !empty($result['done'])) {
        break;
    }
}
if (!sspa_nv_t(!is_wp_error($result) && !empty($result['done']), "advance-parameter-check completes the check in $calls calls")) {
    return;
}
$again = SSPA_Abilities_Control::exec_advance_parameter_check(array('check_id' => $started['check_id']));
sspa_nv_t(!is_wp_error($again) && !empty($again['done']), 'advancing a finished check again answers done without new requests');
$report = SSPA_Abilities_Control::exec_get_parameter_check(array());
file_put_contents($out . '/report.json', wp_json_encode($report, JSON_PRETTY_PRINT));
file_put_contents($out . '/plain-requests.json', wp_json_encode($plain, JSON_PRETTY_PRINT));
if (!sspa_nv_t(is_array($report) && 'sspa/parameter-check@1' === $report['schema'], 'get-parameter-check returns the sspa/parameter-check@1 report')) {
    return;
}
$rows = array();
foreach ($report['parameters'] as $row) {
    $rows[$row['parameter']] = $row;
}

sspa_nv_t(52 === $profiled && 52 === $report['requests']['profiled'], "52 profiled cookie-less requests were made ($profiled)");
sspa_nv_t(count($plain) === $report['requests']['cache_key_page'] + $report['requests']['cache_key'] && count($plain) <= 66, 'the cache-key pass made ' . count($plain) . ' requests, within its 66-request ceiling');
sspa_nv_t(array('_fbp') === $report['baseline_cookies']['home'], 'the plain home request sets only _fbp, the baseline every parameter is compared with');

$fbclid = $rows['fbclid'] ?? null;
$who = $fbclid && $fbclid['cookies'] ? $fbclid['cookies'][0]['set_by'][0] : array();
sspa_nv_t($fbclid && array('_fbc') === array_column($fbclid['cookies'], 'name') && 4 === count($fbclid['cookies'][0]['pages']), 'fbclid makes the site set _fbc on all four pages, and nothing else beyond the baseline');
sspa_nv_t('zz-click-cookie' === ($who['component'] ?? '') && '2.3.4' === ($who['version'] ?? '') && 'init' === ($who['hook'] ?? '') && 'callback' === ($who['attribution'] ?? ''), 'the runtime attribution names zz-click-cookie 2.3.4 on init as the code that sets _fbc');
sspa_nv_t($fbclid && 'bypass' === $fbclid['cache']['outcome'] && array('_fbc') === $fbclid['cache']['response_cookies'], 'fbclid is never served from cache, and its responses carry _fbc');
sspa_nv_t('fbclid' === $report['parameters'][0]['parameter'] && !empty($report['parameters'][0]['worst']), 'fbclid, which sets a cookie and misses the cache, heads the list');
sspa_nv_t(0 === strpos($report['headline'], 'fbclid makes zz-click-cookie (test fixture) 2.3.4 set _fbc and is not served from cache'), 'headline: ' . $report['headline']);

foreach (array('utm_source' => 'ignored', 'utm_campaign' => 'ignored', 'gclid' => 'bypass', 'wbraid' => 'bypass', 'msclkid' => 'separate_key', 'srsltid' => 'separate_key', 'ref' => 'separate_key') as $parameter => $outcome) {
    $row = $rows[$parameter] ?? null;
    sspa_nv_t($row && $outcome === $row['cache']['outcome'] && !$row['cookies'], "$parameter: cache key $outcome (" . ($row ? implode(', ', $row['cache']['statuses']) : 'missing') . '), no cookie of its own');
}
sspa_nv_t(isset($rows['utm_source']) && array('home') === $rows['utm_source']['pages_checked'] && 4 === count($rows['gclid']['pages_checked']), 'campaign parameters are checked on home only and click IDs on every page');

$page = $report['cache_key_page'];
sspa_nv_t($page && 'returning_visitor' === $page['audience'] && array('_fbp') === $page['cookie_names'] && in_array('HIT', $page['statuses'], true), 'the cache-key pass proved a cacheable page as a returning visitor carrying only _fbp (' . ($page ? implode(', ', $page['statuses']) : '') . ')');
$cookie_ok = true;
foreach ($plain as $i => $request) {
    if ($i > 0 && array('_fbp') !== $request['cookies']) {
        $cookie_ok = false;
    }
}
sspa_nv_t($cookie_ok && array() === $plain[0]['cookies'], 'the first cache-key request was cookie-less and every later one carried only _fbp');

$json = wp_json_encode($report);
sspa_nv_t(false === strpos($json, 'fb.1.'), 'no cookie value is stored in the report');
sspa_nv_t(false === get_option(SSPA_Parameter_Check::PENDING_OPTION), 'the pending check (which held the cookie values) is gone');
$cache_report = SSPA_Cache_Delivery::report();
sspa_nv_t(is_wp_error($cache_report) || (isset($cache_report['parameter_check']['check_id']) && $cache_report['parameter_check']['check_id'] === $report['check_id']), 'get-cache-optimisation-analysis also returns the latest parameter check');
