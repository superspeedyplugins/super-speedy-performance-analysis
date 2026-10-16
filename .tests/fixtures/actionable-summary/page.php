<?php
require __DIR__ . '/variance.php';
get_header();
while (have_posts()) {
    the_post();
    the_content();
}
// Deliberately outside a callback. Named template boundaries must locate this gap.
get_template_part('summary-slow');
load_template(__DIR__ . '/summary-slow.php', false);
do_action('sspa_fixture_render');
$raw = file_get_contents('http://127.0.0.1:8081/summary-slow-endpoint.php', false, stream_context_create(array('http' => array('header' => 'Host: ' . wp_parse_url(home_url(), PHP_URL_HOST)))));
echo '<p>' . esc_html($raw) . '</p>';
$until = hrtime(true) + 100000000;
$work = 0;
while (hrtime(true) < $until) {
    $work += sqrt(12345.6);
}
usleep(140000);
get_footer();
