<?php
// The plugin creates one synthetic low-privilege account so a page can be measured the way a
// logged-in customer gets it, without touching a real customer's cart or session. That
// account must be usable by US and by nobody else.
//
// The distinction the hardening relies on, and what this test pins:
//
//   CREDENTIALS  -> the `authenticate` filter chain (login form, XML-RPC, app passwords,
//                   anything calling wp_signon). Refused.
//   COOKIE       -> determine_current_user / wp_validate_auth_cookie, which never runs
//                   `authenticate`. Still works, because that is how we measure.
//
// If a future change blocks the account by some blunter means - disabling the user, an
// impossible hash, a role with no capabilities - the last assertion here fails, because
// profiling as a customer would have stopped working.

function sspa_acct_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

$user_id = SSPA_Auth::test_customer_id();
sspa_acct_t($user_id > 0, 'the synthetic customer exists');
if (!$user_id) {
    return;
}

$user = get_userdata($user_id);
sspa_acct_t(SSPA_Auth::is_test_account($user_id), 'it is flagged as a measurement account');
sspa_acct_t(
    !user_can($user_id, 'manage_options') && !user_can($user_id, 'edit_posts'),
    'it is low privilege: it cannot manage options or edit posts'
);

// 1. The credential path, with the account resolved to a WP_User already.
$refused = apply_filters('authenticate', $user, $user->user_login, 'whatever');
sspa_acct_t(
    is_wp_error($refused) && 'sspa_test_account' === $refused->get_error_code(),
    'a resolved user object is refused by the authenticate chain'
);

// 2. The credential path from the top, exactly as the login form drives it. The username is
//    refused before any password comparison, so the account cannot be probed either.
$attempt = wp_authenticate($user->user_login, 'not-the-password');
sspa_acct_t(
    is_wp_error($attempt) && 'sspa_test_account' === $attempt->get_error_code(),
    'wp_authenticate() refuses the login by username'
);

$by_email = wp_authenticate($user->user_email, 'not-the-password');
sspa_acct_t(
    is_wp_error($by_email) && 'sspa_test_account' === $by_email->get_error_code(),
    'wp_authenticate() refuses the login by email address too'
);

// 3. No password reset, so nobody can mail themselves a way in.
sspa_acct_t(
    false === apply_filters('allow_password_reset', true, $user_id),
    'password reset is refused for the measurement account'
);

// 4. No application passwords, which would otherwise be a credential path around the above.
if (function_exists('wp_is_application_passwords_available_for_user')) {
    sspa_acct_t(
        !wp_is_application_passwords_available_for_user($user),
        'application passwords are unavailable for the measurement account'
    );
} else {
    sspa_acct_t(
        false === apply_filters('wp_is_application_passwords_available_for_user', true, $user),
        'application passwords are refused for the measurement account'
    );
}

// 5. An ordinary administrator is untouched by any of it.
$admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
if ($admins) {
    $admin = get_userdata((int) $admins[0]);
    $admin_result = apply_filters('authenticate', $admin, $admin->user_login, 'whatever');
    sspa_acct_t(
        $admin_result instanceof WP_User && (int) $admin_result->ID === (int) $admin->ID,
        'a real administrator still passes through the authenticate chain'
    );
    sspa_acct_t(
        true === apply_filters('allow_password_reset', true, (int) $admin->ID),
        'a real administrator can still reset their password'
    );
}

// 6. THE ONE THAT MATTERS: we can still measure as this account, because the cookie path
//    is a different path. If this fails, the hardening has broken profiling.
$cookies = SSPA_Auth::cookies_for('customer', 0);
sspa_acct_t(!empty($cookies[LOGGED_IN_COOKIE]), 'a logged-in cookie is still minted for the measurement account');
if (!empty($cookies[LOGGED_IN_COOKIE])) {
    $validated = wp_validate_auth_cookie($cookies[LOGGED_IN_COOKIE], 'logged_in');
    sspa_acct_t(
        (int) $validated === (int) $user_id,
        'the minted cookie still authenticates as the measurement account'
    );

    $parts = explode('|', $cookies[LOGGED_IN_COOKIE]);
    $expires = isset($parts[1]) ? (int) $parts[1] : 0;
    sspa_acct_t(
        $expires > time() && $expires <= time() + 600,
        'the cookie is short lived (expires in ' . max(0, $expires - time()) . 's)'
    );
}
