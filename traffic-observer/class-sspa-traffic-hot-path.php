<?php
defined('ABSPATH') || exit;

/**
 * Minimal production-request observer. Loaded only by the generated active MU file.
 * It buffers request/Woo events and performs one append statement at shutdown.
 */
class SSPA_Traffic_Hot_Path {

    private static $config = array();
    private static $started_ns = 0;
    private static $started_usage = null;
    private static $events = array();
    private static $dedupe = array();
    private static $context_captured = false;
    private static $basket_was_non_empty = false;
    private static $collection_key = false;

    public static function boot($config) {
        if (!is_array($config) || empty($config['collection_id']) || empty($config['table'])) {
            return;
        }
        if (is_multisite() || (int) get_current_blog_id() !== (int) $config['blog_id']) {
            return;
        }
        self::$config = $config;
        self::$started_ns = function_exists('hrtime') ? hrtime(true) : (int) round(microtime(true) * 1000000000);
        self::$started_usage = function_exists('getrusage') ? getrusage() : null;
        self::$basket_was_non_empty = self::cookie_basket_non_empty();
        if (self::collecting_requests() && self::$basket_was_non_empty) {
            self::buffer_event(
                SSPA_Traffic_Codes::EVENT_PRE_EXISTING_BASKET,
                array('flags' => SSPA_Traffic_Codes::FLAG_PRE_EXISTING_BASKET),
                'pre_existing_basket'
            );
        }

        add_action('plugins_loaded', array(__CLASS__, 'register_woo_hooks'), 100);
        add_action('wp', array(__CLASS__, 'capture_context'), PHP_INT_MAX);
        add_action('shutdown', array(__CLASS__, 'flush'), PHP_INT_MAX);
    }

    public static function register_woo_hooks() {
        if (!class_exists('WooCommerce')) {
            return;
        }
        if (self::collecting_requests()) {
            add_action('woocommerce_add_to_cart', array(__CLASS__, 'basket_added'), 100, 6);
            add_action('woocommerce_cart_emptied', array(__CLASS__, 'basket_emptied'), 100);
            add_action('woocommerce_checkout_order_created', array(__CLASS__, 'order_created'), 100);
            add_action('woocommerce_store_api_checkout_order_processed', array(__CLASS__, 'order_created'), 100);
        }
        // These may arrive asynchronously during the bounded outcome window.
        add_action('woocommerce_payment_complete', array(__CLASS__, 'payment_completed'), 100);
        add_action('woocommerce_order_status_changed', array(__CLASS__, 'order_status_changed'), 100, 4);
    }

    public static function capture_context() {
        self::$context_captured = true;
        if (!self::collecting_requests()) {
            return;
        }
        if (function_exists('is_cart') && is_cart()) {
            self::buffer_event(SSPA_Traffic_Codes::EVENT_CART_VIEWED, array(), 'cart_viewed');
        }
        if (function_exists('is_checkout') && is_checkout() && !(function_exists('is_order_received_page') && is_order_received_page())) {
            self::buffer_event(SSPA_Traffic_Codes::EVENT_CHECKOUT_STARTED, array(), 'checkout_started');
        }
    }

    public static function basket_added() {
        if (!self::$basket_was_non_empty) {
            $value = self::basket_value();
            self::buffer_event(SSPA_Traffic_Codes::EVENT_BASKET_STARTED, $value, 'basket_started');
        }
        self::$basket_was_non_empty = true;
    }

    public static function basket_emptied() {
        self::$basket_was_non_empty = false;
        self::buffer_event(SSPA_Traffic_Codes::EVENT_BASKET_EMPTIED, array(), 'basket_emptied');
    }

    public static function order_created($order) {
        if (!self::collecting_requests()) {
            return;
        }
        $order = self::order_object($order);
        if (!$order) {
            return;
        }
        $data = self::order_data($order);
        self::buffer_event(SSPA_Traffic_Codes::EVENT_ORDER_CREATED, $data, 'order_created:' . $data['commerce_key']);
    }

