<?php
// Additions E and F of .docs/2026-10-22-endpoint-startup-profiling-plan.md.
//
// E: an AJAX window observes the fixture endpoints (trimmed to WooCommerce plus the fixture by a
// real MU plugin), then "Profile this endpoint" runs N signed samples of a literal URL through
// the real ad-hoc profiler and loopback. The captures must show the trim applied, the result
// must be stored under an endpoint key that the Pages tab and the AJAX tab both show, and the
// URL must match the observed endpoint.
//
// F: every profiled request records environment evidence from the serving PHP: database
// connection and trivial-query timing, object-cache round trips and drop-in identity, and
// opcache state. php-fpm here runs opcache on. The opcache-off and opcache-full cases are
// served for real by a private PHP built-in server started by this case with its own -d flags
// on this site's docroot, so the shared php-fpm configuration is never touched. It is stopped
// at the end; its log stays under .data/case-141/.

function sspa_141_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

global $wpdb;
wp_set_current_user(1);
$sspa_141_fixtures = SSPA_PLUGIN_DIR . '.tests/fixtures/endpoint-startup/';
wp_mkdir_p(WP_PLUGIN_DIR . '/sspa-endpoint-fixture');
copy($sspa_141_fixtures . 'sspa-endpoint-fixture/sspa-endpoint-fixture.php', WP_PLUGIN_DIR . '/sspa-endpoint-fixture/sspa-endpoint-fixture.php');
copy($sspa_141_fixtures . 'zz-sspa-test-endpoint-trim.php', WPMU_PLUGIN_DIR . '/zz-sspa-test-endpoint-trim.php');
wp_clean_plugins_cache(false);
sspa_141_t(!is_wp_error(activate_plugin('sspa-endpoint-fixture/sspa-endpoint-fixture.php')), 'the endpoint fixture plugin activates');
clearstatcache();
sleep(3);

$sspa_141_active = SSPA_Traffic_Collection::active();
if ($sspa_141_active) {
    SSPA_Traffic_Collection::stop((int) $sspa_141_active['id'], true);
}
$sspa_141_run = SSPA_Run_Controller::active_run_id();
if ($sspa_141_run) {
    SSPA_Run_Controller::cancel($sspa_141_run);
}
SSPA_Run_Controller::cleanup();

$sspa_141_window = SSPA_Ajax_Profile::start(array('scenario' => 'Endpoint profile', 'label' => 'Case 141 window'));
sspa_141_t(!is_wp_error($sspa_141_window), 'an AJAX window starts');
if (is_wp_error($sspa_141_window)) {
    return;
}
sleep(3);
wp_remote_get(rest_url('sspa-endpoint-fixture/v1/startup/red'), array('timeout' => 30));
wp_remote_get(rest_url('sspa-endpoint-fixture/v1/startup/blue'), array('timeout' => 30));
wp_remote_get(add_query_arg(array('action' => 'zz_endpoint_fixture', 'term' => 'green'), admin_url('admin-ajax.php')), array('timeout' => 30));
wp_remote_post(rest_url('sspa-endpoint-fixture/v1/write'), array('timeout' => 30));
SSPA_Ajax_Profile::stop($sspa_141_window['uuid']);

$sspa_141_evidence = SSPA_Report::endpoint_evidence($sspa_141_window['collection_id']);
$sspa_141_keys = array();
foreach ((array) (is_wp_error($sspa_141_evidence) ? array() : $sspa_141_evidence['endpoints']) as $endpoint) {
    $id = $endpoint['identity'];
    $name = 'rest' === $id['transport'] ? $id['route_pattern'] : $id['action'];
    if (false !== strpos($name, 'startup')) { $sspa_141_keys['rest'] = $endpoint; }
    if (false !== strpos($name, '/write')) { $sspa_141_keys['write'] = $endpoint; }
    if ('zz_endpoint_fixture' === $name) { $sspa_141_keys['ajax'] = $endpoint; }
}
sspa_141_t(3 === count($sspa_141_keys), 'the window observed the REST, POST and admin-ajax fixture endpoints');
if (3 !== count($sspa_141_keys)) {
    return;
}
$sspa_141_uuid = $sspa_141_window['uuid'];

