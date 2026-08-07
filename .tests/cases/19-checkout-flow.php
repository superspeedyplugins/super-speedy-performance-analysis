<?php
// Checkout flow profiling, end to end (design: .docs/2026-08-07-checkout-flow-profiling.md).
//
// Drives the REAL entry point - SSPA_Run_Controller::start(['type' => 'checkout']) plus the
// batch loop, the same path the admin-bar button uses - never a hand-assembled sequence of
// steps. Two fixture plugins stand in for the integrations a real store would have: one
// makes a blocking outbound call on payment_complete (so the blocking-HTTP finding has
// something real to catch), the other records what actually happened to each email (so
// "delivered" versus "built but never sent" is decided by the mail stack's own outcome,
// not by our own bookkeeping).
//
// The assertion that matters most is the last one: a flow token carrying no payment mode,
// or a junk one, must take the no-payment path. That is what stops a future refactor
// charging somebody real money.

function sspa_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

global $wpdb;

if (!class_exists('WooCommerce')) {
    echo "FAIL: WooCommerce is not active in the docker env\n";
    return;
}

$profiles_table = SSPA_Schema::table('profiles');
$sessions_table = $wpdb->prefix . 'woocommerce_sessions';

// ---------------------------------------------------------------- fixtures

$plant = function ($slug, $code) {
    $dir = WP_PLUGIN_DIR . '/' . $slug;
    if (!is_dir($dir)) {
        mkdir($dir);
    }
    file_put_contents($dir . '/' . $slug . '.php', $code);
    activate_plugin($slug . '/' . $slug . '.php');
    wp_cache_flush(); // activating from CLI with Redis on can leave apache reading a stale alloptions
};
$remove = function ($slug) {
    deactivate_plugins($slug . '/' . $slug . '.php');
    @unlink(WP_PLUGIN_DIR . '/' . $slug . '/' . $slug . '.php');
    @rmdir(WP_PLUGIN_DIR . '/' . $slug);
};

// A third-party integration that phones home while the customer waits. It calls back into
// this same site at an endpoint that deliberately takes ~150ms, so the call has a real,
// repeatable duration rather than depending on how fast a DNS failure happens to be - the
// finding under test has a threshold, and a test that trips it by luck proves nothing.
$plant('sspa-slow-integration', <<<'PHP'
<?php
/** Plugin Name: SSPA Slow Integration (test fixture) */
add_action('init', function () {
    if (isset($_GET['sspa_test_slow'])) {
        usleep(150000);
        header('Content-Type: text/plain');
        echo 'slow-endpoint-ok';
        exit;
    }
});
add_action('woocommerce_payment_complete', function ($order_id) {
    wp_remote_get(home_url('/?sspa_test_slow=1&order=' . (int) $order_id), array('timeout' => 10));
}, 10, 1);
PHP
);

// Records the mail stack's own verdict on every message. In construct mode the capture
// strips the recipients before transport, so PHPMailer refuses with a "must provide at
// least one recipient" error; in deliver mode the message reaches transport and fails (or
// succeeds) for some entirely different reason. That difference is the proof.
$plant('sspa-mail-observer', <<<'PHP'
<?php
/** Plugin Name: SSPA Mail Observer (test fixture) */
function sspa_mail_observer_record($ok, $message) {
    $log = get_option('sspa_test_mail_log', array());
    $log[] = array('ok' => $ok, 'message' => (string) $message);
    update_option('sspa_test_mail_log', $log, false);
}
add_action('wp_mail_succeeded', function ($atts) { sspa_mail_observer_record(true, 'sent'); });
add_action('wp_mail_failed', function ($error) { sspa_mail_observer_record(false, $error->get_error_message()); });
PHP
);
sleep(3); // opcache revalidate_freq

$active_plugins_before = get_option('active_plugins');
sspa_t(in_array('sspa-slow-integration/sspa-slow-integration.php', $active_plugins_before, true)
    && in_array('sspa-mail-observer/sspa-mail-observer.php', $active_plugins_before, true), 'both fixtures active');

// ---------------------------------------------------------------- a stock-managed product

// The flow picks the cheapest purchasable, in-stock, SHIPPABLE product. Give that same
// product managed stock so the stock-restore assertion has something to assert on: with
// unmanaged stock it would pass vacuously.
$target = SSPA_Checkout_Flow::default_product();
if (!$target) {
    echo "FAIL: no purchasable product in the docker store\n";
    $remove('sspa-slow-integration');
    $remove('sspa-mail-observer');
    return;
}
$target_id = $target->get_id();
$restore_stock_settings = array(
    'manage' => $target->get_manage_stock(),
    'qty' => $target->get_stock_quantity(),
);
$target->set_manage_stock(true);
$target->set_stock_quantity(25);
$target->save();
wc_delete_product_transients($target_id);
sspa_t(true, 'target product: ' . $target->get_name() . " (#$target_id), stock managed at 25");

