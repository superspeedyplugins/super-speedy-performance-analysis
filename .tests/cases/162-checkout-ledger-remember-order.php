<?php
// The checkout flow's temporary-object ledger (sspa_flow_temp) is appended by two processes.
// The run process records each order with remember_order(); the loopback place-order request
// records the customer account WooCommerce creates when guest checkout is off. The place-order
// request writes FIRST, then the run process learns the order id and calls remember_order().
//
// Reproduced on tests-wporg-edition, 16 November 2026: three guest-checkout-off purchases in a
// row each left a marked account (_sspa_temp = 1) that the run never recorded, so it was never
// deleted and the run reported users_left 0. remember_order() read the ledger through this
// process's option cache - here the "option does not exist" entry cached when the run first
// looked - appended the order and wrote that back, dropping the account entry.
//
// Drives the real private remember_order() after a real second WordPress process records the
// account, then asserts what the database holds.
require __DIR__ . '/../lib/fleet.php';

function sspa_162_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
    if (!$ok) { $GLOBALS['sspa_162_failures']++; }
}
$GLOBALS['sspa_162_failures'] = 0;
global $wpdb;

// Clear the previous run's ledger and account on the way in.
foreach (get_users(array('search' => 'sspa-case162-*', 'search_columns' => array('user_login'), 'fields' => 'ID')) as $stale) {
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user((int) $stale);
}
delete_option(SSPA_Checkout_Flow::TEMP_OPTION);

// 1. The run process has looked at the ledger before the purchase and found none, as a run does.
wp_cache_delete(SSPA_Checkout_Flow::TEMP_OPTION, 'options');
sspa_162_t(false === get_option(SSPA_Checkout_Flow::TEMP_OPTION, false), 'precondition: the run process sees no ledger yet');

// 2. The place-order request, a separate WordPress process, records the account WooCommerce created.
$user_id = wp_insert_user(array(
    'user_login' => 'sspa-case162-' . wp_generate_password(6, false),
    'user_pass' => wp_generate_password(24),
    'user_email' => 'case162-' . wp_generate_password(6, false) . '@example.invalid',
    'role' => 'customer',
));
sspa_162_t(!is_wp_error($user_id) && $user_id > 0, 'a real customer account exists');
sspa_fleet_cli(array('eval', 'SSPA_Checkout_Flow::mark_temp_user(' . (int) $user_id . ');'));

// 3. The run process learns the order id and records it.
$order_id = 999999162;
$flow = new ReflectionClass('SSPA_Checkout_Flow');
$remember = $flow->getMethod('remember_order');
$remember->setAccessible(true);
$result = array('order_ids' => array(), 'notes' => array());
$remember->invokeArgs(null, array($order_id, &$result));

$row = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", SSPA_Checkout_Flow::TEMP_OPTION));
$stored = maybe_unserialize((string) $row);
$entries = array();
foreach ((array) $stored as $entry) {
    $entries[] = (isset($entry['type']) ? $entry['type'] : '?') . ':' . (isset($entry['id']) ? (int) $entry['id'] : 0);
}
sspa_162_t(in_array('user:' . (int) $user_id, $entries, true), 'the ledger still records the account the place-order request added (' . implode(',', $entries) . ')');
sspa_162_t(in_array('order:' . $order_id, $entries, true), 'the ledger records the order');

// 4. Cleanup then finds and removes the account, as the run's finally block does.
$delete_users = $flow->getMethod('delete_temp_users');
$delete_users->setAccessible(true);
$cleanup = array('notes' => array());
$delete_users->invokeArgs(null, array(&$cleanup));
sspa_162_t(isset($cleanup['notes']['users_left']) || isset($cleanup['notes']['users_deleted']), 'cleanup reports the account (' . wp_json_encode($cleanup['notes']) . ')');

delete_option(SSPA_Checkout_Flow::TEMP_OPTION);
if ($GLOBALS['sspa_162_failures']) { exit(1); }
