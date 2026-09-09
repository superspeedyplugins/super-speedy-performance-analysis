<?php
// Give the cross-tab browser test its own real, compatible Before/After measurements.
defined('ABSPATH') || exit;
wp_set_current_user(1);
for ($i = 0; $i < 2; $i++) {
    $run_id = SSPA_Run_Controller::start(array('type' => 'spot', 'page_keys' => array('home'), 'user_id' => 1));
    if (is_wp_error($run_id)) {
        WP_CLI::error($run_id->get_error_message());
    }
    $deadline = time() + 180;
    do {
        SSPA_Run_Controller::process_batch($run_id);
        $status = SSPA_Run_Controller::status($run_id);
    } while ($status && in_array($status['status'], array('crawling', 'analysing'), true) && time() < $deadline);
    if (!$status || 'done' !== $status['status']) {
        WP_CLI::error('The cross-tab History measurement did not complete.');
    }
}
echo "PASS: cross-tab browser has two real History measurements\n";
