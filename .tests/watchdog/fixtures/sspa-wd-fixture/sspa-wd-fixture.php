<?php
/**
 * Plugin Name: SSPA Watchdog Fixture
 * Version: __VERSION__
 * Description: Test-only plugin for the update watchdog journey (.tests/watchdog/run.sh). Built into
 * zips of two versions by that script; __MODE__ decides what the version does to the shop page.
 */
defined('ABSPATH') || exit;

add_action('template_redirect', static function () {
    if (!function_exists('is_shop') || !is_shop() || is_admin()) {
        return;
    }
    $mode = '__MODE__';
    if ('slow' === $mode) {
        // A genuinely slower shop page: every request waits 300 ms.
        usleep(300000);
    } elseif ('noisy' === $mode && !is_user_logged_in()) {
        // One noisy measurement: only the first sspa_wd_noisy_remaining shop requests wait, counted
        // down in the database (not the object cache) so each request sees the previous one.
        global $wpdb;
        $taken = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = option_value - 1 WHERE option_name = %s AND CAST(option_value AS SIGNED) > 0",
            'sspa_wd_noisy_remaining'
        ));
        if (1 === (int) $taken) {
            usleep(400000);
        }
    }
});
