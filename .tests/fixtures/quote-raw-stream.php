<?php
/**
 * Plugin Name: SSPA regression - raw stream reads
 * Description: Test fixture only. On a front-end request carrying quote_raw_fixture, reads this
 * site's own static files through PHP's http:// stream wrapper, as plugins calling
 * file_get_contents() on a URL do. The URLs carry a query-string token, an email address and a
 * numeric identifier in the path, so the opt-in raw-stream diagnostic sees real private values.
 *
 *   ?quote_raw_fixture=1     one read
 *   ?quote_raw_fixture=bulk  1,700 reads (a page report over the receiver's 200 KB Markdown limit
 *                              and under its 256 KB body limit)
 *
 * The query argument is not sspa_-prefixed: the catalogue matcher strips sspa_* keys.
 */
defined('ABSPATH') || exit;

add_action('template_redirect', static function () {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- test fixture switch.
    $mode = isset($_GET['quote_raw_fixture']) ? sanitize_key(wp_unslash($_GET['quote_raw_fixture'])) : '';
    if ('' === $mode) {
        return;
    }
    // PHP's stream resolver does not resolve *.localhost, so read through 127.0.0.1 with this
    // site's Host header, as the actionable-summary fixture does.
    $path = (string) wp_parse_url(content_url('uploads/quote-raw/customer@example.com/98765/report.txt'), PHP_URL_PATH);
    $url = 'http://127.0.0.1:8081' . $path . '?token=QUERYSECRET123&email=someone%40example.com';
    $context = stream_context_create(array('http' => array('header' => 'Host: ' . wp_parse_url(home_url(), PHP_URL_HOST))));
    $reads = 'bulk' === $mode ? 1700 : 1;
    for ($i = 0; $i < $reads; $i++) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the raw stream read is what the diagnostic measures.
        file_get_contents($url, false, $context);
    }
}, 0);
