<?php
defined('ABSPATH') || exit;

/**
 * The disclosure shown to the administrator BEFORE a checkout-flow run creates anything.
 *
 * A flow run buys something for real: a real order, real emails, real integrations (doc
 * A5). Suppressing them would measure a store nobody has, so instead the admin is shown
 * exactly what the run will set off and given switches to turn parts off.
 *
 * Gathered on the FRONT END, inside a profiled request, because that is where checkout
 * hooks are actually registered - an inventory taken from a wp-admin screen misses every
 * front-end-only registration and reports a quieter store than the customer meets.
 */
class SSPA_Checkout_Preflight {

    /** The order-lifecycle hooks a store's integrations attach to. */
    const ORDER_HOOKS = array(
        'woocommerce_checkout_order_processed',
        'woocommerce_store_api_checkout_order_processed',
        'woocommerce_new_order',
        'woocommerce_order_status_changed',
        'woocommerce_payment_complete',
        'woocommerce_thankyou',
    );

    const MAX_COMPONENTS = 40;

    /**
     * @return array The disclosure. Every key is present even on a store without
     *               WooCommerce, so the panel never has to guess why something is missing.
     */
    public static function inventory() {
        $out = array(
            'woocommerce' => class_exists('WooCommerce'),
            'checkout_type' => null,
            // Where orders live. The order screens this flow measures are a different piece
            // of software under HPOS, so a management timing without it is uncomparable.
            'hpos' => null,
            'emails' => array(),
            'emails_deferred' => null,
            'webhooks' => array(),
            'webhooks_inline' => null,
            'order_hooks' => array(),
            'order_hooks_more' => 0,
            'needs_payment_filtered' => false,
            'payment_modes' => array(),
            'creates_account' => null,
            'product' => null,
            'address' => null,
            'email_to' => null,
            'stock_managed' => null,
        );
        if (!$out['woocommerce']) {
            return $out;
        }

        $out['checkout_type'] = self::checkout_type();
        $out['hpos'] = class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        $out['emails'] = self::emails();
        $out['emails_deferred'] = self::emails_deferred();
        $out['webhooks'] = self::webhooks();
        $out['webhooks_inline'] = !apply_filters('woocommerce_webhook_deliver_async', true, null, null);
        list($out['order_hooks'], $out['order_hooks_more']) = self::order_hook_components();
        // Another plugin manipulating the same filters our no-payment mode uses can change
        // what that mode measures, so say so rather than quietly interacting with it.
        $out['needs_payment_filtered'] = (bool) has_filter('woocommerce_cart_needs_payment')
            || (bool) has_filter('woocommerce_order_needs_payment');
        $out['payment_modes'] = SSPA_Checkout_Flow::payment_modes();
        // Guest checkout disabled means the store creates a customer ACCOUNT for the
        // purchase. The run marks it at creation and deletes it with the order, but it is
        // one more thing the run sets off, so the admin is told beforehand.
        $out['creates_account'] = 'yes' !== get_option('woocommerce_enable_guest_checkout');

        $product = SSPA_Checkout_Flow::default_product();
        if ($product) {
            $out['product'] = array(
                'id' => $product->get_id(),
                'name' => $product->get_name(),
                'price' => wc_price($product->get_price()),
                'manages_stock' => $product->managing_stock(),
                'stock' => $product->get_stock_quantity(),
            );
            $out['stock_managed'] = $product->managing_stock();
        }
        $out['address'] = SSPA_Checkout_Flow::default_address();
        // The pre-flight runs before a run exists, so show the shape of the address rather
        // than inventing a run id for it.
        $out['email_to'] = SSPA_Checkout_Flow::billing_email('<run id>');

        return $out;
    }

