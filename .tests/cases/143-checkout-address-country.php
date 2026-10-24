<?php
// The synthetic checkout address follows the store's base country. A store based in Greece
// (woocommerce_default_country "GR:I", no store postcode) used to send an empty postcode, and
// the purchase failed at place-order with "Billing Postcode / ZIP is a required field."
// (reported on demo.superspeedyplugins.com, 0.52.0). This case drives the real entry point,
// SSPA_Run_Controller::start(['type' => 'checkout']), on both checkout types, and then a
// store whose base country has no known postcode, which must stop before the cart with its
// own outcome instead of failing at place-order.
//
// The store address is this case's own entry state: it is set on the way in and put back to
// the suite's US:CA at the end so the other checkout cases keep their address. Runs, orders
// (in Trash) and the classic fixture pages are retained for inspection.

function sspa_t143($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
    return $ok;
}

if (!class_exists('WooCommerce')) {
    echo "FAIL: WooCommerce is not active on the test site (run .tests/setup-site.sh)\n";
    return;
}
require_once __DIR__ . '/../lib/retained-fixtures.php';
deactivate_plugins('scalability-pro/scalability-pro.php', true);
sspa_retained_reset('143-checkout-address');

$cart_page = (int) get_option('woocommerce_cart_page_id');
$checkout_page = (int) get_option('woocommerce_checkout_page_id');
foreach ((array) get_option('sspa_case143_block_pages', array()) as $option => $page) {
    // An interrupted earlier run can leave the classic fixture pages selected.
    if ($page) { update_option($option, (int) $page); }
}
$cart_page = (int) get_option('woocommerce_cart_page_id');
$checkout_page = (int) get_option('woocommerce_checkout_page_id');
update_option('sspa_case143_block_pages', array('woocommerce_cart_page_id' => $cart_page, 'woocommerce_checkout_page_id' => $checkout_page));
wp_update_post(array('ID' => $cart_page, 'post_content' => '<!-- wp:woocommerce/cart /-->'));
wp_update_post(array('ID' => $checkout_page, 'post_content' => '<!-- wp:woocommerce/checkout /-->'));
foreach ((array) get_option('sspa_case143_classic_pages', array()) as $prior) {
    if ('1' === get_post_meta((int) $prior, '_sspa_case143_fixture', true)) { wp_delete_post((int) $prior, true); }
}
$classic = array();
foreach (array('cart' => '[woocommerce_cart]', 'checkout' => '[woocommerce_checkout]') as $kind => $shortcode) {
    $classic[$kind] = wp_insert_post(array('post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Case 143 classic ' . $kind, 'post_content' => $shortcode));
    update_post_meta($classic[$kind], '_sspa_case143_fixture', '1');
}
update_option('sspa_case143_classic_pages', array_values($classic));

$set_store = function ($country, $postcode) {
    update_option('woocommerce_default_country', $country);
    update_option('woocommerce_store_postcode', $postcode);
    update_option('woocommerce_store_city', '');
    // WC_Countries caches the locale and base address per request.
    WC()->countries = new WC_Countries();
    wp_cache_flush();
};
$run_checkout = function ($label) {
    $run_id = SSPA_Run_Controller::start(array('type' => 'checkout', 'user_id' => 1, 'allow_integrations' => true, 'allow_webhooks' => true));
    if (is_wp_error($run_id)) {
        echo "FAIL: $label: checkout start: " . $run_id->get_error_message() . "\n";
        return null;
    }
    $saved = (array) get_option('sspa_retained_143-checkout-address', array());
    $saved['runs'] = array_merge((array) ($saved['runs'] ?? array()), array((int) $run_id));
    sspa_retained_save('143-checkout-address', $saved);
    $deadline = time() + 300;
    do {
        SSPA_Run_Controller::process_batch($run_id);
        $status = SSPA_Run_Controller::status($run_id);
    } while ($status && in_array($status['status'], array('queued', 'crawling', 'analysing'), true) && time() < $deadline);
    $run = SSPA_Run_Controller::run_row($run_id);
    $notes = json_decode((string) $run['notes'], true);
    global $wpdb;
    $steps = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE run_id = %d AND page_key <> %s', SSPA_Schema::table('profiles'), (int) $run_id, 'flow-preflight'));
    return array('id' => (int) $run_id, 'run' => $run, 'notes' => is_array($notes) ? $notes : array(), 'steps' => $steps);
};
$describe = function ($r) {
    $n = $r['notes'];
    return 'run ' . $r['id'] . ', outcome ' . (isset($n['outcome']) ? $n['outcome'] : '?')
        . (!empty($n['flow']['error']) ? ' - ' . $n['flow']['error'] : '');
};

