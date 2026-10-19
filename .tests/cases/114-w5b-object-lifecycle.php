<?php
require_once __DIR__ . '/../lib/retained-fixtures.php';
sspa_retained_reset('114');
function sspa_w5b_t($ok, $label) { echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n"; }
wp_set_current_user(1);
foreach (array('post', 'page', 'product') as $type) {
    $transport = 'product' === $type ? 'classic' : 'rest';
    $launch = SSPA_Workflow_Analysis::launch($type, 0, $transport);
    sspa_w5b_t(!is_wp_error($launch), "$type workflow launches");
    if (is_wp_error($launch)) continue;
    $id = $launch['object_id'];
    sspa_w5b_t('private' === get_post_status($id), "$type workflow starts private");
    SSPA_Workflow_Analysis::cleanup();
}
$id = SSPA_Probes::create_temp_order(true);
$order = wc_get_order($id);
$product_id = (int) $order->get_meta('_sspa_temp_product_id');
$product = wc_get_product($product_id);
sspa_w5b_t($product && 'private' === $product->get_status(), 'owned order product is private');
// A real unauthenticated add-to-cart request must not create a public purchase path.
$response = wp_remote_get(add_query_arg('add-to-cart', $product_id, wc_get_cart_url()), array('timeout' => 30));
sspa_w5b_t(!is_wp_error($response) && false === strpos(wp_remote_retrieve_body($response), 'SSPA write-profile test product</a>'), 'owned product cannot be added to a visitor cart');
$cart = wp_remote_get(rest_url('wc/store/v1/cart'), array('timeout' => 20));
$token = wp_remote_retrieve_header($cart, 'cart-token');
$add = wp_remote_post(rest_url('wc/store/v1/cart/add-item'), array('timeout' => 20, 'headers' => array('Cart-Token' => $token, 'Content-Type' => 'application/json'), 'body' => wp_json_encode(array('id' => $product_id, 'quantity' => 1))));
$added = json_decode(wp_remote_retrieve_body($add), true);
sspa_w5b_t($token && 400 === wp_remote_retrieve_response_code($add) && 'woocommerce_rest_product_not_purchasable' === ($added['code'] ?? ''), 'real guest Store API refuses the owned test product as not purchasable');

$order->set_date_created(time() - 7200); $order->save();
SSPA_Run_Controller::cleanup();
sspa_w5b_t(!wc_get_order($id) && !wc_get_product($product_id), 'hourly janitor deletes abandoned write order and its product');
// Keep the deliberate red fixture from obstructing subsequent runs.
SSPA_Probes::delete_temp($id, true);
// Deferred WooCommerce delivery happens outside the profiling request and with_admin.
$settings = get_option('woocommerce_new_order_settings', array());
$changed = $settings; $changed['enabled'] = 'yes'; $changed['recipient'] = 'store-recipient@example.invalid';
update_option('woocommerce_new_order_settings', $changed);
$id = SSPA_Probes::create_temp_order();
$seen = null;
$observe = static function($pre, $atts) use (&$seen) { $seen = $atts; return true; };
add_filter('pre_wp_mail', $observe, PHP_INT_MAX, 2);
$mailer = WC()->mailer()->get_emails()['WC_Email_New_Order'];
$mailer->settings = $changed; $mailer->recipient = $changed['recipient'];
$mailer->trigger($id, wc_get_order($id));
remove_filter('pre_wp_mail', $observe, PHP_INT_MAX);
sspa_w5b_t($seen && false !== strpos(implode(',', (array)$seen['to']), 'synthetic-admin+sspa-perf-'), 'deferred PA order mail reaches only administrator');
SSPA_Probes::delete_temp($id, true);
update_option('woocommerce_new_order_settings', $settings);
// A late mailer callback must not add external recipients inside the PA-object gate.
$late = static function($mailer) { $mailer->addAddress('late-to@example.invalid'); $mailer->addCC('late-cc@example.invalid'); $mailer->addBCC('late-bcc@example.invalid'); };
add_action('phpmailer_init', $late, PHP_INT_MAX - 1);
$tagged = SSPA_Mail::address('row-114');
$sent = SSPA_Mail::with_admin(static function() use ($tagged) { return wp_mail($tagged, 'W5b final recipient boundary', 'Synthetic regression mail.'); });
remove_action('phpmailer_init', $late, PHP_INT_MAX - 1);
$mailer = $GLOBALS['phpmailer'];
sspa_w5b_t($sent && array($tagged) === array_column($mailer->getToAddresses(), 0) && !$mailer->getCcAddresses() && !$mailer->getBccAddresses(), 'final PHPMailer gate preserves the per-run tag and removes late external recipients');
$admin = get_option('admin_email');
try {
    $long = str_repeat('a', 60) . '@example.invalid';
    update_option('admin_email', $long);
    sspa_w5b_t($long === get_option('admin_email') && $long === SSPA_Mail::address('long-tag'), 'overlong plus local part uses the validated plain administrator address');
} finally { update_option('admin_email', $admin); }
$customer = SSPA_Auth::test_customer_id();
$updates = 0;
$observe_update = static function() use (&$updates) { ++$updates; };
add_action('profile_update', $observe_update);
SSPA_Auth::test_customer_id();
remove_action('profile_update', $observe_update);
sspa_w5b_t($customer && 0 === $updates, 'unchanged synthetic customer email does not fire profile_update');
