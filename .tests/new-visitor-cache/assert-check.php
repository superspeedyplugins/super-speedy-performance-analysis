<?php
// Case 152. The Cache performance check against the zz-page-cache fixture, which (like many page
// caches) refuses to store a response carrying Set-Cookie.
//
// Before this build, when the first anonymous response set a cookie the second anonymous request
// was skipped ("application_session_cookie_set"), so the check never showed that a second new
// visitor misses too, and named neither the cookie nor the plugin.
//
// Pass 1, tracker off: nothing sets a cookie, so the cache stores the page (MISS then HIT) and the
// returning-visitor requests are recorded as not needed without a request.
// Pass 2, tracker on (left that way for inspection): both cookie-less requests MISS and set _fbp,
// a returning visitor carrying only _fbp is served from cache, and the headline says
// "Not cached for new visitors" naming the cookie and the plugin from the analysis (case 151).
// Both passes are driven through the MCP abilities, as an agent would.

function sspa_nv_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
    return (bool) $ok;
}
wp_set_current_user(1);
$out = getenv('SSPA_NV_OUTPUT') ?: SSPA_PLUGIN_DIR . '.data/new-visitor-cache/check';
wp_mkdir_p($out);
$tracker = 'zz-tracking-cookie/zz-tracking-cookie.php';
if (!sspa_nv_t(file_exists(WP_CONTENT_DIR . '/advanced-cache.php') && is_plugin_active($tracker), 'the page cache and tracker fixtures are installed (run through .tests/new-visitor-cache/run.sh)')) {
    return;
}

$clear_cache = function () {
    foreach ((array) glob(WP_CONTENT_DIR . '/cache/zz-page-cache/*.html') as $file) {
        unlink($file);
    }
};
$sent = array();
add_filter('http_request_args', function ($args, $url) use (&$sent) {
    if (false === strpos($url, 'sspa_nc=')) {
        $sent[] = array('url' => $url, 'cookies' => array_keys((array) $args['cookies']));
    }
    return $args;
}, 10, 2);
$run_check = function ($label) use (&$sent, $out) {
    $sent = array();
    $started = SSPA_Abilities_Control::exec_run_cache_check(array());
    if (!sspa_nv_t(!is_wp_error($started), "$label: run-cache-check starts")) {
        return null;
    }
    $result = null;
    for ($i = 0; $i < 40; $i++) {
        $result = SSPA_Abilities_Control::exec_advance_cache_check(array('assessment_id' => $started['assessment_id']));
        if (is_wp_error($result) || !empty($result['done'])) {
            break;
        }
    }
    if (!sspa_nv_t(!is_wp_error($result) && !empty($result['done']), "$label: advance-cache-check completes the check")) {
        return null;
    }
    $report = SSPA_Cache_Delivery::latest();
    file_put_contents($out . '/' . sanitize_key($label) . '-report.json', wp_json_encode($report, JSON_PRETTY_PRINT));
    file_put_contents($out . '/' . sanitize_key($label) . '-requests.json', wp_json_encode($sent, JSON_PRETTY_PRINT));
    return array('report' => $report, 'started' => $started, 'sent' => $sent);
};
$row = function ($report, $page, $audience, $number) {
    foreach ($report['delivery_path_observations']['server']['requests'] as $r) {
        if ($r['page_key'] === $page && $r['audience'] === $audience && (int) $r['request_number'] === $number) {
            return $r;
        }
    }
    return null;
};
$status = function ($r) {
    foreach ((array) ($r['cache_layers'] ?? array()) as $layer) {
        if ('x-cache-status' === $layer['evidence_name']) {
            return $layer['status'];
        }
    }
    return '';
};

// Pass 1: no tracker.
deactivate_plugins($tracker, true);
$clear_cache();
$pass = $run_check('tracker off');
if ($pass) {
    $report = $pass['report'];
    $pages = array_column($report['audience_verdicts']['anonymous'], 'page_key');
    sspa_nv_t(4 === count($pages), 'four representative pages were checked');
    $ok = true;
    $not_needed = true;
    foreach ($pages as $page) {
        $ok = $ok && 'MISS' === $status($row($report, $page, 'anonymous', 1)) && 'HIT' === $status($row($report, $page, 'anonymous', 2));
        foreach (array(1, 2) as $n) {
            $r = $row($report, $page, 'returning', $n);
            $not_needed = $not_needed && $r && 'not_needed' === $r['error_class'] && !empty($r['skipped']);
        }
    }
    sspa_nv_t($ok, 'without a cookie the cache stores each page for new visitors (MISS then HIT)');
    sspa_nv_t($not_needed, 'the returning-visitor legs are recorded as not needed');
    $requested = count(array_filter($pass['sent'], function ($s) { return false === strpos($s['url'], 'wp-cron'); }));
    sspa_nv_t(4 * 7 === $requested, "no returning-visitor request is sent when no cookie was set: $requested server requests for 4 pages (7 each, as before)");
    sspa_nv_t('no_cookie_set' === ($report['new_visitors']['status'] ?? ''), 'new_visitors.status is no_cookie_set');
    sspa_nv_t(0 !== strpos($report['headline'], 'Not cached for new visitors'), 'the headline does not claim a new-visitor problem: ' . $report['headline']);
    sspa_nv_t(!array_filter($report['route_failures'], function ($f) { return 'returning' === $f['audience']; }), 'skipped returning-visitor legs are not route failures');
}