    public static function payment_completed($order_id) {
        $order = self::order_object($order_id);
        if (!$order) {
            return;
        }
        $data = self::order_data($order);
        self::buffer_event(SSPA_Traffic_Codes::EVENT_PAYMENT_COMPLETED, $data, 'payment:' . $data['commerce_key']);
    }

    public static function order_status_changed($order_id, $from, $to, $order) {
        $order = self::order_object($order ?: $order_id);
        if (!$order) {
            return;
        }
        $data = self::order_data($order);
        $paid_statuses = function_exists('wc_get_is_paid_statuses') ? wc_get_is_paid_statuses() : array('processing', 'completed');
        if (in_array($to, $paid_statuses, true)) {
            self::buffer_event(SSPA_Traffic_Codes::EVENT_PAID_STATUS_REACHED, $data, 'paid_status:' . $data['commerce_key']);
        } elseif ('cancelled' === $to) {
            self::buffer_event(SSPA_Traffic_Codes::EVENT_ORDER_CANCELLED, $data, 'cancelled:' . $data['commerce_key']);
        } elseif ('refunded' === $to) {
            self::buffer_event(SSPA_Traffic_Codes::EVENT_ORDER_REFUNDED, $data, 'refunded:' . $data['commerce_key']);
        }
    }

    private static function buffer_event($event_code, $data = array(), $dedupe = '') {
        if ($dedupe !== '' && isset(self::$dedupe[$dedupe])) {
            return;
        }
        if ($dedupe !== '') {
            self::$dedupe[$dedupe] = true;
        }
        self::$events[] = array_merge(array(
            'event_code' => (int) $event_code,
            'commerce_key' => null,
            'value_minor' => null,
            'currency' => null,
            'flags' => 0,
        ), $data);
    }

    public static function flush() {
        if (!self::$config) {
            return;
        }
        $flush_started = microtime(true);
        $context = self::actor_context();
        $surface = self::surface();
        $page_class = self::page_class();
        $ssf_protection = self::ssf_protection($page_class);
        $collect_request = self::collecting_requests() && self::should_record_request($context, $surface);

        if ($context['actor_key'] !== null && $context['related_actor_key'] !== null
            && $context['actor_key'] !== $context['related_actor_key']) {
            self::buffer_event(SSPA_Traffic_Codes::EVENT_ACTOR_ALIAS, array(
                'related_actor_key' => $context['related_actor_key'],
            ), 'actor_alias');
        }

        $observer_us = min(16777215, max(0, (int) round((microtime(true) - $flush_started) * 1000000)));
        $rows = array();
        if ($collect_request || self::$events) {
            if ($collect_request) {
                $rows[] = self::request_row($context, $surface, $page_class, $ssf_protection, $observer_us);
            }
            foreach (self::$events as $event) {
                $rows[] = self::event_row($event, $context, $surface, $page_class, $ssf_protection, $observer_us);
            }
        }
        if (!$rows) {
            return;
        }
        self::insert_rows($rows);
    }

    private static function request_row($context, $surface, $page_class, $ssf_protection, $observer_us) {
        $wall_ns = (function_exists('hrtime') ? hrtime(true) : (int) round(microtime(true) * 1000000000)) - self::$started_ns;
        $flags = $context['flags'];
        if (SSPA_Traffic_Codes::SURFACE_PUBLIC_CACHE_CANDIDATE === $surface) {
            $flags |= SSPA_Traffic_Codes::FLAG_CACHE_CANDIDATE;
        }
        $modulus = max(1, (int) self::$config['origin_sample_modulus']);
        if (self::target_actor($context)) {
            $flags |= SSPA_Traffic_Codes::FLAG_EXACT;
        } elseif ($modulus > 1) {
            $flags |= SSPA_Traffic_Codes::FLAG_SAMPLED;
        } else {
            $flags |= SSPA_Traffic_Codes::FLAG_EXACT;
        }
        return array(
            'actor_key' => $context['actor_key'],
            'related_actor_key' => null,
            'commerce_key' => null,
            'path_key' => self::path_key(),
            'event_code' => SSPA_Traffic_Codes::EVENT_REQUEST,
            'actor_state' => $context['actor_state'],
            'automation_code' => $context['automation_code'],
            'surface_code' => $surface,
            'page_class' => $page_class,
            'ssf_protection_code' => $ssf_protection,
            'status_code' => min(999, max(0, (int) http_response_code())),
            'wall_ms' => min(16777215, max(0, (int) round($wall_ns / 1000000))),
            'cpu_us' => self::cpu_delta_us(),
            'query_count' => isset($GLOBALS['wpdb']->num_queries) ? min(16777215, max(0, (int) $GLOBALS['wpdb']->num_queries)) : null,
            'observer_us' => $observer_us,
            'value_minor' => null,
            'currency' => null,
            'flags' => $flags,
        );
    }

