<?php
// Phase 5 (2026-10-21-quote-request-plan.md, scope 4): `wp sspa profiler enable|disable` changes
// the profiler exactly as the Tools tab's Enable profiler / Disable profiler buttons do. Each
// command runs as a real WP-CLI subprocess; each button is the real authenticated admin-ajax
// action. After every step the case compares what Tools reports: SSPA_Helper_Files::health(),
// the loader file and the recorded choice (sspa_profiler_enabled).
require SSPA_PLUGIN_DIR . '.tests/lib/fleet.php';
wp_set_current_user(1);
$active = SSPA_Run_Controller::active_run_id();
if ($active) {
    SSPA_Run_Controller::cancel($active);
}
$cookies = SSPA_Auth::cookies_for('admin', 1);
$_COOKIE[LOGGED_IN_COOKIE] = $cookies[LOGGED_IN_COOKIE];
$nonce = wp_create_nonce('sspa_admin');
$button = static function ($enabled) use ($cookies, $nonce) {
    $r = wp_remote_post(admin_url('admin-ajax.php'), array('cookies' => $cookies, 'timeout' => 60, 'body' => array('action' => 'sspa_profiler_toggle', 'enabled' => $enabled, 'nonce' => $nonce)));
    return json_decode(wp_remote_retrieve_body($r), true);
};
$state = static function () {
    wp_cache_delete('sspa_profiler_enabled', 'options');
    wp_cache_delete('notoptions', 'options');
    wp_cache_delete('alloptions', 'options');
    clearstatcache();
    $health = SSPA_Helper_Files::health();
    return array(
        'health' => $health,
        'loader' => is_file(SSPA_Helper_Files::mu_path()) ? md5_file(SSPA_Helper_Files::mu_path()) : null,
        'choice' => (bool) get_option('sspa_profiler_enabled'),
    );
};

$on = $button(1);
$by_button_on = $state();
$off = $button(0);
$by_button_off = $state();
sspa_fleet_assert(!empty($on['success']) && !empty($off['success']) && $by_button_on['health']['mu'] && !$by_button_off['health']['mu'] && 'disabled' === $by_button_off['health']['mu_reason'],
    'the Tools buttons enable the profiler (healthy) and disable it (reason "disabled")');

$enabled = sspa_fleet_cli(array('sspa', 'profiler', 'enable'));
$by_cli_on = $state();
sspa_fleet_assert($by_cli_on === $by_button_on, 'wp sspa profiler enable leaves Tools exactly as Enable profiler does (loader, health, recorded choice)');
sspa_fleet_assert(false !== strpos($enabled, 'Profiler enabled'), 'enable reports success: ' . $enabled);

$disabled = sspa_fleet_cli(array('sspa', 'profiler', 'disable'));
$by_cli_off = $state();
sspa_fleet_assert($by_cli_off === $by_button_off && !is_file(SSPA_Helper_Files::mu_path()) && !$by_cli_off['choice'],
    'wp sspa profiler disable leaves Tools exactly as Disable profiler does: no loader, choice recorded off');
$tools = wp_remote_post(admin_url('admin-ajax.php'), array('cookies' => $cookies, 'timeout' => 60, 'body' => array('action' => 'sspa_render_tab', 'tabs' => 'tools', 'nonce' => $nonce)));
sspa_fleet_assert(false !== strpos(wp_remote_retrieve_body($tools), 'Enable profiler'), 'after the CLI disable the Tools tab offers Enable profiler');

// Like the buttons, the commands refuse while an analysis is running.
$run = SSPA_Run_Controller::start(array('type' => 'spot', 'page_keys' => array('home'), 'user_id' => 1));
sspa_fleet_assert(is_wp_error($run), 'with the profiler disabled an analysis cannot start');
sspa_fleet_cli(array('sspa', 'profiler', 'enable'));
$run = SSPA_Run_Controller::start(array('type' => 'spot', 'page_keys' => array('home'), 'user_id' => 1));
if (!is_wp_error($run)) {
    sspa_fleet_cli(array('sspa', 'profiler', 'disable'), 1);
    $during = $state();
    $button_during = $button(0);
    sspa_fleet_assert($during['health']['mu'] && empty($button_during['success']) && false !== strpos((string) $button_during['data'], 'active analysis') && $state()['health']['mu'],
        'while an analysis is running both the command and the button refuse to disable the profiler');
    SSPA_Run_Controller::cancel($run);
} else {
    sspa_fleet_assert(false, 'after the CLI enable an analysis can start: ' . $run->get_error_message());
}
// Leave the explicit enabled state for subsequent analysis cases.
sspa_fleet_assert(SSPA_Helper_Files::health()['mu'], 'the profiler is left enabled');
sspa_fleet_done();
