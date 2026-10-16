<?php
// Existing Tools tab and its real authenticated WordPress action, not an alternate UI.
function w1_prof_t($ok, $label) { echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n"; }
wp_set_current_user(1);
$active = SSPA_Run_Controller::active_run_id(); if ($active) SSPA_Run_Controller::cancel($active);
SSPA_Helper_Files::remove_all();
delete_option('sspa_profiler_enabled');
SSPA_Install::activate();
w1_prof_t(!file_exists(SSPA_Helper_Files::mu_path()), 'fresh activation does not install the profiler loader');
w1_prof_t(SSPA_Helper_Files::dropin_status() === 'ours', 'activation still installs an unoccupied db.php');
$cookies = SSPA_Auth::cookies_for('admin', 1);
$_COOKIE[LOGGED_IN_COOKIE] = $cookies[LOGGED_IN_COOKIE];
$nonce = wp_create_nonce('sspa_admin');
$screen = wp_remote_get(admin_url('admin.php?page=sspa'), array('cookies' => $cookies, 'timeout' => 30));
w1_prof_t(!is_wp_error($screen) && wp_remote_retrieve_response_code($screen) === 200 && !file_exists(SSPA_Helper_Files::mu_path()), 'opening the existing screen never creates the loader');
$result = SSPA_Run_Controller::start(array('type' => 'spot', 'page_keys' => array('home'), 'user_id' => 1));
w1_prof_t(is_wp_error($result) && strpos($result->get_error_message(), 'Enable profiler') !== false && strpos($result->get_error_message(), '#tools') !== false && !file_exists(SSPA_Helper_Files::mu_path()), 'analysis without loader returns an Enable profiler prompt linking to Tools');
if (!is_wp_error($result)) SSPA_Run_Controller::cancel($result);
$tools = wp_remote_post(admin_url('admin-ajax.php'), array('cookies' => $cookies, 'timeout' => 30, 'body' => array('action' => 'sspa_render_tab', 'tabs' => 'tools', 'nonce' => $nonce)));
$tools_json = json_decode(wp_remote_retrieve_body($tools), true);
w1_prof_t(strpos(wp_json_encode($tools_json), 'Enable profiler') !== false, 'Tools tab exposes the explicit Enable profiler control');
$action = static function($enabled) use ($cookies, $nonce) {
    $r = wp_remote_post(admin_url('admin-ajax.php'), array('cookies' => $cookies, 'timeout' => 30, 'body' => array('action' => 'sspa_profiler_toggle', 'enabled' => $enabled, 'nonce' => $nonce)));
    return json_decode(wp_remote_retrieve_body($r), true);
};
$on = $action(1);
w1_prof_t(!empty($on['success']) && file_exists(SSPA_Helper_Files::mu_path()), 'Enable action writes the loader');
$invalid = wp_remote_post(admin_url('admin-ajax.php'), array('cookies' => $cookies, 'timeout' => 30, 'body' => array('action' => 'sspa_profiler_toggle', 'enabled' => 'junk', 'nonce' => $nonce)));
w1_prof_t(!is_wp_error($invalid) && 400 === wp_remote_retrieve_response_code($invalid) && file_exists(SSPA_Helper_Files::mu_path()), 'an invalid toggle value cannot act as an implicit Disable');
$action(1);

$off = $action(0);
w1_prof_t(!empty($off['success']) && !file_exists(SSPA_Helper_Files::mu_path()), 'Disable action removes the loader');
$on = $action(1);
// A genuine local secret rotation makes installed helpers stale without changing source.
delete_option('sspa_secret');
SSPA_Token::secret();
$stale_bytes = file_get_contents(SSPA_Helper_Files::mu_path());
$stale_screen = wp_remote_get(admin_url('admin.php?page=sspa'), array('cookies' => $cookies, 'timeout' => 30));
$stale_html = wp_remote_retrieve_body($stale_screen);
w1_prof_t(strpos($stale_html, 'refreshed the next time an analysis runs') === false && file_get_contents(SSPA_Helper_Files::mu_path()) === $stale_bytes, 'stale helper guidance requires explicit Enable instead of promising automatic repair');
$on = $action(1);
// The file, not a cached option, is authoritative for migrated pre-consent installs.
delete_option('sspa_profiler_enabled');
update_option('sspa_behaviour_version', '0.43.4');
$before = is_file(SSPA_Helper_Files::mu_path()) ? file_get_contents(SSPA_Helper_Files::mu_path()) : '';
SSPA_Install::maybe_upgrade();
w1_prof_t($before !== '' && file_get_contents(SSPA_Helper_Files::mu_path()) === $before && get_option('sspa_profiler_enabled'), 'upgrade preserves an existing loader and records enabled state');
SSPA_Install::deactivate();
w1_prof_t(!file_exists(SSPA_Helper_Files::mu_path()), 'deactivation removes the loader');
SSPA_Install::activate();
w1_prof_t(!file_exists(SSPA_Helper_Files::mu_path()), 'reactivation requires another explicit Enable');
$action(1); // Leave the explicit enabled state for subsequent analysis cases.
