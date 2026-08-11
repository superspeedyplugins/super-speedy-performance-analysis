<?php
defined('ABSPATH') || exit;

/**
 * Checkout flow profiling: measure what a customer waits through while buying something,
 * step by step, with the site's full plugin set active and nothing switched off.
 *
 * Design doc: .docs/2026-08-07-checkout-flow-profiling.md. Two halves live in this class:
 *
 * - maybe_arm_request(): runs on EVERY request and does nothing unless the request is one
 *   of the flow's own signed, single-use, expiring loopback requests. That is where the
 *   payment gateway is skipped, the payment-boundary mark is stamped and the run's opt-out
 *   switches are applied. Real traffic is never affected.
 * - run(): drives one complete purchase over loopback and returns per-step results.
 *
 * Scope rule (doc A2): if the customer does not wait for it, it is out of scope. Work
 * queued to Action Scheduler or WP-Cron is somebody else's problem; only the in-request
 * cost of queueing it is measured here.
 */
class SSPA_Checkout_Flow {

    /**
     * Payment modes ALLOWED TO TAKE A PAYMENT, as they appear in the token's `pm` flag.
     * A whitelist, never a blacklist: a missing, unknown or future flag value must fall
     * through to no payment, because getting the default wrong in the other direction
     * charges someone real money. Empty until a gateway adapter (phase 3) justifies one.
     */
    const PAYMENT_MODES = array();

    /** Orders created by an in-flight run, so a crash cannot leave them behind. */
    const TEMP_OPTION = 'sspa_flow_temp';

    /** Meta key marking an object this plugin created. Nothing without it is ever deleted. */
    const TEMP_META = '_sspa_temp';

    /** Called from the main plugin file, unconditionally. */
    public static function register() {
        add_action('plugins_loaded', array(__CLASS__, 'maybe_arm_request'), 1);
        add_action('wp_ajax_sspa_checkout_preflight', array(__CLASS__, 'ajax_preflight'));
        add_action('wp_ajax_sspa_checkout_start', array(__CLASS__, 'ajax_start'));
        add_action('wp_ajax_sspa_checkout_result', array(__CLASS__, 'ajax_result'));
    }

    private static function ajax_guard() {
        check_ajax_referer('sspa_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('forbidden', 403);
        }
    }

    /**
     * Clicking the button does NOT start a purchase. It runs the pre-flight and returns
     * the disclosure, and only a second, explicit click starts anything. The pre-flight
     * runs every time even once consent is stored, because the answer changes whenever the
     * store's plugins do.
     */
    public static function ajax_preflight() {
        self::ajax_guard();
        if (!class_exists('WooCommerce')) {
            wp_send_json_error(__('WooCommerce is not active, so there is no checkout to profile.', 'super-speedy-performance-analysis'));
        }
        if (SSPA_Run_Controller::active_run_id()) {
            wp_send_json_error(__('An analysis is already running.', 'super-speedy-performance-analysis'));
        }
        SSPA_Helper_Files::ensure_installed();

        $crawler = new SSPA_Crawler();
        $sample = $crawler->send_profiled(home_url('/?sspa_flow_probe=1'), array(
            'method' => 'GET',
            'flags' => array('v' => 'guest', 'ck' => 'pre'),
        ));
        if (!is_array($sample['json'])) {
            wp_send_json_error(sprintf(
                /* translators: %s: an error code such as no_canary */
                __('The pre-flight request could not be measured (%s). Check the Tools tab.', 'super-speedy-performance-analysis'),
                $sample['error'] ? $sample['error'] : 'http ' . $sample['code']
            ));
        }
        wp_send_json_success(array(
            'inventory' => $sample['json'],
            'consented' => (bool) sspa_get_option('checkout_consent'),
            'defaults' => array(
                'mail_mode' => sspa_get_option('checkout_mail_mode'),
                'payment_mode' => sspa_get_option('checkout_payment_mode'),
                'allow_integrations' => (bool) sspa_get_option('checkout_allow_integrations'),
                'allow_webhooks' => (bool) sspa_get_option('checkout_allow_webhooks'),
            ),
        ));
    }

    public static function ajax_start() {
        self::ajax_guard();
        $args = array(
            'type' => 'checkout',
            'trigger' => 'adminbar',
            'allow_integrations' => !empty($_POST['allow_integrations']),
            'allow_webhooks' => !empty($_POST['allow_webhooks']),
            'mail_mode' => isset($_POST['mail_mode']) ? sanitize_key(wp_unslash($_POST['mail_mode'])) : 'deliver',
            // Whitelist again here. The panel already refuses to offer sandbox mode
            // without a matching adapter; this is the second, independent check.
            'payment_mode' => (isset($_POST['payment_mode']) && 'sandbox' === $_POST['payment_mode'] && self::sandbox_adapter())
                ? 'sandbox'
                : 'no_payment',
        );
        if (!empty($_POST['product_id'])) {
            $args['product_id'] = (int) $_POST['product_id'];
        }
        sspa_update_option('checkout_consent', true);

        $run_id = SSPA_Run_Controller::start($args);
        if (is_wp_error($run_id)) {
            wp_send_json_error($run_id->get_error_message());
        }
        wp_send_json_success(SSPA_Run_Controller::status($run_id));
    }

    /**
     * The most recent finished checkout run, as the panel renders it. Also reports an
     * in-flight run so a reopened popover reattaches instead of starting a second purchase.
     */
    public static function ajax_result() {
        global $wpdb;
        self::ajax_guard();

        $active = SSPA_Run_Controller::active_run_id();
        if ($active) {
            $run = SSPA_Run_Controller::run_row($active);
            if ($run && 'checkout' === $run['run_type']) {
                wp_send_json_success(array('running' => (int) $active));
            }
        }
        $run_id = isset($_POST['run_id']) ? (int) $_POST['run_id'] : 0;
        if (!$run_id) {
            $run_id = (int) $wpdb->get_var(
                'SELECT id FROM ' . SSPA_Schema::table('runs') . " WHERE run_type = 'checkout' AND status IN ('done','failed') ORDER BY id DESC LIMIT 1"
            );
        }
        if (!$run_id) {
            wp_send_json_success(array('found' => false));
        }
        $waterfall = self::waterfall($run_id);
        if (!$waterfall) {
            wp_send_json_success(array('found' => false));
        }
        $run = SSPA_Run_Controller::run_row($run_id);
        $waterfall['found'] = true;
        $waterfall['status'] = $run['status'];
        $waterfall['created'] = get_date_from_gmt($run['started'], get_option('date_format') . ' ' . get_option('time_format'));
        $waterfall['age_seconds'] = max(0, time() - (int) strtotime($run['started'] . ' UTC'));
        $waterfall['findings'] = $wpdb->get_results($wpdb->prepare(
            'SELECT severity, finding_type, component, page_key, evidence, recommendation_key
             FROM ' . SSPA_Schema::table('findings') . ' WHERE run_id = %d ORDER BY FIELD(severity, %s, %s, %s)',
            $run_id,
            'critical',
            'warn',
            'info'
        ), ARRAY_A);
        foreach ($waterfall['findings'] as &$finding) {
            $finding['recommendation'] = SSPA_Rules::recommendation($finding['recommendation_key']);
            $finding['evidence'] = json_decode((string) $finding['evidence'], true);
        }
        unset($finding);
        wp_send_json_success($waterfall);
    }

    private static function flag($key) {
        return isset($GLOBALS['sspa_flags'][$key]) ? $GLOBALS['sspa_flags'][$key] : null;
    }

    // ---------------- inside a profiled flow request ----------------

    /**
     * Runs INSIDE a profiled flow request only. See doc A6.
     */
    public static function maybe_arm_request() {
        if ('flow' !== self::flag('ck')) {
            return;
        }

        // One purchase per run sits inside Woo's 3-per-60s checkout rate limit, but a CLI
        // --repeats run does not, and a rate-limited step would be recorded as a failure
        // rather than a measurement. Signed single-use loopback requests only.
        add_filter('woocommerce_store_api_rate_limit_options', function ($options) {
            $options['enabled'] = false;
            return $options;
        }, PHP_INT_MAX);

        // Mark every order this request creates as ours, at the moment it is created, so
        // the delete probe and the janitor can both prove an order is safe to remove.
        add_action('woocommerce_new_order', array(__CLASS__, 'mark_temp_order'), PHP_INT_MIN);
        add_action('woocommerce_store_api_checkout_update_order_meta', array(__CLASS__, 'mark_temp_order'), PHP_INT_MIN);

        // The payment-boundary mark, used by the report to split time the customer can
        // still abandon during from time after the sale is secured (doc A6.4). Stamped in
        // EVERY payment mode.
        //
        // The boundary is ENTRY to WC_Order::payment_complete(), i.e.
        // woocommerce_pre_payment_complete: in sandbox mode the gateway has already come
        // back successful by then, so the money is in. Everything payment_complete() does
        // AFTER that - the status transition, the order emails it sends, stock reduction,
        // cache purges and integrations hanging off the transition - is confirmation-wait,
        // not at-risk time. Hooking the trailing woocommerce_payment_complete action (as
        // 0.11.0/0.11.1 did) filed all of that on the wrong side of the boundary.
        add_action('woocommerce_pre_payment_complete', array(__CLASS__, 'mark_payment_complete'), PHP_INT_MIN);
        // Fallback for anything that fires the trailing action without the leading one;
        // the isset guard in the handler means the earlier hook always wins.
        add_action('woocommerce_payment_complete', array(__CLASS__, 'mark_payment_complete'), PHP_INT_MIN);

        // A store with guest checkout disabled makes the Store API (and the classic
        // checkout) create a customer account for the purchase. That account is ours to
        // remove again - mark it the moment it is created, exactly like the order.
        add_action('woocommerce_created_customer', array(__CLASS__, 'mark_temp_user'), PHP_INT_MIN);

        // Skip the gateway WITHOUT touching cart totals, so shipping and tax still
        // calculate in full and are measured, and every downstream integration still sees
        // a normal-value order and does its normal work (doc A6.1 reason 3).
        if (!in_array(self::flag('pm'), self::PAYMENT_MODES, true)) {
            add_filter('woocommerce_order_needs_payment', '__return_false', PHP_INT_MAX);
            add_filter('woocommerce_cart_needs_payment', '__return_false', PHP_INT_MAX);
        }

        // Opt-outs. Each one makes the number no longer a real-store number, which is why
        // the run controller records any that were used in the run notes.
        if ('0' === self::flag('wh')) {
            add_filter('woocommerce_webhook_should_deliver', '__return_false', PHP_INT_MAX);
        }
        if ('0' === self::flag('ig')) {
            // Third-party callbacks register on init/woocommerce_init, so the unhooking
            // has to wait until they are all in place.
            add_action('wp_loaded', array(__CLASS__, 'unhook_integrations'), PHP_INT_MAX);
        }
    }

