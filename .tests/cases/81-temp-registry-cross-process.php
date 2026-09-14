<?php
// The temp registry (sspa_flow_temp) is written by two processes: the run process records
// the order, and the loopback place-order request records the auto-created customer when
// guest checkout is off. Cleanup runs in the run process. If it rewrites the registry from
// its own cached copy it drops the user entry the other process added, and the account is
// never deleted. Drive the real private cleanup methods in their real order across two real
// WordPress processes.
require __DIR__ . '/../lib/fleet.php';

function sspa_81_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
    if (!$ok) { $GLOBALS['sspa_81_failures']++; }
}
$GLOBALS['sspa_81_failures'] = 0;
global $wpdb;

// Clear the previous run's registry and any leftover synthetic account on the way in.
foreach (get_users(array('meta_key' => SSPA_Checkout_Flow::TEMP_META, 'fields' => 'ID', 'search' => 'sspa-case81-*', 'search_columns' => array('user_login'))) as $stale) {
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user((int) $stale);
}
delete_option(SSPA_Checkout_Flow::TEMP_OPTION);
wp_cache_delete(SSPA_Checkout_Flow::TEMP_OPTION, 'options');

// 1. The run process records its order. This primes this process's option cache exactly as
//    remember_order() does.
$order_entry_id = 999999001;
update_option(SSPA_Checkout_Flow::TEMP_OPTION, array(array('type' => 'order', 'id' => $order_entry_id, 'ts' => time())), false);

// 2. Another real WordPress process records the auto-created customer, as the loopback
//    place-order request does.
$user_id = wp_insert_user(array(
    'user_login' => 'sspa-case81-' . wp_generate_password(6, false),
    'user_pass' => wp_generate_password(24),
    'user_email' => 'case81-' . wp_generate_password(6, false) . '@example.invalid',
    'role' => 'customer',
));
sspa_81_t(!is_wp_error($user_id) && $user_id > 0, 'a real customer account exists to be cleaned up');
sspa_fleet_cli(array('eval', 'SSPA_Checkout_Flow::mark_temp_user(' . (int) $user_id . ');'));

$row = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", SSPA_Checkout_Flow::TEMP_OPTION));
$stored = maybe_unserialize((string) $row);
$stored_types = is_array($stored) ? wp_list_pluck($stored, 'type') : array();
sspa_81_t(in_array('order', $stored_types, true) && in_array('user', $stored_types, true), 'the database registry holds both the order and the user entry (' . implode(',', $stored_types) . ')');
$cached = get_option(SSPA_Checkout_Flow::TEMP_OPTION, array());
echo 'INFO: this process currently reads ' . count((array) $cached) . ' registry entries through its cache' . "\n";

// 3. Cleanup in the run process, in production order: orders are forgotten first, then
//    users are deleted.
$flow = new ReflectionClass('SSPA_Checkout_Flow');
$forget = $flow->getMethod('forget_temp_entries');
$forget->setAccessible(true);
$forget->invoke(null, 'order', array($order_entry_id));
$delete_users = $flow->getMethod('delete_temp_users');
$delete_users->setAccessible(true);
$result = array('notes' => array());
$delete_users->invokeArgs(null, array(&$result));

sspa_81_t(false === get_userdata($user_id), 'the auto-created customer account is deleted');
sspa_81_t(isset($result['notes']['users_deleted']) && 1 === (int) $result['notes']['users_deleted'], 'the run notes report the deletion (' . (isset($result['notes']['users_deleted']) ? (int) $result['notes']['users_deleted'] : 'unset') . ')');
sspa_81_t(0 === count(get_users(array('meta_key' => SSPA_Checkout_Flow::TEMP_META, 'fields' => 'ID'))), 'no _sspa_temp user marker remains');
wp_cache_delete(SSPA_Checkout_Flow::TEMP_OPTION, 'options');
sspa_81_t(false === get_option(SSPA_Checkout_Flow::TEMP_OPTION, false), 'the registry is cleared once both entries are handled');

// A failed run leaves its synthetic account in place as evidence; the next run clears it on
// the way in.
if ($GLOBALS['sspa_81_failures']) { exit(1); }
