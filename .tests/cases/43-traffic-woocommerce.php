<?php
// Phase 3 WooCommerce adapter: basket, cart, logged-in cohort, order and delayed payment
// events join through HMAC keys while planted customer/order/product values never enter output.

function sspa_tw_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

global $wpdb;
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

$events = SSPA_Schema::table('traffic_events');
$collections = SSPA_Schema::table('traffic_collections');
SSPA_Traffic_Helper::remove();
$wpdb->query("DELETE FROM $events");
$wpdb->query("DELETE FROM $collections");

$product_ids = wc_get_products(array('status' => 'publish', 'type' => 'simple', 'limit' => 1, 'return' => 'ids'));
$product_id = $product_ids ? (int) $product_ids[0] : 0;
sspa_tw_t($product_id > 0, 'fixture has a simple WooCommerce product');
if (!$product_id) {
    return;
}

$started = SSPA_Traffic_Collection::start('24h', 'test');
sspa_tw_t(!is_wp_error($started), 'WooCommerce traffic collection starts');
if (is_wp_error($started)) {
    echo 'FAIL: start error: ' . $started->get_error_message() . "\n";
    return;
}
$collection_id = (int) $started['collection']['id'];
$collection_row = SSPA_Traffic_Collection::get($collection_id);
SSPA_Traffic_Helper::install(array(
    'collection_id' => $collection_id,
    'collect_until' => strtotime($collection_row['collect_until'] . ' UTC'),
    'outcomes_until' => strtotime($collection_row['outcomes_until'] . ' UTC'),
    'event_id_stop' => (int) $collection_row['event_id_stop'],
    'origin_sample_modulus' => 1,
    'key_option' => SSPA_Traffic_Collection::key_option($collection_id),
));

$add = wp_remote_get(add_query_arg('add-to-cart', $product_id, home_url('/')), array('timeout' => 20, 'redirection' => 0));
$cookies = is_wp_error($add) ? array() : wp_remote_retrieve_cookies($add);
$cart = wp_remote_get(wc_get_cart_url(), array('timeout' => 20, 'cookies' => $cookies));
$basket_events = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $events WHERE collection_id = %d AND event_code = %d", $collection_id, SSPA_Traffic_Codes::EVENT_BASKET_STARTED));
$cart_events = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $events WHERE collection_id = %d AND event_code = %d", $collection_id, SSPA_Traffic_Codes::EVENT_CART_VIEWED));
$basket_request = $wpdb->get_row($wpdb->prepare("SELECT actor_key,actor_state,flags FROM $events WHERE collection_id = %d AND event_code = 1 AND actor_state = %d ORDER BY id DESC LIMIT 1", $collection_id, SSPA_Traffic_Codes::ACTOR_GUEST_NON_EMPTY_BASKET), ARRAY_A);
sspa_tw_t(!is_wp_error($add) && !is_wp_error($cart), 'guest basket requests complete');
sspa_tw_t($basket_events >= 1 && $cart_events >= 1, 'empty-to-non-empty basket and cart view events are observed');
sspa_tw_t($basket_request && strlen($basket_request['actor_key']) === 12, 'guest basket request has only a twelve-byte keyed actor join');

$planted_email = 'traffic-planted@example.test';
$user_id = wp_create_user('sspa-traffic-planted', wp_generate_password(24), $planted_email);
if (!is_wp_error($user_id)) {
    (new WP_User($user_id))->set_role('customer');
    $expires = time() + HOUR_IN_SECONDS;
    $auth_cookie = new WP_Http_Cookie(array(
        'name' => LOGGED_IN_COOKIE,
        'value' => wp_generate_auth_cookie($user_id, $expires, 'logged_in'),
        'expires' => $expires,
    ), home_url('/'));
    wp_remote_get(home_url('/my-account/?sspa_traffic_fixture=logged-in'), array('timeout' => 20, 'cookies' => array($auth_cookie)));
    wp_remote_get(home_url('/shop/?sspa_traffic_fixture=guest-to-account'), array('timeout' => 20, 'cookies' => array_merge($cookies, array($auth_cookie))));
}
$logged_in_request = $wpdb->get_row($wpdb->prepare("SELECT actor_key,actor_state FROM $events WHERE collection_id = %d AND event_code = 1 AND actor_state = %d ORDER BY id DESC LIMIT 1", $collection_id, SSPA_Traffic_Codes::ACTOR_LOGGED_IN_NO_BASKET), ARRAY_A);
$alias_event = $wpdb->get_row($wpdb->prepare("SELECT actor_key,related_actor_key FROM $events WHERE collection_id = %d AND event_code = %d ORDER BY id DESC LIMIT 1", $collection_id, SSPA_Traffic_Codes::EVENT_ACTOR_ALIAS), ARRAY_A);
sspa_tw_t(!is_wp_error($user_id) && $logged_in_request && strlen($logged_in_request['actor_key']) === 12, 'logged-in customer request is retained exactly under a keyed actor join');
sspa_tw_t($alias_event && strlen($alias_event['actor_key']) === 12 && strlen($alias_event['related_actor_key']) === 12 && $alias_event['actor_key'] !== $alias_event['related_actor_key'], 'guest basket session aliases to the later authenticated account without raw ids');

$fixture_dir = WP_PLUGIN_DIR . '/sspa-traffic-woo-fixture';
if (!is_dir($fixture_dir)) {
    mkdir($fixture_dir);
}
$fixture_file = $fixture_dir . '/fixture.php';
$fixture_code = <<<'PHP'
<?php
/**
 * Plugin Name: SSPA Traffic Woo Fixture
 */
