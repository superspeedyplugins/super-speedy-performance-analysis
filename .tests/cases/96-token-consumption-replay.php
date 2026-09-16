<?php
// Reading or discarding capture data must not make its signed request reusable.
defined('ABSPATH') || exit;
$failures = 0;
$check = function ($ok, $label) use (&$failures) {
    echo ($ok ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$ok) $failures++;
};
$installed = SSPA_Helper_Files::ensure_installed();
if (is_wp_error($installed)) throw new RuntimeException($installed->get_error_message());
$consume = new ReflectionMethod('SSPA_Crawler', 'fetch_capture');
$consume->setAccessible(true);
foreach (array('consume', 'discard') as $operation) {
    $url = add_query_arg('sspa_replay_case', $operation . '-' . wp_generate_uuid4(), home_url('/'));
    $token = SSPA_Token::mint($url, array('v' => 'anon', 'oc' => '1'));
    $request = function () use ($url, $token) {
        $response = wp_remote_get($url, array('timeout' => 30, 'headers' => array(SSPA_Token::HEADER => $token['header'])));
        if (is_wp_error($response)) throw new RuntimeException($response->get_error_message());
        return $response;
    };
    $first = $request();
    $check($token['id'] === wp_remote_retrieve_header($first, 'x-sspa-profiled'), "$operation: first signed request is profiled");
    $duplicate = $request();
    $check('1' === wp_remote_retrieve_header($duplicate, 'x-sspa-replay'), "$operation: duplicate is rejected before capture consumption");
    if ('consume' === $operation) {
        $capture = $consume->invoke(null, $token['id']);
        $check(is_array($capture) && !empty($capture['overview']), 'consume: real stored capture is returned');
    } else {
        SSPA_Crawler::discard_capture($token['id']);
    }
    $check(null === $consume->invoke(null, $token['id']), "$operation: capture itself has been removed");
    $replay = $request();
    $check('1' === wp_remote_retrieve_header($replay, 'x-sspa-replay') && '' === wp_remote_retrieve_header($replay, 'x-sspa-profiled'), "$operation: same signed URL and flags remain rejected afterwards");
    $check(false !== SSPA_Token::verify($token['header'], SSPA_Token::request_path($url)), "$operation: test ran before token expiry");
}
// A correctly signed expired token must fail independently of its used-token claim.
$url = add_query_arg('sspa_replay_case', 'expired', home_url('/'));
$expired = SSPA_Token::mint($url);
$parts = explode('.', $expired['header']);
$parts[1] = time() - 1;
$parts[3] = hash_hmac('sha256', $parts[0] . '|' . $parts[1] . '|' . $parts[2] . '|' . SSPA_Token::request_path($url), SSPA_Token::secret());
$response = wp_remote_get($url, array('timeout' => 30, 'headers' => array(SSPA_Token::HEADER => implode('.', $parts))));
$check(!is_wp_error($response) && '' === wp_remote_retrieve_header($response, 'x-sspa-profiled'), 'Correctly signed expired token cannot profile another request');
if ($failures) throw new RuntimeException($failures . ' token-consumption replay assertions failed');