// ---- E: the URL the administrator confirms must be the observed endpoint ----
$sspa_141_template = SSPA_Endpoint_Profile::url_template($sspa_141_keys['rest']['identity']);
sspa_141_t(rest_url('sspa-endpoint-fixture/v1/startup/{term}') === $sspa_141_template, 'the REST URL template drops the optional group and names the placeholder (' . $sspa_141_template . ')');
$sspa_141_try = static function ($key, $url, $samples = 3, $confirmed = true) use ($sspa_141_uuid) {
    return SSPA_Endpoint_Profile::start(array('uuid' => $sspa_141_uuid, 'key' => $key, 'url' => $url, 'samples' => $samples, 'confirmed' => $confirmed));
};
$sspa_141_rest_key = $sspa_141_keys['rest']['key'];
$sspa_141_refusals = array(
    'unconfirmed' => array($sspa_141_rest_key, rest_url('sspa-endpoint-fixture/v1/startup/blue'), 3, false, 'sspa_endpoint_confirm'),
    'eleven samples' => array($sspa_141_rest_key, rest_url('sspa-endpoint-fixture/v1/startup/blue'), 11, true, 'sspa_endpoint_samples'),
    'placeholder left in' => array($sspa_141_rest_key, $sspa_141_template, 3, true, 'sspa_endpoint_placeholder'),
    'another route' => array($sspa_141_rest_key, rest_url('wp/v2/posts'), 3, true, 'sspa_endpoint_mismatch'),
    'another site' => array($sspa_141_rest_key, 'https://example.com/wp-json/sspa-endpoint-fixture/v1/startup/blue', 3, true, 'sspa_endpoint_url'),
    'a POST endpoint' => array($sspa_141_keys['write']['key'], rest_url('sspa-endpoint-fixture/v1/write'), 3, true, 'sspa_endpoint_method'),
    'admin-ajax with another action' => array($sspa_141_keys['ajax']['key'], add_query_arg('action', 'heartbeat', admin_url('admin-ajax.php')), 3, true, 'sspa_endpoint_mismatch'),
);
foreach ($sspa_141_refusals as $label => $case) {
    $result = $sspa_141_try($case[0], $case[1], $case[2], $case[3]);
    sspa_141_t(is_wp_error($result) && $case[4] === $result->get_error_code(), 'refused: ' . $label . ' (' . (is_wp_error($result) ? $result->get_error_code() : 'started') . ')');
}
sspa_141_t(!SSPA_Run_Controller::active_run_id(), 'no refused request started a run');

$sspa_141_drive = static function ($run_id) {
    $deadline = time() + 300;
    do {
        SSPA_Run_Controller::process_batch($run_id);
        $status = SSPA_Run_Controller::status($run_id);
    } while ($status && in_array($status['status'], array('queued', 'crawling', 'analysing'), true) && time() < $deadline);
    return $status ? $status['status'] : 'none';
};

// ---- E: profile the REST endpoint with 4 samples ----
$sspa_141_url = rest_url('sspa-endpoint-fixture/v1/startup/blue');
$sspa_141_started = $sspa_141_try($sspa_141_rest_key, $sspa_141_url, 4);
sspa_141_t(!is_wp_error($sspa_141_started) && 0 === strpos($sspa_141_started['page_key'], 'endpoint-') && 'anon' === $sspa_141_started['variant'],
    'profiling the REST endpoint starts under an anonymous endpoint key (' . (is_wp_error($sspa_141_started) ? $sspa_141_started->get_error_message() : $sspa_141_started['page_key']) . ')');
if (is_wp_error($sspa_141_started)) {
    return;
}
sspa_141_t('done' === $sspa_141_drive($sspa_141_started['run_id']), 'the endpoint profile completes');
$sspa_141_row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE run_id = %d', SSPA_Schema::table('profiles'), $sspa_141_started['run_id']), ARRAY_A);
$sspa_141_samples = $sspa_141_row ? (array) json_decode((string) $sspa_141_row['samples'], true) : array();
$sspa_141_errors = array_filter(array_map(static function ($s) { return isset($s['error']) ? $s['error'] : ''; }, $sspa_141_samples));
sspa_141_t($sspa_141_row && $sspa_141_started['page_key'] === $sspa_141_row['page_key'] && 4 === count($sspa_141_samples) && !$sspa_141_errors,
    'four measured samples, each captured (' . count($sspa_141_samples) . ' samples, errors: ' . implode(',', $sspa_141_errors) . ')');
