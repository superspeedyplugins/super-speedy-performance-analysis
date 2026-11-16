<?php
// The profiler's files in the Pro edition (this checkout's tree): installed and refreshed without the
// Enable click, and kept off after an explicit Disable, through the real Tools action.
//
// The wordpress.org edition's opposite rule (activation writes nothing; only Enable profiler installs
// the files) is stripped from this tree and proved from its built zip by .tests/wporg/edition/run.sh.
function w1_prof_t($ok, $label) { echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n"; }
wp_set_current_user(1);
$active = SSPA_Run_Controller::active_run_id(); if ($active) SSPA_Run_Controller::cancel($active);
$cookies = SSPA_Auth::cookies_for('admin', 1);
$_COOKIE[LOGGED_IN_COOKIE] = $cookies[LOGGED_IN_COOKIE];
$nonce = wp_create_nonce('sspa_admin');
// The toggle runs in a separate web request; this process must read what it wrote, not its own cache.
$fresh = static function () {
    foreach (array('sspa_profiler_switched_off', 'sspa_profiler_enabled', 'sspa_profiler_auto_checked', 'notoptions', 'alloptions') as $key) {
        wp_cache_delete($key, 'options');
    }
};
$action = static function($enabled) use ($cookies, $nonce, $fresh) {
    $r = wp_remote_post(admin_url('admin-ajax.php'), array('cookies' => $cookies, 'timeout' => 30, 'body' => array('action' => 'sspa_profiler_toggle', 'enabled' => $enabled, 'nonce' => $nonce)));
    $fresh();
    return json_decode(wp_remote_retrieve_body($r), true);
};
$installed = static function () {
    clearstatcache();
    return file_exists(SSPA_Helper_Files::mu_path()) && 'ours' === SSPA_Helper_Files::dropin_status();
};

// 1. Never switched off: activation installs both files.
SSPA_Helper_Files::remove_all();
delete_option('sspa_profiler_enabled');
delete_option('sspa_profiler_switched_off');
SSPA_Install::activate();
w1_prof_t($installed(), 'Pro: activation installs the profiler loader and db.php');

// 2. An analysis starting with no files installs them and runs.
SSPA_Helper_Files::remove_all();
w1_prof_t(!file_exists(SSPA_Helper_Files::mu_path()), 'precondition: files removed without an explicit Disable');
$result = SSPA_Run_Controller::start(array('type' => 'spot', 'page_keys' => array('home'), 'user_id' => 1));
w1_prof_t(!is_wp_error($result) && $installed(), 'Pro: starting an analysis installs the files instead of asking for Enable profiler');
if (!is_wp_error($result)) SSPA_Run_Controller::cancel($result);

// 3. Stale files (a genuine local secret rotation) are refreshed when an analysis starts.
delete_option('sspa_secret');
SSPA_Token::secret();
w1_prof_t('stale' === SSPA_Helper_Files::health()['mu_reason'], 'precondition: rotating the secret makes the installed files stale');
$result = SSPA_Run_Controller::start(array('type' => 'spot', 'page_keys' => array('home'), 'user_id' => 1));
w1_prof_t(!is_wp_error($result) && SSPA_Helper_Files::health()['mu'], 'Pro: starting an analysis refreshes stale files');
if (!is_wp_error($result)) SSPA_Run_Controller::cancel($result);

// 4. A new build refreshes stale files on the first administrator admin page, once.
delete_option('sspa_secret');
SSPA_Token::secret();
delete_option('sspa_profiler_auto_checked');
$screen = wp_remote_get(admin_url('index.php'), array('cookies' => $cookies, 'timeout' => 30));
$fresh();
clearstatcache();
w1_prof_t(!is_wp_error($screen) && 200 === wp_remote_retrieve_response_code($screen) && SSPA_Helper_Files::health()['mu'], 'Pro: the first admin page of a build refreshes stale files');
w1_prof_t('' !== (string) get_option('sspa_profiler_auto_checked'), 'and records that this build was checked');

// 5. An explicit Disable is respected everywhere until Enable.
$off = $action(0);
clearstatcache();
w1_prof_t(!empty($off['success']) && !file_exists(SSPA_Helper_Files::mu_path()) && !file_exists(SSPA_Helper_Files::dropin_path()), 'Disable action removes the loader and db.php');
w1_prof_t((bool) get_option('sspa_profiler_switched_off'), 'Disable is remembered');
SSPA_Install::activate();
delete_option('sspa_profiler_auto_checked');
$screen = wp_remote_get(admin_url('admin.php?page=sspa'), array('cookies' => $cookies, 'timeout' => 30));
clearstatcache();
w1_prof_t(!is_wp_error($screen) && 200 === wp_remote_retrieve_response_code($screen) && !file_exists(SSPA_Helper_Files::mu_path()), 'after Disable, neither activation nor an admin page installs the loader');
$result = SSPA_Run_Controller::start(array('type' => 'spot', 'page_keys' => array('home'), 'user_id' => 1));
w1_prof_t(is_wp_error($result) && strpos($result->get_error_message(), 'Enable profiler') !== false && strpos($result->get_error_message(), '#tools') !== false && !file_exists(SSPA_Helper_Files::mu_path()), 'after Disable, an analysis returns the Enable profiler prompt linking to Tools');
if (!is_wp_error($result)) SSPA_Run_Controller::cancel($result);
$tools = wp_remote_post(admin_url('admin-ajax.php'), array('cookies' => $cookies, 'timeout' => 30, 'body' => array('action' => 'sspa_render_tab', 'tabs' => 'tools', 'nonce' => $nonce)));
w1_prof_t(strpos(wp_json_encode(json_decode(wp_remote_retrieve_body($tools), true)), 'Enable profiler') !== false, 'Tools tab exposes the explicit Enable profiler control');
$on = $action(1);
w1_prof_t(!empty($on['success']) && $installed() && !get_option('sspa_profiler_switched_off'), 'Enable action writes the loader and db.php and clears the Disable');
$invalid = wp_remote_post(admin_url('admin-ajax.php'), array('cookies' => $cookies, 'timeout' => 30, 'body' => array('action' => 'sspa_profiler_toggle', 'enabled' => 'junk', 'nonce' => $nonce)));
w1_prof_t(!is_wp_error($invalid) && 400 === wp_remote_retrieve_response_code($invalid) && file_exists(SSPA_Helper_Files::mu_path()), 'an invalid toggle value cannot act as an implicit Disable');

// 6. A foreign db.php is never replaced, by Enable or automatically; the loader still installs.
$action(0);
$foreign = "<?php\n/**\n * Plugin Name: SSPA case 105 foreign drop-in\n */\n";
file_put_contents(SSPA_Helper_Files::dropin_path(), $foreign);
$fon = $action(1);
w1_prof_t(!empty($fon['success']) && file_get_contents(SSPA_Helper_Files::dropin_path()) === $foreign && file_exists(SSPA_Helper_Files::mu_path()), 'Enable leaves a foreign db.php untouched');
SSPA_Helper_Files::remove_all();
SSPA_Install::activate();
clearstatcache();
w1_prof_t(file_get_contents(SSPA_Helper_Files::dropin_path()) === $foreign && file_exists(SSPA_Helper_Files::mu_path()), 'automatic install leaves a foreign db.php untouched');
$action(0);
w1_prof_t(file_get_contents(SSPA_Helper_Files::dropin_path()) === $foreign, 'Disable leaves a foreign db.php untouched');
unlink(SSPA_Helper_Files::dropin_path());
$action(1);

// 7. Upgrade, deactivation and reactivation.
delete_option('sspa_profiler_enabled');
update_option('sspa_behaviour_version', '0.43.4');
$before = is_file(SSPA_Helper_Files::mu_path()) ? file_get_contents(SSPA_Helper_Files::mu_path()) : '';
SSPA_Install::maybe_upgrade();
w1_prof_t($before !== '' && file_get_contents(SSPA_Helper_Files::mu_path()) === $before && get_option('sspa_profiler_enabled'), 'upgrade preserves an existing loader and records enabled state');
SSPA_Install::deactivate();
clearstatcache();
w1_prof_t(!file_exists(SSPA_Helper_Files::mu_path()) && !file_exists(SSPA_Helper_Files::dropin_path()), 'deactivation removes the loader and db.php');
SSPA_Install::activate();
w1_prof_t($installed(), 'Pro: reactivation installs them again (no explicit Disable was given)');
$action(1); // Leave the profiler enabled for subsequent analysis cases.
