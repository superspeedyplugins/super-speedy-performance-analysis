<?php
// The order-management step records the status the checkout left the order at, so the
// panel can name the transition honestly ("processing -> completed"). That status must come
// from the database, not from the order object this process cached while the order was still
// a checkout draft: the Store API purchase completes the order in another process. Drive the
// real private management step with a real order across two real WordPress processes.
require __DIR__ . '/../lib/fleet.php';

function sspa_82_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
    if (!$ok) { $GLOBALS['sspa_82_failures']++; }
}
$GLOBALS['sspa_82_failures'] = 0;

$product = SSPA_Checkout_Flow::default_product();
$product_id = $product ? (int) $product->get_id() : 0;
sspa_82_t($product_id > 0, 'the dedicated hidden test product exists (' . $product_id . ')');

// 1. The run process creates the order as the checkout leaves it before payment - a draft -
//    and, as remember_order() does, loads it into its own object cache.
$order = wc_create_order(array('status' => 'checkout-draft', 'created_via' => 'store-api'));
$order->add_product(wc_get_product($product_id), 1);
$order->set_billing_email('case82@example.invalid');
$order->calculate_totals();
$order->save();
$order_id = $order->get_id();
SSPA_Checkout_Flow::mark_temp_order($order_id);
$cached = wc_get_order($order_id);
sspa_82_t($cached && 'checkout-draft' === $cached->get_status(), 'this process holds the order as a checkout draft (' . ($cached ? $cached->get_status() : 'none') . ')');

// 2. Another real process completes the purchase, as the Store API request does.
sspa_fleet_cli(array('eval', '$o = wc_get_order(' . (int) $order_id . '); $o->set_status("processing"); $o->save();'));
global $wpdb;
$db_status = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}wc_orders WHERE id = %d", $order_id));
sspa_82_t('wc-processing' === $db_status, 'the database row is processing (' . $db_status . ')');

// 3. The real management step runs in the run process: view, complete, refund, Trash.
$flow = new ReflectionClass('SSPA_Checkout_Flow');
$manage = $flow->getMethod('run_order_management');
$manage->setAccessible(true);
$result = array('order_ids' => array($order_id), 'steps' => array(), 'notes' => array());
$manage->invokeArgs(null, array(new SSPA_Crawler(), array(), &$result, 1));

$from = isset($result['notes']['complete_from_status']) ? $result['notes']['complete_from_status'] : 'unset';
sspa_82_t('processing' === $from, 'the recorded before-status is the real processing status, not the cached draft (' . $from . ')');
$final = wc_get_order($order_id);
$statuses = wp_list_pluck(array_filter($result['steps'], static function ($step) { return empty($step['skipped']); }), 'page_key');
echo 'INFO: management steps run: ' . implode(', ', $statuses) . "; order now " . ($final ? $final->get_status() : 'missing') . "\n";

if ($GLOBALS['sspa_82_failures']) { exit(1); }
