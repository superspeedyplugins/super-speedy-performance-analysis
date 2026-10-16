<?php
/**
 * Plugin Name: SSPA render fixture
 * Version: 1.0.0
 * License: GPL-3.0-or-later
 */
function sspa_fixture_slow_shortcode() {
    usleep(120000);
    if (isset($_GET['summary_nested_stream'])) {
        file_get_contents('http://127.0.0.1:8081/summary-slow-endpoint.php?delay=180&secret=fixture-only', false, stream_context_create(array('http' => array('header' => 'Host: ' . wp_parse_url(home_url(), PHP_URL_HOST)))));
    }
    return '<p>Slow shortcode: 120 ms</p>';
}
add_shortcode('sspa_slow', 'sspa_fixture_slow_shortcode');
function sspa_fixture_custom_render() {
    usleep(110000);
}
add_action('sspa_fixture_render', 'sspa_fixture_custom_render');
add_filter('template_include', function ($template) {
    return is_page('summary-fixture') ? __DIR__ . '/page.php' : $template;
}, 999);

add_action('sspa_fixture_template_callback', function () { usleep(30000); });
