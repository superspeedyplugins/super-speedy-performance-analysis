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
     * A synthetic measurement account (the meta-flagged test customer) must never be usable
     * by a person: every way of signing in with credentials - the login form, XML-RPC,
     * application passwords - is refused below, as is a password reset. The account is only
     * ever measured as through a short-lived measurement cookie, so refusing credentials
     * costs nothing and leaves no way in for anyone else.
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
        // SSPA-PRO-START
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
        // SSPA-PRO-END
        return 'admin' === $variant ? self::session_cookies_for($user_id) : array();
    }

    /**
     * The administrator's OWN login cookies, taken from the request this code is running in.
     *
     * The wordpress.org edition never creates a login: an administrator measurement rides on
     * the session of the administrator whose browser is driving the run, exactly as WordPress
     * core's Site Health loopback test and the plugin and theme editors' loopback check pass
     * the current request's cookies to their own loopback requests. Only the three WordPress
     * login cookies are passed on, and only when they are valid for $user_id, so a run never
     * measures as anyone else. A request with no browser behind it (WP-Cron, WP-CLI) has no
     * session, and the caller leaves those measurements for a request that does.
     *
     * @return array cookie name => value, or array() when this request carries no valid login for $user_id.
     */
    public static function session_cookies_for($user_id) {
        $user_id = (int) $user_id;
        if ($user_id <= 0 || !defined('LOGGED_IN_COOKIE') || empty($_COOKIE[LOGGED_IN_COOKIE]) || !is_string($_COOKIE[LOGGED_IN_COOKIE])) {
            return array();
        }
        // A WordPress login cookie is username|expiration|token|hmac; sanitising cannot change a
        // valid one, and one it did change fails the validation below.
        $logged_in = sanitize_text_field(wp_unslash($_COOKIE[LOGGED_IN_COOKIE]));
        if ((int) wp_validate_auth_cookie($logged_in, 'logged_in') !== $user_id) {
            return array();
        }
        $cookies = array(LOGGED_IN_COOKIE => $logged_in);
        foreach (array(AUTH_COOKIE => 'auth', SECURE_AUTH_COOKIE => 'secure_auth') as $name => $scheme) {
            if (!empty($_COOKIE[$name]) && is_string($_COOKIE[$name])) {
                $value = sanitize_text_field(wp_unslash($_COOKIE[$name]));
                if ((int) wp_validate_auth_cookie($value, $scheme) === $user_id) {
                    $cookies[$name] = $value;
                }
            }
        }
        return $cookies;
    }

    /**
     * Whether a queued job measures as the administrator and so needs their login.
     *
     * The wordpress.org edition can only use the driving administrator's own session
     * (session_cookies_for()), so a batch with no browser behind it must leave such a job for
     * the next batch the Performance Analysis screen drives. The full edition mints its own
     * five-minute login and never waits.
     */
    public static function job_waits_for_session($job, $user_id) {
        // SSPA-PRO-START
        return false;
        // SSPA-PRO-END
        $needs = (isset($job['variant']) && 'admin' === $job['variant']) || !empty($job['checkout']);
        return $needs && !self::session_cookies_for($user_id);
    }

    /**
     * The X-WP-Nonce a REST request sent with these login cookies needs. WordPress REST treats
     * cookie authentication without a nonce as a logged-out request (rest_cookie_check_errors),
     * so an endpoint requiring a login answers 401 to cookies alone. wp_create_nonce() binds to
     * the current user and the session token in the logged-in cookie, so both are switched to the
     * cookie's own for the call and restored after it.
     *
     * @return string The nonce, or '' when the cookies carry no valid login.
     */
    public static function rest_nonce_for($cookies) {
        if (empty($cookies[LOGGED_IN_COOKIE])) {
            return '';
        }
        $user_id = wp_validate_auth_cookie($cookies[LOGGED_IN_COOKIE], 'logged_in');
        if (!$user_id) {
            return '';
        }
        // SSPA-PRO-START
        $saved_cookies = $_COOKIE;
        $current_user = get_current_user_id();
        try {
            $_COOKIE[LOGGED_IN_COOKIE] = $cookies[LOGGED_IN_COOKIE];
            wp_set_current_user($user_id);
            $nonce = wp_create_nonce('wp_rest');
        } finally {
            wp_set_current_user($current_user);
            $_COOKIE = $saved_cookies;
        }
        return $nonce;
        // SSPA-PRO-END
        // The wordpress.org edition's cookies are always this request's own login
        // (session_cookies_for()), so the nonce is simply the current user's.
        $own = isset($_COOKIE[LOGGED_IN_COOKIE]) && is_string($_COOKIE[LOGGED_IN_COOKIE]) && sanitize_text_field(wp_unslash($_COOKIE[LOGGED_IN_COOKIE])) === $cookies[LOGGED_IN_COOKIE];
        return ($own && (int) $user_id === get_current_user_id()) ? wp_create_nonce('wp_rest') : '';
    }

    /**
     * The marked synthetic customer's id, or 0 when none exists. Read-only: it never
     * creates, edits or deletes a user, so it is safe on any request, including page
     * rendering. The full edition creates the account only on the explicit way in to an
     * analysis or a cache check.
     */
    public static function test_customer_id() {
        $existing = get_users(array(
            'meta_key' => self::TEST_ACCOUNT_META,
            'meta_value' => '1',
            'number' => 1,
            'fields' => 'ID',
        ));
        return $existing ? (int) $existing[0] : 0;
    }

    // SSPA-PRO-START
    /**
     * The synthetic customer, created or brought to its administrator-plus address when
     * needed. Called only on the way in to work that measures as that customer.
     *
     * @return int|WP_Error The account id, or WordPress's own reason it could not be saved.
     */
    public static function ensure_test_customer() {
        return SSPA_Mail::with_admin(static function() { return self::create_customer(); });
    }

    private static function create_customer() {
        try {
            $email = SSPA_Mail::address('customer');
        } catch (RuntimeException $e) {
            return new WP_Error('sspa_test_customer_email', $e->getMessage());
        }
        $existing = self::test_customer_id();
        if ($existing) {
            if (get_userdata($existing)->user_email !== $email) {
                $updated = wp_update_user(array('ID' => $existing, 'user_email' => $email));
                if (is_wp_error($updated)) {
                    return self::customer_error($updated);
                }
            }
            return $existing;
        }
        $role = get_role('customer') ? 'customer' : 'subscriber';
        $user_id = wp_insert_user(array(
            'user_login' => 'sspa-test-customer',
            'user_pass' => wp_generate_password(32),
            'user_email' => $email,
            'role' => $role,
            'display_name' => 'SSPA Test Customer',
        ));
        if (is_wp_error($user_id)) {
            return self::customer_error($user_id);
        }
        update_user_meta($user_id, self::TEST_ACCOUNT_META, '1');
        return (int) $user_id;
    }

    private static function customer_error(WP_Error $error) {
        return new WP_Error(
            'sspa_test_customer_unavailable',
            sprintf(
                /* translators: %s: WordPress's reason the user could not be saved. */
                __('The synthetic test customer account could not be saved: %s', 'super-speedy-performance-analysis'),
                wp_strip_all_tags($error->get_error_message())
            )
        );
    }

    /**
     * A new synthetic customer for this run: every marked account is removed and one is
     * created. Called on the way IN to a run, never on the way out, so the account a run
     * measured as stays inspectable until the next run replaces it. The account owns
     * nothing, so nothing is reassigned.
     *
     * @return int|WP_Error The fresh account's id, or why it could not be created.
     */
    public static function fresh_test_customer() {
        return SSPA_Mail::with_admin(static function() { return self::replace_customer(); });
    }

    private static function replace_customer() {
        foreach (get_users(array('meta_key' => self::TEST_ACCOUNT_META, 'meta_value' => '1', 'fields' => 'ID')) as $stale) {
            if (!function_exists('wp_delete_user')) { require_once SSPA_CORE_DIR . 'wp-admin/includes/user.php'; }
            wp_delete_user((int) $stale);
        }
        return self::create_customer();
    }
    // SSPA-PRO-END
}
