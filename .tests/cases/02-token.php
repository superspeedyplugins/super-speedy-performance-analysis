<?php
function sspa_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

$url = home_url('/shop/?a=1&b=2');
$token = SSPA_Token::mint($url, array('v' => 'admin', 'oc' => '0'));
$path = SSPA_Token::request_path($url);

$ok = SSPA_Token::verify($token['header'], $path);
sspa_t(is_array($ok) && $ok['id'] === $token['id'], 'valid token verifies');
sspa_t(is_array($ok) && $ok['flags'] === array('v' => 'admin', 'oc' => '0'), 'flags roundtrip');

sspa_t(SSPA_Token::verify($token['header'], '/other-path/') === false, 'wrong path rejected');

$tampered = preg_replace('/[a-f0-9]{4}$/', 'ffff', $token['header']);
sspa_t(SSPA_Token::verify($tampered, $path) === false, 'tampered signature rejected');

$parts = explode('.', $token['header']);
$parts[1] = time() - 10;
sspa_t(SSPA_Token::verify(implode('.', $parts), $path) === false, 'expired token rejected');

$no_flags = SSPA_Token::mint($url, array());
$v = SSPA_Token::verify($no_flags['header'], $path);
sspa_t(is_array($v) && $v['flags'] === array(), 'empty flags roundtrip');
