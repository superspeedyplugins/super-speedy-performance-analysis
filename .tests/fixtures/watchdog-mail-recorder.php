<?php
/**
 * Plugin Name: SSPA test - watchdog mail recorder
 * Description: Test-only. Records every wp_mail call (recipients, subject, body, headers) after
 * every wp_mail filter in the sspa_test_watchdog_mail option, then lets the mail continue to the
 * test site's local SMTP sink. Installed by .tests/watchdog/run.sh on its own site only.
 */
add_filter('pre_wp_mail', static function ($short, $atts) {
    $log = get_option('sspa_test_watchdog_mail', array());
    $log = is_array($log) ? $log : array();
    $headers = is_array($atts['headers']) ? implode("\n", $atts['headers']) : (string) $atts['headers'];
    $log[] = array(
        'to' => (array) $atts['to'],
        'subject' => (string) $atts['subject'],
        'message' => (string) $atts['message'],
        'headers' => $headers,
        'at' => time(),
    );
    update_option('sspa_test_watchdog_mail', $log, false);
    return $short;
}, PHP_INT_MAX, 2);
