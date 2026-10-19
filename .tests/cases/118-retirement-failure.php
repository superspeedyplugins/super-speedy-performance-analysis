<?php
// Exercise a real stop-marker filesystem conflict without changing generated config paths.
function sspa_retire_t($ok, $label) { echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n"; }
$a = SSPA_Traffic_Collection::active();
if ($a) { SSPA_Traffic_Collection::stop((int) $a['id'], true); }
delete_option('sspa_traffic_retirement_error');
$ceiling = static function() { return 100; };
add_filter('sspa_traffic_event_ceiling', $ceiling);
$start = SSPA_Traffic_Collection::start('1h', 'test');
remove_filter('sspa_traffic_event_ceiling', $ceiling);
if (is_wp_error($start)) { sspa_retire_t(false, $start->get_error_message()); return; }
$stopped = SSPA_Traffic_Helper::stopped_path();
$created = false;
$session = '';
try {
    if (file_exists($stopped)) { throw new RuntimeException('Stop-marker fault fixture path is occupied.'); }
    $created = mkdir($stopped);
    sspa_retire_t($created && is_dir($stopped), 'real generated stop-marker path is occupied by a directory');
    sleep(3); // Installed observer publication becomes visible to the serving SAPI.
    $codes = array(); $error = '';
    for ($i = 0; $i < 120; ++$i) {
        $response = wp_remote_get(home_url('/?w5b-retirement=case118'), array('timeout' => 20));
        $codes[] = wp_remote_retrieve_response_code($response);
        wp_cache_delete('sspa_traffic_retirement_error', 'options'); wp_cache_delete('notoptions', 'options');
        $error = get_option('sspa_traffic_retirement_error');
        if ($error) { break; }
    }
    sspa_retire_t($codes && !array_diff($codes, array(200)), 'visitor requests stay HTTP 200 through the retirement fault');
    sspa_retire_t('Could not retire the traffic observer.' === $error, 'retirement fault is recorded for the administrator');
    wp_set_current_user(1);
    $expiry = time() + 300; $session = WP_Session_Tokens::get_instance(1)->create($expiry);
    $cookies = array(LOGGED_IN_COOKIE => wp_generate_auth_cookie(1, $expiry, 'logged_in', $session), AUTH_COOKIE => wp_generate_auth_cookie(1, $expiry, 'auth', $session));
    $admin = wp_remote_get(admin_url('admin.php?page=sspa'), array('cookies' => $cookies, 'timeout' => 30));
    sspa_retire_t(200 === wp_remote_retrieve_response_code($admin) && false !== strpos(wp_remote_retrieve_body($admin), 'Could not retire the traffic observer.'), 'actual PA screen shows the retirement fault without fataling');
    $out = SSPA_PLUGIN_DIR . '.data/retirement-failure/' . basename(rtrim(ABSPATH, '/'));
    wp_mkdir_p($out); file_put_contents($out . '/admin.html', wp_remote_retrieve_body($admin));
    file_put_contents($out . '/evidence.json', wp_json_encode(array('requests' => count($codes), 'codes' => array_count_values($codes), 'error' => $error)));
} finally {
    if ($session) { WP_Session_Tokens::get_instance(1)->destroy($session); }
    if ($created) { rmdir($stopped); }
    SSPA_Traffic_Collection::stop((int) $start['collection']['id'], true);
    delete_option('sspa_traffic_retirement_error'); // Disarm the deliberate, now-repaired fault.
}