    /**
     * Block or shortcode checkout. Uses WooCommerce's own helper where it exists: a block
     * theme template can supply the checkout block without it ever appearing in
     * post_content, which is exactly what has_block() against the page misses.
     *
     * @return string block|classic|unknown
     */
    public static function checkout_type() {
        if (class_exists('\Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils')
            && method_exists('\Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils', 'is_checkout_block_default')) {
            return \Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils::is_checkout_block_default() ? 'block' : 'classic';
        }
        $page_id = function_exists('wc_get_page_id') ? wc_get_page_id('checkout') : 0;
        $page = $page_id > 0 ? get_post($page_id) : null;
        if (!$page) {
            return 'unknown';
        }
        if (function_exists('has_block') && has_block('woocommerce/checkout', $page)) {
            return 'block';
        }
        return (false !== strpos((string) $page->post_content, 'woocommerce_checkout')) ? 'classic' : 'unknown';
    }

    /**
     * The transactional emails an ORDER can set off, with where each would land.
     *
     * Only order emails: WooCommerce's mailer also holds the account emails (password
     * reset, new account, email confirmation), and listing those would tell the admin this
     * run sends thirteen messages when it sends three. An email counts as an order email
     * when it has hooked itself to an order-related action, which catches a third-party
     * order email as readily as a core one - and third-party order emails are exactly the
     * ones worth disclosing.
     *
     * @return array[] {id, title, enabled, recipient, hooks}
     */
    private static function emails() {
        $out = array();
        if (!function_exists('WC') || !method_exists(WC(), 'mailer')) {
            return $out;
        }
        // Read the emails FIRST: each WC_Email registers its own *_notification actions in
        // its constructor, and WC()->mailer() is what constructs them. Building the hook
        // map before this call reads a registry they are not in yet.
        $emails = WC()->mailer()->get_emails();
        $hooks_by_email = self::email_hooks();

        foreach ($emails as $email) {
            if (!is_object($email) || !method_exists($email, 'is_enabled')) {
                continue;
            }
            $id = isset($email->id) ? (string) $email->id : '';
            $hooks = isset($hooks_by_email[spl_object_hash($email)]) ? $hooks_by_email[spl_object_hash($email)] : array();
            $order_related = false;
            foreach ($hooks as $hook) {
                if (false !== strpos($hook, 'order')) {
                    $order_related = true;
                    break;
                }
            }
            if (!$order_related) {
                continue;
            }
            $recipient = method_exists($email, 'get_recipient') ? (string) $email->get_recipient() : '';
            $out[] = array(
                'id' => $id,
                'title' => isset($email->title) ? (string) $email->title : '',
                'enabled' => (bool) $email->is_enabled(),
                // A customer email has no configured recipient: it goes to the address on
                // the order, which for this run is the tagged admin address.
                'recipient' => $recipient !== '' ? $recipient : null,
                'hooks' => array_values($hooks),
            );
        }
        return $out;
    }

    /**
     * Which actions each WC_Email instance has hooked itself to, from the live registry.
     * One pass over $wp_filter; this only ever runs inside the pre-flight probe.
     *
     * @return array spl_object_hash => [hook names]
     */
    private static function email_hooks() {
        $map = array();
        if (empty($GLOBALS['wp_filter']) || !is_array($GLOBALS['wp_filter'])) {
            return $map;
        }
        foreach ($GLOBALS['wp_filter'] as $hook => $registry) {
            if (!isset($registry->callbacks) || false === strpos($hook, 'notification')) {
                continue; // WC_Email subclasses only ever hook *_notification actions
            }
            foreach ($registry->callbacks as $callbacks) {
                foreach ((array) $callbacks as $cb) {
                    $fn = isset($cb['function']) ? $cb['function'] : null;
                    if (!is_array($fn) || !isset($fn[0]) || !is_object($fn[0]) || !($fn[0] instanceof WC_Email)) {
                        continue;
                    }
                    $key = spl_object_hash($fn[0]);
                    if (!isset($map[$key])) {
                        $map[$key] = array();
                    }
                    $map[$key][$hook] = $hook;
                }
            }
        }
        return $map;
    }