    public static function mark_payment_complete() {
        if (!isset($GLOBALS['sspa_marks']['payment_complete']) && isset($GLOBALS['timestart'])) {
            $GLOBALS['sspa_marks']['payment_complete'] = (microtime(true) - (float) $GLOBALS['timestart']) * 1000;
        }
    }

    /**
     * Runs inside the flow's own place-order request when the store auto-creates a
     * customer account (guest checkout disabled). Marked and recorded exactly like the
     * order, so cleanup and the janitor can both prove it is safe to remove.
     */
    public static function mark_temp_user($user_id) {
        $user_id = (int) $user_id;
        if (!$user_id) {
            return;
        }
        update_user_meta($user_id, self::TEMP_META, '1');
        $temp = get_option(self::TEMP_OPTION, array());
        $temp = is_array($temp) ? $temp : array();
        $temp[] = array('type' => 'user', 'id' => $user_id, 'ts' => time());
        update_option(self::TEMP_OPTION, $temp, false);
    }

    /** @param int|WC_Order $order_id */
    public static function mark_temp_order($order_id) {
        $order = is_object($order_id) ? $order_id : (function_exists('wc_get_order') ? wc_get_order($order_id) : null);
        if (!$order || !method_exists($order, 'update_meta_data') || $order->get_meta(self::TEMP_META)) {
            return;
        }
        $order->update_meta_data(self::TEMP_META, '1');
        $order->save_meta_data();
    }

    /**
     * Remove third-party order callbacks for this request only. Core, WooCommerce and this
     * plugin stay hooked, or the checkout would not complete at all.
     */
    public static function unhook_integrations() {
        require_once SSPA_PLUGIN_DIR . 'profiler/class-sspa-component-map.php';
        $map = new SSPA_Component_Map();
        $keep = array('core', 'woocommerce', 'super-speedy-performance-analysis', 'mu:sspa-loader');

        foreach (SSPA_Checkout_Preflight::ORDER_HOOKS as $hook) {
            if (empty($GLOBALS['wp_filter'][$hook]) || !isset($GLOBALS['wp_filter'][$hook]->callbacks)) {
                continue;
            }
            foreach ($GLOBALS['wp_filter'][$hook]->callbacks as $priority => $callbacks) {
                foreach ((array) $callbacks as $cb) {
                    $fn = isset($cb['function']) ? $cb['function'] : null;
                    $file = self::callback_file($fn);
                    if (!$file) {
                        continue;
                    }
                    $cls = $map->classify_file($file);
                    if (!in_array($cls['component'], $keep, true)) {
                        remove_action($hook, $fn, $priority);
                    }
                }
            }
        }
    }

    private static function callback_file($fn) {
        try {
            if (is_array($fn) && isset($fn[0], $fn[1])) {
                $ref = new ReflectionMethod(is_object($fn[0]) ? get_class($fn[0]) : $fn[0], $fn[1]);
            } elseif ($fn instanceof Closure) {
                $ref = new ReflectionFunction($fn);
            } elseif (is_string($fn) && function_exists($fn)) {
                $ref = new ReflectionFunction($fn);
            } else {
                return '';
            }
            return (string) $ref->getFileName();
        } catch (Throwable $e) {
            return '';
        }
    }

    // ---------------- what the run will use ----------------

    /**
     * The payment modes this store can actually offer. `no_payment` is always present
     * because it needs no configuration; `sandbox` appears only when a gateway adapter
     * matched an enabled gateway AND that adapter confirmed test mode (doc A6.2).
     *
     * @return array[] {key, label, available, note}
     */
    public static function payment_modes() {
        $modes = array(array(
            'key' => 'no_payment',
            'label' => __('No payment (default)', 'super-speedy-performance-analysis'),
            'available' => true,
            'note' => __('Measures everything the store does around the payment, including order emails, stock and every integration. Does NOT measure your payment provider\'s own latency.', 'super-speedy-performance-analysis'),
        ));

        $adapters = self::gateway_adapters();
        $matched = null;
        foreach ($adapters as $adapter) {
            if ($adapter->is_test_mode()) {
                $matched = $adapter;
                break;
            }
        }
        $modes[] = array(
            'key' => 'sandbox',
            'label' => $matched
                ? sprintf(__('Sandbox payment - %s', 'super-speedy-performance-analysis'), $matched->label())
                : __('Sandbox payment', 'super-speedy-performance-analysis'),
            'available' => (bool) $matched,
            'note' => $matched
                ? __('Also measures the gateway round trip, which is usually the single largest outbound call in the whole flow.', 'super-speedy-performance-analysis')
                : __('Needs a supported payment gateway in test mode. None of the gateways enabled on this store is one.', 'super-speedy-performance-analysis'),
        );
        return $modes;
    }

    /**
     * Gateway adapters (phase 3, doc A6.2 / T15). Empty until one is written and verified
     * against a real install of that gateway, which is the honest state: reporting "no
     * supported gateway in test mode" is correct, and guessing a payment payload is not.
     *
     * @return SSPA_Gateway_Adapter[]
     */
    public static function gateway_adapters() {
        return apply_filters('sspa_checkout_gateway_adapters', array());
    }

    /**
     * The cheapest purchasable, in-stock, simple product that NEEDS SHIPPING. A variable
     * product needs a variation id to buy, so it makes a poor default; and a virtual or
     * downloadable one skips shipping entirely, which would quietly drop zone matching,
     * method instantiation and any live carrier API call out of the measurement - often
     * the most expensive part of a real checkout. Falls back to a virtual product only
     * when the store sells nothing physical.
     *
     * @return WC_Product|null
     */
    public static function default_product($product_id = 0) {
        if (!function_exists('wc_get_products')) {
            return null;
        }
        $product_id = (int) $product_id;
        if (!$product_id) {
            $product_id = (int) sspa_get_option('checkout_product_id');
        }
        if ($product_id) {
            // An explicitly chosen product that cannot be bought is a failure, not a cue
            // to quietly buy something else: silently measuring a different product is a
            // wrong answer that looks like a right one.
            $product = wc_get_product($product_id);
            return ($product && $product->is_purchasable() && $product->is_in_stock()) ? $product : null;
        }
        $candidates = wc_get_products(array(
            'status' => 'publish',
            'type' => 'simple',
            'limit' => 50,
            'stock_status' => 'instock',
            'orderby' => 'ID',
            'order' => 'ASC',
        ));
        $best = null;
        $best_virtual = null;
        foreach ($candidates as $product) {
            if (!$product->is_purchasable() || !$product->is_in_stock() || '' === $product->get_price()) {
                continue;
            }
            if ($product->needs_shipping()) {
                if (!$best || (float) $product->get_price() < (float) $best->get_price()) {
                    $best = $product;
                }
            } elseif (!$best_virtual || (float) $product->get_price() < (float) $best_virtual->get_price()) {
                $best_virtual = $product;
            }
        }
        return $best ? $best : $best_virtual;
    }