// ---------------------------------------------------------------- 1. snapshots

$orders_before = count(wc_get_orders(array('limit' => -1, 'return' => 'ids', 'status' => 'all')));
$stock_before = (int) wc_get_product($target_id)->get_stock_quantity();
$sessions_before = (int) $wpdb->get_var("SELECT COUNT(*) FROM $sessions_table");
$temp_meta_before = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
    SSPA_Checkout_Flow::TEMP_META
));
delete_option('sspa_test_mail_log');

// ---------------------------------------------------------------- 2. the real entry point

$run_id = SSPA_Run_Controller::start(array(
    'type' => 'checkout',
    'user_id' => 1,
    'mail_mode' => 'deliver',
    'allow_integrations' => true,
    'allow_webhooks' => true,
));
if (is_wp_error($run_id)) {
    echo 'FAIL: checkout start: ' . $run_id->get_error_message() . "\n";
    $remove('sspa-slow-integration');
    $remove('sspa-mail-observer');
    return;
}
$deadline = time() + 300;
do {
    SSPA_Run_Controller::process_batch($run_id);
    $status = SSPA_Run_Controller::status($run_id);
} while ($status && in_array($status['status'], array('crawling', 'analysing'), true) && time() < $deadline);

$run = SSPA_Run_Controller::run_row($run_id);
$notes = json_decode((string) $run['notes'], true);
sspa_t($run && 'done' === $run['status'], 'checkout run done (status: ' . ($run ? $run['status'] : 'null') . ')');
sspa_t(is_array($notes) && 'ok' === $notes['outcome'], 'flow outcome ok (' . (is_array($notes) ? $notes['outcome'] : '?') . ')');
sspa_t(is_array($notes) && 'checkout' === $notes['type'], 'run recorded as a checkout run');

// A checkout run must never masquerade as a site analysis - every "latest analysis" query
// filters run_type IN ('baseline','spot').
$latest_analysis = (int) $wpdb->get_var(
    'SELECT id FROM ' . SSPA_Schema::table('runs') . " WHERE status = 'done' AND run_type IN ('baseline','spot') ORDER BY id DESC LIMIT 1"
);
sspa_t($latest_analysis !== $run_id, 'checkout run is not picked up as the latest site analysis');

// ---------------------------------------------------------------- 3. every step measured

$rows = array();
foreach ($wpdb->get_results($wpdb->prepare(
    "SELECT * FROM $profiles_table WHERE run_id = %d ORDER BY id",
    $run_id
), ARRAY_A) as $r) {
    $rows[$r['page_key']] = $r;
}

$expected = array(
    'flow-preflight' => 'GET',
    'flow-view-product' => 'GET',
    'flow-add-to-cart' => 'POST',
    'flow-view-cart' => 'GET',
    'flow-cart-api' => 'GET',
    'flow-update-customer' => 'POST',
    'flow-select-shipping' => 'POST',
    'flow-view-checkout' => 'GET',
    'flow-checkout-draft' => 'GET',
    'flow-place-order' => 'POST',
    'flow-order-received' => 'GET',
    'flow-delete-order' => 'GET',
);
foreach ($expected as $key => $method) {
    if (!isset($rows[$key])) {
        sspa_t(false, "step $key was profiled");
        continue;
    }
    $row = $rows[$key];
    // A step recorded with null metrics is a failure, not a pass: it means a cache
    // answered, or the capture never landed.
    sspa_t(
        null !== $row['page_gen_ms'] && $row['method'] === $method && null === $row['blocked_by'],
        sprintf('step %-22s %s, gen=%sms, %s', $key, $row['method'], round((float) $row['page_gen_ms'], 1), $method === $row['method'] ? 'method correct' : 'METHOD WRONG')
    );
}

// The cart and checkout PAGES must see a real cart. An empty-cart checkout page bounces to
// the cart with a 302, which is exactly the measurement this feature exists to replace.
sspa_t(isset($rows['flow-view-checkout']) && 200 === (int) $rows['flow-view-checkout']['response_code'],
    'checkout page rendered a real cart (200, not a redirect to an empty cart)');

// ---------------------------------------------------------------- 4. zero residue