    private static function event_row($event, $context, $surface, $page_class, $ssf_protection, $observer_us) {
        return array(
            'actor_key' => $context['actor_key'],
            'related_actor_key' => isset($event['related_actor_key']) ? $event['related_actor_key'] : null,
            'commerce_key' => isset($event['commerce_key']) ? $event['commerce_key'] : null,
            'path_key' => null,
            'event_code' => (int) $event['event_code'],
            'actor_state' => $context['actor_state'],
            'automation_code' => $context['automation_code'],
            'surface_code' => $surface,
            'page_class' => $page_class,
            'ssf_protection_code' => $ssf_protection,
            'status_code' => 0,
            'wall_ms' => 0,
            'cpu_us' => null,
            'query_count' => null,
            'observer_us' => $observer_us,
            'value_minor' => isset($event['value_minor']) ? $event['value_minor'] : null,
            'currency' => isset($event['currency']) ? $event['currency'] : null,
            'flags' => $context['flags'] | (isset($event['flags']) ? (int) $event['flags'] : 0) | SSPA_Traffic_Codes::FLAG_EXACT,
        );
    }

    private static function actor_context() {
        $logged_in = function_exists('is_user_logged_in') && is_user_logged_in();
        $basket = self::basket_non_empty();
        $staff = false;
        $user_key = null;
        $session_key = null;
        $automation = self::automation_class();

        if ($logged_in) {
            $user = wp_get_current_user();
            $staff_roles = array_intersect((array) $user->roles, array('administrator', 'shop_manager', 'editor'));
            $staff = !empty($staff_roles);
            $user_key = self::key('user:', (string) $user->ID);
        }
        $session_source = self::session_source();
        if ($session_source !== null) {
            $session_key = self::key($session_source[0], $session_source[1]);
        }

        if ($staff) {
            $state = SSPA_Traffic_Codes::ACTOR_STAFF;
        } elseif ($logged_in && $basket) {
            $state = SSPA_Traffic_Codes::ACTOR_LOGGED_IN_NON_EMPTY_BASKET;
        } elseif ($logged_in) {
            $state = SSPA_Traffic_Codes::ACTOR_LOGGED_IN_NO_BASKET;
        } elseif ($basket) {
            $state = SSPA_Traffic_Codes::ACTOR_GUEST_NON_EMPTY_BASKET;
        } elseif ($session_key !== null) {
            $state = SSPA_Traffic_Codes::ACTOR_ANONYMOUS_EMPTY_SESSION;
        } elseif (SSPA_Traffic_Codes::AUTOMATION_NOT_IDENTIFIED !== $automation) {
            $state = SSPA_Traffic_Codes::ACTOR_AUTOMATED_CLAIMED;
        } else {
            $state = SSPA_Traffic_Codes::ACTOR_ANONYMOUS_NO_SESSION;
        }

        $flags = 0;
        if ($logged_in) {
            $flags |= SSPA_Traffic_Codes::FLAG_LOGGED_IN;
        }
        if ($basket) {
            $flags |= SSPA_Traffic_Codes::FLAG_NON_EMPTY_BASKET;
        }
        if ($staff) {
            $flags |= SSPA_Traffic_Codes::FLAG_STAFF;
        }
        return array(
            'actor_key' => $user_key !== null ? $user_key : $session_key,
            'related_actor_key' => $user_key !== null ? $session_key : null,
            'actor_state' => $state,
            'automation_code' => $automation,
            'flags' => $flags,
        );
    }