    /**
     * A deliverable address in the store's own base country. The country drives tax and
     * shipping lookups, and an invalid postcode silently changes which zone matches - so
     * where the store has not set one, fall back to a known-valid postcode for that
     * country rather than sending an empty string the Store API will reject.
     */
    public static function default_address() {
        $saved = sspa_get_option('checkout_address');
        if (is_array($saved) && !empty($saved['country'])) {
            return $saved;
        }
        $countries = function_exists('WC') ? WC()->countries : null;
        $country = $countries ? $countries->get_base_country() : 'US';
        $state = $countries ? $countries->get_base_state() : '';
        $postcode = $countries ? $countries->get_base_postcode() : '';
        $city = $countries ? $countries->get_base_city() : '';

        $fallback_postcodes = array(
            'US' => '90210', 'GB' => 'SW1A 1AA', 'CA' => 'K1A 0B1', 'AU' => '2000',
            'NZ' => '6011', 'IE' => 'D02 AF30', 'DE' => '10115', 'FR' => '75001',
            'ES' => '28001', 'IT' => '00100', 'NL' => '1012 JS', 'BE' => '1000',
            'SE' => '111 20', 'NO' => '0150', 'DK' => '1050', 'FI' => '00100',
            'PT' => '1000-001', 'PL' => '00-001', 'AT' => '1010', 'CH' => '8001',
            'IN' => '110001', 'JP' => '100-0001', 'BR' => '01310-100', 'ZA' => '8001',
        );
        if ('' === (string) $postcode && isset($fallback_postcodes[$country])) {
            $postcode = $fallback_postcodes[$country];
        }
        $fallback_states = array('US' => 'CA', 'CA' => 'ON', 'AU' => 'NSW', 'IN' => 'DL', 'BR' => 'SP');
        if ('' === (string) $state && isset($fallback_states[$country])) {
            $state = $fallback_states[$country];
        }

        return array(
            'first_name' => 'SSPA',
            'last_name' => 'Test',
            'company' => '',
            'address_1' => '1 Test Street',
            'address_2' => '',
            'city' => '' !== (string) $city ? $city : 'Test City',
            'state' => (string) $state,
            'postcode' => (string) $postcode,
            'country' => (string) $country,
            'phone' => '01234567890',
        );
    }

    /**
     * Where the customer-facing order emails will land: the site's admin address, tagged
     * per run so they can be filtered or deleted in bulk. No mail filtering is involved,
     * which is exactly what keeps the measurement faithful (doc C1).
     */
    /**
     * @param int|string $run_id The run's id, or a placeholder for the pre-flight, which
     *                           runs before a run exists ("+sspa-perf-0" would be a lie).
     */
    public static function billing_email($run_id) {
        $override = sspa_get_option('checkout_email');
        if (is_string($override) && is_email($override)) {
            return $override;
        }
        $admin = (string) get_option('admin_email');
        if (!is_email($admin)) {
            return 'sspa-perf@' . (string) parse_url(home_url(), PHP_URL_HOST);
        }
        $tag = is_int($run_id) ? (string) $run_id : (string) $run_id;
        $at = strrpos($admin, '@');
        return substr($admin, 0, $at) . '+sspa-perf-' . $tag . substr($admin, $at);
    }

    // ---------------- the run ----------------

    /**
     * Run one complete purchase over loopback.
     *
     * @param array $opts {run_id, product_id, quantity, address, email, mail_mode,
     *                     payment_mode, allow_integrations, allow_webhooks, plugin_set_hash}
     * @return array {steps: [{page_key, method, url, sample, skipped}], order_ids, outcome,
     *                notes} - outcome is 'ok' or one of the failure codes in doc C4.
     */
    public static function run($opts = array()) {
        $run_id = isset($opts['run_id']) ? (int) $opts['run_id'] : 0;
        $result = array('steps' => array(), 'order_ids' => array(), 'outcome' => 'ok', 'notes' => array());

        if (!class_exists('WooCommerce') || !function_exists('WC')) {
            $result['outcome'] = 'no_store';
            return $result;
        }
        $product = self::default_product(isset($opts['product_id']) ? $opts['product_id'] : 0);
        if (!$product) {
            $result['outcome'] = 'no_product';
            return $result;
        }
        $checkout_type = SSPA_Checkout_Preflight::checkout_type();
        if ('block' === $checkout_type && !class_exists('\Automattic\WooCommerce\StoreApi\Utilities\CartTokenUtils')) {
            // The block path needs a cart token to skip the Store API's nonce.
            $result['outcome'] = 'unsupported_store';
            return $result;
        }
        if (!in_array($checkout_type, array('block', 'classic'), true)) {
            // Neither the block nor the shortcode: a page builder's own checkout, or a
            // checkout page that has been emptied. Guessing at the payload would produce a
            // "we were unable to process your order" that reads like a broken store.
            $result['outcome'] = 'unsupported_checkout';
            return $result;
        }

        $quantity = max(1, (int) (isset($opts['quantity']) ? $opts['quantity'] : sspa_get_option('checkout_quantity')));
        $address = isset($opts['address']) && is_array($opts['address']) ? $opts['address'] : self::default_address();
        $email = isset($opts['email']) && is_email($opts['email']) ? $opts['email'] : self::billing_email($run_id);
        $payment_mode = isset($opts['payment_mode']) ? $opts['payment_mode'] : 'no_payment';

        $flags = self::step_flags($opts, $payment_mode);
        if (is_wp_error($flags)) {
            $result['outcome'] = $flags->get_error_code();
            return $result;
        }
        $result['notes'] = array(
            'product_id' => $product->get_id(),
            'product_name' => $product->get_name(),
            'quantity' => $quantity,
            'payment_mode' => $payment_mode,
            'mail_mode' => isset($opts['mail_mode']) ? $opts['mail_mode'] : 'deliver',
            'allow_integrations' => !isset($opts['allow_integrations']) || !empty($opts['allow_integrations']),
            'allow_webhooks' => !isset($opts['allow_webhooks']) || !empty($opts['allow_webhooks']),
            'checkout_type' => $checkout_type,
            'email' => $email,
            'stock_before' => $product->managing_stock() ? $product->get_stock_quantity() : null,
            'stock_after' => null,
            'stock_restored_manually' => false,
        );

        $crawler = new SSPA_Crawler();
        $customer_id = '';
        $cart_token = '';
        if ('block' === $checkout_type) {
            // A cart token removes the Store API's nonce requirement entirely (doc A7
            // fact 2), so the block path needs no nonce of its own.
            $customer_id = 't_' . bin2hex(random_bytes(8));
            $cart_token = \Automattic\WooCommerce\StoreApi\Utilities\CartTokenUtils::get_cart_token($customer_id);
        }
        $headers = $cart_token ? array('Cart-Token' => $cart_token) : array();

        $billing = array_merge($address, array('email' => $email));
        $shipping = $address;
        unset($shipping['phone']);

        // Front-end page views need a real cart or they measure an EMPTY cart page, which
        // is the exact flaw this feature exists to fix (doc A3). On the block path
        // WooCommerce restores a guest session from a cart token passed as ?session=
        // (WC_Session_Handler::init_session_from_request); on the classic path the session
        // cookie comes back from add-to-cart. Either way one session serves the whole
        // visit, as a real customer would have, instead of a fresh clone per page.
        $jar = array('cookies' => array(), 'session_keys' => array());

        // Neither branch returns from inside the try: PHP evaluates a return value BEFORE
        // running finally, so an early `return $result` in there would hand back a copy
        // taken before cleanup recorded anything - every failure path reporting "0 orders
        // deleted" while having deleted one.
        $user_id = isset($opts['user_id']) ? (int) $opts['user_id'] : 0;
        $ctx = compact('product', 'quantity', 'billing', 'shipping', 'email', 'payment_mode', 'cart_token', 'headers');
        try {
            if ('classic' === $checkout_type) {
                self::run_classic($crawler, $flags, $result, $ctx, $jar);
            } else {
                self::run_block($crawler, $flags, $result, $ctx, $jar);
            }
            // The order the checkout just created is now the order to manage: view it in
            // wp-admin and mark it completed, the two things a shop owner does most. Only
            // when the purchase succeeded and produced an order - there is nothing to manage
            // otherwise - and never on a failed checkout, whose outcome is already recorded.
            if ('ok' === $result['outcome'] && $result['order_ids']) {
                self::run_order_management($crawler, $flags, $result, $user_id);
            }
        } catch (Throwable $e) {
            $result['outcome'] = 'exception';
            $result['notes']['error'] = $e->getMessage();
        } finally {
            // Cleanup runs here, never on the happy path: any failure after an order
            // exists still deletes it.
            $result['steps'][] = self::cleanup_step($crawler, $result, $flags);
            self::verify_stock($product->get_id(), $result);
            self::delete_sessions(array_merge(array($customer_id), $jar['session_keys']), $result);
            self::delete_temp_users($result);
            // The (now deleted) order ids go in the notes so the analysis engine can
            // recognise a purge/cache plugin that fetched the ORDER's own permalink -
            // with HPOS that URL is /?p=<order id>, and matching the id is what makes
            // the detection deterministic.
            $result['notes']['order_ids'] = $result['order_ids'];
        }

        return $result;
    }