add_action('wp_ajax_nopriv_sspa_traffic_woo_fixture', 'sspa_traffic_woo_fixture');
function sspa_traffic_woo_fixture() {
    if (!hash_equals((string) get_option('sspa_traffic_woo_fixture_token'), (string) ($_GET['token'] ?? ''))) {
        wp_send_json_error('forbidden', 403);
    }
    $mode = sanitize_key((string) ($_GET['mode'] ?? ''));
    if ('create' === $mode || 'create-admin' === $mode) {
        $product = wc_get_product((int) $_GET['product']);
        $order = wc_create_order();
        $order->set_created_via('create-admin' === $mode ? 'admin' : 'checkout');
        $order->set_currency('EUR');
        $order->add_product($product, 1);
        $order->calculate_totals();
        $order->save();
        if ('create' === $mode) {
            update_option('sspa_traffic_woo_fixture_order', $order->get_id(), false);
        } else {
            update_option('sspa_traffic_woo_fixture_admin_order', $order->get_id(), false);
        }
        do_action('woocommerce_checkout_order_created', $order);
        wp_send_json_success(array('created' => true));
    }
    if ('pay' === $mode) {
        $order = wc_get_order((int) get_option('sspa_traffic_woo_fixture_order'));
        if (!$order) {
            wp_send_json_error('missing');
        }
        $order->payment_complete('fixture-transaction');
        wp_send_json_success(array('paid' => true));
    }
    wp_send_json_error('mode');
}
PHP;
file_put_contents($fixture_file, $fixture_code);
$activated = activate_plugin('sspa-traffic-woo-fixture/fixture.php');
$token = wp_generate_password(32, false, false);
update_option('sspa_traffic_woo_fixture_token', $token, false);

$base = admin_url('admin-ajax.php?action=sspa_traffic_woo_fixture&token=' . rawurlencode($token));
$created = wp_remote_get($base . '&mode=create&product=' . $product_id, array('timeout' => 20));
$paid = wp_remote_get($base . '&mode=pay', array('timeout' => 20));
$admin_created = wp_remote_get($base . '&mode=create-admin&product=' . $product_id, array('timeout' => 20));
$order_id = (int) get_option('sspa_traffic_woo_fixture_order');
$order_event = $wpdb->get_row($wpdb->prepare("SELECT commerce_key,value_minor,currency,flags FROM $events WHERE collection_id = %d AND event_code = %d AND (flags & %d) = 0 ORDER BY id DESC LIMIT 1", $collection_id, SSPA_Traffic_Codes::EVENT_ORDER_CREATED, SSPA_Traffic_Codes::FLAG_EXCLUDED_ADMIN), ARRAY_A);
$paid_event = $wpdb->get_row($wpdb->prepare("SELECT commerce_key FROM $events WHERE collection_id = %d AND event_code IN (%d,%d) ORDER BY id DESC LIMIT 1", $collection_id, SSPA_Traffic_Codes::EVENT_PAYMENT_COMPLETED, SSPA_Traffic_Codes::EVENT_PAID_STATUS_REACHED), ARRAY_A);
$excluded_admin = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $events WHERE collection_id = %d AND event_code = %d AND (flags & %d) <> 0", $collection_id, SSPA_Traffic_Codes::EVENT_ORDER_CREATED, SSPA_Traffic_Codes::FLAG_EXCLUDED_ADMIN));
sspa_tw_t(!is_wp_error($activated) && !is_wp_error($created) && !is_wp_error($paid) && !is_wp_error($admin_created), 'classic order, delayed payment and admin-order fixtures complete');
sspa_tw_t($order_event && strlen($order_event['commerce_key']) === 12 && 'EUR' === $order_event['currency'], 'shopper order stores keyed commerce join, minor-unit value and ISO currency only');
sspa_tw_t($paid_event && hash_equals($order_event['commerce_key'], $paid_event['commerce_key']), 'delayed payment joins to order creation without storing order id');
sspa_tw_t($excluded_admin >= 1, 'admin-created order is marked outside the shopper funnel');

$observations = SSPA_Traffic_Collection::observations($collection_id);
$encoded = wp_json_encode($observations);
sspa_tw_t(!is_wp_error($observations) && SSPA_Traffic_Privacy::SCHEMA === $observations['schema'], 'privacy-safe experimental observations build');
sspa_tw_t(false === strpos($encoded, $planted_email) && false === strpos($encoded, 'sspa-traffic-planted') && !SSPA_Traffic_Privacy::validate_export($observations), 'observation JSON contains no planted customer data or forbidden identity properties');
$columns = $wpdb->get_col("SHOW COLUMNS FROM $events");
sspa_tw_t(!array_intersect($columns, array('email', 'ip', 'user_id', 'session_id', 'order_id', 'product_id', 'coupon_code', 'user_agent')), 'event table has no prohibited customer-data columns');

foreach (array($order_id, (int) get_option('sspa_traffic_woo_fixture_admin_order')) as $delete_id) {
    $order = wc_get_order($delete_id);
    if ($order) {
        $order->delete(true);
    }
}
if (!is_wp_error($user_id)) {
    wp_delete_user($user_id);
}
deactivate_plugins('sspa-traffic-woo-fixture/fixture.php');
unlink($fixture_file);
rmdir($fixture_dir);
delete_option('sspa_traffic_woo_fixture_token');
delete_option('sspa_traffic_woo_fixture_order');
delete_option('sspa_traffic_woo_fixture_admin_order');
SSPA_Traffic_Collection::stop($collection_id, true);
delete_option(SSPA_Traffic_Collection::key_option($collection_id));
$wpdb->query("DELETE FROM $events");
$wpdb->query("DELETE FROM $collections");