$sspa_141_capture = $sspa_141_row ? SSPA_Profile_Panel::capture($sspa_141_row) : null;
$sspa_141_includes = isset($sspa_141_capture['boot']['includes']) ? array_keys((array) $sspa_141_capture['boot']['includes']) : array();
sspa_141_t(in_array('woocommerce', $sspa_141_includes, true) && in_array('sspa-endpoint-fixture', $sspa_141_includes, true)
    && !in_array('super-speedy-performance-analysis', $sspa_141_includes, true) && !in_array('redis-cache', $sspa_141_includes, true),
    'the profiled request honoured the endpoint\'s trim (loaded: ' . implode(', ', $sspa_141_includes) . ')');

// ---- F: environment evidence from the serving PHP (php-fpm, opcache on) ----
$sspa_141_env = isset($sspa_141_capture['environment']) ? $sspa_141_capture['environment'] : null;
sspa_141_t(is_array($sspa_141_env) && 5 <= (int) $sspa_141_capture['schema'], 'the capture carries an environment section (schema ' . var_export($sspa_141_capture['schema'] ?? null, true) . ')');
$sspa_141_oc = $sspa_141_env['opcache'] ?? array();
sspa_141_t('fpm-fcgi' === ($sspa_141_env['sapi'] ?? '') && true === ($sspa_141_oc['enabled'] ?? null), 'opcache is read from the serving php-fpm and is on (' . wp_json_encode(array($sspa_141_env['sapi'] ?? null, $sspa_141_oc['enabled'] ?? null)) . ')');
sspa_141_t(($sspa_141_oc['request_files']['included'] ?? 0) > 500 && ($sspa_141_oc['request_files']['cached'] ?? 0) > 0
    && $sspa_141_oc['request_files']['cached'] <= $sspa_141_oc['request_files']['included'],
    'it counts this request\'s included files and how many are in opcache (' . wp_json_encode($sspa_141_oc['request_files'] ?? null) . ')');
sspa_141_t(($sspa_141_oc['memory']['used_bytes'] ?? 0) > 0 && isset($sspa_141_oc['memory']['free_bytes'], $sspa_141_oc['memory']['wasted_bytes'], $sspa_141_oc['cache_full'], $sspa_141_oc['scripts']['cached'], $sspa_141_oc['scripts']['max_accelerated_files'], $sspa_141_oc['statistics']['hit_rate'], $sspa_141_oc['statistics']['oom_restarts']),
    'memory, cached scripts against the limit, hit rate and restarts are recorded');
// The shared local php-fpm opcache is whatever state it is in (on 22 October 2026 it was full), so check the probe against an independent read of that same opcache, and
// each warning against the probe's own figures, rather than assume a healthy cache.
copy($sspa_141_fixtures . 'opcache-status.php', ABSPATH . 'zz-sspa-opcache-status.php');
sleep(3);
$sspa_141_independent = json_decode(wp_remote_retrieve_body(wp_remote_get(home_url('/zz-sspa-opcache-status.php'), array('timeout' => 30))), true);
$sspa_141_ind = isset($sspa_141_independent['status']) ? $sspa_141_independent['status'] : null;
$sspa_141_total = isset($sspa_141_oc['memory']) ? $sspa_141_oc['memory']['used_bytes'] + $sspa_141_oc['memory']['free_bytes'] + $sspa_141_oc['memory']['wasted_bytes'] : null;
sspa_141_t(is_array($sspa_141_ind) && 'fpm-fcgi' === $sspa_141_independent['sapi'] && $sspa_141_ind['memory_total'] === $sspa_141_total
    && $sspa_141_ind['max_cached_keys'] === $sspa_141_oc['scripts']['max_cached_keys'] && $sspa_141_ind['start_time'] === $sspa_141_oc['statistics']['start_time']
    && $sspa_141_ind['oom_restarts'] >= $sspa_141_oc['statistics']['oom_restarts'],
    'the probe read the same php-fpm opcache an independent opcache_get_status() reads (' . wp_json_encode(array('independent' => $sspa_141_ind, 'probe_total' => $sspa_141_total)) . ')');