    /**
     * Whether order emails are dispatched inside the request or handed to a deferred
     * queue. This single boolean is the difference between order emails costing the
     * customer over a second and costing them nothing (doc A7 fact 6).
     */
    private static function emails_deferred() {
        $default = false;
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            $default = (bool) \Automattic\WooCommerce\Utilities\FeaturesUtil::feature_is_enabled('deferred_transactional_emails');
        }
        return (bool) apply_filters('woocommerce_defer_transactional_emails', $default);
    }

    /**
     * Active webhooks reduced to their delivery HOST - the full URL routinely carries a
     * secret in its query string and this list is shown on screen.
     *
     * @return array[] {host, topic}
     */
    private static function webhooks() {
        $out = array();
        if (!class_exists('WC_Data_Store')) {
            return $out;
        }
        try {
            $ids = WC_Data_Store::load('webhook')->get_webhooks_ids('active');
        } catch (Exception $e) {
            return $out;
        }
        foreach ((array) $ids as $id) {
            $webhook = function_exists('wc_get_webhook') ? wc_get_webhook($id) : null;
            if (!$webhook) {
                continue;
            }
            $host = parse_url((string) $webhook->get_delivery_url(), PHP_URL_HOST);
            $out[] = array(
                'host' => $host ? $host : 'unknown',
                'topic' => (string) $webhook->get_topic(),
            );
        }
        return $out;
    }

    /**
     * Which components will run code when this order is created, resolved from the live
     * hook registry by reflecting each callback back to the file that defined it.
     *
     * @return array [ [ {component, type, hooks: []}, ... ], int overflow ]
     */
    private static function order_hook_components() {
        require_once SSPA_PLUGIN_DIR . 'profiler/class-sspa-component-map.php';
        $map = new SSPA_Component_Map();
        $components = array();

        foreach (self::ORDER_HOOKS as $hook) {
            if (empty($GLOBALS['wp_filter'][$hook]) || !isset($GLOBALS['wp_filter'][$hook]->callbacks)) {
                continue;
            }
            foreach ($GLOBALS['wp_filter'][$hook]->callbacks as $callbacks) {
                foreach ((array) $callbacks as $cb) {
                    $file = self::callback_file(isset($cb['function']) ? $cb['function'] : null);
                    if (!$file) {
                        continue;
                    }
                    $cls = $map->classify_file($file);
                    $slug = $cls['component'];
                    if (!isset($components[$slug])) {
                        $components[$slug] = array('component' => $slug, 'type' => $cls['type'], 'hooks' => array());
                    }
                    $components[$slug]['hooks'][$hook] = true;
                }
            }
        }

        foreach ($components as &$c) {
            $c['hooks'] = array_keys($c['hooks']);
        }
        unset($c);
        ksort($components);
        $components = array_values($components);
        $overflow = max(0, count($components) - self::MAX_COMPONENTS);
        return array(array_slice($components, 0, self::MAX_COMPONENTS), $overflow);
    }

    /** @return string The defining file of a callable, or '' when it has none. */
    private static function callback_file($fn) {
        try {
            if (is_array($fn) && isset($fn[0], $fn[1])) {
                $ref = new ReflectionMethod(is_object($fn[0]) ? get_class($fn[0]) : $fn[0], $fn[1]);
            } elseif ($fn instanceof Closure) {
                $ref = new ReflectionFunction($fn);
            } elseif (is_string($fn) && function_exists($fn)) {
                $ref = new ReflectionFunction($fn);
            } elseif (is_object($fn) && method_exists($fn, '__invoke')) {
                $ref = new ReflectionMethod($fn, '__invoke');
            } else {
                return '';
            }
            return (string) $ref->getFileName();
        } catch (Throwable $e) {
            return ''; // internal callable - nothing to attribute
        }
    }
}
