<?php
// Order management, appended to the checkout flow (Dave's brief, 11th August 2026): after the
// purchase, the flow views the order in wp-admin and marks it completed - the two things a
// shop owner does most - measuring the order-edit screen and the completion cascade (the
// completed-order email, stock/downloads and every plugin hooking `completed`).
//
// Drives the REAL entry point - SSPA_Run_Controller::start(['type' => 'checkout']) plus the
// batch loop - so the management steps are exercised exactly as a run does them, never
// hand-assembled. A fixture records that the completion hook fired INSIDE the measured step,
// so "the cascade ran" is decided by WooCommerce firing the transition, not by our bookkeeping.

function sspa_om_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

global $wpdb;

if (!class_exists('WooCommerce')) {
    echo "FAIL: WooCommerce is not active in the docker env\n";
    return;
}

// A fixture that records the moment an order is marked completed - proof the cascade ran
// inside the measured loopback step, in a different process from this test.
$sspa_om_dir = WP_PLUGIN_DIR . '/sspa-om-fixture';
if (!is_dir($sspa_om_dir)) {
    mkdir($sspa_om_dir);
}
file_put_contents($sspa_om_dir . '/sspa-om-fixture.php', <<<'PHP'
<?php
/**
 * Plugin Name: SSPA Order-Management Fixture (test fixture)
 * Version: 1.0.0
 */
add_action('woocommerce_order_status_completed', function ($order_id) {
    update_option('sspa_om_completed_fired', (int) $order_id, false);
}, 10, 1);
PHP
);
activate_plugin('sspa-om-fixture/sspa-om-fixture.php');
delete_option('sspa_om_completed_fired');
wp_cache_flush();
sleep(3); // opcache

// Suppress emails: this case is about the transition and the steps, and case 19 already
// pins mail-mode fidelity with its mail-recorder fixture.
$sspa_run = SSPA_Run_Controller::start(array('type' => 'checkout', 'user_id' => 1, 'mail_mode' => 'suppress'));
if (is_wp_error($sspa_run)) {
    echo 'FAIL: checkout start: ' . $sspa_run->get_error_message() . "\n";
    return;
}
$sspa_deadline = time() + 300;
do {
    SSPA_Run_Controller::process_batch($sspa_run);
    $sspa_s = SSPA_Run_Controller::status($sspa_run);
} while ($sspa_s && in_array($sspa_s['status'], array('crawling', 'analysing'), true) && time() < $sspa_deadline);

$sspa_notes = json_decode((string) $wpdb->get_var($wpdb->prepare(
    'SELECT notes FROM ' . SSPA_Schema::table('runs') . ' WHERE id = %d',
    $sspa_run
)), true);
$sspa_outcome = isset($sspa_notes['outcome']) ? $sspa_notes['outcome'] : 'unknown';
sspa_om_t('ok' === $sspa_outcome, 'the checkout succeeded, so there was an order to manage (' . $sspa_outcome . ')');

if ('ok' === $sspa_outcome) {
    // --- The two management steps exist, as admin ---
    $sspa_steps = $wpdb->get_results($wpdb->prepare(
        'SELECT page_key, variant, method, page_gen_ms FROM ' . SSPA_Schema::table('profiles') . "
         WHERE run_id = %d AND page_key IN ('flow-view-order', 'flow-complete-order') ORDER BY id ASC",
        $sspa_run
    ), ARRAY_A);
    $sspa_by_key = array();
    foreach ($sspa_steps as $sspa_step) {
        $sspa_by_key[$sspa_step['page_key']] = $sspa_step;
    }
    sspa_om_t(isset($sspa_by_key['flow-view-order']), 'the order-view step was measured');
    sspa_om_t(isset($sspa_by_key['flow-complete-order']), 'the mark-completed step was measured');
    sspa_om_t(
        isset($sspa_by_key['flow-view-order']) && 'admin' === $sspa_by_key['flow-view-order']['variant'],
        'the order-view step ran as admin, not guest'
    );
    sspa_om_t(
        isset($sspa_by_key['flow-view-order']) && null !== $sspa_by_key['flow-view-order']['page_gen_ms'],
        'the order screen actually rendered (not a login redirect)'
    );

    // --- The completion cascade really fired, inside the measured step ---
    wp_cache_delete('sspa_om_completed_fired', 'options');
    $sspa_fired = (int) get_option('sspa_om_completed_fired');
    sspa_om_t($sspa_fired > 0, 'the completed-order cascade fired during the run (order ' . $sspa_fired . ')');

    // --- The transition is named honestly, processing -> completed on physical goods ---
    sspa_om_t(
        'processing' === (isset($sspa_notes['flow']['complete_from_status']) ? $sspa_notes['flow']['complete_from_status'] : null),
        'the order was at processing before completion (' . (isset($sspa_notes['flow']['complete_from_status']) ? $sspa_notes['flow']['complete_from_status'] : 'null') . ')'
    );
    sspa_om_t(
        'completed' === (isset($sspa_notes['flow']['complete_to_status']) ? $sspa_notes['flow']['complete_to_status'] : null),
        'and completed after'
    );

    // --- The waterfall keeps management out of the customer total ---
    $sspa_wf = SSPA_Checkout_Flow::waterfall($sspa_run);
    sspa_om_t(!empty($sspa_wf['management']) && count($sspa_wf['management']) >= 2, 'the waterfall has a management bucket with both steps');
    sspa_om_t($sspa_wf['management_ms'] > 0, 'the management bucket has measured time (' . $sspa_wf['management_ms'] . 'ms)');
    // The panel labels the transition from these - they must be populated, not null (the flow
    // notes are nested under 'flow', an easy path to get wrong).
    sspa_om_t(
        'processing' === $sspa_wf['complete_from_status'] && 'completed' === $sspa_wf['complete_to_status'],
        'the waterfall exposes the transition for the panel label (' . var_export($sspa_wf['complete_from_status'], true) . ' -> ' . var_export($sspa_wf['complete_to_status'], true) . ')'
    );
    // The customer total is at-risk + secured only; management is separate.
    sspa_om_t(
        abs(($sspa_wf['at_risk_ms'] + $sspa_wf['secured_ms']) - $sspa_wf['total_ms']) < 0.5,
        'the customer total is checkout only - management is not added to it'
    );
    $sspa_mgmt_keys = array_map(function ($r) { return $r['page_key']; }, $sspa_wf['management']);
    sspa_om_t(
        !in_array('flow-view-order', array_map(function ($r) { return $r['page_key']; }, $sspa_wf['at_risk']), true)
        && in_array('flow-view-order', $sspa_mgmt_keys, true),
        'the order-view step is in management, never in the at-risk (customer) bucket'
    );

    // --- Cleanup still deleted the order ---
    $sspa_safety = isset($sspa_notes['safety']) ? $sspa_notes['safety'] : array();
    sspa_om_t(
        isset($sspa_safety['orders_left']) && 0 === (int) $sspa_safety['orders_left'],
        'the completed order was still deleted afterwards (' . (isset($sspa_safety['orders_left']) ? (int) $sspa_safety['orders_left'] : '?') . ' left)'
    );
    if ($sspa_fired > 0) {
        sspa_om_t(!wc_get_order($sspa_fired), 'the specific completed order no longer exists');
    }
}

// --- Cleanup ---
deactivate_plugins('sspa-om-fixture/sspa-om-fixture.php', true);
@unlink($sspa_om_dir . '/sspa-om-fixture.php');
@rmdir($sspa_om_dir);
delete_option('sspa_om_completed_fired');
sspa_om_t(!is_dir($sspa_om_dir), 'fixture removed');
