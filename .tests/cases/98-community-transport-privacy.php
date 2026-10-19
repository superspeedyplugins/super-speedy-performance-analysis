<?php
// Reproduced: WordPress's default User-Agent sent home_url with anonymised evidence.
// Capture the real transport arguments and stop before any network operation.
$requests = array();
$collector = static function () { return 'https://collector.example.org'; };
$secret = static function () { return str_repeat('a', 64); };
$intercept = static function ($pre, $args, $url) use (&$requests) {
    $requests[] = $args;
    return new WP_Error('sspa_transport_privacy_test', 'No external transport during the privacy regression.');
};
add_filter('pre_option_sspa_collector_url', $collector);
add_filter('pre_option_sspa_collector_secret_' . md5('https://collector.example.org'), $secret);
add_filter('pre_http_request', $intercept, PHP_INT_MAX, 3);
try {
    SSPA_Community_Client::register(true);
    $upload = new ReflectionMethod('SSPA_Community_Client', 'upload');
    $upload->setAccessible(true);
    $unapproved = $upload->invoke(null, array('payload_gzip' => 'test', 'compressed_bytes' => 4), array(
        'upload_url' => 'https://93.184.215.14/sspa-privacy-test',
        'required_headers' => array('Content-Type' => 'application/gzip'),
    ));
    $unapproved_blocked = count($requests) === 1;
    $upload->invoke(null, array('payload_gzip' => 'test', 'compressed_bytes' => 4), array('upload_url' => 'https://00000000000000000000000000000000.r2.cloudflarestorage.com/sspa-privacy-test', 'required_headers' => array('Content-Type' => 'application/gzip')));
    $before_insecure_upload = count($requests);
    $insecure_upload = $upload->invoke(null, array('payload_gzip' => 'test', 'compressed_bytes' => 4), array(
        'upload_url' => 'http://93.184.215.14/sspa-privacy-test',
        'required_headers' => array('Content-Type' => 'application/gzip'),
    ));
    $insecure_upload_blocked = count($requests) === $before_insecure_upload;
} finally {
    remove_filter('pre_http_request', $intercept, PHP_INT_MAX);
    remove_filter('pre_option_sspa_collector_url', $collector);
    remove_filter('pre_option_sspa_collector_secret_' . md5('https://collector.example.org'), $secret);
}
$failures = 0;
$check = static function ($ok, $label) use (&$failures) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
    if (!$ok) { ++$failures; }
};
$check($unapproved_blocked && is_wp_error($unapproved) && 'sspa_invalid_upload_authorisation' === $unapproved->get_error_code(), 'unapproved HTTPS upload host is refused before transport');
$check(2 === count($requests), 'registration and direct upload reached the real intercepted HTTP boundary');
$check($insecure_upload_blocked, 'plaintext upload authorisation sends no HTTP request');
$check(is_wp_error($insecure_upload) && 'sspa_invalid_upload_authorisation' === $insecure_upload->get_error_code(), 'plaintext upload authorisation is rejected with the real validation error');
foreach ($requests as $args) {
    $check(false === strpos(wp_json_encode($args, JSON_UNESCAPED_SLASHES), home_url()), $args['method'] . ' transport arguments exclude the site URL');
    $check('Super Speedy Performance Analysis/' . SSPA_VERSION === $args['user-agent'], $args['method'] . ' identifies the plugin without identifying its site');
}
if ($failures) { throw new RuntimeException($failures . ' community transport privacy assertions failed'); }