$orders_after = count(wc_get_orders(array('limit' => -1, 'return' => 'ids', 'status' => 'all')));
$stock_after = (int) wc_get_product($target_id)->get_stock_quantity();
$temp_meta_after = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
    SSPA_Checkout_Flow::TEMP_META
));
sspa_t($orders_after === $orders_before, "zero order residue ($orders_before -> $orders_after)");
sspa_t($stock_after === $stock_before, "stock restored ($stock_before -> $stock_after)");
sspa_t($temp_meta_after === $temp_meta_before, "no _sspa_temp markers left ($temp_meta_before -> $temp_meta_after)");
sspa_t(false === get_option(SSPA_Checkout_Flow::TEMP_OPTION, false), 'sspa_flow_temp cleared');

// The flow creates guest sessions (its own cart, plus the one the page views use) and
// deletes them again by key. Session rows are ephemeral WooCommerce data, but leaving rows
// behind that we know are ours is not good enough.
$sessions_after = (int) $wpdb->get_var("SELECT COUNT(*) FROM $sessions_table");
sspa_t($sessions_after <= $sessions_before, "no session rows left behind ($sessions_before -> $sessions_after)");

// ---------------------------------------------------------------- 5. the order really completed

$flow = is_array($notes) ? $notes['flow'] : array();
sspa_t(isset($flow['order_status']) && in_array($flow['order_status'], array('processing', 'completed', 'on-hold'), true),
    'order reached a payment_complete status (' . (isset($flow['order_status']) ? $flow['order_status'] : '?') . '), not a leftover draft');
sspa_t(!empty($flow['order_total']) && (float) $flow['order_total'] > 0,
    'order total stayed real (' . (isset($flow['order_total']) ? $flow['order_total'] : '?') . ') - the no-payment mode did not zero the cart');
sspa_t(isset($flow['orders_deleted']) && $flow['orders_deleted'] >= 1 && 0 === (int) $flow['orders_left'],
    'safety report: ' . (int) $flow['orders_deleted'] . ' deleted, ' . (int) $flow['orders_left'] . ' left');

// ---------------------------------------------------------------- 6. mail behaviour matches the mode

$mail_log = get_option('sspa_test_mail_log', array());
$delivered = array_filter($mail_log, function ($entry) {
    return $entry['ok'] || false === stripos($entry['message'], 'recipient');
});
sspa_t(count($mail_log) >= 1, 'deliver mode: the mail stack ran (' . count($mail_log) . ' message(s))');
sspa_t(count($delivered) >= 1, 'deliver mode: recipients survived into transport - nothing was stripped');
$mail_ms = isset($rows['flow-place-order']) ? (float) $rows['flow-place-order']['mail_ms'] : 0;
sspa_t((int) $rows['flow-place-order']['mail_count'] >= 1 && $mail_ms > 0,
    'deliver mode: mail timed inside the place-order step (' . (int) $rows['flow-place-order']['mail_count'] . ' in ' . round($mail_ms, 1) . 'ms)');

// ---------------------------------------------------------------- 7. the pre-flight inventory

$inventory = is_array($notes) && isset($notes['inventory']) ? $notes['inventory'] : null;
sspa_t(is_array($inventory) && 'block' === $inventory['checkout_type'], 'pre-flight identified the block checkout');
$hook_components = array();
foreach ((array) (is_array($inventory) ? $inventory['order_hooks'] : array()) as $c) {
    $hook_components[] = $c['component'];
}
sspa_t(in_array('woocommerce', $hook_components, true), 'pre-flight names woocommerce among the order-hook components');
sspa_t(in_array('sspa-slow-integration', $hook_components, true),
    'pre-flight names the planted integration - it resolved a closure back to its plugin');

// ---------------------------------------------------------------- 8. the profiler left nothing behind

sspa_t(get_option('active_plugins') === $active_plugins_before, 'active_plugins untouched');
sspa_t(!file_exists(WP_CONTENT_DIR . '/db.php.sspa-hold'), 'no db.php hold left behind');

// ---------------------------------------------------------------- 9. the Excimer roll-up

