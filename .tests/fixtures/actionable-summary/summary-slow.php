<?php
usleep(160000);
echo '<p>Direct template part: 160 ms</p>';

if (isset($_GET['summary_nested'])) {
    do_action('sspa_fixture_template_callback');
    load_template(__DIR__ . '/summary-child.php', false);
}

if (isset($_GET['summary_nested_stream'])) {
    echo esc_html(file_get_contents('http://127.0.0.1:8081/summary-slow-endpoint.php?delay=180&secret=fixture-only', false, stream_context_create(array('http' => array('header' => 'Host: ' . wp_parse_url(home_url(), PHP_URL_HOST))))));
}
