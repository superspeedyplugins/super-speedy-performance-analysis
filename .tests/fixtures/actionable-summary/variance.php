<?php
// Real requests deliberately put the greatest query count and HTTP duration in
// the generation-median sample. Independent metric medians cannot reconcile it.
if (get_option('sspa_summary_first_cost', false) && !empty($_SERVER['HTTP_X_SSPA_TOKEN'])) {
    $counter = ABSPATH . 'summary-sample-counter';
    $n = (int) file_get_contents($counter);
    file_put_contents($counter, (string) ($n + 1));
    if (0 === $n) usleep(2000000);
}
if (get_option('sspa_summary_variance', false) && !empty($_SERVER['HTTP_X_SSPA_TOKEN'])) {
    $counter = ABSPATH . 'summary-sample-counter';
    $n = (int) file_get_contents($counter);
    file_put_contents($counter, (string) ($n + 1));
    $slot = $n % 4; // warm-up, then three measurements
    $query_counts = array(1, 12, 1, 5);
    $delay = array(20, 250, 100, 20);
    $sleep = array(0, 500000, 1000000, 0);
    global $wpdb;
    for ($i = 0; $i < $query_counts[$slot]; $i++) $wpdb->get_var('SELECT 42');
    wp_remote_get('http://127.0.0.1:8081/summary-slow-endpoint.php?delay=' . $delay[$slot], array('headers' => array('Host' => wp_parse_url(home_url(), PHP_URL_HOST))));
    usleep($sleep[$slot]);
}
