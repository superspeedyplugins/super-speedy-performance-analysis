<?php
/**
 * Plugin Name: SSPA Home Query Fixture
 * Description: Test fixture. Runs four known, cheap, distinct SQL queries on every front-page request so a profile of Home provably captured real queries whatever the object-cache state. Installed by case 05; retained on the test site.
 * Version: 1.0.0
 */
// Only on this plugin's isolated test sites: the default scenario `tests` and any `tests-*`.
if (!defined('ABSPATH') || !preg_match('/^tests(-|$)/', basename(rtrim(ABSPATH, '/')))) return;

add_action('template_redirect', static function () {
    if (!function_exists('is_front_page') || !is_front_page()) {
        return;
    }
    global $wpdb;
    // Four different statement shapes, so they fingerprint to four different keys, each
    // carrying the marker as a literal so the raw text can be found in the capture. All
    // direct $wpdb calls: nothing here is answered by the object cache.
    $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'synthetic_home_query_fixture_option'");
    $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title LIKE 'synthetic_home_query_fixture%'");
    $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'synthetic_home_query_fixture' LIMIT 1");
    $wpdb->get_var("SELECT 'synthetic_home_query_fixture' AS marker FROM {$wpdb->users} LIMIT 1");
}, 5);
