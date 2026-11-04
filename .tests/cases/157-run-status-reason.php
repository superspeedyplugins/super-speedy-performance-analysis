<?php
// SSPA_Run_Controller::status() says why a run failed or was cancelled, in `reason`.
// Scalability Pro's "Apply all settings" polls status() and could only say "Measurement
// unavailable" because status() returned no reason, and a cancel from PA's own screen left
// notes NULL (reproduced on a real site, plan .docs/2026-11-05-run-status-reason.md).
//
// Every run here is a real run started through SSPA_Run_Controller::start() and ended through
// a real path: PA's admin AJAX cancel over HTTP, the cancel-run ability, a bare cancel() as an
// external plugin calls it, process_batch on a run whose queue is gone, and a real checkout
// that stops on a store address with no usable postcode (as case 143). Runs are retained.

function sspa_t157($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
    return $ok;
}

require_once __DIR__ . '/../lib/retained-fixtures.php';
sspa_retained_reset('157-run-status-reason');
wp_set_current_user(1);
deactivate_plugins('scalability-pro/scalability-pro.php', true);

$active = SSPA_Run_Controller::active_run_id();
if ($active) { SSPA_Run_Controller::cancel($active); }
// The first (red) version of this case deleted a run's queue, which also lost the queue's
// claim owner, so its failed run never released the active-run claim. Clear that leftover on
// the way in when no run is active.
if (!SSPA_Run_Controller::active_run_id()) { SSPA_Atomic_Claim::force_release('sspa_active_run_' . get_current_blog_id()); }

$cookies = SSPA_Auth::cookies_for('admin', 1);
$_COOKIE[LOGGED_IN_COOKIE] = $cookies[LOGGED_IN_COOKIE];
$nonce = wp_create_nonce('sspa_admin');
$ajax = static function ($body) use ($cookies, $nonce) {
    $r = wp_remote_post(admin_url('admin-ajax.php'), array('cookies' => $cookies, 'timeout' => 60, 'body' => $body + array('nonce' => $nonce)));
    return is_wp_error($r) ? $r : json_decode(wp_remote_retrieve_body($r), true);
};
if (!file_exists(SSPA_Helper_Files::mu_path())) {
    $ajax(array('action' => 'sspa_profiler_toggle', 'enabled' => 1));
}

$runs = array();
$start = static function ($label) use (&$runs) {
    $run_id = SSPA_Run_Controller::start(array('type' => 'spot', 'page_keys' => array('home'), 'user_id' => 1));
    if (is_wp_error($run_id)) {
        sspa_t157(false, "$label: a spot run starts (" . $run_id->get_error_message() . ')');
        return 0;
    }
    $runs[] = (int) $run_id;
    sspa_retained_save('157-run-status-reason', array('runs' => $runs));
    return (int) $run_id;
};
$reason_of = static function ($status) {
    return is_array($status) && array_key_exists('reason', $status) ? $status['reason'] : '(no reason key)';
};

// ---------------------------------------------------------------- 1. running: no reason
$run = $start('running');
if ($run) {
    $status = SSPA_Run_Controller::status($run);
    sspa_t157('crawling' === $status['status'] && '' === $reason_of($status), 'a running run has an empty reason (got ' . wp_json_encode($reason_of($status)) . ')');

    // ------------------------------------------------------------ 2. PA's own Cancel button
    $response = $ajax(array('action' => 'sspa_cancel_run', 'run_id' => $run));
    sspa_t157(!is_wp_error($response) && !empty($response['success']), 'the admin AJAX cancel answers success');
    $status = SSPA_Run_Controller::status($run);
    sspa_t157('cancelled' === $status['status'], 'the run is cancelled (status ' . $status['status'] . ')');
    sspa_t157('Cancelled from the Performance Analysis screen.' === $reason_of($status), 'a cancel from PA\'s screen says so (got ' . wp_json_encode($reason_of($status)) . ')');
    $notes = json_decode((string) SSPA_Run_Controller::run_row($run)['notes'], true);
    sspa_t157(is_array($notes) && !empty($notes['cancel_reason']), 'the reason is recorded in the run notes, not only derived (notes ' . wp_json_encode($notes) . ')');
}