    private static function should_record_request($context, $surface) {
        if (self::target_actor($context)) {
            return true;
        }
        if (in_array((int) $surface, array(
            SSPA_Traffic_Codes::SURFACE_WP_ADMIN,
            SSPA_Traffic_Codes::SURFACE_ADMIN_AJAX,
            SSPA_Traffic_Codes::SURFACE_REST_READ,
            SSPA_Traffic_Codes::SURFACE_REST_WRITE,
            SSPA_Traffic_Codes::SURFACE_WEBHOOK,
            SSPA_Traffic_Codes::SURFACE_WP_CRON,
        ), true)) {
            return false;
        }
        $modulus = max(1, (int) self::$config['origin_sample_modulus']);
        if ($modulus === 1) {
            return true;
        }
        $sample_key = self::key('sample:', self::$config['collection_id'] . '|' . self::$started_ns . '|' . self::request_path());
        if ($sample_key === null) {
            return false;
        }
        $number = unpack('Nvalue', substr($sample_key, 0, 4));
        return ((int) $number['value'] % $modulus) === 0;
    }

    private static function target_actor($context) {
        return in_array((int) $context['actor_state'], array(
            SSPA_Traffic_Codes::ACTOR_GUEST_NON_EMPTY_BASKET,
            SSPA_Traffic_Codes::ACTOR_LOGGED_IN_NO_BASKET,
            SSPA_Traffic_Codes::ACTOR_LOGGED_IN_NON_EMPTY_BASKET,
        ), true);
    }

    private static function collecting_requests() {
        return time() <= (int) self::$config['collect_until'];
    }

    private static function key($context, $raw) {
        if (self::$collection_key === false) {
            $stored = get_option(self::$config['key_option']);
            self::$collection_key = is_string($stored) && strlen($stored) === 64 && ctype_xdigit($stored)
                ? hex2bin($stored)
                : null;
        }
        if (!is_string(self::$collection_key)) {
            return null;
        }
        return substr(hash_hmac('sha256', $context . $raw, self::$collection_key, true), 0, 12);
    }

    private static function path_key() {
        $key = self::key('path:', self::request_path());
        return $key !== null ? substr($key, 0, 8) : null;
    }

    private static function session_source() {
        if (function_exists('WC') && WC() && isset(WC()->session) && is_object(WC()->session)) {
            $customer_id = WC()->session->get_customer_id();
            if (is_string($customer_id) && $customer_id !== '') {
                return array('wc-session:', $customer_id);
            }
        }
        foreach ((array) $_COOKIE as $name => $value) {
            if (strpos((string) $name, 'wp_woocommerce_session_') === 0 && is_string($value) && $value !== '') {
                return array('wc-cookie:', $value);
            }
        }
        return null;
    }

    private static function basket_non_empty() {
        if (function_exists('WC') && WC() && isset(WC()->cart) && is_object(WC()->cart)) {
            return !WC()->cart->is_empty();
        }
        return self::cookie_basket_non_empty();
    }

    private static function cookie_basket_non_empty() {
        return isset($_COOKIE['woocommerce_items_in_cart']) && (int) $_COOKIE['woocommerce_items_in_cart'] > 0;
    }

    private static function basket_value() {
        if (!function_exists('WC') || !WC() || !isset(WC()->cart) || !is_object(WC()->cart)) {
            return array();
        }
        return self::money_data(WC()->cart->get_total('edit'), get_woocommerce_currency());
    }

    private static function order_object($order) {
        if (is_object($order) && is_a($order, 'WC_Order')) {
            return $order;
        }
        return function_exists('wc_get_order') ? wc_get_order((int) $order) : false;
    }

