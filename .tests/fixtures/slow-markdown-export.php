<?php
/**
 * Plugin Name: SSPA test - slow Markdown export
 * Description: Test-only environment condition for .tests/browser/copy-markdown.e2e.cjs.
 *
 * A customer's large page capture took longer than the browser's five-second user-activation
 * window to export, and Copy Markdown then failed. This reproduces that duration without
 * changing what is exported: with option sspa_test_slow_markdown_export = "delay" it sleeps
 * before the REAL sspa_markdown_export handler runs, and the real handler answers. With
 * "fatal" it raises a PHP fatal error before the handler, so the browser sees the HTTP 500 a
 * crashing server gives. Any other value (or none) does nothing. Never shipped: .tests is
 * stripped from every build.
 */
defined('ABSPATH') || exit;

add_action('wp_ajax_sspa_markdown_export', function () {
    $mode = (string) get_option('sspa_test_slow_markdown_export', '');
    if ('delay' === $mode) {
        sleep(max(1, (int) get_option('sspa_test_slow_markdown_export_seconds', 7)));
    } elseif ('fatal' === $mode) {
        throw new Error('SSPA test: simulated fatal error before the Markdown export');
    }
}, 0);
