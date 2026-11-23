<?php
/**
 * Plugin Name: SSPA test fixture - one-file report
 * Description: Test-only MU plugin for case 166. On Home, the Shop and a single post it runs one
 * real 55 ms statement five times, so the analysis raises a slow-query finding and a duplicate-
 * query finding with the same SQL fingerprint. Installed only on the retained
 * tests-one-file-report site. Armed only while option sspa_166_sql_fixture is 1, so the case's
 * CPU-bound spot run of Home is not slowed by it.
 */
defined('ABSPATH') || exit;
if ('tests-one-file-report' !== basename(rtrim(ABSPATH, '/'))) {
    return;
}
add_action('template_redirect', static function () {
    $shop = function_exists('is_shop') && is_shop();
    if (!get_option('sspa_166_sql_fixture') || is_admin() || !(is_front_page() || $shop || is_singular('post'))) {
        return;
    }
    global $wpdb;
    for ($i = 0; $i < 5; $i++) {
        $wpdb->get_var('SELECT SLEEP(0.055) AS sspa_ofr_marker');
    }
}, 5);