// ---------------------------------------------------------------- 1. Greece, no store postcode

$set_store('GR:I', '');
$address = SSPA_Checkout_Flow::default_address();
sspa_t143('GR' === $address['country'] && 'I' === $address['state'], 'the address keeps the store base country and state (GR, I): ' . wp_json_encode(array($address['country'], $address['state'])));
sspa_t143('' !== $address['postcode'] && WC_Validation::is_postcode(wc_format_postcode($address['postcode'], 'GR'), 'GR'), 'a Greek store without a postcode gets a valid Greek postcode (got "' . $address['postcode'] . '")');

foreach (array('block' => null, 'classic' => $classic) as $type => $pages) {
    if ($pages) {
        update_option('woocommerce_cart_page_id', $pages['cart']);
        update_option('woocommerce_checkout_page_id', $pages['checkout']);
        wp_cache_flush();
        sleep(1);
    }
    sspa_t143($type === SSPA_Checkout_Preflight::checkout_type(), "the store is on the $type checkout");
    $r = $run_checkout("GR $type");
    if (!$r) { continue; }
    sspa_t143('ok' === ($r['notes']['outcome'] ?? ''), "a Greek store with no postcode completes a $type purchase (" . $describe($r) . ')');
    $order = !empty($r['notes']['flow']['order_id']) ? wc_get_order((int) $r['notes']['flow']['order_id']) : null;
    sspa_t143($order && 'GR' === $order->get_billing_country() && '' !== $order->get_billing_postcode(), "the $type order was placed with a Greek billing address" . ($order ? ' (' . $order->get_billing_country() . ' ' . $order->get_billing_postcode() . ', order ' . $order->get_id() . ')' : ' (no order)'));
}
update_option('woocommerce_cart_page_id', $cart_page);
update_option('woocommerce_checkout_page_id', $checkout_page);
wp_cache_flush();

// ---------------------------------------------------------------- 2. no known postcode

// Antarctica: a base country WooCommerce offers, whose locale requires a postcode, and for
// which no real postcode exists to fall back to. The run must stop before adding to cart.
$set_store('AQ', '');
$address = SSPA_Checkout_Flow::default_address();
sspa_t143('AQ' === $address['country'] && '' === $address['postcode'], 'no postcode is invented for a country without one (got "' . $address['postcode'] . '")');
$orders_before = (int) $GLOBALS['wpdb']->get_var('SELECT COUNT(*) FROM ' . $GLOBALS['wpdb']->prefix . 'wc_orders');
$r = $run_checkout('AQ');
if ($r) {
    sspa_t143('checkout_address_incomplete' === ($r['notes']['outcome'] ?? ''), 'a store address without a required postcode stops with its own outcome (' . $describe($r) . ')');
    $error = (string) ($r['notes']['flow']['error'] ?? '');
    sspa_t143(false !== strpos($error, 'WooCommerce > Settings > General') && false !== stripos($error, 'postcode'), 'the failure tells the admin to set the store postcode in WooCommerce > Settings > General: "' . $error . '"');
    sspa_t143(0 === $r['steps'], 'nothing was requested: no product view, no add to cart (' . $r['steps'] . ' purchase steps profiled after the read-only pre-flight probe)');
    $orders_after = (int) $GLOBALS['wpdb']->get_var('SELECT COUNT(*) FROM ' . $GLOBALS['wpdb']->prefix . 'wc_orders');
    sspa_t143($orders_after === $orders_before, "no order was created ($orders_before before, $orders_after after)");
    sspa_t143($r['run'] && 'failed' === $r['run']['status'], 'the run is recorded as failed, not as a measured purchase (status ' . ($r['run'] ? $r['run']['status'] : 'null') . ')');
}

$set_store('US:CA', '');
