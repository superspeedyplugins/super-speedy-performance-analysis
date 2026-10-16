<?php
// Issue 46: a foreign database drop-in supplies the connection, like SQLite Database
// Integration. Stock wpdb deliberately cannot connect. Exercise the real authenticated
// admin-ajax entry point used by Run Analysis, including obsolete client parameters.
defined('ABSPATH') || exit;
require __DIR__ . '/../lib/native-guard.php';
$scenario = getenv('SSPA_SUITE_SCOPE') ? 'tests-' . getenv('SSPA_SUITE_SCOPE') . '-issue46' : 'tests-issue46';
if (basename(rtrim(ABSPATH, '/')) !== $scenario) {
    passthru('bash ' . escapeshellarg(SSPA_PLUGIN_DIR . '.tests/issue46/run.sh'), $exit);
    if ($exit !== 0) { WP_CLI::error('Dedicated issue 46 regression failed'); }
    return;
}
$GLOBALS['sspa_101_failures'] = 0;
function sspa_101_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
    if (!$ok) { $GLOBALS['sspa_101_failures']++; }
}
function sspa_101_request($url, $args = array()) {
    return wp_remote_request($url, array_merge(array('timeout' => 30, 'redirection' => 0), $args));
}
function sspa_101_code($response) {
    return is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
}
wp_set_current_user(1);
$active = SSPA_Run_Controller::active_run_id();
if ($active) { SSPA_Run_Controller::cancel($active); }
$evidence = SSPA_PLUGIN_DIR . '.data/w0';
wp_mkdir_p($evidence);
$config_path = ABSPATH . 'wp-config.php';
$config = file_get_contents($config_path);
file_put_contents($evidence . '/issue46-wp-config.backup', $config);
// Keep generated credentials only on the private local site, never in source or logs.
$fixture = "<?php\n/*\nPlugin Name: Issue 46 connection provider\n*/\n"
    . '$wpdb = new wpdb(' . var_export(DB_USER, true) . ', ' . var_export(DB_PASSWORD, true)
    . ', ' . var_export(DB_NAME, true) . ', ' . var_export(DB_HOST, true) . ");\n";
$dropin = WP_CONTENT_DIR . '/db.php';
if (is_link($dropin)) { unlink($dropin); }
file_put_contents($dropin, $fixture);
$broken_config = preg_replace("/define\(\s*(['\"])DB_PASSWORD\\1\s*,[^\n]+/", "define('DB_PASSWORD', 'issue46-deliberately-invalid');", $config, 1, $replaced);
if ($replaced !== 1) { throw new RuntimeException('Could not establish connection-provider prerequisite'); }
file_put_contents($config_path, $broken_config);
sleep(3);
$cookies = SSPA_Auth::cookies_for('admin', 1);
$_COOKIE[LOGGED_IN_COOKIE] = $cookies[LOGGED_IN_COOKIE];
$nonce = wp_create_nonce('sspa_admin');
sspa_101_t(200 === sspa_101_code(sspa_101_request(home_url('/'))), 'connection-supplying foreign drop-in serves the front end with unusable stock credentials');
sspa_101_t(200 === sspa_101_code(sspa_101_request(admin_url('index.php'), array('cookies' => $cookies))), 'authenticated wp-admin works before the swap request');
sspa_101_t(wp_using_ext_object_cache() && file_exists(WP_CONTENT_DIR . '/object-cache.php'), 'persistent object-cache prerequisite exists');
$oc_hash = hash_file('sha256', WP_CONTENT_DIR . '/object-cache.php');
$response = sspa_101_request(admin_url('admin-ajax.php'), array('method' => 'POST', 'cookies' => $cookies, 'body' => array(
    'action' => 'sspa_start_run', 'nonce' => $nonce, 'page_keys' => array('home'), 'swap_dropin' => 1,
)));
echo 'START HTTP ' . sspa_101_code($response) . "\n";
sspa_101_t(file_exists($dropin) && file_get_contents($dropin) === $fixture && !file_exists(WP_CONTENT_DIR . '/db.php.sspa-hold'), 'Run Analysis never displaces the connection provider despite a legacy swap request');
foreach (array(home_url('/'), admin_url('index.php')) as $url) {
    $page = sspa_101_request($url, array('cookies' => $cookies));
    echo 'AFTER ' . $url . ' HTTP ' . sspa_101_code($page) . (strpos(wp_remote_retrieve_body($page), 'Error establishing a database connection') !== false ? ' Error establishing a database connection' : '') . "\n";
    sspa_101_t(200 === sspa_101_code($page), 'site remains available after Run Analysis: ' . $url);
}
if (file_get_contents($dropin) !== $fixture) {
    echo "FAIL: issue 46 reproduced; site requires manual restoration from db.php.sspa-hold and issue46-wp-config.backup\n";
    exit(1);
}
// Complete the real run created by the UI action before trying cache impact.
$json = json_decode(wp_remote_retrieve_body($response), true);
sspa_101_t(is_array($json) && !empty($json['success']) && !empty($json['data']['run_id']), 'Run Analysis accepts the request in degraded mode');
wp_cache_flush();
$active = SSPA_Run_Controller::active_run_id();
if ($active) {
    $deadline = time() + 120;
    do {
        SSPA_Run_Controller::process_batch($active);
        $status = SSPA_Run_Controller::status($active);
    } while ($status && in_array($status['status'], array('crawling', 'analysing'), true) && time() < $deadline);
    sspa_101_t($status && 'done' === $status['status'], 'the real UI-started degraded analysis completes');
    if (!$status || 'done' !== $status['status']) { SSPA_Run_Controller::cancel($active); }
} else {
    sspa_101_t(false, 'the UI-started run exists for processing');
}
$response = sspa_101_request(admin_url('admin-ajax.php'), array('method' => 'POST', 'cookies' => $cookies, 'body' => array(
    'action' => 'sspa_start_run', 'nonce' => $nonce, 'type' => 'cache_impact', 'page_keys' => array('home'), 'oc_sitewide' => 1,
)));
$json = json_decode(wp_remote_retrieve_body($response), true);
sspa_101_t(is_array($json) && empty($json['success']) && is_string($json['data'] ?? null) && strpos($json['data'], 'Issue 46 connection provider') !== false, 'Cache Impact returns an explicit error naming Issue 46 connection provider: ' . wp_json_encode($json));
sspa_101_t($oc_hash === hash_file('sha256', WP_CONTENT_DIR . '/object-cache.php') && !file_exists(WP_CONTENT_DIR . '/object-cache.php.sspa-hold'), 'Cache Impact never renames object-cache.php');
sspa_101_t($fixture === file_get_contents($dropin), 'foreign db.php remains byte-for-byte unchanged');
$screen = sspa_101_request(admin_url('admin.php?page=sspa'), array('cookies' => $cookies));
$screen_html = wp_remote_retrieve_body($screen);
sspa_101_t(200 === sspa_101_code($screen) && strpos($screen_html, 'Issue 46 connection provider') !== false, 'Performance Analysis screen names the foreign owner');
sspa_101_t(strpos($screen_html, 'id="sspa-swap-dropin"') === false && strpos($screen_html, 'id="sspa-replace-stale-dropin"') === false, 'Performance Analysis offers no foreign drop-in displacement control');
// Disarm the fault on successful runs. The foreign drop-in remains inspectable.
file_put_contents($config_path, $config);
sleep(3);
exit($GLOBALS['sspa_101_failures'] ? 1 : 0);
