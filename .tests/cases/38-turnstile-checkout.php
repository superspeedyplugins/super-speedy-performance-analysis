<?php
// A signed synthetic checkout cannot solve an interactive Cloudflare Turnstile challenge.
// Plant the same documented filter contract and both WooCommerce validation surfaces, then
// drive the real checkout-flow entry point. Without SSPA's request-scoped bypass this case
// fails at place_order with "Please verify that you are human" and creates no order.

function sspa_turnstile_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

if (!class_exists('WooCommerce')) {
    echo "FAIL: WooCommerce is not active on the test site (run .tests/setup-site.sh)\n";
    return;
}

$slug = 'sspa-turnstile-fixture';
$dir = WP_PLUGIN_DIR . '/' . $slug;
if (!is_dir($dir)) {
    mkdir($dir);
}
file_put_contents($dir . '/' . $slug . '.php', <<<'PHP'
<?php
/** Plugin Name: SSPA Turnstile Checkout Fixture */

// Detection surface exposed by Simple Cloudflare Turnstile when configured.
function cfturnstile_field_show() {}

function sspa_turnstile_fixture_disabled() {
    return (bool) apply_filters('cfturnstile_widget_disable', false);
}

// Block checkout validation, matching the Store API integration's execution point.
add_action('woocommerce_store_api_checkout_update_order_from_request', function ($order, $request) {
    if (!sspa_turnstile_fixture_disabled()) {
        throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
            'sspa_turnstile_required',
            'Please verify that you are human.',
            400
        );
    }
}, 10, 2);

// Classic checkout validation.
add_action('woocommerce_after_checkout_validation', function ($data, $errors) {
    if (!sspa_turnstile_fixture_disabled()) {
        $errors->add('sspa_turnstile_required', 'Please verify that you are human.');
    }
}, 10, 2);
PHP
);

$activated = activate_plugin($slug . '/' . $slug . '.php');
wp_cache_flush();
sleep(3); // Apache opcache revalidation.
sspa_turnstile_t(!is_wp_error($activated), 'Turnstile validation fixture activated');

$product = SSPA_Checkout_Flow::default_product();
if (!$product) {
    echo "FAIL: no purchasable product on the test site (run .tests/setup-site.sh)\n";
    deactivate_plugins($slug . '/' . $slug . '.php');
    @unlink($dir . '/' . $slug . '.php');
    @rmdir($dir);
    return;
}

// Ensure a physical default product has a real shipping method available.
$zone = new WC_Shipping_Zone(0);
$method_id = $zone->add_shipping_method('flat_rate');

$run_id = SSPA_Run_Controller::start(array(
    'type' => 'checkout',
    'user_id' => 1,
    'product_id' => $product->get_id(),
    'mail_mode' => 'construct',
    'allow_integrations' => true,
    'allow_webhooks' => true,
));

if (is_wp_error($run_id)) {
    sspa_turnstile_t(false, 'checkout start: ' . $run_id->get_error_message());
} else {
    $deadline = time() + 180;
    do {
        SSPA_Run_Controller::process_batch($run_id);
        $status = SSPA_Run_Controller::status($run_id);
    } while ($status && in_array($status['status'], array('crawling', 'analysing'), true) && time() < $deadline);

    $run = SSPA_Run_Controller::run_row($run_id);
    $notes = $run ? json_decode((string) $run['notes'], true) : array();
    sspa_turnstile_t($run && 'done' === $run['status'], 'checkout completes with Turnstile validation active');
    sspa_turnstile_t(isset($notes['outcome']) && 'ok' === $notes['outcome'], 'flow outcome is ok, not place_order_failed');
    sspa_turnstile_t(!empty($notes['flow']['human_verification_bypassed']), 'result records the request-scoped human-verification bypass');
    sspa_turnstile_t(!empty($notes['safety']) && 0 === (int) $notes['safety']['orders_left'], 'temporary order is removed');
}

if (false !== $method_id) {
    $zone->delete_shipping_method($method_id);
}
deactivate_plugins($slug . '/' . $slug . '.php');
@unlink($dir . '/' . $slug . '.php');
@rmdir($dir);
wp_cache_flush();

