<?php
// The rules feed has no live source: superspeedy.org returns rest_no_route for
// ?rest_route=/ssph/v1/rules. Only a SUCCESSFUL fetch writes the cache transient, so without
// a failure backoff the hourly cleanup cron re-requests a dead endpoint every hour forever.
// This proves a failed fetch stops the next one from going out at all.

function sspa_feed_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

$old_cache = get_transient(SSPA_Rules_Feed::CACHE_KEY);
delete_transient(SSPA_Rules_Feed::CACHE_KEY);
delete_transient(SSPA_Rules_Feed::BACKOFF_KEY);

// Stand in for the real hub's 404 without leaving the container.
$sspa_calls = 0;
$sspa_intercept = function ($pre, $args, $url) use (&$sspa_calls) {
    $sspa_calls++;
    return array(
        'headers' => array(),
        'response' => array('code' => 404, 'message' => 'Not Found'),
        'body' => '{"code":"rest_no_route","message":"No route was found matching the URL and request method.","data":{"status":404}}',
        'cookies' => array(),
        'filename' => null,
    );
};
add_filter('pre_http_request', $sspa_intercept, 10, 3);

try {
    $first = SSPA_Rules_Feed::refresh();
    sspa_feed_t(is_wp_error($first) && 'sspa_feed_invalid' === $first->get_error_code(), 'a 404 feed response is a malformed-feed error (' . (is_wp_error($first) ? $first->get_error_code() : 'not an error') . ')');
    sspa_feed_t(1 === $sspa_calls, 'the first refresh made exactly one request (' . $sspa_calls . ')');
    sspa_feed_t(false !== get_transient(SSPA_Rules_Feed::BACKOFF_KEY), 'the failure recorded a backoff');
    sspa_feed_t(false === get_transient(SSPA_Rules_Feed::CACHE_KEY), 'a failed fetch caches no rules');

    // The whole point: the next cleanup pass must not reach the network at all.
    $second = SSPA_Rules_Feed::refresh();
    sspa_feed_t(is_wp_error($second) && 'sspa_feed_backoff' === $second->get_error_code(), 'the second refresh reports the backoff (' . (is_wp_error($second) ? $second->get_error_code() : 'not an error') . ')');
    sspa_feed_t(1 === $sspa_calls, 'the second refresh made NO request (' . $sspa_calls . ' total)');

    // A recovered feed must clear the backoff rather than stay suppressed for 12 hours.
    delete_transient(SSPA_Rules_Feed::BACKOFF_KEY);
    remove_filter('pre_http_request', $sspa_intercept, 10);
    $sspa_signed = function ($pre, $args, $url) {
        return array(
            'headers' => array(),
            'response' => array('code' => 200, 'message' => 'OK'),
            'body' => wp_json_encode(array('version' => 1, 'rules' => array('demo' => true), 'signature' => 'not-a-real-signature')),
            'cookies' => array(),
            'filename' => null,
        );
    };
    add_filter('pre_http_request', $sspa_signed, 10, 3);
    $third = SSPA_Rules_Feed::refresh();
    // The bundled public key cannot verify a made-up signature, so this must still be rejected -
    // an unverified feed is exactly the attack the signature exists to stop.
    sspa_feed_t(is_wp_error($third) && 'sspa_feed_unverified' === $third->get_error_code(), 'an unsigned feed is rejected, not trusted (' . (is_wp_error($third) ? $third->get_error_code() : 'not an error') . ')');
    sspa_feed_t(false === get_transient(SSPA_Rules_Feed::CACHE_KEY), 'an unverified feed is never cached');
    remove_filter('pre_http_request', $sspa_signed, 10);
} finally {
    remove_filter('pre_http_request', $sspa_intercept, 10);
    delete_transient(SSPA_Rules_Feed::BACKOFF_KEY);
    if (is_array($old_cache)) {
        set_transient(SSPA_Rules_Feed::CACHE_KEY, $old_cache, DAY_IN_SECONDS);
    } else {
        delete_transient(SSPA_Rules_Feed::CACHE_KEY);
    }
}
