<?php
// A real spot analysis of the named catalogue pages, processed to completion. Prints the run.
// Usage: wp eval-file analyse.php <page-key> [<page-key> ...]
require_once __DIR__ . '/../lib/native-guard.php';
$page_keys = array_values(array_filter((array) $args));
$run_id = SSPA_Run_Controller::start(array(
    'type' => 'spot',
    'trigger' => 'manual',
    'user_id' => 1,
    'page_keys' => $page_keys,
));
if (is_wp_error($run_id)) {
    echo 'FAIL: analysis did not start: ' . $run_id->get_error_message() . "\n";
    exit(1);
}
$deadline = time() + 1200;
do {
    SSPA_Run_Controller::process_batch($run_id);
    $status = SSPA_Run_Controller::status($run_id);
} while ($status && 'crawling' === $status['status'] && time() < $deadline);
if (!$status || 'done' !== $status['status']) {
    echo 'FAIL: analysis ' . $run_id . ' ended as ' . ($status ? $status['status'] : 'missing') . "\n";
    exit(1);
}
echo "analysis $run_id done\n";
