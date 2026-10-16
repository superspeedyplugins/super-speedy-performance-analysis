<?php
// Dedicated native site only. A real per-request hosts route sends the server's
// signed TLS connection to a local peer which resets the handshake. Browsers
// continue to address nginx normally. No pre_http_request error is fabricated.
add_action('http_api_curl', function ($handle, $args, $url) {
    $port = (int) get_option('sspa_summary_reset_port');
    if (!$port || wp_parse_url($url, PHP_URL_HOST) !== wp_parse_url(home_url(), PHP_URL_HOST)) return;
    $headers = array_change_key_case((array) $args['headers'], CASE_LOWER);
    if (empty($headers[strtolower(SSPA_Token::HEADER)])) return;
    $host = wp_parse_url($url, PHP_URL_HOST);
    curl_setopt($handle, CURLOPT_PROXY, '');
    curl_setopt($handle, CURLOPT_URL, 'https://' . $host . ':8081/');
    curl_setopt($handle, CURLOPT_CONNECT_TO, array($host . ':8081:127.0.0.1:' . $port));
}, 10, 3);


add_action('template_redirect', function () {
    if (!empty($_SERVER['HTTP_X_SSPA_TOKEN']) && get_option('sspa_summary_browser_deny') && is_page('summary-fixture')) {
        status_header(403);
        echo 'Dedicated browser target refusal';
        exit;
    }
}, PHP_INT_MIN);