$sspa_141_codes = array_column((array) ($sspa_141_oc['warnings'] ?? array()), 'code');
$sspa_141_uncached = $sspa_141_oc['request_files']['included'] - $sspa_141_oc['request_files']['cached'];
sspa_141_t(!in_array('opcache_disabled', $sspa_141_codes, true)
    && in_array('opcache_full', $sspa_141_codes, true) === (bool) $sspa_141_oc['cache_full']
    && in_array('opcache_restarts', $sspa_141_codes, true) === ($sspa_141_oc['statistics']['oom_restarts'] + $sspa_141_oc['statistics']['hash_restarts'] > 0)
    && in_array('opcache_request_files_uncached', $sspa_141_codes, true) === ($sspa_141_uncached > 0.05 * $sspa_141_oc['request_files']['included']),
    'each opcache warning follows from the figures (' . wp_json_encode(array('codes' => $sspa_141_codes, 'full' => $sspa_141_oc['cache_full'], 'restarts' => array($sspa_141_oc['statistics']['oom_restarts'], $sspa_141_oc['statistics']['hash_restarts']), 'uncached' => $sspa_141_uncached)) . ')');
$sspa_141_db = $sspa_141_env['db'] ?? array();
sspa_141_t(isset($sspa_141_db['connect_ms']) && $sspa_141_db['connect_ms'] > 0 && $sspa_141_db['connect_ms'] < 5000, 'the database connection time is measured (' . wp_json_encode($sspa_141_db['connect_ms'] ?? null) . ' ms)');
sspa_141_t(3 === ($sspa_141_db['trivial_query']['n'] ?? 0) && ($sspa_141_db['trivial_query']['median_ms'] ?? 0) > 0, 'three trivial queries are timed (' . wp_json_encode($sspa_141_db['trivial_query'] ?? null) . ')');
sspa_141_t(!isset($sspa_141_db['host']) && in_array($sspa_141_db['transport'] ?? '', array('socket', 'tcp'), true), 'the database transport is recorded, the host is not');
$sspa_141_cache = $sspa_141_env['object_cache'] ?? array();
sspa_141_t(true === ($sspa_141_cache['persistent'] ?? null) && false !== stripos((string) ($sspa_141_cache['dropin']['name'] ?? ''), 'redis'),
    'the persistent object cache and its drop-in are identified (' . wp_json_encode($sspa_141_cache['dropin'] ?? null) . ')');
sspa_141_t(10 === ($sspa_141_cache['round_trip']['n'] ?? 0) && ($sspa_141_cache['round_trip']['median_ms'] ?? 0) > 0, 'ten object-cache misses are timed (' . wp_json_encode($sspa_141_cache['round_trip'] ?? null) . ')');
sspa_141_t(isset($sspa_141_env['probe_ms']) && $sspa_141_env['probe_ms'] > 0, 'the probes\' own cost is recorded (' . wp_json_encode($sspa_141_env['probe_ms'] ?? null) . ' ms)');

$sspa_141_panel = SSPA_Profile_Panel::render((int) $sspa_141_row['id'], array('cached' => false));
sspa_141_t(is_string($sspa_141_panel) && false !== strpos($sspa_141_panel, 'sspa-environment') && false !== strpos($sspa_141_panel, 'OPcache')
    && false !== strpos($sspa_141_panel, number_format_i18n((int) $sspa_141_oc['request_files']['cached'])), 'the profile panel shows the environment evidence');

ob_start();
include SSPA_PLUGIN_DIR . 'includes/admin/tabs/pages.php';
$sspa_141_pages = ob_get_clean();
sspa_141_t(false !== strpos($sspa_141_pages, $sspa_141_started['page_key']) && false !== strpos($sspa_141_pages, 'data-profile-id="' . (int) $sspa_141_row['id'] . '"'),
    'the Pages tab lists the endpoint profile');