// ---------------------------------------------------------------- 3. cancel-run ability
$run = $start('ability');
if ($run) {
    SSPA_Abilities_Control::exec_cancel_run(array('run_id' => $run));
    $status = SSPA_Run_Controller::status($run);
    sspa_t157('cancelled' === $status['status'] && 'Cancelled by the cancel-run ability.' === $reason_of($status), 'a cancel through the cancel-run ability says so (got ' . wp_json_encode($reason_of($status)) . ')');
}

// ---------------------------------------------------------------- 4. bare cancel() from another plugin
$run = $start('external');
if ($run) {
    SSPA_Run_Controller::cancel($run);
    $status = SSPA_Run_Controller::status($run);
    sspa_t157('cancelled' === $status['status'] && 'Cancelled' === $reason_of($status), 'a cancel with no stated reason says Cancelled (got ' . wp_json_encode($reason_of($status)) . ')');

    // A later cancel with a reason never rewrites a finished run's notes.
    SSPA_Run_Controller::cancel($run, 'too late');
    sspa_t157('Cancelled' === $reason_of(SSPA_Run_Controller::status($run)), 'cancelling an already-cancelled run does not change its reason');
}

// ---------------------------------------------------------------- 5. failed: no progress for 3 hours
// The driver tab was closed and nothing advanced the run: the cleanup cron fails it.
$run = $start('stale');
if ($run) {
    $queue = SSPA_Run_Queue::get($run);
    $queue['last_progress'] = time() - 4 * HOUR_IN_SECONDS;
    SSPA_Run_Queue::save($run, $queue);
    SSPA_Run_Controller::cleanup();
    $status = SSPA_Run_Controller::status($run);
    $reason = $reason_of($status);
    sspa_t157('failed' === $status['status'], 'a run with no progress for 4 hours is failed by the cleanup cron (status ' . $status['status'] . ')');
    sspa_t157(false !== stripos($reason, '3 hours') && strlen($reason) > 30, 'the failure reason is a sentence saying it made no progress (got ' . wp_json_encode($reason) . ')');
}

// ---------------------------------------------------------------- 6. failed: a real checkout that cannot place an order
if (class_exists('WooCommerce')) {
    $country = get_option('woocommerce_default_country');
    $postcode = get_option('woocommerce_store_postcode');
    update_option('woocommerce_default_country', 'AQ');
    update_option('woocommerce_store_postcode', '');
    WC()->countries = new WC_Countries();
    wp_cache_flush();
    $run = SSPA_Run_Controller::start(array('type' => 'checkout', 'user_id' => 1, 'allow_integrations' => true, 'allow_webhooks' => true));
    if (is_wp_error($run)) {
        sspa_t157(false, 'checkout start: ' . $run->get_error_message());
    } else {
        $runs[] = (int) $run;
        sspa_retained_save('157-run-status-reason', array('runs' => $runs));
        $deadline = time() + 300;
        do {
            SSPA_Run_Controller::process_batch($run);
            $status = SSPA_Run_Controller::status($run);
        } while ($status && in_array($status['status'], array('queued', 'crawling', 'analysing'), true) && time() < $deadline);
        $notes = json_decode((string) SSPA_Run_Controller::run_row($run)['notes'], true);
        $error = (string) ($notes['flow']['error'] ?? '');
        sspa_t157('failed' === $status['status'] && '' !== $error, 'the checkout run fails with a recorded flow error (status ' . $status['status'] . ')');
        sspa_t157('' !== $error && $error === $reason_of($status), 'the failed checkout reason is its flow error (got ' . wp_json_encode($reason_of($status)) . ')');
    }
    update_option('woocommerce_default_country', $country);
    update_option('woocommerce_store_postcode', $postcode);
    wp_cache_flush();
} else {
    sspa_t157(false, 'WooCommerce is not active on the test site (run .tests/setup-site.sh)');
}