    /**
     * The block checkout: the Store API POSTs a crawler never sends, with the cart token
     * standing in for the nonce.
     *
     * @param array $ctx {product, quantity, billing, shipping, email, payment_mode,
     *                    cart_token, headers}
     */
    private static function run_block($crawler, $flags, &$result, $ctx, &$jar) {
        $product = $ctx['product'];
        $quantity = $ctx['quantity'];
        $billing = $ctx['billing'];
        $shipping = $ctx['shipping'];
        $payment_mode = $ctx['payment_mode'];
        $cart_token = $ctx['cart_token'];
        $headers = $ctx['headers'];

        // --- 2. the product page ---
        $step = self::request($crawler, 'flow-view-product', 'GET', $product->get_permalink(), $flags);
        $result['steps'][] = $step;
        if (!self::step_ok($step)) {
            $result['outcome'] = 'view_product_failed';
            return;
        }

        // --- 3. add to cart ---
        $step = self::api_request($crawler, 'flow-add-to-cart', 'POST', 'wc/store/v1/cart/add-item', $flags, $headers, array(
            'id' => $product->get_id(),
            'quantity' => $quantity,
        ));
        $result['steps'][] = $step;
        if (!self::step_ok($step) || empty($step['sample']['json']['items'])) {
            $result['outcome'] = 'add_to_cart_failed';
            $result['notes']['error'] = self::api_error($step);
            return;
        }

        // --- 4/5. the cart, as a page and as the API the block calls ---
        $result['steps'][] = self::page_request($crawler, 'flow-view-cart', wc_get_cart_url(), $flags, $cart_token, $jar);
        $result['steps'][] = self::api_request($crawler, 'flow-cart-api', 'GET', 'wc/store/v1/cart', $flags, $headers);

        // --- 6. the customer's address: tax and shipping are calculated here ---
        $step = self::api_request($crawler, 'flow-update-customer', 'POST', 'wc/store/v1/cart/update-customer', $flags, $headers, array(
            'billing_address' => $billing,
            'shipping_address' => $shipping,
        ));
        $result['steps'][] = $step;
        if (!self::step_ok($step)) {
            $result['outcome'] = 'update_customer_failed';
            $result['notes']['error'] = self::api_error($step);
            return;
        }

        // --- 7. pick a shipping rate, when the store offers any ---
        $rate = self::first_rate($step['sample']['json']);
        if ($rate) {
            $result['steps'][] = self::api_request($crawler, 'flow-select-shipping', 'POST', 'wc/store/v1/cart/select-shipping-rate', $flags, $headers, $rate);
        } else {
            $result['steps'][] = self::skipped_step('flow-select-shipping', __('no shipping rates offered for this address', 'super-speedy-performance-analysis'));
        }

        // --- 8. the checkout page ---
        $result['steps'][] = self::page_request($crawler, 'flow-view-checkout', wc_get_checkout_url(), $flags, $cart_token, $jar);

        // --- 9. the draft order the block checkout creates while you type ---
        $step = self::api_request($crawler, 'flow-checkout-draft', 'GET', 'wc/store/v1/checkout', $flags, $headers);
        $result['steps'][] = $step;
        if (!empty($step['sample']['json']['order_id'])) {
            self::remember_order((int) $step['sample']['json']['order_id'], $result);
        }

        // --- 10. place the order ---
        $body = array('billing_address' => $billing, 'shipping_address' => $shipping);
        if ('sandbox' === $payment_mode) {
            $adapter = self::sandbox_adapter();
            if ($adapter) {
                $body['payment_method'] = $adapter->gateway_id();
                $body['payment_data'] = $adapter->payment_data();
            }
        }
        $step = self::api_request($crawler, 'flow-place-order', 'POST', 'wc/store/v1/checkout', $flags, $headers, $body);
        $result['steps'][] = $step;
        $json = isset($step['sample']['json']) ? $step['sample']['json'] : null;
        if (!empty($json['order_id'])) {
            self::remember_order((int) $json['order_id'], $result);
        }
        if (!self::step_ok($step) || empty($json['order_id'])) {
            $result['outcome'] = (429 === (int) $step['sample']['code']) ? 'rate_limited' : 'place_order_failed';
            $result['notes']['error'] = self::api_error($step);
            return;
        }
        $result['notes']['order_status'] = isset($json['status']) ? $json['status'] : null;
        // Read the total off the order itself: the checkout response schema carries the
        // addresses and the payment result, not the totals. Read now, because the
        // order is deleted in the finally below.
        $placed = wc_get_order((int) $json['order_id']);
        $result['notes']['order_total'] = $placed ? $placed->get_total() : null;
        $result['notes']['order_needed_shipping'] = $placed ? $placed->needs_shipping_address() : null;

        // --- 11. the confirmation the customer lands on ---
        $received = !empty($json['payment_result']['redirect_url'])
            ? $json['payment_result']['redirect_url']
            : self::order_received_url((int) $json['order_id']);
        if ($received) {
            $result['steps'][] = self::page_request($crawler, 'flow-order-received', $received, $flags, $cart_token, $jar);
        } else {
            $result['steps'][] = self::skipped_step('flow-order-received', __('no confirmation URL returned', 'super-speedy-performance-analysis'));
        }
    }


    /**
     * The shortcode checkout (doc T12/C3). Two things make it harder than the block path,
     * and both are places where a wrong guess produces "we were unable to process your
     * order", which reads like a broken store rather than a broken profiler:
     *
     * 1. There is no cart token, so the cart lives in the `wp_woocommerce_session_*`
     *    cookie and every step has to carry the jar forward.
     * 2. The POSTs need nonces minted as the LOGGED-OUT visitor who will send them. Worse
     *    than doc A7 fact 8 describes: WooCommerce filters `nonce_user_logged_out` to
     *    return the CART SESSION's customer id for any action starting with `woocommerce`
     *    (WC_Session_Handler::maybe_update_nonce_user_logged_out), so the place-order
     *    nonce is bound to a session id that only exists once add-to-cart has run.
     *
     * @param array $ctx {product, quantity, billing, shipping, email}
     */
    private static function run_classic($crawler, $flags, &$result, $ctx, &$jar) {
        $product = $ctx['product'];
        $billing = $ctx['billing'];
        $shipping = $ctx['shipping'];

        // --- 1. the product page ---
        $step = self::page_request($crawler, 'flow-view-product', $product->get_permalink(), $flags, '', $jar);
        $result['steps'][] = $step;
        if (!self::step_ok($step)) {
            $result['outcome'] = 'view_product_failed';
            return;
        }

        // --- 2. add to cart. No nonce: WC_AJAX::add_to_cart() checks none. The response
        //        is what establishes the cart session this whole run then uses.
        $step = self::jar_request($crawler, 'flow-add-to-cart', 'POST', home_url('/?wc-ajax=add_to_cart'), $flags, $jar, array(
            'body' => array('product_id' => $product->get_id(), 'quantity' => $ctx['quantity']),
        ));
        $result['steps'][] = $step;
        $added = self::step_ok($step) && empty($step['sample']['json']['error']);
        if (!$added) {
            $result['outcome'] = 'add_to_cart_failed';
            $result['notes']['error'] = self::classic_error($step);
            return;
        }

        $customer_id = self::session_customer_id($jar);
        if (!$customer_id) {
            // Without the session id the place-order nonce cannot be bound correctly, and
            // an unbound nonce fails as "your session has expired" - a misleading result
            // rather than an honest one. Stop and name it.
            $result['outcome'] = 'no_session';
            $result['notes']['error'] = 'add-to-cart returned no WooCommerce session cookie';
            return;
        }
        $result['notes']['session_customer_id'] = $customer_id;

        // --- 3. the cart page ---
        $result['steps'][] = self::page_request($crawler, 'flow-view-cart', wc_get_cart_url(), $flags, '', $jar);

        // --- 4. the address: this is where tax and shipping are calculated, and on a
        //        classic checkout it is fired on every keystroke-ish change.
        $review_body = array(
            'security' => self::guest_nonce('update-order-review', $customer_id),
            'payment_method' => '',
            'country' => $billing['country'],
            'state' => $billing['state'],
            'postcode' => $billing['postcode'],
            'city' => $billing['city'],
            'address' => $billing['address_1'],
            'address_2' => $billing['address_2'],
            's_country' => $shipping['country'],
            's_state' => $shipping['state'],
            's_postcode' => $shipping['postcode'],
            's_city' => $shipping['city'],
            's_address' => $shipping['address_1'],
            's_address_2' => $shipping['address_2'],
            'has_full_address' => 'true',
            'post_data' => http_build_query(self::classic_checkout_fields($billing, $shipping, '')),
        );
        $step = self::jar_request($crawler, 'flow-update-order-review', 'POST', home_url('/?wc-ajax=update_order_review'), $flags, $jar, array('body' => $review_body));
        $result['steps'][] = $step;
        if (!self::step_ok($step)) {
            $result['outcome'] = 'update_customer_failed';
            $result['notes']['error'] = self::classic_error($step);
            return;
        }

        // The rate the browser would have had selected. WooCommerce also defaults one into
        // the session while rendering the review (wc_get_chosen_shipping_method_for_package),
        // so an unparsed fragment is not fatal - it just means we post nothing and let that
        // default stand, exactly as an untouched radio group would.
        $rate_id = self::classic_chosen_rate($step);
        if ($rate_id) {
            $result['notes']['shipping_rate'] = $rate_id;
        }

        // --- 5. the checkout page ---
        $result['steps'][] = self::page_request($crawler, 'flow-view-checkout', wc_get_checkout_url(), $flags, '', $jar);

        // --- 6. place the order ---
        $nonce = self::guest_nonce('woocommerce-process_checkout', $customer_id);
        $order_body = self::classic_checkout_fields($billing, $shipping, $nonce);
        if ($rate_id) {
            $order_body['shipping_method'] = array($rate_id);
        }
        $step = self::jar_request($crawler, 'flow-place-order', 'POST', home_url('/?wc-ajax=checkout'), $flags, $jar, array('body' => $order_body));
        $result['steps'][] = $step;

        $json = isset($step['sample']['json']) ? $step['sample']['json'] : null;
        $redirect = (is_array($json) && !empty($json['redirect'])) ? (string) $json['redirect'] : '';
        $order_id = $redirect ? self::order_id_from_url($redirect) : 0;
        if ($order_id) {
            self::remember_order($order_id, $result);
        }
        if (!self::step_ok($step) || !is_array($json) || 'success' !== (isset($json['result']) ? $json['result'] : '') || !$order_id) {
            $result['outcome'] = 'place_order_failed';
            $result['notes']['error'] = self::classic_error($step);
            return;
        }

        $placed = wc_get_order($order_id);
        $result['notes']['order_status'] = $placed ? $placed->get_status() : null;
        $result['notes']['order_total'] = $placed ? $placed->get_total() : null;
        $result['notes']['order_needed_shipping'] = $placed ? $placed->needs_shipping_address() : null;

        // --- 7. the confirmation the customer lands on ---
        $result['steps'][] = self::page_request($crawler, 'flow-order-received', $redirect, $flags, '', $jar);
    }

