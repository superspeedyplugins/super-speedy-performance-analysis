<?php
// Navigation must use the saved target without replaying workflow actions.
defined('ABSPATH') || exit;
function sspa_68_check($ok, $label) { echo ($ok ? 'PASS: ' : 'FAIL: ') . $label . "\n"; }
if (!method_exists('SSPA_Profile_Panel', 'measured_page_url')) {
    sspa_68_check(false, 'saved profiles provide a read-only measured-page target');
    return;
}
$row = array('page_key'=>'shop', 'method'=>'GET', 'url'=>home_url('/?post_type=product'));
sspa_68_check(SSPA_Profile_Panel::measured_page_url($row) === $row['url'], 'ordinary measured URL is retained exactly');
foreach (array(
    array('method'=>'POST'),
    array('page_key'=>'flow-refund-order'),
    array('page_key'=>'mail-probe'),
    array('url'=>'javascript:alert(1)'),
    array('url'=>home_url('/?action=delete&post=123&_wpnonce=example')),
    array('url'=>home_url('/?add-to-cart=123')),
    array('url'=>home_url('/?sspa_flow_probe=1')),
) as $change) {
    sspa_68_check('' === SSPA_Profile_Panel::measured_page_url(array_merge($row,$change)), 'unsafe/action-only target has no replay link: '.wp_json_encode($change));
}
$edit = array_merge($row,array('url'=>admin_url('post.php?post=123&action=edit')));
sspa_68_check(SSPA_Profile_Panel::measured_page_url($edit) === $edit['url'], 'read-only editor navigation remains available');

$profile_id = SSPA_Profile_Panel::newest_profile_id_for_page('home');
sspa_68_check($profile_id > 0, 'a real retained home profile exists for endpoint checks');
if ($profile_id) {
    $subscriber = get_user_by('login', 'sspa-target-reader-test');
    $reader_id = $subscriber ? $subscriber->ID : wp_insert_user(array('user_login'=>'sspa-target-reader-test', 'user_pass'=>wp_generate_password(32), 'role'=>'subscriber'));
    if (is_wp_error($reader_id)) { sspa_68_check(false, 'subscriber fixture can be created'); return; }
    foreach (array(1, (int)$reader_id) as $user_id) {
        wp_set_current_user($user_id);
        $expiry = time() + 600;
        $token = WP_Session_Tokens::get_instance($user_id)->create($expiry);
        $cookies = array(LOGGED_IN_COOKIE=>wp_generate_auth_cookie($user_id,$expiry,'logged_in',$token), AUTH_COOKIE=>wp_generate_auth_cookie($user_id,$expiry,'auth',$token));
        $old_cookie = $_COOKIE[LOGGED_IN_COOKIE] ?? null;
        $_COOKIE[LOGGED_IN_COOKIE] = $cookies[LOGGED_IN_COOKIE];
        $nonce = wp_create_nonce('sspa_admin');
        if (null === $old_cookie) { unset($_COOKIE[LOGGED_IN_COOKIE]); } else { $_COOKIE[LOGGED_IN_COOKIE] = $old_cookie; }
        foreach (array('valid', 'bad_nonce', 'missing') as $mode) {
            $response = wp_remote_post(admin_url('admin-ajax.php'),array('timeout'=>30, 'cookies'=>$cookies, 'body'=>array(
                'action'=>'sspa_profile_target', 'profile_id'=>'missing' === $mode ? PHP_INT_MAX : $profile_id,
                'nonce'=>'bad_nonce' === $mode ? 'invalid-fixture' : $nonce,
            )));
            $expected = 1 !== $user_id || 'bad_nonce' === $mode ? 403 : ('missing' === $mode ? 404 : 200);
            sspa_68_check(!is_wp_error($response) && wp_remote_retrieve_response_code($response) === $expected, 'target endpoint HTTP '.$expected.' for '.(1 === $user_id ? 'admin ' : 'subscriber ').$mode);
            if (200 === $expected && !is_wp_error($response)) {
                $data = json_decode(wp_remote_retrieve_body($response),true);
                sspa_68_check(!empty($data['success']) && ($data['data']['url'] ?? null) === SSPA_Profile_Panel::measured_page_url(SSPA_Profile_Panel::profile_row($profile_id)), 'endpoint returns the exact saved profile target');
            }
        }
    }
    wp_set_current_user(1);
}
