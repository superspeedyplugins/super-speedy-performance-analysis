<?php
// Case 142, run by .tests/observer-guard/run.sh on its dedicated site, where PA is a real folder.
//
// A running AJAX window installs wp-content/mu-plugins/sspa-traffic-observer.php, which loads
// files from the plugin's directory. Renaming a plugin's folder over FTP is the usual way to
// disable a plugin when troubleshooting. Up to 0.51.0 the observer required those files
// unguarded, so every request, wp-admin and the login page included, returned HTTP 500.
//
// This starts a real window, renames the plugin folder exactly as a customer does, and sends real
// HTTP requests. They must succeed and say why the observer stood down. The folder is put back
// afterwards and the window stopped through the plugin. Exits non-zero on any failure.

$GLOBALS['sspa_142_failures'] = 0;
function sspa_142_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
    if (!$ok) { $GLOBALS['sspa_142_failures']++; }
}

// The copy under test must be the invoking checkout's code.
$sspa_142_checkout = trailingslashit((string) getenv('SSPA_142_CHECKOUT'));
foreach (array('mu/sspa-traffic-observer.php', 'super-speedy-performance-analysis.php', 'includes/traffic/class-sspa-traffic-helper.php') as $sspa_142_file) {
    sspa_142_t(is_file($sspa_142_checkout . $sspa_142_file) && md5_file($sspa_142_checkout . $sspa_142_file) === md5_file(SSPA_PLUGIN_DIR . $sspa_142_file), 'the site\'s plugin copy matches the checkout: ' . $sspa_142_file);
}
sspa_142_t(!is_link(untrailingslashit(SSPA_PLUGIN_DIR)) && 0 === strpos(SSPA_PLUGIN_DIR, WP_PLUGIN_DIR . '/'), 'PA is a real folder inside this site\'s plugins directory (' . SSPA_PLUGIN_DIR . ')');

wp_set_current_user(1);
$sspa_142_active = SSPA_Traffic_Collection::active();
if ($sspa_142_active) {
    SSPA_Traffic_Collection::stop((int) $sspa_142_active['id'], true);
}
$sspa_142_window = SSPA_Ajax_Profile::start(array('scenario' => 'Observer without plugin', 'label' => 'Case 142 window'));
sspa_142_t(!is_wp_error($sspa_142_window), 'an AJAX window starts');
if (is_wp_error($sspa_142_window)) {
    exit(1);
}
sspa_142_t(SSPA_Traffic_Helper::is_ours(SSPA_Traffic_Helper::path()), 'the traffic observer MU plugin is installed');
sleep(3); // php-fpm revalidates changed PHP files at most every 2s.

$sspa_142_get = static function ($path) {
    $response = wp_remote_get(home_url($path), array('timeout' => 30, 'redirection' => 0));
    return is_wp_error($response)
        ? array('code' => 0, 'header' => '', 'error' => $response->get_error_message())
        : array('code' => (int) wp_remote_retrieve_response_code($response), 'header' => (string) wp_remote_retrieve_header($response, 'x-sspa-traffic-observer'), 'error' => '');
};
$sspa_142_before = $sspa_142_get('/');
sspa_142_t(200 === $sspa_142_before['code'] && '' === $sspa_142_before['header'], 'with the plugin in place the home page returns 200 and the observer is silent');

$sspa_142_link = untrailingslashit(SSPA_PLUGIN_DIR);
$sspa_142_moved = $sspa_142_link . '-moved-by-case-142';
$sspa_142_results = array();
if (!rename($sspa_142_link, $sspa_142_moved)) {
    sspa_142_t(false, 'the plugin folder could be moved aside');
    exit(1);
}
try {
    clearstatcache();
    sleep(3);
    foreach (array('/', '/wp-login.php', '/wp-json/', '/?sspa_142=1') as $sspa_142_path) {
        $sspa_142_results[$sspa_142_path] = $sspa_142_get($sspa_142_path);
    }
} finally {
    rename($sspa_142_moved, $sspa_142_link);
    clearstatcache();
}
foreach ($sspa_142_results as $sspa_142_path => $sspa_142_r) {
    sspa_142_t(in_array($sspa_142_r['code'], array(200, 302), true),
        'with the plugin folder missing, ' . $sspa_142_path . ' still answers (HTTP ' . $sspa_142_r['code'] . ($sspa_142_r['error'] ? ', ' . $sspa_142_r['error'] : '') . ')');
    sspa_142_t(false !== stripos($sspa_142_r['header'], 'plugin files missing'),
        $sspa_142_path . ' says why the observer stood down (X-SSPA-Traffic-Observer: ' . var_export($sspa_142_r['header'], true) . ')');
}

sleep(3);
$sspa_142_after = $sspa_142_get('/');
sspa_142_t(200 === $sspa_142_after['code'] && '' === $sspa_142_after['header'], 'with the folder back the observer resumes silently (HTTP ' . $sspa_142_after['code'] . ')');
SSPA_Ajax_Profile::stop($sspa_142_window['uuid']);
exit($GLOBALS['sspa_142_failures'] ? 1 : 0);
