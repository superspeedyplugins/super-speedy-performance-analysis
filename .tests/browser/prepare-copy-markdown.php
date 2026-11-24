<?php
// Entry state for copy-markdown.e2e.cjs, after browser-prepare.php: one real completed checkout
// run (the same entry point the admin bar uses, no payment), so the checkout panel's Copy
// Markdown button exists. Reused while the latest checkout run is done; never fabricated.
require __DIR__ . '/../lib/fleet.php';
global $wpdb;
update_option('sspa_test_slow_markdown_export', '', false);
$runs = SSPA_Schema::table('runs');
$checkout = (int) $wpdb->get_var("SELECT id FROM {$runs} WHERE run_type = 'checkout' AND status = 'done' ORDER BY id DESC LIMIT 1");
if (!$checkout) {
    $checkout = SSPA_Run_Controller::start(array('type' => 'checkout', 'user_id' => 1));
    if (is_wp_error($checkout)) throw new RuntimeException('checkout start: ' . $checkout->get_error_message());
    $deadline = time() + 300;
    do {
        SSPA_Run_Controller::process_batch($checkout);
        $status = SSPA_Run_Controller::status($checkout);
    } while ($status && in_array($status['status'], array('queued', 'crawling', 'analysing'), true) && time() < $deadline);
    if (!$status || 'done' !== $status['status']) throw new RuntimeException('checkout run ' . $checkout . ' did not complete: ' . wp_json_encode($status));
}
$last = $wpdb->get_row("SELECT id FROM {$runs} WHERE run_type IN ('baseline','spot') AND status = 'done' ORDER BY id DESC LIMIT 1", ARRAY_A);
$profile = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . SSPA_Schema::table('profiles') . ' WHERE run_id = %d ORDER BY id LIMIT 1', (int) $last['id']));
echo wp_json_encode(array('checkout_run' => (int) $checkout, 'run' => (int) $last['id'], 'profile' => $profile));
