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

$plant = function ($slug, $code, $load_first = false) {
    $dir = WP_PLUGIN_DIR . '/' . $slug;
    if (!is_dir($dir)) {
        mkdir($dir);
    }
    file_put_contents($dir . '/' . $slug . '.php', $code);
    activate_plugin($slug . '/' . $slug . '.php');
    if ($load_first) {
        // A plugin that replaces a pluggable function has to load before anything else can
        // require core's pluggable.php. Activation appends by default, which makes this test
        // depend on the unrelated active-plugin order in a long-lived Docker volume.
        $basename = $slug . '/' . $slug . '.php';
        $active = array_values(array_diff((array) get_option('active_plugins'), array($basename)));
        array_unshift($active, $basename);
        update_option('active_plugins', $active);
    }
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
        usleep(isset($_GET['long']) ? 1400000 : 150000);
        header('Content-Type: text/plain');
        echo 'slow-endpoint-ok';
        exit;
    }
});
// The misbehaviours a real purge stack exhibited, all on payment_complete:
// - two plain slow calls: the blocking-http finding must aggregate them into ONE finding
// - a fetch of the ORDER's own permalink (/?p=<order id>) - the HPOS purge signature
// - a fetch of /amp/ with no AMP plugin installed - the phantom-AMP purge signature
// - a call that times out (1.4s endpoint, 1s timeout) - the failing self-fetch signature
// Plus one on CANCELLATION: that fires during the harness's own delete step and must
// never surface in the customer-facing outbound list or the roll-up.
add_action('woocommerce_payment_complete', function ($order_id) {
    wp_remote_get(home_url('/?sspa_test_slow=1&n=1&order=' . (int) $order_id), array('timeout' => 10));
    wp_remote_get(home_url('/?sspa_test_slow=1&n=2&order=' . (int) $order_id), array('timeout' => 10));
    wp_remote_get(home_url('/?p=' . (int) $order_id), array('timeout' => 10));
    wp_remote_get(home_url('/amp/'), array('timeout' => 10));
    wp_remote_get(home_url('/?sspa_test_slow=1&long=1'), array('timeout' => 1));
}, 10, 1);
add_action('woocommerce_order_status_cancelled', function ($order_id) {
    wp_remote_get(home_url('/?sspa_test_slow=1&cancel=1&order=' . (int) $order_id), array('timeout' => 10));
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

// WooCommerce's sample import does not configure shipping. Add one real rest-of-world rate
// so the block flow exercises select-shipping-rate instead of correctly recording that the
// step was skipped. Remove this exact method during teardown.
$shipping_zone = new WC_Shipping_Zone(0);
$shipping_method_id = $shipping_zone->add_shipping_method('flat_rate');
sspa_t(false !== $shipping_method_id, 'temporary flat-rate shipping method added');

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
sspa_t(
    !empty($flow['email']) && is_email($flow['email'])
    && !empty($flow['order_id']) && !empty($flow['order_number'])
    && isset($flow['coupon_codes']) && is_array($flow['coupon_codes'])
    && !empty($flow['items'][0]['name']) && (int) $flow['quantity'] === (int) $flow['items'][0]['quantity'],
    'fulfilment identifiers survive local order cleanup'
);

// ---------------------------------------------------------------- 6. mail behaviour matches the mode

// The observer wrote the log from INSIDE the loopback requests (a different process); this
// controller process called delete_option('sspa_test_mail_log') before the run, which put the
// key in its own `notoptions` cache, so a plain get_option here can return the stale empty
// default and never see the loopbacks' DB writes - both the value cache AND the notoptions
// array have to be busted. (Surfaced in 0.17.0 when the flow began writing a completed-order
// email from a management loopback after the delete; the raw DB row was correct, only the
// controller's cache was stale.)
wp_cache_delete('sspa_test_mail_log', 'options');
wp_cache_delete('notoptions', 'options');
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
sspa_t(
    isset($waterfall['order_details'])
    && $waterfall['order_details']['email'] === $flow['email']
    && $waterfall['order_details']['order_number'] === $flow['order_number']
    && $waterfall['order_details']['items'] === $flow['items'],
    'the local result payload exposes the exact order details rendered in the overlay'
);
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
$fixture_findings = array();
foreach ($blocking as $f) {
    if ('sspa-slow-integration' === $f['component']) {
        $fixture_findings[] = json_decode($f['evidence'], true);
    }
}
// One aggregated finding for its five calls, never one finding per call.
sspa_t(1 === count($fixture_findings), 'the planted blocking calls produced ONE blocking-http finding (' . count($fixture_findings) . ')');
if ($fixture_findings) {
    $ev = $fixture_findings[0];
    sspa_t(5 === (int) $ev['calls'] && (float) $ev['ms'] >= 1000,
        'the finding aggregates all five calls (' . (int) $ev['calls'] . ' calls, ' . round((float) $ev['ms']) . 'ms)');
}
sspa_t(isset($findings['checkout_mail_inline']), 'order emails sent in-request were reported as such');

// The three deterministic misbehaviour signatures, each matched by BEHAVIOUR against the
// fixture's mimicry of a real purge stack, and each attributed to the fixture plugin.
foreach (array(
    'checkout_purge_order_pages' => 'the order-permalink purge (HPOS /?p=<order id>) is recognised',
    'checkout_amp_purge_missing' => 'the phantom-AMP purge (no AMP plugin, /amp/ fetch) is recognised',
    'checkout_self_fetch_failed' => 'the failing self-fetch (timeout) is recognised',
) as $sig_type => $sig_label) {
    $sig_hit = null;
    foreach ((array) (isset($findings[$sig_type]) ? $findings[$sig_type] : array()) as $f) {
        if ('sspa-slow-integration' === $f['component']) {
            $sig_hit = json_decode($f['evidence'], true);
        }
    }
    sspa_t(null !== $sig_hit, $sig_label . ($sig_hit ? ' (' . round((float) $sig_hit['ms']) . 'ms)' : ''));
}

// The waterfall's customer-facing panels must contain NOTHING from the harness steps.
// The fixture makes a call during cancellation - i.e. during our delete step - and it
// must not appear; the two payment_complete calls must, with query keys and a trace.
$wf_check = SSPA_Checkout_Flow::waterfall($run_id);
$harness_leak = 0;
$fixture_calls = 0;
$has_q = false;
$has_trace = false;
foreach ((array) $wf_check['http'] as $c) {
    if (in_array($c['step'], array('flow-delete-order', 'flow-preflight'), true)) {
        $harness_leak++;
    }
    if ('sspa-slow-integration' === $c['component'] && 'flow-place-order' === $c['step']) {
        $fixture_calls++;
        if (!empty($c['q']) && false !== strpos($c['q'], 'sspa_test_slow')) {
            $has_q = true;
        }
        if (!empty($c['trace']) || !empty($c['caller'])) {
            $has_trace = true;
        }
    }
}
sspa_t(0 === $harness_leak, "no harness calls leak into the outbound list ($harness_leak leaked)");
sspa_t(5 === $fixture_calls, "all customer-facing fixture calls listed ($fixture_calls)");
sspa_t($has_q, 'outbound calls keep their query-string keys');
sspa_t($has_trace, 'outbound calls carry the calling function');
$rollup_steps = array();
foreach ((array) (isset($wf_check['profile']['components'][0]['by_step']) ? $wf_check['profile']['components'][0]['by_step'] : array()) as $step_key => $unused_ms) {
    $rollup_steps[] = $step_key;
}
sspa_t(!in_array('flow-delete-order', $rollup_steps, true) && !in_array('flow-preflight', $rollup_steps, true),
    'the component roll-up excludes the harness steps');

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

// The payment boundary must be stamped at ENTRY to payment_complete()
// (pre_payment_complete): everything after it - status transition, emails, purges,
// integrations - is confirmation-wait, not at-risk time. Filing it on the wrong side
// inverts the report's central split.
sspa_t(is_array($no_pm) && true === $no_pm['pre_mark_hooked'],
    'the boundary mark is hooked on woocommerce_pre_payment_complete in a flow request');
sspa_t(is_array($no_pm) && true === $no_pm['user_mark_hooked'],
    'auto-created customer accounts are marked at creation in a flow request');
sspa_t(is_array($unarmed) && empty($unarmed['pre_mark_hooked']),
    'no boundary hook outside a flow request');

// ---------------------------------------------------------------- 12b. forced accounts + a
// pluggable-wp_mail mailer, together (both are global store state, one extra purchase)
//
// Guest checkout disabled = the store creates a customer ACCOUNT for the purchase, which
// the run must delete again - that account is residue a real store owner would find in
// their user list. And a mailer that replaces the pluggable wp_mail() never fires
// wp_mail_succeeded/failed, which used to collapse a three-email checkout to "1 message".

$plant('sspa-mail-api-mimic', <<<'PHP'
<?php
/** Plugin Name: SSPA Mail API Mimic (test fixture) */
// Mimics Mailgun's HTTP mode: replaces the pluggable wp_mail(), applies the wp_mail
// filter like core does, then "sends" without PHPMailer and without firing
// wp_mail_succeeded or wp_mail_failed. The guard is how real override plugins do it too:
// in a web request plugins load before pluggable.php so this definition wins; in the
// CLI process that activates the fixture, core's copy is already loaded.
if ( ! function_exists( 'wp_mail' ) ) {
    function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
        apply_filters( 'wp_mail', compact( 'to', 'subject', 'message', 'headers', 'attachments' ) );
        $log = get_option( 'sspa_test_api_mail_log', array() );
        $log[] = (array) $to;
        update_option( 'sspa_test_api_mail_log', $log, false );
        return true;
    }
}
PHP
, true);
delete_option('sspa_test_api_mail_log');
$guest_before = get_option('woocommerce_enable_guest_checkout');
update_option('woocommerce_enable_guest_checkout', 'no');
update_option('woocommerce_enable_signup_and_login_from_checkout', 'yes');
wp_cache_flush();
sleep(3); // opcache

$users_before = count(get_users(array('fields' => 'ID')));
$acct_run = SSPA_Run_Controller::start(array('type' => 'checkout', 'user_id' => 1, 'mail_mode' => 'deliver'));
if (is_wp_error($acct_run)) {
    sspa_t(false, 'forced-account run start: ' . $acct_run->get_error_message());
} else {
    $deadline = time() + 300;
    do {
        SSPA_Run_Controller::process_batch($acct_run);
        $s = SSPA_Run_Controller::status($acct_run);
    } while ($s && in_array($s['status'], array('crawling', 'analysing'), true) && time() < $deadline);
    $acct_notes = json_decode((string) SSPA_Run_Controller::run_row($acct_run)['notes'], true);

    sspa_t(is_array($acct_notes) && 'ok' === $acct_notes['outcome'],
        'purchase completes on a store that disallows guest checkout ('
        . (is_array($acct_notes) ? $acct_notes['outcome'] : '?')
        . (isset($acct_notes['flow']['error']) ? ' - ' . $acct_notes['flow']['error'] : '') . ')');
    sspa_t(is_array($acct_notes) && (int) $acct_notes['safety']['users_deleted'] >= 1 && 0 === (int) $acct_notes['safety']['users_left'],
        'the auto-created customer account was deleted ('
        . (is_array($acct_notes) ? (int) $acct_notes['safety']['users_deleted'] : 0) . ' deleted, '
        . (is_array($acct_notes) ? (int) $acct_notes['safety']['users_left'] : '?') . ' left)');
    $users_after = count(get_users(array('fields' => 'ID')));
    sspa_t($users_after === $users_before, "zero account residue ($users_before -> $users_after users)");
    sspa_t(0 === count(get_users(array('meta_key' => SSPA_Checkout_Flow::TEMP_META, 'fields' => 'ID'))),
        'no _sspa_temp user markers left behind');

    // Mail through the pluggable override: every send must be counted, none timed.
    $sent = get_option('sspa_test_api_mail_log', array());
    $acct_wf = SSPA_Checkout_Flow::waterfall($acct_run);
    sspa_t(count($sent) >= 2, 'the mimic mailer really sent order emails (' . count($sent) . ')');
    sspa_t((int) $acct_wf['mail']['count'] >= count($sent) - 1,
        'every send through a pluggable wp_mail is counted (' . $acct_wf['mail']['count'] . ' of ' . count($sent) . ')');
    sspa_t((int) $acct_wf['mail']['untimed'] >= 1, 'those sends are reported as untimed, not as 0ms');
}
update_option('woocommerce_enable_guest_checkout', $guest_before);
$remove('sspa-mail-api-mimic');
delete_option('sspa_test_api_mail_log');
wp_cache_flush();

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

// Remove them before the classic section: they are cheaper than the real target product,
// so leaving them published would have the next run buy one of THEM, and the stock
// assertions below would then be checking a product the run never touched.
wp_delete_post($oos_id, true);
wp_delete_post($short_id, true);
wc_delete_product_transients($target_id);

// ---------------------------------------------------------------- 14. the classic checkout
//
// The shortcode checkout is a different flow with different failure modes, so it gets its
// own run rather than being assumed to work because the block one does. The store is
// pointed at throwaway shortcode pages and put back afterwards; the block pages are never
// edited.

$orig_cart_page = (int) get_option('woocommerce_cart_page_id');
$orig_checkout_page = (int) get_option('woocommerce_checkout_page_id');
$classic_cart = wp_insert_post(array('post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'SSPA classic cart', 'post_content' => '[woocommerce_cart]'));
$classic_checkout = wp_insert_post(array('post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'SSPA classic checkout', 'post_content' => '[woocommerce_checkout]'));
update_option('woocommerce_cart_page_id', $classic_cart);
update_option('woocommerce_checkout_page_id', $classic_checkout);
wp_cache_flush();

sspa_t('classic' === SSPA_Checkout_Preflight::checkout_type(), 'shortcode checkout detected as classic');

// The place-order nonce must be bound to the LOGGED-OUT loopback visitor's cart session,
// not to this process. If a refactor drops either half of that binding these values
// collapse together, and the run below stops completing.
$bound = SSPA_Checkout_Flow::guest_nonce('woocommerce-process_checkout', 't_testcustomerid');
$unbound = SSPA_Checkout_Flow::guest_nonce('woocommerce-process_checkout', '');
$as_admin = wp_create_nonce('woocommerce-process_checkout');
sspa_t($bound !== $unbound && $bound !== $as_admin, 'the place-order nonce binds to the cart session, not to this process');
// update-order-review does NOT start with "woocommerce", so WooCommerce leaves its uid
// alone and the session must NOT change it.
sspa_t(SSPA_Checkout_Flow::guest_nonce('update-order-review', 't_testcustomerid')
    === SSPA_Checkout_Flow::guest_nonce('update-order-review', ''), 'update-order-review stays bound to the logged-out uid');

$orders_pre_classic = count(wc_get_orders(array('limit' => -1, 'return' => 'ids', 'status' => 'all')));
$stock_pre_classic = (int) wc_get_product($target_id)->get_stock_quantity();
delete_option('sspa_test_mail_log');

$classic_run = SSPA_Run_Controller::start(array('type' => 'checkout', 'user_id' => 1, 'mail_mode' => 'deliver'));
if (is_wp_error($classic_run)) {
    sspa_t(false, 'classic checkout start: ' . $classic_run->get_error_message());
} else {
    $deadline = time() + 300;
    do {
        SSPA_Run_Controller::process_batch($classic_run);
        $s = SSPA_Run_Controller::status($classic_run);
    } while ($s && in_array($s['status'], array('crawling', 'analysing'), true) && time() < $deadline);

    $classic_row = SSPA_Run_Controller::run_row($classic_run);
    $classic_notes = json_decode((string) $classic_row['notes'], true);
    sspa_t('done' === $classic_row['status'] && 'ok' === $classic_notes['outcome'],
        'classic purchase completed (' . $classic_row['status'] . '/' . $classic_notes['outcome']
        . (isset($classic_notes['flow']['error']) ? ' - ' . $classic_notes['flow']['error'] : '') . ')');
    sspa_t('classic' === $classic_notes['flow']['checkout_type'], 'run recorded as a classic checkout');
    sspa_t(in_array($classic_notes['flow']['checkout_nonce_source'], array('checkout_page', 'order_review'), true),
        'classic checkout used the nonce rendered for its guest session (' . $classic_notes['flow']['checkout_nonce_source'] . ')');
    sspa_t(!empty($classic_notes['flow']['session_customer_id']),
        'the cart session id was read back from the add-to-cart cookie');

    $classic_rows = array();
    foreach ($wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $profiles_table WHERE run_id = %d ORDER BY id",
        $classic_run
    ), ARRAY_A) as $r) {
        $classic_rows[$r['page_key']] = $r;
    }
    // C3's step list: no cart API, no separate rate selection, no draft order.
    $classic_expected = array(
        'flow-preflight' => 'GET',
        'flow-view-product' => 'GET',
        'flow-add-to-cart' => 'POST',
        'flow-view-cart' => 'GET',
        'flow-update-order-review' => 'POST',
        'flow-view-checkout' => 'GET',
        'flow-place-order' => 'POST',
        'flow-order-received' => 'GET',
        'flow-delete-order' => 'GET',
    );
    foreach ($classic_expected as $key => $method) {
        if (!isset($classic_rows[$key])) {
            sspa_t(false, "classic step $key was profiled");
            continue;
        }
        $row = $classic_rows[$key];
        sspa_t(
            null !== $row['page_gen_ms'] && $row['method'] === $method && null === $row['blocked_by'] && (int) $row['response_code'] < 400,
            sprintf('classic step %-26s %s %s, gen=%sms', $key, $row['method'], $row['response_code'], round((float) $row['page_gen_ms'], 1))
        );
    }

    sspa_t(!empty($classic_notes['flow']['order_total']) && (float) $classic_notes['flow']['order_total'] > 0,
        'classic order total stayed real (' . $classic_notes['flow']['order_total'] . ')');
    sspa_t(in_array($classic_notes['flow']['order_status'], array('processing', 'completed', 'on-hold'), true),
        'classic order reached a payment_complete status (' . $classic_notes['flow']['order_status'] . ')');
    sspa_t(1 === (int) $classic_notes['safety']['orders_deleted'] && 0 === (int) $classic_notes['safety']['orders_left'],
        'classic run deleted its order (' . (int) $classic_notes['safety']['orders_deleted'] . ' deleted, ' . (int) $classic_notes['safety']['orders_left'] . ' left)');

    $classic_capture = !empty($classic_rows['flow-place-order']['profile_blob'])
        ? json_decode((string) @gzuncompress($classic_rows['flow-place-order']['profile_blob']), true)
        : null;
    $classic_mark = (is_array($classic_capture) && isset($classic_capture['marks']['payment_complete']))
        ? (float) $classic_capture['marks']['payment_complete']
        : null;
    sspa_t(null !== $classic_mark && $classic_mark > 0 && $classic_mark < (float) $classic_rows['flow-place-order']['page_gen_ms'],
        'classic payment boundary marked at ' . round((float) $classic_mark, 1) . 'ms');

    // Cross-process read: bust the controller's option caches first (see the note at the
    // block-run mail read above).
    wp_cache_delete('sspa_test_mail_log', 'options');
    wp_cache_delete('notoptions', 'options');
    $classic_mail = get_option('sspa_test_mail_log', array());
    sspa_t(count($classic_mail) >= 1, 'classic run sent order emails for real (' . count($classic_mail) . ')');

    $orders_post_classic = count(wc_get_orders(array('limit' => -1, 'return' => 'ids', 'status' => 'all')));
    sspa_t($orders_post_classic === $orders_pre_classic, "classic zero order residue ($orders_pre_classic -> $orders_post_classic)");
    sspa_t((int) wc_get_product($target_id)->get_stock_quantity() === $stock_pre_classic,
        "classic stock restored ($stock_pre_classic -> " . (int) wc_get_product($target_id)->get_stock_quantity() . ')');
    sspa_t(false === get_option(SSPA_Checkout_Flow::TEMP_OPTION, false), 'classic run cleared sspa_flow_temp');
}

update_option('woocommerce_cart_page_id', $orig_cart_page);
update_option('woocommerce_checkout_page_id', $orig_checkout_page);
wp_delete_post($classic_cart, true);
wp_delete_post($classic_checkout, true);
wp_cache_flush();
sspa_t('block' === SSPA_Checkout_Preflight::checkout_type(), 'store put back on the block checkout');

// ---------------------------------------------------------------- teardown

$target = wc_get_product($target_id);
$target->set_manage_stock($restore_stock_settings['manage']);
$target->set_stock_quantity($restore_stock_settings['qty']);
$target->save();
if (false !== $shipping_method_id) {
    $shipping_zone->delete_shipping_method($shipping_method_id);
}
delete_option('sspa_test_mail_log');
$remove('sspa-slow-integration');
$remove('sspa-mail-observer');