    /**
     * Order management: the two things a shop owner does most, measured on the order the
     * checkout just created (Dave's brief, 11th August 2026).
     *
     *  - view the order in wp-admin (how slow is the order-edit screen);
     *  - mark it processing -> completed (the completed-order email, stock/download
     *    permissions and every plugin hooking `completed` - the saleable "changing an order
     *    to completed takes N seconds because plugin X does Y").
     *
     * Both run as the admin who started the run (their own cookies, no temporary admin
     * user), so the screen and the transition see what that admin would. The transition is
     * performed inside a profiled probe request - the same mechanism the delete step uses -
     * so its email is intercepted per the run's mail mode, exactly as a checkout email is.
     */
    private static function run_order_management($crawler, $flags, &$result, $user_id) {
        $order_id = (int) end($result['order_ids']);
        $order = ($order_id && function_exists('wc_get_order')) ? wc_get_order($order_id) : null;
        if (!$order) {
            $result['steps'][] = self::skipped_step('flow-view-order', __('no order to manage', 'super-speedy-performance-analysis'));
            $result['steps'][] = self::skipped_step('flow-complete-order', __('no order to manage', 'super-speedy-performance-analysis'));
            return;
        }

        $admin_cookies = SSPA_Auth::cookies_for('admin', $user_id);
        if (!$admin_cookies) {
            // No admin session means the order screen would render the logged-out login
            // redirect, not the order - an honest skip beats a misleading 302.
            $why = __('no admin session to view the order as', 'super-speedy-performance-analysis');
            $result['steps'][] = self::skipped_step('flow-view-order', $why);
            $result['steps'][] = self::skipped_step('flow-complete-order', $why);
            return;
        }

        // --- 12. view the order in wp-admin ---
        $hpos = class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        $view_url = $hpos
            ? admin_url('admin.php?page=wc-orders&action=edit&id=' . $order_id)
            : admin_url('post.php?post=' . $order_id . '&action=edit');
        $view = self::request($crawler, 'flow-view-order', 'GET', $view_url, array('v' => 'admin'), array('cookies' => $admin_cookies));
        $view['variant'] = 'admin';
        $result['steps'][] = $view;

        // The status the checkout left it at, so the panel can name the transition honestly
        // ("processing -> completed" on physical goods; the default product is physical, so
        // that is the usual case).
        $result['notes']['complete_from_status'] = $order->get_status();

        // --- 13. mark it completed (the transition cascade) ---
        $complete_flags = array('v' => 'admin', 'ck' => 'complete', 'oid' => (string) $order_id);
        if (!empty($flags['mail'])) {
            $complete_flags['mail'] = $flags['mail']; // the completed-order email, timed or intercepted per mode
        }
        if (!empty($flags['ps'])) {
            $complete_flags['ps'] = $flags['ps']; // phase 4: which plugin costs order completion
        }
        $complete = self::request($crawler, 'flow-complete-order', 'GET', home_url('/?sspa_flow_probe=1'), $complete_flags, array('cookies' => $admin_cookies));
        $complete['variant'] = 'admin';
        $result['steps'][] = $complete;

        // The transition happened in the loopback, a different process, so this process's
        // order cache is stale - bust it before reading the resulting status, or a completed
        // order still reads as processing.
        wp_cache_delete($order_id, 'orders');
        clean_post_cache($order_id);
        $fresh = wc_get_order($order_id);
        $result['notes']['complete_to_status'] = $fresh ? $fresh->get_status() : null;
    }

    /**
     * The full billing field set a classic checkout form posts. Shipping fields are only
     * sent when the store is not shipping to the billing address, matching what the form
     * itself submits when "ship to a different address" is unticked.
     */
    private static function classic_checkout_fields($billing, $shipping, $nonce) {
        $fields = array(
            'billing_first_name' => $billing['first_name'],
            'billing_last_name' => $billing['last_name'],
            'billing_company' => $billing['company'],
            'billing_country' => $billing['country'],
            'billing_address_1' => $billing['address_1'],
            'billing_address_2' => $billing['address_2'],
            'billing_city' => $billing['city'],
            'billing_state' => $billing['state'],
            'billing_postcode' => $billing['postcode'],
            'billing_phone' => $billing['phone'],
            'billing_email' => $billing['email'],
            'shipping_first_name' => $shipping['first_name'],
            'shipping_last_name' => $shipping['last_name'],
            'shipping_company' => $shipping['company'],
            'shipping_country' => $shipping['country'],
            'shipping_address_1' => $shipping['address_1'],
            'shipping_address_2' => $shipping['address_2'],
            'shipping_city' => $shipping['city'],
            'shipping_state' => $shipping['state'],
            'shipping_postcode' => $shipping['postcode'],
            'ship_to_different_address' => '0',
            'order_comments' => '',
            // Empty is correct: the flow's no-payment filters make WC_Cart::needs_payment()
            // false, so validate_checkout() never asks for a gateway and the order takes
            // the process_order_without_payment() branch.
            'payment_method' => '',
            '_wp_http_referer' => '/?wc-ajax=update_order_review',
        );
        // Only sent when the store actually shows the checkbox; posting terms-field without
        // terms would fail validation for a reason the store never imposes.
        if (function_exists('wc_terms_and_conditions_page_id') && wc_terms_and_conditions_page_id()) {
            $fields['terms'] = '1';
            $fields['terms-field'] = '1';
        }
        if ($nonce) {
            $fields['woocommerce-process-checkout-nonce'] = $nonce;
            $fields['_wpnonce'] = $nonce;
        }
        return $fields;
    }

    /**
     * Mint a nonce as the logged-out visitor who will actually send it.
     *
     * wp_create_nonce() binds to the CURRENT request's user and session token, so a nonce
     * minted inside the admin's AJAX request is bound to the admin (doc A7 fact 8). On top
     * of that, WooCommerce rewrites the logged-out uid to the cart session's customer id
     * for any action whose name starts with `woocommerce`, and the session we care about
     * is the loopback visitor's, not this process's. Both are handled here.
     */
    public static function guest_nonce($action, $customer_id) {
        $saved_cookies = $_COOKIE;
        $current_user = get_current_user_id();
        $bind = function ($uid, $nonce_action) use ($customer_id) {
            if ($customer_id && is_string($nonce_action) && 0 === strpos($nonce_action, 'woocommerce')) {
                return $customer_id;
            }
            return $uid;
        };
        // After WooCommerce's own filter at priority 10, which would otherwise bind the
        // nonce to THIS process's cart session.
        add_filter('nonce_user_logged_out', $bind, 100, 2);
        try {
            foreach (array('LOGGED_IN_COOKIE', 'AUTH_COOKIE', 'SECURE_AUTH_COOKIE') as $const) {
                if (defined($const)) {
                    unset($_COOKIE[constant($const)]);
                }
            }
            wp_set_current_user(0);
            $nonce = wp_create_nonce($action);
        } finally {
            remove_filter('nonce_user_logged_out', $bind, 100);
            wp_set_current_user($current_user);
            $_COOKIE = $saved_cookies;
        }
        return $nonce;
    }

    /** The cart session's customer id, read from the cookie WooCommerce set on our jar. */
    private static function session_customer_id($jar) {
        foreach ($jar['cookies'] as $name => $cookie) {
            if (0 === strpos((string) $name, 'wp_woocommerce_session_')) {
                $id = strtok((string) $cookie->value, '|');
                return $id ? $id : '';
            }
        }
        return '';
    }

    /**
     * The shipping rate the order-review fragment came back with selected - what the
     * browser would then post. Returns '' when the store offers no shipping choice.
     */
    private static function classic_chosen_rate($step) {
        $body = isset($step['sample']['json']['fragments']) ? implode(' ', (array) $step['sample']['json']['fragments']) : '';
        if ('' === $body) {
            return '';
        }
        if (preg_match('/name=[\'"]shipping_method\[0\][\'"][^>]*value=[\'"]([^\'"]+)[\'"][^>]*checked/i', $body, $m)
            || preg_match('/name=[\'"]shipping_method\[0\][\'"][^>]*value=[\'"]([^\'"]+)[\'"]/i', $body, $m)) {
            return html_entity_decode($m[1]);
        }
        return '';
    }

