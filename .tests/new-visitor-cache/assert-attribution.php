<?php
// Case 151. A real spot analysis of home, shop, product-cat and product-single with the
// zz-tracking-cookie fixture active (it sets _fbp on init for every cookie-less request, as a
// server-side tracker does). The profiled requests are cookie-less, so this is the new-visitor case.
//
// Before this build the cookie was seen in the response but never linked to a plugin, and the
// headline said only "Visitor-specific content may need attention". Now the boot timer must name
// the plugin, version, hook and callback, the finding must say what it means, and the Overview,
// Plugins tab and get-plugins-table must show it. A single profiled request then checks the two
// non-callback attributions: a cookie set while a plugin file is included, and one set on a hook
// the profiler does not time.

require_once SSPA_PLUGIN_DIR . '.tests/lib/retained-fixtures.php';
function sspa_nv_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
    return (bool) $ok;
}
global $wpdb;
wp_set_current_user(1);
sspa_retained_reset('151-new-visitor-attribution');
$out = getenv('SSPA_NV_OUTPUT') ?: SSPA_PLUGIN_DIR . '.data/new-visitor-cache/attribution';
wp_mkdir_p($out);

if (!sspa_nv_t(is_plugin_active('zz-tracking-cookie/zz-tracking-cookie.php'), 'the zz-tracking-cookie fixture is active (run through .tests/new-visitor-cache/run.sh)')) {
    return;
}

$run_id = SSPA_Run_Controller::start(array('type' => 'spot', 'trigger' => 'manual', 'user_id' => 1,
    'page_keys' => array('home', 'shop', 'product-cat', 'product-single')));
if (!sspa_nv_t(!is_wp_error($run_id), 'a spot analysis of the four representative pages starts')) {
    return;
}
sspa_retained_save('151-new-visitor-attribution', array('runs' => array((int) $run_id)));
$deadline = time() + 900;
do {
    SSPA_Run_Controller::process_batch($run_id);
    $status = SSPA_Run_Controller::status($run_id);
} while ($status && in_array($status['status'], array('queued', 'crawling', 'analysing'), true) && time() < $deadline);
if (!sspa_nv_t($status && 'done' === $status['status'], "analysis $run_id finished (" . ($status ? $status['status'] : 'null') . ')')) {
    return;
}
update_option('sspa_test_nv_run_id', (int) $run_id, false);

// 1. Runtime attribution in every cookie-less page profile.
$rows = $wpdb->get_results($wpdb->prepare(
    "SELECT id, page_key, profile_blob FROM %i WHERE run_id = %d AND plugin_set_hash = '' AND variant = 'anon'",
    SSPA_Schema::table('profiles'),
    (int) $run_id
), ARRAY_A);
$attributed = array();
$values_stored = false;
$checks = array();
foreach ($rows as $row) {
    $json = (string) @gzuncompress($row['profile_blob']);
    $capture = json_decode($json, true);
    if (false !== strpos($json, 'fb.1.')) {
        $values_stored = true;
    }
    foreach ((array) ($capture['boot']['set_cookies'] ?? array()) as $cookie) {
        if ('_fbp' === ($cookie['name'] ?? '') && 'zz-tracking-cookie' === ($cookie['component'] ?? '')
            && 'plugin' === ($cookie['type'] ?? '') && '1.0.0' === ($cookie['version'] ?? '')
            && 'init' === ($cookie['hook'] ?? '') && 'ZZ_Tracking_Cookie::param_builder_server_setup' === ($cookie['callback'] ?? '')
            && 'callback' === ($cookie['attribution'] ?? '') && 'zz-tracking-cookie (test fixture)' === ($cookie['component_name'] ?? '')) {
            $attributed[$row['page_key']] = true;
        }
    }
    if (isset($capture['boot']['set_cookie_checks'])) {
        $checks[$row['page_key']] = $capture['boot']['set_cookie_checks'];
    }
}
file_put_contents($out . '/set-cookie-checks.json', wp_json_encode($checks, JSON_PRETTY_PRINT));
sspa_nv_t(4 === count($rows), 'four cookie-less page profiles were stored (' . count($rows) . ')');
sspa_nv_t(4 === count($attributed), 'every page profile attributes _fbp to zz-tracking-cookie 1.0.0 on init in ZZ_Tracking_Cookie::param_builder_server_setup (' . implode(', ', array_keys($attributed)) . ')');
sspa_nv_t(!$values_stored, 'no _fbp cookie value is stored in any page profile');
sspa_nv_t(4 === count($checks) && !array_filter($checks, function ($c) { return !isset($c['checks'], $c['ms']) || $c['checks'] < 1; }), 'each profile records how many Set-Cookie checks ran and what they cost');

