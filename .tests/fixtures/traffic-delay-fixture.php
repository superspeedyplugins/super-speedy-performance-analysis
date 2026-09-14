<?php
/**
 * Plugin Name: SSPA Traffic Delay Fixture
 * Description: Test fixture. While the option sspa_traffic_delay_ms is above zero, every front-end request spends that many milliseconds in one measured SQL sleep, so a second traffic collection window can be compared against a first with a known, controlled performance change. Installed by case 89; retained on the test site.
 * Version: 1.0.0
 */
// Only on this plugin's isolated test sites: the default scenario `tests` and any `tests-*`.
if (!defined('ABSPATH') || !preg_match('/^tests(-|$)/', basename(rtrim(ABSPATH, '/')))) return;

add_action('template_redirect', static function () {
    if (is_admin()) {
        return;
    }
    $delay_ms = (int) get_option('sspa_traffic_delay_ms', 0);
    if ($delay_ms <= 0) {
        return;
    }
    global $wpdb;
    // A real query that takes the time, so both wall time and query count move by a known
    // amount and the change shows up in the origin page-generation figures.
    $wpdb->query($wpdb->prepare('SELECT SLEEP(%f)', $delay_ms / 1000));
}, 4);