    /** wc_get_order_id_by_order_key() rather than parsing the permalink, which varies. */
    private static function order_id_from_url($url) {
        $query = (string) wp_parse_url($url, PHP_URL_QUERY);
        parse_str($query, $args);
        if (!empty($args['key']) && function_exists('wc_get_order_id_by_order_key')) {
            $id = (int) wc_get_order_id_by_order_key($args['key']);
            if ($id) {
                return $id;
            }
        }
        return preg_match('#order-received/(\d+)#', (string) $url, $m) ? (int) $m[1] : 0;
    }

    /**
     * Classic AJAX reports failure as HTML notices rather than a JSON error code, so pull
     * the message out of the markup instead of reporting a bare HTTP status.
     */
    private static function classic_error($step) {
        $sample = isset($step['sample']) ? $step['sample'] : null;
        if (!$sample) {
            return null;
        }
        if ($sample['error']) {
            return $sample['error'];
        }
        if ($sample['blocked_by']) {
            return 'blocked_' . $sample['blocked_by'];
        }
        $messages = isset($sample['json']['messages']) ? $sample['json']['messages'] : '';
        if (!$messages && isset($sample['json']['error'])) {
            $messages = is_string($sample['json']['error']) ? $sample['json']['error'] : wp_json_encode($sample['json']['error']);
        }
        if (!$messages) {
            $messages = $sample['body'];
        }
        $text = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags((string) $messages)));
        return $text !== '' ? mb_substr(wp_specialchars_decode($text, ENT_QUOTES), 0, 300) : 'http_' . $sample['code'];
    }

    /**
     * Token flags shared by every step. `ck=flow` is what arms maybe_arm_request();
     * omitting `pm` is what keeps the payment gateway out of it.
     *
     * @return array|WP_Error
     */
    private static function step_flags($opts, $payment_mode) {
        $flags = array('v' => 'guest', 'ck' => 'flow');

        $mail_mode = isset($opts['mail_mode']) ? $opts['mail_mode'] : sspa_get_option('checkout_mail_mode');
        if ('construct' === $mail_mode) {
            $flags['mail'] = 'c';
        } elseif ('suppress' !== $mail_mode) {
            $flags['mail'] = 'd'; // the default: real emails, really sent, really timed
        }

        if ('sandbox' === $payment_mode) {
            // Two independent checks before a payment can be attempted, because getting
            // this wrong charges someone real money: the adapter must confirm test mode
            // here, and it confirmed it again when the mode was offered in the pre-flight.
            if (!self::sandbox_adapter()) {
                return new WP_Error('no_sandbox_gateway');
            }
            $flags['pm'] = 's';
        }

        if (isset($opts['allow_integrations']) && !$opts['allow_integrations']) {
            $flags['ig'] = '0';
        }
        if (isset($opts['allow_webhooks']) && !$opts['allow_webhooks']) {
            $flags['wh'] = '0';
        }
        if (!empty($opts['plugin_set_hash'])) {
            $flags['ps'] = $opts['plugin_set_hash']; // phase 4: which plugin costs the checkout
        }
        return $flags;
    }

    /** @return SSPA_Gateway_Adapter|null */
    private static function sandbox_adapter() {
        foreach (self::gateway_adapters() as $adapter) {
            if ($adapter->is_test_mode()) {
                return $adapter;
            }
        }
        return null;
    }

    // ---------------- step plumbing ----------------

    private static function request($crawler, $page_key, $method, $url, $flags, $args = array()) {
        $sample = $crawler->send_profiled($url, array_merge($args, array('method' => $method, 'flags' => $flags)));
        return array('page_key' => $page_key, 'method' => $method, 'url' => $url, 'sample' => $sample, 'skipped' => null);
    }

    /**
     * A Store API request, with the pretty-permalink 301 trap handled: some installs 301
     * /wp-json/ paths to a trailing slash and a 301 on a POST loses the body. send_profiled()
     * does not follow redirects, so this surfaces as a 301 rather than a silently wrong
     * measurement; retry once with the ?rest_route= form, which WooCommerce supports
     * explicitly for Store API requests.
     */
    private static function api_request($crawler, $page_key, $method, $route, $flags, $headers, $body = null) {
        $args = array('headers' => $headers);
        if (null !== $body) {
            $args['body'] = $body;
            $args['json'] = true;
        }
        $step = self::request($crawler, $page_key, $method, rest_url($route), $flags, $args);
        if (in_array((int) $step['sample']['code'], array(301, 302, 307, 308), true)) {
            $step = self::request($crawler, $page_key, $method, home_url('/?rest_route=/' . $route), $flags, $args);
            $step['rest_route_fallback'] = true;
        }
        return $step;
    }

    /**
     * A front-end page view that must see the cart the customer actually has.
     *
     * The first such view hands WooCommerce the cart token as ?session=, which its session
     * handler validates and restores into a cookie session (guest sessions only - the
     * token's customer id is `t_`-prefixed). Every later view sends that cookie back, so
     * one session serves the whole visit rather than a fresh clone per page.
     *
     * @param array $jar {cookies: WP_Http_Cookie[], session_keys: string[]} by reference.
     */
    private static function page_request($crawler, $page_key, $url, $flags, $cart_token, &$jar) {
        // A cart token is the block path's way in; the classic path has no token and gets
        // its session from the cookie add-to-cart returned, so it passes '' here.
        if ($cart_token && !$jar['cookies']) {
            $url .= ((false === strpos($url, '?')) ? '?' : '&') . 'session=' . rawurlencode($cart_token);
        }
        return self::jar_request($crawler, $page_key, 'GET', $url, $flags, $jar);
    }

    /**
     * A request that sends the jar and folds whatever comes back into it, so one session
     * serves the whole visit. Also records the WooCommerce session keys the run creates,
     * so they can be deleted again afterwards.
     */
    private static function jar_request($crawler, $page_key, $method, $url, $flags, &$jar, $args = array()) {
        if ($jar['cookies']) {
            $args['cookies'] = $jar['cookies'];
        }
        $step = self::request($crawler, $page_key, $method, $url, $flags, $args);

        foreach ((array) $step['sample']['cookies'] as $cookie) {
            if (!is_object($cookie) || !isset($cookie->name)) {
                continue;
            }
            $jar['cookies'][$cookie->name] = $cookie;
            if (0 === strpos($cookie->name, 'wp_woocommerce_session_')) {
                // The customer id is the first field of the cookie value. Recorded so the
                // session row this run created can be removed again afterwards.
                $key = strtok((string) $cookie->value, '|');
                if ($key && !in_array($key, $jar['session_keys'], true)) {
                    $jar['session_keys'][] = $key;
                }
            }
        }
        return $step;
    }

    /**
     * Remove the guest session rows this run created. WooCommerce would expire them on
     * its own schedule, but leaving rows behind in a customer's store when we know
     * exactly which ones are ours is not good enough.
     */
    private static function delete_sessions($keys, &$result) {
        global $wpdb;
        $table = $wpdb->prefix . 'woocommerce_sessions';
        $deleted = 0;
        foreach (array_unique(array_filter($keys)) as $key) {
            $deleted += (int) $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE session_key = %s", $key));
        }
        $result['notes']['sessions_deleted'] = $deleted;
    }

    private static function skipped_step($page_key, $why) {
        return array('page_key' => $page_key, 'method' => 'GET', 'url' => '', 'sample' => null, 'skipped' => $why);
    }

    private static function step_ok($step) {
        $s = $step['sample'];
        return $s && !$s['error'] && !$s['blocked_by'] && $s['code'] >= 200 && $s['code'] < 300;
    }

    /** The Store API's own error code and message, so a failure is named rather than silent. */
    private static function api_error($step) {
        $s = isset($step['sample']) ? $step['sample'] : null;
        if (!$s) {
            return null;
        }
        if ($s['error']) {
            return $s['error'];
        }
        if ($s['blocked_by']) {
            return 'blocked_' . $s['blocked_by'];
        }
        if (isset($s['json']['code'])) {
            // The Store API's message arrives HTML-escaped; decode it here or the panel,
            // which escapes on output, shows the reader a literal &quot;.
            $message = isset($s['json']['message'])
                ? wp_specialchars_decode(wp_strip_all_tags((string) $s['json']['message']), ENT_QUOTES)
                : '';
            return $s['json']['code'] . ': ' . $message;
        }
        return 'http_' . $s['code'];
    }

    /** @return array|null {package_id, rate_id} for the first offered rate. */
    private static function first_rate($cart) {
        if (empty($cart['shipping_rates']) || !is_array($cart['shipping_rates'])) {
            return null;
        }
        foreach ($cart['shipping_rates'] as $package) {
            if (empty($package['shipping_rates'])) {
                continue;
            }
            foreach ($package['shipping_rates'] as $rate) {
                if (!empty($rate['rate_id'])) {
                    return array(
                        'package_id' => isset($package['package_id']) ? $package['package_id'] : 0,
                        'rate_id' => (string) $rate['rate_id'],
                    );
                }
            }
        }
        return null;
    }

    private static function order_received_url($order_id) {
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        return $order ? $order->get_checkout_order_received_url() : '';
    }

    /**
     * Record an order the moment its id is known, BEFORE the next request goes out, and
     * mark it as ours from this process too. The in-request hook already marks it; doing
     * it here as well means a crash inside that request cannot leave an unmarked order
     * that nothing is ever allowed to delete.
     */
    private static function remember_order($order_id, &$result) {
        if (!$order_id || in_array($order_id, $result['order_ids'], true)) {
            return;
        }
        $result['order_ids'][] = $order_id;

        $temp = get_option(self::TEMP_OPTION, array());
        $temp = is_array($temp) ? $temp : array();
        $temp[] = array('type' => 'order', 'id' => $order_id, 'ts' => time());
        update_option(self::TEMP_OPTION, $temp, false);

        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        if ($order && !$order->get_meta(self::TEMP_META)) {
            $order->update_meta_data(self::TEMP_META, '1');
            $order->save_meta_data();
        }
    }

    /**
     * Delete every order this run created, measuring the delete cascade as we go - a slow
     * delete is worth knowing about. Nobody waits for it, so the report keeps it below the
     * customer-facing total (doc T9 rule 1).
     */
    private static function cleanup_step($crawler, &$result, $flags) {
        if (!$result['order_ids']) {
            return self::skipped_step('flow-delete-order', __('no order was created', 'super-speedy-performance-analysis'));
        }
        $step = null;
        foreach ($result['order_ids'] as $order_id) {
            $del_flags = array('v' => 'guest', 'ck' => 'del', 'oid' => (string) $order_id);
            if (!empty($flags['ps'])) {
                $del_flags['ps'] = $flags['ps'];
            }
            $one = self::request($crawler, 'flow-delete-order', 'GET', home_url('/?sspa_flow_probe=1'), $del_flags);
            if (!$step) {
                $step = $one; // the first delete is the measured one; the rest are the same work
            }
            // Whatever the probe managed, make certain nothing is left: the probe runs in
            // a request that can fatal, and an orphan order in a customer's store is the
            // one outcome this feature must never produce.
            self::force_delete_order($order_id);
        }
        self::forget_orders($result['order_ids']);
        $result['notes']['orders_deleted'] = count($result['order_ids']);
        $result['notes']['orders_left'] = self::orders_still_present($result['order_ids']);
        return $step;
    }

    private static function force_delete_order($order_id) {
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        if (!$order || !$order->get_meta(self::TEMP_META)) {
            return; // never delete an order without our own marker, whatever else says
        }
        if (!$order->has_status(array('cancelled', 'refunded'))) {
            $order->update_status('cancelled'); // so wc_maybe_increase_stock_levels runs
        }
        $order->delete(true); // HPOS-safe
    }

    private static function orders_still_present($ids) {
        $left = 0;
        foreach ($ids as $id) {
            if (function_exists('wc_get_order') && wc_get_order($id)) {
                $left++;
            }
        }
        return $left;
    }

    private static function forget_orders($ids) {
        self::forget_temp_entries('order', $ids);
    }

    /** Type-aware: a user entry must never be dropped by an order id collision. */
    private static function forget_temp_entries($type, $ids) {
        $temp = get_option(self::TEMP_OPTION, array());
        if (!is_array($temp)) {
            delete_option(self::TEMP_OPTION);
            return;
        }
        $ids = array_map('intval', $ids);
        $temp = array_values(array_filter($temp, function ($entry) use ($type, $ids) {
            $entry_type = isset($entry['type']) ? $entry['type'] : 'order';
            if ($entry_type !== $type) {
                return true;
            }
            return !isset($entry['id']) || !in_array((int) $entry['id'], $ids, true);
        }));
        if ($temp) {
            update_option(self::TEMP_OPTION, $temp, false);
        } else {
            delete_option(self::TEMP_OPTION);
        }
    }

    /**
     * Remove the customer account the store auto-created for this purchase (guest
     * checkout disabled). Same discipline as orders: only ever an account carrying our
     * own marker, recorded in the run notes, backed up by the janitor.
     */
    private static function delete_temp_users(&$result) {
        // The entries were written by the loopback place-order request; make sure this
        // process reads them fresh rather than from its own options cache.
        wp_cache_delete(self::TEMP_OPTION, 'options');
        $temp = get_option(self::TEMP_OPTION, array());
        $user_ids = array();
        foreach ((array) $temp as $entry) {
            if (isset($entry['type'], $entry['id']) && 'user' === $entry['type']) {
                $user_ids[] = (int) $entry['id'];
            }
        }
        if (!$user_ids) {
            return;
        }
        if (!function_exists('wp_delete_user')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }
        $deleted = 0;
        foreach ($user_ids as $user_id) {
            if (get_user_meta($user_id, self::TEMP_META, true)) {
                wp_delete_user($user_id);
                $deleted++;
            }
        }
        self::forget_temp_entries('user', $user_ids);
        $result['notes']['users_deleted'] = $deleted;
        $result['notes']['users_left'] = count(array_filter($user_ids, 'get_userdata'));
    }

    /**
     * Cancelling before deleting is what should restore stock. Verify rather than assume
     * (doc A8 item 1): if it did not, put it back and say that it was needed.
     */
    private static function verify_stock($product_id, &$result) {
        if (null === $result['notes']['stock_before']) {
            return; // stock is not managed for this product - nothing to restore
        }
        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients($product_id);
        }
        $product = wc_get_product($product_id);
        if (!$product) {
            return;
        }
        $after = $product->get_stock_quantity();
        if (null !== $after && (int) $after !== (int) $result['notes']['stock_before']) {
            wc_update_product_stock($product, (int) $result['notes']['stock_before'], 'set');
            $result['notes']['stock_restored_manually'] = true;
            $product = wc_get_product($product_id);
            $after = $product ? $product->get_stock_quantity() : $after;
        }
        $result['notes']['stock_after'] = $after;
    }

    // ---------------- reporting ----------------

    /**
     * Roll the per-step Excimer reports up into one view of the whole purchase.
     *
     * Per-step sampling data is already free: every step is an ordinary profiled request,
     * so the capture attached a report to each one. Two limitations are printed rather
     * than hidden: each step's report is already truncated to its top functions, so this
     * is a sum of top-N lists rather than a true whole-flow profile; and incl_ms cannot be
     * added to self_ms to make a total, for the same reason it cannot within one request.
     *
     * @param array $step_profiles page_key => capture['profile']
     * @return array|null
     */
    public static function rollup_profile($step_profiles) {
        $components = array();   // component => ['ms' => float, 'by_step' => [step => ms]]
        $functions = array();    // fn => [...]
        $samples = 0;
        $wall_ms = 0.0;
        $steps_seen = 0;

        foreach ((array) $step_profiles as $page_key => $profile) {
            if (!is_array($profile) || empty($profile['components'])) {
                continue;
            }
            $steps_seen++;
            $samples += isset($profile['samples']) ? (int) $profile['samples'] : 0;
            $wall_ms += isset($profile['wall_ms']) ? (float) $profile['wall_ms'] : 0.0;

            foreach ($profile['components'] as $component => $ms) {
                if (!isset($components[$component])) {
                    $components[$component] = array('component' => $component, 'ms' => 0.0, 'by_step' => array());
                }
                $components[$component]['ms'] += (float) $ms;
                $components[$component]['by_step'][$page_key] = round((float) $ms, 1);
            }
            foreach ((array) (isset($profile['functions']) ? $profile['functions'] : array()) as $fn) {
                $name = isset($fn['fn']) ? $fn['fn'] : '';
                if ('' === $name) {
                    continue;
                }
                if (!isset($functions[$name])) {
                    $functions[$name] = array(
                        'fn' => $name,
                        'component' => isset($fn['component']) ? $fn['component'] : 'core',
                        'ctype' => isset($fn['ctype']) ? $fn['ctype'] : 'core',
                        'incl_ms' => 0.0,
                        'self_ms' => 0.0,
                        'worst_step' => $page_key,
                        'worst_ms' => 0.0,
                    );
                }
                $incl = isset($fn['incl_ms']) ? (float) $fn['incl_ms'] : 0.0;
                $functions[$name]['incl_ms'] += $incl;
                $functions[$name]['self_ms'] += isset($fn['self_ms']) ? (float) $fn['self_ms'] : 0.0;
                if ($incl > $functions[$name]['worst_ms']) {
                    $functions[$name]['worst_ms'] = $incl;
                    $functions[$name]['worst_step'] = $page_key;
                }
            }
        }

        if (!$steps_seen) {
            return null; // no Excimer on this server - the panel points at the Tools tab
        }

        foreach ($components as &$c) {
            $c['ms'] = round($c['ms'], 1);
        }
        unset($c);
        usort($components, function ($a, $b) {
            return $b['ms'] <=> $a['ms'];
        });
        foreach ($functions as &$f) {
            $f['incl_ms'] = round($f['incl_ms'], 1);
            $f['self_ms'] = round($f['self_ms'], 1);
            $f['worst_ms'] = round($f['worst_ms'], 1);
        }
        unset($f);
        usort($functions, function ($a, $b) {
            return $b['incl_ms'] <=> $a['incl_ms'];
        });

        return array(
            'steps_profiled' => $steps_seen,
            'samples' => $samples,
            'sampled_wall_ms' => round($wall_ms, 1),
            'components' => array_values($components),
            'functions' => array_slice($functions, 0, 40),
            'note' => __('Top functions seen across steps: each step\'s own report is already truncated, so this is a sum of top-40 lists rather than a whole-flow profile. Inclusive and self times cannot be added together.', 'super-speedy-performance-analysis'),
        );
    }

    /**
     * The result panel's data: the waterfall, split at the payment boundary (doc A6.4).
     * Slowness BEFORE the money is captured risks the sale; slowness after it is a bad
     * impression but the money is in. A single total hides that distinction.
     *
     * @return array|null
     */
    public static function waterfall($run_id) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . SSPA_Schema::table('profiles') . ' WHERE run_id = %d ORDER BY id ASC',
            (int) $run_id
        ), ARRAY_A);
        if (!$rows) {
            return null;
        }
        $run = SSPA_Run_Controller::run_row($run_id);
        $notes = $run ? json_decode((string) $run['notes'], true) : array();
        $notes = is_array($notes) ? $notes : array();

        $at_risk = array();
        $secured = array();
        $management = array();
        $excluded = array();
        $at_risk_ms = 0.0;
        $secured_ms = 0.0;
        $management_ms = 0.0;
        $boundary_known = null;
        $step_profiles = array();
        $http_calls = array();
        $mail_calls = 0;
        $mail_ms = 0.0;

        $mail_untimed = 0;
        foreach ($rows as $row) {
            $key = $row['page_key'];
            // Harness steps: the pre-flight probe and the admin cleanup. Their timing rows
            // stay visible below the waterfall, but NOTHING they did may reach the
            // customer-facing panels - the component roll-up, the outbound-call list and
            // the mail totals all answer "what did the customer wait through", and the
            // delete step's purge/email cascade is OUR work, not the store's. Leaking it
            // in here overstated a purge plugin's cost by a quarter on a real store.
            $is_harness = in_array($key, array('flow-preflight', 'flow-delete-order'), true);
            $gen = (null !== $row['page_gen_ms']) ? (float) $row['page_gen_ms'] : null;
            $capture = !empty($row['profile_blob']) ? json_decode((string) @gzuncompress($row['profile_blob']), true) : null;
            if (is_array($capture) && !$is_harness) {
                if (!empty($capture['profile'])) {
                    $step_profiles[$key] = $capture['profile'];
                }
                foreach ((array) (isset($capture['http']['calls']) ? $capture['http']['calls'] : array()) as $call) {
                    $call['step'] = $key;
                    $http_calls[] = $call;
                }
                $mail_calls += isset($capture['mail']['count']) ? (int) $capture['mail']['count'] : 0;
                $mail_ms += isset($capture['mail']['total_construct_ms']) ? (float) $capture['mail']['total_construct_ms'] : 0.0;
                foreach ((array) (isset($capture['mail']['calls']) ? $capture['mail']['calls'] : array()) as $mail_call) {
                    if (!isset($mail_call['construct_ms']) || null === $mail_call['construct_ms']) {
                        $mail_untimed++;
                    }
                }
            }

            $entry = array(
                'page_key' => $key,
                'label' => self::step_label($key),
                'method' => $row['method'],
                'gen_ms' => (null !== $gen) ? round($gen, 1) : null,
                'sql_ms' => (null !== $row['sql_ms']) ? round((float) $row['sql_ms'], 1) : null,
                'sql_count' => (null !== $row['sql_count']) ? (int) $row['sql_count'] : null,
                'http_ms' => (null !== $row['http_ms']) ? round((float) $row['http_ms'], 1) : null,
                'code' => (int) $row['response_code'],
                'blocked_by' => $row['blocked_by'],
            );

            // Nobody waits for the cleanup, so it sits below the total and is excluded
            // from it - measured because a slow delete cascade is worth knowing about,
            // never added to the customer-facing figure.
            if ($is_harness) {
                $excluded[] = $entry;
                continue;
            }

            if ('flow-place-order' === $key && null !== $gen) {
                $mark = (is_array($capture) && isset($capture['marks']['payment_complete']))
                    ? (float) $capture['marks']['payment_complete']
                    : null;
                if (null !== $mark && $mark > 0 && $mark < $gen) {
                    $boundary_known = true;
                    $before = $entry;
                    $before['label'] = __('place order: up to payment', 'super-speedy-performance-analysis');
                    $before['gen_ms'] = round($mark, 1);
                    $at_risk[] = $before;
                    $at_risk_ms += $mark;

                    $after = $entry;
                    $after['label'] = __('place order: after payment', 'super-speedy-performance-analysis');
                    $after['gen_ms'] = round($gen - $mark, 1);
                    $secured[] = $after;
                    $secured_ms += ($gen - $mark);
                    continue;
                }
                // Never guess the boundary: one combined row, and say why.
                $boundary_known = false;
            }

            if (in_array($key, array('flow-order-received'), true)) {
                $secured[] = $entry;
                $secured_ms += (float) $gen;
                continue;
            }
            // Order management is the SHOP OWNER's time, after the sale - neither at-risk
            // (the customer has gone) nor secured checkout time. Its own bucket, kept out of
            // the customer-facing total, because "your order screen takes 3s" is a real cost
            // but not one a customer waits through.
            if (in_array($key, array('flow-view-order', 'flow-complete-order'), true)) {
                $management[] = $entry;
                $management_ms += (float) $gen;
                continue;
            }
            $at_risk[] = $entry;
            $at_risk_ms += (float) $gen;
        }

        // By label, not page key: place-order contributes two rows that share a key.
        $slowest = null;
        foreach (array_merge($at_risk, $secured, $management) as $entry) {
            if (null !== $entry['gen_ms'] && (!$slowest || $entry['gen_ms'] > $slowest['gen_ms'])) {
                $slowest = $entry;
            }
        }

        usort($http_calls, function ($a, $b) {
            return (float) $b['ms'] <=> (float) $a['ms'];
        });

        return array(
            'run_id' => (int) $run_id,
            'at_risk' => $at_risk,
            'at_risk_ms' => round($at_risk_ms, 1),
            'secured' => $secured,
            'secured_ms' => round($secured_ms, 1),
            'total_ms' => round($at_risk_ms + $secured_ms, 1),
            // Post-sale admin work, kept separate from the customer total (Dave, 11 Aug 2026).
            // The from/to statuses live under the flow notes, where finish_checkout stores the
            // flow's own notes - not at the top level.
            'management' => $management,
            'management_ms' => round($management_ms, 1),
            'complete_from_status' => isset($notes['flow']['complete_from_status']) ? $notes['flow']['complete_from_status'] : null,
            'complete_to_status' => isset($notes['flow']['complete_to_status']) ? $notes['flow']['complete_to_status'] : null,
            'excluded' => $excluded,
            'slowest' => $slowest ? $slowest['label'] : null,
            'slowest_step' => $slowest ? $slowest['page_key'] : null,
            'boundary_known' => $boundary_known,
            'http' => array_slice($http_calls, 0, 20),
            'mail' => array('count' => $mail_calls, 'ms' => round($mail_ms, 1), 'untimed' => $mail_untimed),
            'profile' => self::rollup_profile($step_profiles),
            'notes' => $notes,
            // Single measured purchase, never a median of N: a full checkout is heavy
            // enough that variance is not the dominant error term, and every repeat is
            // another real order (doc A4).
            'basis' => __('Single measured purchase, server time only. Two consecutive runs can differ - first-run effects such as a cold opcache land in the measurement.', 'super-speedy-performance-analysis'),
            'payment_caveat' => ('sandbox' === (isset($notes['flow']['payment_mode']) ? $notes['flow']['payment_mode'] : 'no_payment'))
                ? null
                : __('Your payment provider\'s own latency is not included: this run skipped the gateway. Everything the store does around the payment IS measured.', 'super-speedy-performance-analysis'),
        );
    }

    public static function step_label($page_key) {
        $labels = array(
            'flow-preflight' => __('pre-flight inventory', 'super-speedy-performance-analysis'),
            'flow-view-product' => __('view product', 'super-speedy-performance-analysis'),
            'flow-add-to-cart' => __('add to cart', 'super-speedy-performance-analysis'),
            'flow-view-cart' => __('view cart', 'super-speedy-performance-analysis'),
            'flow-cart-api' => __('load cart data', 'super-speedy-performance-analysis'),
            'flow-update-customer' => __('enter address (tax + shipping)', 'super-speedy-performance-analysis'),
            'flow-update-order-review' => __('enter address (tax + shipping)', 'super-speedy-performance-analysis'),
            'flow-select-shipping' => __('select shipping', 'super-speedy-performance-analysis'),
            'flow-view-checkout' => __('view checkout', 'super-speedy-performance-analysis'),
            'flow-checkout-draft' => __('create draft order', 'super-speedy-performance-analysis'),
            'flow-place-order' => __('place order', 'super-speedy-performance-analysis'),
            'flow-order-received' => __('order received', 'super-speedy-performance-analysis'),
            'flow-view-order' => __('view order (wp-admin)', 'super-speedy-performance-analysis'),
            'flow-complete-order' => __('mark order completed', 'super-speedy-performance-analysis'),
            'flow-delete-order' => __('delete order (admin cleanup)', 'super-speedy-performance-analysis'),
        );
        return isset($labels[$page_key]) ? $labels[$page_key] : $page_key;
    }
}