ob_start();
include SSPA_PLUGIN_DIR . 'includes/admin/tabs/ajax.php';
$sspa_141_ajax_tab = ob_get_clean();
sspa_141_t((bool) preg_match('/data-key="' . preg_quote($sspa_141_rest_key, '/') . '".*?sspa-endpoint-profile-view" data-profile-id="' . (int) $sspa_141_row['id'] . '"/s', $sspa_141_ajax_tab),
    'the AJAX tab row opens the latest profile of that endpoint');

// ---- E: the admin-ajax endpoint ----
$sspa_141_ajax_url = add_query_arg(array('action' => 'zz_endpoint_fixture', 'term' => 'green'), admin_url('admin-ajax.php'));
$sspa_141_ajax_started = $sspa_141_try($sspa_141_keys['ajax']['key'], $sspa_141_ajax_url, 2);
sspa_141_t(!is_wp_error($sspa_141_ajax_started) && 'anon' === $sspa_141_ajax_started['variant'] && 'done' === $sspa_141_drive($sspa_141_ajax_started['run_id']),
    'profiling the anonymous admin-ajax endpoint completes as anonymous');
$sspa_141_ajax_row = is_wp_error($sspa_141_ajax_started) ? null : $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE run_id = %d', SSPA_Schema::table('profiles'), $sspa_141_ajax_started['run_id']), ARRAY_A);
$sspa_141_ajax_samples = $sspa_141_ajax_row ? (array) json_decode((string) $sspa_141_ajax_row['samples'], true) : array();
sspa_141_t($sspa_141_ajax_row && 2 === count($sspa_141_ajax_samples) && 200 === (int) $sspa_141_ajax_row['response_code'] && 'anon' === $sspa_141_ajax_row['variant'],
    'two captured admin-ajax samples, HTTP 200 (' . ($sspa_141_ajax_row ? $sspa_141_ajax_row['response_code'] . ', ' . count($sspa_141_ajax_samples) : 'no row') . ')');

// ---- F: opcache off and opcache full, served for real by a private PHP built-in server ----
$sspa_141_data = SSPA_PLUGIN_DIR . '.data/case-141';
wp_mkdir_p($sspa_141_data);
$sspa_141_serve = static function ($label, $ini, $requests) use ($sspa_141_data) {
    $port = 18900 + wp_rand(0, 90);
    $command = array_merge(array(PHP_BINARY), $ini, array('-S', '127.0.0.1:' . $port, '-t', ABSPATH, ABSPATH . 'index.php'));
    $log = $sspa_141_data . '/' . $label . '.log';
    $process = proc_open($command, array(0 => array('file', '/dev/null', 'r'), 1 => array('file', $log, 'w'), 2 => array('file', $log, 'a')), $pipes, ABSPATH);
    if (!is_resource($process)) {
        return array('error' => 'could not start the built-in server');
    }
    $captures = array();
    try {
        $deadline = microtime(true) + 10;
        $ready = false;
        while (!$ready && microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($socket) {
                fclose($socket);
                $ready = true;
            } else {
                usleep(100000);
            }
        }
        if (!$ready) {
            return array('error' => 'the built-in server did not listen within 10 s; see ' . $log);
        }
        $crawler = new SSPA_Crawler();
        for ($i = 0; $i < $requests; $i++) {
            $sample = $crawler->send_profiled('http://127.0.0.1:' . $port . '/wp-json/sspa-endpoint-fixture/v1/startup/served' . $i, array('flags' => array('v' => 'anon')));
            $captures[] = isset($sample['capture']) ? $sample['capture'] : array('error' => $sample['error'] ?? 'no capture', 'code' => $sample['code'] ?? null);
        }
    } finally {
        $status = proc_get_status($process);
        proc_terminate($process);
        proc_close($process);
    }
    return array('captures' => $captures, 'pid' => $status['pid']);
};

