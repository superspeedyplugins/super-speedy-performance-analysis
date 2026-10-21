<?php
/**
 * Plugin Name: SSPA regression - quote receiver boundary
 * Description: Test fixture only, on the receiver site that runs the real Super Speedy Contact
 * code (frozen at its commit) so Performance Analysis's real request meets the real route.
 *
 * - The receiver has no mail server: wp_mail() is recorded in option sspa_test_receiver_mail
 *   and reported as sent, so the route's own notification path still runs.
 * - Option sspa_test_receiver_limits overrides rate-limit buckets through Contact's own
 *   ssp_contact_rate_limits filter, so a 429 is reached in two requests rather than eleven.
 * - Option sspa_test_receiver_fail_storage makes WordPress refuse to insert the lead post, the
 *   real storage failure the route answers with 503.
 * - All external HTTP is refused.
 */
defined('ABSPATH') || exit;
if (!preg_match('/^tests(-|$)/', basename(rtrim(ABSPATH, '/')))) {
    return;
}

add_filter('pre_wp_mail', static function ($short, $atts) {
    $log = get_option('sspa_test_receiver_mail', array());
    $log = is_array($log) ? $log : array();
    $log[] = array('to' => $atts['to'], 'subject' => $atts['subject'], 'bytes' => strlen((string) $atts['message']));
    update_option('sspa_test_receiver_mail', $log, false);
    return true;
}, 10, 2);

add_filter('ssp_contact_rate_limits', static function ($limits) {
    $override = get_option('sspa_test_receiver_limits');
    if (is_array($override)) {
        foreach ($override as $bucket => $rule) {
            $limits[$bucket] = $rule;
        }
    }
    return $limits;
});

add_filter('wp_insert_post_empty_content', static function ($empty, $postarr) {
    if (get_option('sspa_test_receiver_fail_storage') && isset($postarr['post_type']) && 'ssp_contact_lead' === $postarr['post_type']) {
        return true;
    }
    return $empty;
}, 10, 2);

add_filter('pre_http_request', static function ($pre, $args, $url) {
    if (false !== $pre) {
        return $pre;
    }
    $host = (string) wp_parse_url($url, PHP_URL_HOST);
    if ('localhost' === $host || '127.0.0.1' === $host || '.localhost' === substr($host, -10)) {
        return false;
    }
    return new WP_Error('sspa_regression_external_blocked', 'External HTTP is disabled on this regression receiver');
}, PHP_INT_MAX, 3);