$capture = null;
if (!empty($rows['flow-place-order']['profile_blob'])) {
    $capture = json_decode((string) @gzuncompress($rows['flow-place-order']['profile_blob']), true);
}
if (!is_array($capture) || empty($capture['profile'])) {
    echo "SKIP: no Excimer profile in the capture - the extension is not loaded in the apache container\n";
} else {
    $waterfall = SSPA_Checkout_Flow::waterfall($run_id);
    $rollup = $waterfall['profile'];
    sspa_t(is_array($rollup) && !empty($rollup['components']), 'roll-up produced per-component times across the flow');
    $names = array();
    foreach ($rollup['components'] as $c) {
        $names[] = $c['component'];
    }
    sspa_t(in_array('woocommerce', $names, true), 'roll-up names woocommerce across the purchase');
    sspa_t(!empty($capture['profile']['components']['woocommerce']), 'place-order step attributes PHP time to woocommerce');

    // Sanity check that the sampler saw the requests it thinks it saw: sampled ms should
    // be in the same ballpark as summed step generation time, never wildly beyond it.
    $summed_gen = 0.0;
    foreach ($rows as $key => $row) {
        if (null !== $row['page_gen_ms']) {
            $summed_gen += (float) $row['page_gen_ms'];
        }
    }
    $ratio = $summed_gen > 0 ? $rollup['sampled_wall_ms'] / $summed_gen : 0;
    sspa_t($ratio > 0.3 && $ratio < 1.5, sprintf('sampled ms within a sane factor of generation time (%.2f)', $ratio));
}

// ---------------------------------------------------------------- 10. the payment boundary

$place_capture = $capture;
$mark = (is_array($place_capture) && isset($place_capture['marks']['payment_complete']))
    ? (float) $place_capture['marks']['payment_complete']
    : null;
$place_gen = (float) $rows['flow-place-order']['page_gen_ms'];
sspa_t(null !== $mark && $mark > 0 && $mark < $place_gen,
    sprintf('payment boundary marked at %s ms inside a %s ms step', round((float) $mark, 1), round($place_gen, 1)));

$waterfall = SSPA_Checkout_Flow::waterfall($run_id);
sspa_t(true === $waterfall['boundary_known'], 'waterfall split the place-order step at the payment boundary');
sspa_t($waterfall['at_risk_ms'] > 0 && $waterfall['secured_ms'] > 0, 'both halves of the wait have time in them');
// Nobody waits for the cleanup, so it is measured but excluded from the customer figure.
$excluded_keys = array();
foreach ($waterfall['excluded'] as $row) {
    $excluded_keys[] = $row['page_key'];
}
sspa_t(in_array('flow-delete-order', $excluded_keys, true), 'the delete step is excluded from the customer-facing total');
sspa_t(abs(($waterfall['at_risk_ms'] + $waterfall['secured_ms']) - $waterfall['total_ms']) < 0.5, 'the two halves add up to the total');

// ---------------------------------------------------------------- 11. findings

$findings = array();
foreach ($wpdb->get_results($wpdb->prepare(
    'SELECT finding_type, component, page_key, evidence FROM ' . SSPA_Schema::table('findings') . ' WHERE run_id = %d',
    $run_id
), ARRAY_A) as $f) {
    $findings[$f['finding_type']][] = $f;
}
$blocking = isset($findings['checkout_blocking_http']) ? $findings['checkout_blocking_http'] : array();
$caught_fixture = false;
foreach ($blocking as $f) {
    if ('sspa-slow-integration' === $f['component']) {
        $caught_fixture = true;
    }
}
sspa_t($caught_fixture, 'the planted blocking HTTP call was caught and attributed to its plugin');
sspa_t(isset($findings['checkout_mail_inline']), 'order emails sent in-request were reported as such');

// ---------------------------------------------------------------- 12. payment-mode safety
//
// The assertion that stops a future refactor charging someone real money. Driven through
// the real armed-request path (a signed flow token hitting the np probe), not an
// in-process call, because the gate lives in a hook that only fires on such a request.

$crawler = new SSPA_Crawler();
$gate = function ($flags) use ($crawler) {
    $sample = $crawler->send_profiled(home_url('/?sspa_flow_probe=1'), array('method' => 'GET', 'flags' => $flags));
    return is_array($sample['json']) ? $sample['json'] : null;
};

$no_pm = $gate(array('v' => 'guest', 'ck' => 'flow', 'npc' => '1'));
$junk_pm = $gate(array('v' => 'guest', 'ck' => 'flow', 'pm' => 'zzz', 'npc' => '1'));
$sandbox_pm = $gate(array('v' => 'guest', 'ck' => 'flow', 'pm' => 's', 'npc' => '1'));
$unarmed = $gate(array('v' => 'guest', 'npc' => '1'));

// The np probe reports on an in-memory £47 pending order, so "needs payment" is true
// unless our filters made it false.
sspa_t(is_array($no_pm) && false === $no_pm['order_needs_payment'] && false === $no_pm['cart_needs_payment'],
    'flow token with no payment mode takes the no-payment path');