    private static function order_data($order) {
        $flags = 0;
        $created_via = method_exists($order, 'get_created_via') ? (string) $order->get_created_via() : '';
        if (is_admin() && !wp_doing_ajax()) {
            $flags |= SSPA_Traffic_Codes::FLAG_EXCLUDED_ADMIN;
        }
        if ('admin' === $created_via) {
            $flags |= SSPA_Traffic_Codes::FLAG_EXCLUDED_ADMIN;
        }
        if (in_array($created_via, array('rest-api', 'import'), true)) {
            $flags |= SSPA_Traffic_Codes::FLAG_EXCLUDED_API;
        }
        if (strpos($created_via, 'subscription') !== false || strpos($created_via, 'renewal') !== false) {
            $flags |= SSPA_Traffic_Codes::FLAG_EXCLUDED_RENEWAL;
        }
        return array_merge(array(
            'commerce_key' => self::key('order:', (string) $order->get_id()),
            'flags' => $flags,
        ), self::money_data($order->get_total(), $order->get_currency()));
    }

    private static function money_data($amount, $currency) {
        $decimals = function_exists('wc_get_price_decimals') ? min(6, max(0, (int) wc_get_price_decimals())) : 2;
        return array(
            'value_minor' => (int) round((float) $amount * pow(10, $decimals)),
            'currency' => preg_match('/^[A-Z]{3}$/', strtoupper((string) $currency)) ? strtoupper((string) $currency) : null,
        );
    }