// Pass 2: tracker on.
activate_plugin($tracker);
$clear_cache();
$pass = $run_check('tracker on');
if ($pass) {
    $report = $pass['report'];
    $json = wp_json_encode($report);
    $again = true;
    $returning = true;
    foreach (array_column($report['audience_verdicts']['anonymous'], 'page_key') as $page) {
        $first = $row($report, $page, 'anonymous', 1);
        $second = $row($report, $page, 'anonymous', 2);
        $again = $again && $second && '' === $second['error_class'] && 200 === (int) $second['http_status']
            && 'MISS' === $status($first) && 'MISS' === $status($second)
            && in_array('_fbp', $first['application_cookie_names'], true) && in_array('_fbp', $second['application_cookie_names'], true);
        $returning = $returning && 'MISS' === $status($row($report, $page, 'returning', 1)) && 'HIT' === $status($row($report, $page, 'returning', 2));
    }
    sspa_nv_t($again, 'the second cookie-less request is made ("new visitor again") and, like the first, sets _fbp and misses the cache on every page');
    $returning_sent = array_values(array_filter($pass['sent'], function ($s) use ($report) {
        return false === strpos($s['url'], 'wp-cron') && in_array('_fbp', $s['cookies'], true);
    }));
    sspa_nv_t(8 === count($returning_sent) && !array_filter($returning_sent, function ($s) { return array('_fbp') !== $s['cookies']; }), 'eight returning-visitor requests carried only the _fbp cookie the first response set');
    sspa_nv_t($returning, 'a returning visitor carrying only _fbp is served from cache (MISS then HIT) on every page');
    sspa_nv_t('not_cached' === $report['audiences']['anonymous']['headline'], 'visitors are reported not cached, not unknown (' . $report['audiences']['anonymous']['headline'] . ')');
    $nv = $report['new_visitors'] ?? array();
    sspa_nv_t('not_cached_for_new_visitors' === ($nv['status'] ?? ''), 'new_visitors.status is not_cached_for_new_visitors');
    $cookie = current(array_filter((array) ($nv['cookies'] ?? array()), function ($c) { return '_fbp' === $c['name']; }));
    sspa_nv_t($cookie && 'zz-tracking-cookie' === $cookie['component'] && '1.0.0' === $cookie['version'] && 'init' === $cookie['hook'], 'the report names zz-tracking-cookie 1.0.0 on init as the source of _fbp, from the analysis');
    sspa_nv_t(0 === strpos($report['headline'], 'Not cached for new visitors') && false !== strpos($report['headline'], '_fbp') && false !== strpos($report['headline'], 'zz-tracking-cookie (test fixture) 1.0.0'), 'headline: ' . $report['headline']);
    sspa_nv_t(false !== strpos((string) ($nv['detail'] ?? ''), 'MISS') && false !== strpos((string) ($nv['detail'] ?? ''), 'HIT'), 'the detail reports the HIT and MISS observed: ' . ($nv['detail'] ?? ''));
    sspa_nv_t(false === strpos($json, 'fb.1.'), 'no cookie value is stored in the report');
    sspa_nv_t(false === get_option(SSPA_Cache_Delivery::PENDING_OPTION), 'the pending check (which held the cookie for the returning requests) is gone');
    sspa_nv_t(4 * 9 === (int) $pass['started']['server_requests'], 'run-cache-check announces at most 9 server requests per page');

    // The finding from case 151 now reports what the check observed.
    $run_id = (int) get_option('sspa_test_nv_run_id');
    $finding = current(array_filter(SSPA_Insights::all_of_type($run_id, 'new_visitor_cache_blocked'), function ($f) { return 'zz-tracking-cookie' === $f['component']; }));
    $text = $finding ? SSPA_Insights::render($finding) : array('detail' => '');
    file_put_contents($out . '/finding-after-check.json', wp_json_encode($text, JSON_PRETTY_PRINT));
    sspa_nv_t(false !== strpos($text['detail'], 'The Cache performance check observed'), 'the finding now carries the check\'s observation: ' . $text['detail']);

    // The Cache performance tab shows it.
    ob_start();
    include SSPA_PLUGIN_DIR . 'includes/admin/tabs/cache.php';
    $html = ob_get_clean();
    file_put_contents($out . '/cache-tab.html', $html);
    sspa_nv_t(false !== strpos($html, 'sspa-cache-new-visitors') && false !== strpos($html, 'Not cached for new visitors') && false !== strpos($html, '_fbp'), 'the Cache performance tab shows the "Not cached for new visitors" block naming _fbp');
}