// 2. The assessment and the finding.
$safety = SSPA_Report::cache_safety($run_id);
$rows_attr = is_wp_error($safety) ? array() : (array) ($safety['assessment']['set_cookie_attribution'] ?? array());
$fbp = current(array_filter($rows_attr, function ($r) { return '_fbp' === $r['name']; }));
sspa_nv_t($fbp && 'zz-tracking-cookie' === $fbp['component'] && 4 === count($fbp['pages']), 'the cache-safety assessment names zz-tracking-cookie for _fbp on four pages');
$v2 = SSPA_Cache_Recon::export_v2($run_id);
sspa_nv_t(!is_wp_error($v2) && false === strpos(wp_json_encode($v2), 'set_cookie_attribution'), 'the frozen sspa/shared-cache-safety-report@2 export is unchanged by the new member');

$findings = SSPA_Insights::all_of_type($run_id, 'new_visitor_cache_blocked');
$finding = current(array_filter($findings, function ($f) { return 'zz-tracking-cookie' === $f['component']; }));
if (sspa_nv_t((bool) $finding, 'a new_visitor_cache_blocked finding names zz-tracking-cookie')) {
    $text = SSPA_Insights::render($finding);
    file_put_contents($out . '/finding.json', wp_json_encode(array('row' => $finding, 'rendered' => $text), JSON_PRETTY_PRINT));
    sspa_nv_t('critical' === $finding['severity'], 'the finding is critical');
    sspa_nv_t(0 === strpos($text['headline'], 'zz-tracking-cookie (test fixture) 1.0.0 sets the _fbp cookie on the first visit to home, shop, product-cat and product-single (on init, in ZZ_Tracking_Cookie::param_builder_server_setup)'), 'headline: ' . $text['headline']);
    sspa_nv_t(false !== strpos($text['detail'], 'A page cache that refuses responses carrying Set-Cookie will not store these pages for new visitors.'), 'detail says what it means without claiming every cache refuses Set-Cookie');
    sspa_nv_t(!preg_match('/hummingbird|litespeed|rocket|cloudflare|varnish|nginx|enabler|w3 total|breeze/i', $text['headline'] . ' ' . $text['detail'] . ' ' . $text['rec_body']), 'no cache product is named');
    sspa_nv_t('' !== $text['rec_title'], 'the finding carries a recommendation from the rules snapshot');
}

// 3. Where it is shown.
$top = SSPA_Insights::top($run_id, 5);
sspa_nv_t($top && 'new_visitor_cache_blocked' === $top[0]['finding_type'], 'the finding leads the Overview insights');
$html = SSPA_Plugins_Table::render($run_id, 'code_owner');
file_put_contents($out . '/plugins-table.html', $html);
sspa_nv_t((bool) preg_match('#data-component="zz-tracking-cookie".*?</tr>#s', $html, $m) && false !== strpos($m[0], 'sets a cookie for new visitors'), 'the Plugins tab row for zz-tracking-cookie carries the "sets a cookie for new visitors" badge');
sspa_nv_t((bool) preg_match('#data-component="zz-click-cookie".*?</tr>#s', $html, $m) && false === strpos($m[0], 'sets a cookie for new visitors'), 'zz-click-cookie, which sets nothing without fbclid, has no badge');
$table = SSPA_Abilities_Control::exec_plugins_table(array());
$row = current(array_filter((array) ($table['components'] ?? array()), function ($c) { return 'zz-tracking-cookie' === $c['component']; }));
sspa_nv_t($row && array('_fbp') === ($row['new_visitor_cookies'] ?? null), 'get-plugins-table reports new_visitor_cookies ["_fbp"] for zz-tracking-cookie');

// 4. Include-time and untimed-hook attribution in one profiled request.
$crawler = new SSPA_Crawler();
$sample = $crawler->send_profiled(add_query_arg(array('zz_include' => '1', 'zz_untimed' => '1'), home_url('/')), array('flags' => array('v' => 'anon')));
$cookies = array();
foreach ((array) ($sample['capture']['boot']['set_cookies'] ?? array()) as $cookie) {
    $cookies[$cookie['name']] = $cookie;
}
file_put_contents($out . '/probe-set-cookies.json', wp_json_encode(array_values($cookies), JSON_PRETTY_PRINT));
sspa_nv_t(isset($cookies['zz_include']) && 'plugin_include' === $cookies['zz_include']['attribution'] && 'zz-click-cookie' === $cookies['zz_include']['component'] && '2.3.4' === $cookies['zz_include']['version'], 'a cookie set while zz-click-cookie is included is attributed to its include, version 2.3.4');
sspa_nv_t(isset($cookies['zz_untimed']) && 'outside_timed_callback' === $cookies['zz_untimed']['attribution'] && null === $cookies['zz_untimed']['component'] && '' !== (string) $cookies['zz_untimed']['detected_at'], 'a cookie set on parse_request (not timed) is recorded as set outside a timed callback, with where it was noticed, not guessed');
sspa_nv_t(isset($cookies['_fbp']) && 'callback' === $cookies['_fbp']['attribution'], 'the same request still attributes _fbp to its callback');