    private static function surface() {
        $method = strtoupper(isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET');
        $path = self::request_path();
        if (function_exists('wp_doing_cron') && wp_doing_cron()) {
            return SSPA_Traffic_Codes::SURFACE_WP_CRON;
        }
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return SSPA_Traffic_Codes::SURFACE_ADMIN_AJAX;
        }
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return in_array($method, array('GET', 'HEAD', 'OPTIONS'), true) ? SSPA_Traffic_Codes::SURFACE_REST_READ : SSPA_Traffic_Codes::SURFACE_REST_WRITE;
        }
        if (strpos($path, '/wp-login.php') !== false) {
            return SSPA_Traffic_Codes::SURFACE_LOGIN_OR_AUTH;
        }
        if (is_admin()) {
            return SSPA_Traffic_Codes::SURFACE_WP_ADMIN;
        }
        if (isset($_GET['wc-api'])) {
            return SSPA_Traffic_Codes::SURFACE_WEBHOOK;
        }
        if (function_exists('is_cart') && is_cart()) {
            return SSPA_Traffic_Codes::SURFACE_CART;
        }
        if (function_exists('is_checkout') && is_checkout()) {
            return SSPA_Traffic_Codes::SURFACE_CHECKOUT;
        }
        if (function_exists('is_account_page') && is_account_page()) {
            return SSPA_Traffic_Codes::SURFACE_ACCOUNT;
        }
        if ((function_exists('is_feed') && is_feed()) || strpos($path, 'sitemap') !== false) {
            return SSPA_Traffic_Codes::SURFACE_SITEMAP_OR_FEED;
        }
        if (in_array($method, array('GET', 'HEAD'), true)) {
            return SSPA_Traffic_Codes::SURFACE_PUBLIC_CACHE_CANDIDATE;
        }
        return SSPA_Traffic_Codes::SURFACE_PUBLIC_PRIVATE;
    }

    private static function page_class() {
        if (!self::$context_captured || is_admin() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return SSPA_Traffic_Codes::PAGE_UNKNOWN;
        }
        if (function_exists('is_front_page') && is_front_page()) {
            return SSPA_Traffic_Codes::PAGE_HOME;
        }
        if (function_exists('is_shop') && is_shop()) {
            return SSPA_Traffic_Codes::PAGE_SHOP;
        }
        if (function_exists('is_product') && is_product()) {
            return SSPA_Traffic_Codes::PAGE_PRODUCT_SINGLE;
        }
        if (function_exists('is_product_taxonomy') && is_product_taxonomy()) {
            return SSPA_Traffic_Codes::PAGE_PRODUCT_ARCHIVE;
        }
        if (function_exists('is_search') && is_search()) {
            return SSPA_Traffic_Codes::PAGE_SEARCH;
        }
        if (function_exists('is_tax') && is_tax()) {
            return SSPA_Traffic_Codes::PAGE_TAXONOMY;
        }
        if (function_exists('is_archive') && is_archive()) {
            return SSPA_Traffic_Codes::PAGE_ARCHIVE;
        }
        if (function_exists('is_singular') && is_singular()) {
            return SSPA_Traffic_Codes::PAGE_SINGLE;
        }
        return SSPA_Traffic_Codes::PAGE_OTHER_PUBLIC;
    }

    private static function request_path() {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        $path = wp_parse_url($uri, PHP_URL_PATH);
        return is_string($path) && $path !== '' ? $path : '/';
    }

    private static function automation_class() {
        $ua = strtolower(isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '');
        if ($ua === '') {
            return SSPA_Traffic_Codes::AUTOMATION_NOT_IDENTIFIED;
        }
        foreach (array('googlebot-product', 'adsbot-google', 'shoppingbot', 'pricebot', 'merchantbot') as $token) {
            if (strpos($ua, $token) !== false) {
                return SSPA_Traffic_Codes::AUTOMATION_CLAIMED_SHOPPING;
            }
        }
        foreach (array('googlebot', 'bingbot', 'yandexbot', 'baiduspider', 'duckduckbot', 'slurp', 'applebot', 'petalbot', 'seznambot', 'sogou') as $token) {
            if (strpos($ua, $token) !== false) {
                return SSPA_Traffic_Codes::AUTOMATION_CLAIMED_SEARCH;
            }
        }
        foreach (array('bot', 'crawler', 'spider', 'crawl', 'facebookexternalhit', 'facebot', 'twitterbot', 'linkedinbot', 'semrush', 'ahrefs', 'mj12', 'dotbot', 'bytespider', 'gptbot', 'chatgpt-user', 'claudebot', 'anthropic-ai', 'perplexitybot', 'ccbot', 'amazonbot', 'ia_archiver') as $token) {
            if (strpos($ua, $token) !== false) {
                return SSPA_Traffic_Codes::AUTOMATION_CLAIMED_GENERIC;
            }
        }
        return SSPA_Traffic_Codes::AUTOMATION_NOT_IDENTIFIED;
    }

    /**
     * Classify with SSF's installed pure archive-gate function. No policy is recreated here.
     */
    private static function ssf_protection($page_class) {
        if (SSPA_Traffic_Codes::PAGE_PRODUCT_SINGLE === (int) $page_class) {
            return SSPA_Traffic_Codes::SSF_PRODUCT_SINGLE_NOT_PROTECTABLE;
        }
        global $ssf_archive_gate_policy;
        if (!function_exists('ssf_archive_gate_decision') || !is_array($ssf_archive_gate_policy)) {
            return SSPA_Traffic_Codes::SSF_PROTECTION_UNAVAILABLE;
        }
        $policy = $ssf_archive_gate_policy;
        $policy['enabled'] = true;
        $decision = ssf_archive_gate_decision(
            isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET',
            isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/',
            $policy
        );
        if ('redirect' === ($decision['action'] ?? '')) {
            $target = wp_parse_url((string) ($decision['location'] ?? ''), PHP_URL_PATH);
            foreach ((array) ($policy['landing_paths'] ?? array()) as $path => $details) {
                if ((string) $target === (string) $path && empty($details['taxonomies']) && empty($details['terms'])) {
                    return SSPA_Traffic_Codes::SSF_REDIRECT_SHOP;
                }
            }
            return SSPA_Traffic_Codes::SSF_REDIRECT_NEAREST_ARCHIVE;
        }
        if ('indexable' === ($decision['reason'] ?? '') && in_array((int) $page_class, array(
            SSPA_Traffic_Codes::PAGE_SHOP,
            SSPA_Traffic_Codes::PAGE_PRODUCT_ARCHIVE,
        ), true)) {
            return SSPA_Traffic_Codes::SSF_PRODUCT_ARCHIVE_ALLOWED;
        }
        return SSPA_Traffic_Codes::SSF_UNRELATED_REQUEST;
    }

    private static function cpu_delta_us() {
        if (!is_array(self::$started_usage) || !function_exists('getrusage')) {
            return null;
        }
        $end = getrusage();
        $start_us = ((int) self::$started_usage['ru_utime.tv_sec'] + (int) self::$started_usage['ru_stime.tv_sec']) * 1000000
            + (int) self::$started_usage['ru_utime.tv_usec'] + (int) self::$started_usage['ru_stime.tv_usec'];
        $end_us = ((int) $end['ru_utime.tv_sec'] + (int) $end['ru_stime.tv_sec']) * 1000000
            + (int) $end['ru_utime.tv_usec'] + (int) $end['ru_stime.tv_usec'];
        return min(4294967295, max(0, $end_us - $start_us));
    }

    private static function insert_rows($rows) {
        global $wpdb;
        $values = array();
        $groups = array();
        foreach ($rows as $row) {
            $parts = array('%d', '%d');
            $values[] = (int) self::$config['collection_id'];
            $values[] = time();
            foreach (array('actor_key', 'related_actor_key', 'commerce_key', 'path_key') as $binary) {
                if ($row[$binary] === null) {
                    $parts[] = 'NULL';
                } else {
                    $parts[] = '%s';
                    $values[] = $row[$binary];
                }
            }
            foreach (array('event_code', 'actor_state', 'automation_code', 'surface_code', 'page_class', 'ssf_protection_code', 'status_code', 'wall_ms') as $number) {
                $parts[] = '%d';
                $values[] = (int) $row[$number];
            }
            foreach (array('cpu_us', 'query_count', 'observer_us', 'value_minor') as $nullable_number) {
                if ($row[$nullable_number] === null) {
                    $parts[] = 'NULL';
                } else {
                    $parts[] = '%d';
                    $values[] = (int) $row[$nullable_number];
                }
            }
            if ($row['currency'] === null) {
                $parts[] = 'NULL';
            } else {
                $parts[] = '%s';
                $values[] = $row['currency'];
            }
            $parts[] = '%d';
            $values[] = (int) $row['flags'];
            $groups[] = '(' . implode(',', $parts) . ')';
        }
        $columns = '(collection_id,observed_at,actor_key,related_actor_key,commerce_key,path_key,event_code,actor_state,automation_code,surface_code,page_class,ssf_protection_code,status_code,wall_ms,cpu_us,query_count,observer_us,value_minor,currency,flags)';
        $sql = 'INSERT INTO `' . esc_sql(self::$config['table']) . '` ' . $columns . ' VALUES ' . implode(',', $groups);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is built above from literal column names and generated %s/%d/NULL placeholders only; every value goes in through $values.
        $prepared = $wpdb->prepare($sql, $values);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $prepared is the return of $wpdb->prepare() on the line above.
        $inserted = $wpdb->query($prepared);
        if ($inserted === false) {
            self::retire('database_error');
            return;
        }
        $last_id = (int) $wpdb->insert_id + max(0, count($rows) - 1);
        if ($last_id >= (int) self::$config['event_id_stop']) {
            self::retire('event_limit');
        }
    }

    private static function retire($reason) {
        $observer = self::$config['observer_path'];
        $stopped = self::$config['stopped_path'];
        if (is_file($observer)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- retirement path inside the observer's hot path; runs before the plugin (and WP_Filesystem) is available and must stay dependency-free.
            @rename($observer, $stopped);
            @file_put_contents($stopped, sanitize_key($reason));
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($observer, true);
        }
    }
}
