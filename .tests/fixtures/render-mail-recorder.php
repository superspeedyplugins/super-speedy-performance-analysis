<?php
/**
 * Plugin Name: SSPA test - render mail recorder
 * Description: Test-only. Records the recipients every wp_mail call ends with (after every
 * wp_mail filter, PA's included) in the sspa_test_render_mail option, and does not send.
 * Installed by case 147 on its own site only.
 */
add_filter('pre_wp_mail', static function($short, $atts) {
    if (!get_option('sspa_test_render_mail_armed')) {
        return $short;
    }
    $log = get_option('sspa_test_render_mail', array());
    $log[] = array('to' => (array) $atts['to'], 'subject' => (string) $atts['subject']);
    update_option('sspa_test_render_mail', $log, false);
    return true;
}, PHP_INT_MAX, 2);
