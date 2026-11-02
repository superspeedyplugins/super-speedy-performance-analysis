<?php
/**
 * Plugin Name: zz-click-cookie (test fixture)
 * Description: Mimics a tracker that sets _fbc server-side only when the URL carries fbclid. Test probes: zz_include (set during the plugin include) and zz_untimed (set on parse_request).
 * Version: 2.3.4
 */
defined('ABSPATH') || exit;

add_action('init', function () {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- test fixture reading a public click identifier.
    if (is_admin() || empty($_GET['fbclid'])) {
        return;
    }
    setcookie('_fbc', 'fb.1.' . time() . '.' . sanitize_text_field(wp_unslash($_GET['fbclid'])), time() + 7776000, '/');
});

// Attribution probes, only when a test asks for them: a cookie set while this plugin's file is
// included, and one set on parse_request, a hook the profiler does not time.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- test fixture switch.
if (!empty($_GET['zz_include'])) {
    setcookie('zz_include', '1', 0, '/');
}
add_action('parse_request', function () {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- test fixture switch.
    if (!empty($_GET['zz_untimed'])) {
        setcookie('zz_untimed', '1', 0, '/');
    }
});