sspa_t(is_array($junk_pm) && false === $junk_pm['order_needs_payment'] && false === $junk_pm['cart_needs_payment'],
    'flow token with a junk payment mode falls through to no payment (whitelist, not blacklist)');
// pm=s is reserved for a confirmed sandbox adapter. No adapter is written yet, so the
// constant is empty and even the sandbox flag must not switch payment on.
sspa_t(is_array($sandbox_pm) && empty(SSPA_Checkout_Flow::PAYMENT_MODES) && false === $sandbox_pm['order_needs_payment'],
    'pm=s does not enable payment while no gateway adapter exists');
sspa_t(is_array($unarmed) && true === $unarmed['order_needs_payment'],
    'a request without ck=flow is untouched - the real answer is still true');

// ---------------------------------------------------------------- 13. named failures, nothing created

$orders_pre_fail = count(wc_get_orders(array('limit' => -1, 'return' => 'ids', 'status' => 'all')));

// (a) An explicitly requested out-of-stock product refuses before anything is created,
//     rather than quietly buying a different product.
$oos_id = wp_insert_post(array('post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'SSPA out of stock fixture'));
$oos = wc_get_product($oos_id);
$oos->set_regular_price('9.99');
$oos->set_manage_stock(true);
$oos->set_stock_quantity(0);
$oos->set_stock_status('outofstock');
$oos->save();

$fail_run = SSPA_Run_Controller::start(array('type' => 'checkout', 'user_id' => 1, 'product_id' => $oos_id));
$deadline = time() + 120;
do {
    SSPA_Run_Controller::process_batch($fail_run);
    $s = SSPA_Run_Controller::status($fail_run);
} while ($s && in_array($s['status'], array('crawling', 'analysing'), true) && time() < $deadline);
$fail_notes = json_decode((string) SSPA_Run_Controller::run_row($fail_run)['notes'], true);
sspa_t(is_array($fail_notes) && 'no_product' === $fail_notes['outcome'],
    'out-of-stock product produces a named failure (' . (is_array($fail_notes) ? $fail_notes['outcome'] : '?') . '), not a silent substitution');

// (b) More units than exist: the Store API refuses the add-to-cart, which is the failure
//     path that has to clean up after itself.
$short_id = wp_insert_post(array('post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'SSPA short stock fixture'));
$short = wc_get_product($short_id);
$short->set_regular_price('9.99');
$short->set_manage_stock(true);
$short->set_stock_quantity(1);
$short->set_stock_status('instock');
$short->save();
wc_delete_product_transients($short_id);

$short_run = SSPA_Run_Controller::start(array('type' => 'checkout', 'user_id' => 1, 'product_id' => $short_id, 'quantity' => 9));
$deadline = time() + 120;
do {
    SSPA_Run_Controller::process_batch($short_run);
    $s = SSPA_Run_Controller::status($short_run);
} while ($s && in_array($s['status'], array('crawling', 'analysing'), true) && time() < $deadline);
$short_notes = json_decode((string) SSPA_Run_Controller::run_row($short_run)['notes'], true);
sspa_t(is_array($short_notes) && 'add_to_cart_failed' === $short_notes['outcome'],
    'over-ordering produces add_to_cart_failed (' . (is_array($short_notes) ? $short_notes['outcome'] : '?') . ')');
sspa_t(is_array($short_notes) && !empty($short_notes['flow']['error']),
    'the failure carries the Store API\'s own error: ' . (isset($short_notes['flow']['error']) ? $short_notes['flow']['error'] : 'none'));
sspa_t('failed' === SSPA_Run_Controller::run_row($short_run)['status'], 'a flow that never bought anything is a failed run, not a quiet one');

$orders_post_fail = count(wc_get_orders(array('limit' => -1, 'return' => 'ids', 'status' => 'all')));
sspa_t($orders_post_fail === $orders_pre_fail, "failed runs created nothing ($orders_pre_fail -> $orders_post_fail)");
sspa_t((int) wc_get_product($short_id)->get_stock_quantity() === 1, 'failed run left stock alone');

// ---------------------------------------------------------------- teardown

wp_delete_post($oos_id, true);
wp_delete_post($short_id, true);
$target = wc_get_product($target_id);
$target->set_manage_stock($restore_stock_settings['manage']);
$target->set_stock_quantity($restore_stock_settings['qty']);
$target->save();
delete_option('sspa_test_mail_log');
$remove('sspa-slow-integration');
$remove('sspa-mail-observer');