$sspa_141_off = $sspa_141_serve('opcache-off', array('-d', 'opcache.enable=0'), 1);
$sspa_141_off_env = $sspa_141_off['captures'][0]['environment'] ?? null;
$sspa_141_off_codes = array_column((array) ($sspa_141_off_env['opcache']['warnings'] ?? array()), 'code');
sspa_141_t('cli-server' === ($sspa_141_off_env['sapi'] ?? '') && false === ($sspa_141_off_env['opcache']['enabled'] ?? null) && in_array('opcache_disabled', $sspa_141_off_codes, true),
    'opcache off: the served request reports it disabled, with a warning (' . wp_json_encode(isset($sspa_141_off['error']) ? $sspa_141_off['error'] : array($sspa_141_off_env['sapi'] ?? null, $sspa_141_off_env['opcache'] ?? ($sspa_141_off['captures'][0] ?? null))) . ')');
$sspa_141_off_panel = '';
if ($sspa_141_off_env) {
    $sspa_141_off_panel = SSPA_Profile_Panel::environment_html($sspa_141_off['captures'][0]);
}
sspa_141_t(false !== strpos($sspa_141_off_panel, 'sspa-environment-alert') && false !== stripos($sspa_141_off_panel, 'opcache is disabled'), 'the panel shows the disabled warning');
// An environment warning is not a failed measurement: the panel's status announces the first
// sspa-adhoc-error, so the opcache warnings above the fold must not use that class.
$sspa_141_has_error_warning = in_array('error', array_column((array) ($sspa_141_oc['warnings'] ?? array()), 'severity'), true);
sspa_141_t(false === strpos((string) $sspa_141_panel, 'sspa-adhoc-error') && $sspa_141_has_error_warning === (false !== strpos((string) $sspa_141_panel, 'sspa-environment-alert')),
    'opcache warnings show as environment alerts and never mark the successful measurement failed (error-severity warnings: ' . ($sspa_141_has_error_warning ? 'yes' : 'no') . ')');

$sspa_141_tiny = $sspa_141_serve('opcache-tiny', array('-d', 'opcache.enable=1', '-d', 'opcache.memory_consumption=16', '-d', 'opcache.interned_strings_buffer=2', '-d', 'opcache.max_accelerated_files=200'), 3);
$sspa_141_tiny_env = isset($sspa_141_tiny['captures']) ? end($sspa_141_tiny['captures']) : array();
$sspa_141_tiny_env = $sspa_141_tiny_env['environment'] ?? null;
$sspa_141_tiny_oc = $sspa_141_tiny_env['opcache'] ?? array();
$sspa_141_tiny_codes = array_column((array) ($sspa_141_tiny_oc['warnings'] ?? array()), 'code');
sspa_141_t(true === ($sspa_141_tiny_oc['enabled'] ?? null) && true === ($sspa_141_tiny_oc['cache_full'] ?? null) && in_array('opcache_full', $sspa_141_tiny_codes, true),
    'a deliberately tiny opcache reports itself full, with a warning (' . wp_json_encode(isset($sspa_141_tiny['error']) ? $sspa_141_tiny['error'] : array('full' => $sspa_141_tiny_oc['cache_full'] ?? null, 'scripts' => $sspa_141_tiny_oc['scripts'] ?? null, 'codes' => $sspa_141_tiny_codes)) . ')');
sspa_141_t(isset($sspa_141_tiny_oc['request_files']) && $sspa_141_tiny_oc['request_files']['cached'] < $sspa_141_tiny_oc['request_files']['included'] && in_array('opcache_request_files_uncached', $sspa_141_tiny_codes, true),
    'it shows this request\'s files were not all cached (' . wp_json_encode($sspa_141_tiny_oc['request_files'] ?? null) . ')');
foreach (array($sspa_141_off, $sspa_141_tiny) as $sspa_141_server) {
    $sspa_141_alive = !empty($sspa_141_server['pid']) && file_exists('/proc/' . (int) $sspa_141_server['pid']);
    sspa_141_t(!$sspa_141_alive, 'the private built-in server it started has stopped (pid ' . ($sspa_141_server['pid'] ?? '?') . ')');
}
