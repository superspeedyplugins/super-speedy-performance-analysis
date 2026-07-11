<?php
defined('ABSPATH') || exit;

/**
 * Auth cookies for loopback requests. Admin variant uses the run-initiating admin's own
 * account (no temporary admin users - Dave's rule). The customer variant (phase 2+) uses a
 * flagged low-privilege test account so real customers' carts/sessions are never touched.
 */
class SSPA_Auth {

    /**
     * @return array cookie name => value, for wp_remote_get's 'cookies' arg.
     */
    public static function cookies_for($variant, $user_id) {
        if ('anon' === $variant) {
            return array();
        }
        if ('customer' === $variant) {
            $user_id = self::test_customer_id();
        }
        if (!$user_id || !get_userdata($user_id)) {
            return array();
        }
        $expiry = time() + 300;
        $scheme_secure = force_ssl_admin() || 'https' === parse_url(home_url(), PHP_URL_SCHEME);
        $cookies = array(
            LOGGED_IN_COOKIE => wp_generate_auth_cookie($user_id, $expiry, 'logged_in'),
        );
        if ($scheme_secure) {
            $cookies[SECURE_AUTH_COOKIE] = wp_generate_auth_cookie($user_id, $expiry, 'secure_auth');
        } else {
            $cookies[AUTH_COOKIE] = wp_generate_auth_cookie($user_id, $expiry, 'auth');
        }
        return $cookies;
    }

    public static function test_customer_id() {
        $existing = get_users(array(
            'meta_key' => 'sspa_test_account',
            'meta_value' => '1',
            'number' => 1,
            'fields' => 'ID',
        ));
        if ($existing) {
            return (int) $existing[0];
        }
        $role = get_role('customer') ? 'customer' : 'subscriber';
        $user_id = wp_insert_user(array(
            'user_login' => 'sspa-test-customer',
            'user_pass' => wp_generate_password(32),
            'user_email' => 'sspa-test-customer@' . parse_url(home_url(), PHP_URL_HOST),
            'role' => $role,
            'display_name' => 'SSPA Test Customer',
        ));
        if (is_wp_error($user_id)) {
            return 0;
        }
        update_user_meta($user_id, 'sspa_test_account', '1');
        return (int) $user_id;
    }
}
