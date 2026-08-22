<?php
defined('ABSPATH') || exit;

/**
 * Auth cookies for loopback requests. Admin variant uses the run-initiating admin's own
 * account (no temporary admin users - Dave's rule). The customer variant (phase 2+) uses a
 * flagged low-privilege test account so real customers' carts/sessions are never touched.
 */
class SSPA_Auth {

    const TEST_ACCOUNT_META = 'sspa_test_account';

    /**
     * The synthetic accounts we measure as must never be usable by a person.
     *
     * This works because WordPress separates the two ways of becoming a user:
     *
     *   - CREDENTIALS go through the `authenticate` filter - the login form, XML-RPC,
     *     application passwords, anything calling wp_signon(). All refused below.
     *   - A COOKIE goes through `determine_current_user` and wp_validate_auth_cookie,
     *     which never touches `authenticate`.
     *
     * Our profiling requests use the cookie path (cookies_for() mints a five-minute one),
     * so blocking the credential path costs us nothing and leaves no way in for anyone
     * else - even if the 32-character password we never stored were somehow recovered.
     */
    public static function register() {
        add_filter('authenticate', array(__CLASS__, 'refuse_interactive_login'), 30, 3);
        add_filter('allow_password_reset', array(__CLASS__, 'refuse_password_reset'), 10, 2);
        add_filter('wp_is_application_passwords_available_for_user', array(__CLASS__, 'refuse_application_passwords'), 10, 2);
    }

    /** Is this a synthetic account this plugin created for measurement? */
    public static function is_test_account($user_id) {
        $user_id = (int) $user_id;
        return $user_id > 0 && '1' === (string) get_user_meta($user_id, self::TEST_ACCOUNT_META, true);
    }

    public static function refuse_interactive_login($user, $username = '', $password = '') {
        if ($user instanceof WP_User && self::is_test_account($user->ID)) {
            return new WP_Error(
                'sspa_test_account',
                __('This account exists only for performance measurement and cannot be logged into.', 'super-speedy-performance-analysis')
            );
        }
        // A username that resolves to the test account is refused before any password is
        // checked, so the account cannot be probed for a valid password either.
        if (is_string($username) && '' !== $username) {
            $named = get_user_by('login', $username);
            if (!$named) {
                $named = get_user_by('email', $username);
            }
            if ($named && self::is_test_account($named->ID)) {
                return new WP_Error(
                    'sspa_test_account',
                    __('This account exists only for performance measurement and cannot be logged into.', 'super-speedy-performance-analysis')
                );
            }
        }
        return $user;
    }

    public static function refuse_password_reset($allow, $user_id) {
        return self::is_test_account($user_id) ? false : $allow;
    }

    public static function refuse_application_passwords($available, $user = null) {
        if ($user instanceof WP_User && self::is_test_account($user->ID)) {
            return false;
        }
        return $available;
    }

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
        $scheme_secure = force_ssl_admin() || 'https' === wp_parse_url(home_url(), PHP_URL_SCHEME);
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
            'meta_key' => self::TEST_ACCOUNT_META,
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
            'user_email' => 'sspa-test-customer@' . wp_parse_url(home_url(), PHP_URL_HOST),
            'role' => $role,
            'display_name' => 'SSPA Test Customer',
        ));
        if (is_wp_error($user_id)) {
            return 0;
        }
        update_user_meta($user_id, self::TEST_ACCOUNT_META, '1');
        return (int) $user_id;
    }
}
