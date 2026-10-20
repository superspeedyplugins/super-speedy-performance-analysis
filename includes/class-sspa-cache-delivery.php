<?php
defined('ABSPATH') || exit;

/**
 * Bounded, administrator-triggered cache performance check for three audiences.
 *
 * The browser and server transports deliberately remain separate. Browser Resource Timing
 * measures the anonymous visitor path. WordPress HTTP API requests from the server measure
 * all three audiences on one boundary: an anonymous visitor, a request carrying
 * WooCommerce's basket cookies (no basket and no session are created) and the synthetic
 * logged-in test customer. A final anonymous request per page checks that nothing the
 * logged-in request produced reached the anonymous cache entry. No timing is subtracted
 * from another, and origin generation from the analysis run is shown beside, never mixed in.
 */
class SSPA_Cache_Delivery {

    const SCHEMA = 'sspa/cache-optimisation-analysis@3';
    const PENDING_OPTION = 'sspa_cache_delivery_pending';
    const REPORT_OPTION = 'sspa_cache_delivery_report';
    const MAX_BODY_BYTES = 5242880;
    const REQUESTS_PER_URL = 2;

    /** The audiences measured on the server loopback boundary, in request order. */
    const AUDIENCES = array('anonymous', 'basket', 'customer');

    /** Catalogue pages: the pages a visitor with a basket or a login keeps browsing. */
    const CATALOGUE_PAGES = array('shop', 'product_category', 'product');

    /**
     * The fixed server request order for every page. Anonymous first so a cache entry exists,
     * then the two private audiences, then one final anonymous request that would show a
     * private response stored under the public cache key.
     */
    public static function server_legs() {
        return array(
            array('anonymous', 1),
            array('anonymous', 2),
            array('basket', 1),
            array('basket', 2),
            array('customer', 1),
            array('customer', 2),
            array('final_anonymous', 1),
        );
    }

    public static function ajax_prepare() {
        check_ajax_referer('sspa_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You are not allowed to run this check.', 'super-speedy-performance-analysis')), 403);
        }
        $prepared = self::prepare();
        if (is_wp_error($prepared)) {
            wp_send_json_error(array('message' => $prepared->get_error_message(), 'code' => $prepared->get_error_code()));
        }
        wp_send_json_success($prepared);
    }

    public static function ajax_server_probe() {
        check_ajax_referer('sspa_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You are not allowed to run this check.', 'super-speedy-performance-analysis')), 403);
        }
        $assessment_id = isset($_POST['assessment_id']) ? sanitize_text_field(wp_unslash($_POST['assessment_id'])) : '';
        $page_key = isset($_POST['page_key']) ? sanitize_key(wp_unslash($_POST['page_key'])) : '';
        $audience = isset($_POST['audience']) ? sanitize_key(wp_unslash($_POST['audience'])) : '';
        $request_number = isset($_POST['request_number']) ? (int) $_POST['request_number'] : 0;
        $result = self::probe_server_request($assessment_id, $page_key, $audience, $request_number);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message(), 'code' => $result->get_error_code()));
        }
        // The browser needs only progress, not the evidence itself.
        wp_send_json_success(array('page_key' => $result['page_key'], 'audience' => $result['audience'], 'request_number' => $result['request_number']));
    }

    public static function ajax_complete() {
        check_ajax_referer('sspa_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You are not allowed to run this check.', 'super-speedy-performance-analysis')), 403);
        }
        $assessment_id = isset($_POST['assessment_id']) ? sanitize_text_field(wp_unslash($_POST['assessment_id'])) : '';
        // A structured form array, sanitised as it is read. Every field is then validated
        // against the fixed targets by normalise_browser_results().
        $browser_results = isset($_POST['browser_results']) ? map_deep(wp_unslash($_POST['browser_results']), 'sanitize_text_field') : array();
        $report = self::complete($assessment_id, is_array($browser_results) ? array_values($browser_results) : array());
        if (is_wp_error($report)) {
            wp_send_json_error(array('message' => $report->get_error_message(), 'code' => $report->get_error_code()));
        }
        wp_send_json_success(array(
            'schema' => $report['schema'],
            'page_cache_status' => $report['page_cache_status'],
        ));
    }

    /**
     * Fixed, generated public targets. There is no arbitrary URL input.
     *
     * @param bool $with_facts Also read product and category facts. Only the check itself
     *                         needs them; rendering the tab does not.
     */
    public static function targets($with_facts = false) {
        $catalogue = SSPA_Catalogue::build(array('home', 'shop', 'product-cat', 'product-single'));
        $by_key = array();
        foreach ($catalogue as $job) {
            if ('anon' === $job['variant']) {
                $by_key[$job['page_key']] = $job['url'];
            }
        }

        $definitions = array(
            'home' => array('catalogue_key' => 'home', 'label' => __('Home', 'super-speedy-performance-analysis'), 'reason' => 'canonical_home'),
            'shop' => array('catalogue_key' => 'shop', 'label' => __('Shop', 'super-speedy-performance-analysis'), 'reason' => 'woocommerce_shop_page'),
            'product_category' => array('catalogue_key' => 'product-cat', 'label' => __('Product category', 'super-speedy-performance-analysis'), 'reason' => 'largest_populated_product_category'),
            'product' => array('catalogue_key' => 'product-single', 'label' => __('Product', 'super-speedy-performance-analysis'), 'reason' => 'latest_published_product'),
        );

        $targets = array();
        foreach ($definitions as $page_key => $definition) {
            $url = isset($by_key[$definition['catalogue_key']]) ? $by_key[$definition['catalogue_key']] : '';
            if (!$url || !self::safe_target($url)) {
                continue;
            }
            $target = array(
                'page_key' => $page_key,
                'origin_page_key' => $definition['catalogue_key'],
                'label' => $definition['label'],
                'url' => esc_url_raw($url),
                'selection_reason' => $definition['reason'],
            );
            if ($with_facts) {
                $target['facts'] = self::target_facts($page_key, $url);
            }
            $targets[] = $target;
        }
        return $targets;
    }

    private static function target_facts($page_key, $url) {
        if ('product_category' === $page_key && taxonomy_exists('product_cat')) {
            $path = trim((string) wp_parse_url($url, PHP_URL_PATH), '/');
            $slug = $path ? basename($path) : '';
            $term = $slug ? get_term_by('slug', $slug, 'product_cat') : false;
            if ($term) {
                return array('direct_product_count' => (int) $term->count);
            }
        }
        if ('product' === $page_key && function_exists('wc_get_product')) {
            $product = wc_get_product(url_to_postid($url));
            if ($product) {
                return array(
                    'product_type' => sanitize_key($product->get_type()),
                    'in_stock' => (bool) $product->is_in_stock(),
                    'purchasable' => (bool) $product->is_purchasable(),
                );
            }
        }
        return array();
    }

    /** Requests the check will send, for the explanation shown before the button. */
    public static function request_plan($target_count) {
        $server_per_page = count(self::server_legs());
        return array(
            'browser_requests' => $target_count * self::REQUESTS_PER_URL,
            'server_requests' => $target_count * $server_per_page,
            'request_count' => $target_count * (self::REQUESTS_PER_URL + $server_per_page),
        );
    }

    public static function prepare() {
        $targets = self::targets(true);
        if (!$targets || 'home' !== $targets[0]['page_key']) {
            return new WP_Error('sspa_cache_delivery_targets', __('The check needs a public home page on this site.', 'super-speedy-performance-analysis'));
        }
        $assessment_id = wp_generate_uuid4();
        $pending = array(
            'assessment_id' => $assessment_id,
            'run_id' => (int) SSPA_Report::latest_done_run_id(),
            'created_at' => time(),
            'targets' => $targets,
            // A random value of the shape WooCommerce writes. It names no real basket.
            'basket_cart_hash' => md5(wp_generate_uuid4()),
            'server_results' => array(),
        );
        update_option(self::PENDING_OPTION, $pending, false);
        $plan = self::request_plan(count($targets));
        $client_targets = array();
        foreach ($targets as $target) {
            $client_targets[] = array('page_key' => $target['page_key'], 'label' => $target['label'], 'url' => $target['url']);
        }
        return array_merge(array(
            'assessment_id' => $assessment_id,
            'targets' => $client_targets,
            'server_legs' => array_map(function ($leg) {
                return array('audience' => $leg[0], 'request_number' => $leg[1]);
            }, self::server_legs()),
        ), $plan);
    }

    public static function probe_server_request($assessment_id, $page_key, $audience, $request_number) {
        $pending = self::pending($assessment_id);
        if (is_wp_error($pending)) {
            return $pending;
        }
        $target = self::target_by_key($pending['targets'], $page_key);
        if (!$target) {
            return new WP_Error('sspa_cache_delivery_target', __('That page is not part of this fixed check.', 'super-speedy-performance-analysis'));
        }
        $legs = self::server_legs();
        $position = null;
        foreach ($legs as $index => $leg) {
            if ($leg[0] === $audience && $leg[1] === (int) $request_number) {
                $position = $index;
                break;
            }
        }
        if (null === $position) {
            return new WP_Error('sspa_cache_delivery_request', __('Invalid cache check request.', 'super-speedy-performance-analysis'));
        }

        $key = $page_key . ':' . $audience . ':' . (int) $request_number;
        if (isset($pending['server_results'][$key])) {
            return $pending['server_results'][$key];
        }
        // The leak check means something only after the private requests, so the order is
        // enforced here rather than trusted to the browser.
        for ($i = 0; $i < $position; $i++) {
            if (!isset($pending['server_results'][$page_key . ':' . $legs[$i][0] . ':' . $legs[$i][1]])) {
                return new WP_Error('sspa_cache_delivery_order', __('The cache check requests must run in their fixed order.', 'super-speedy-performance-analysis'));
            }
        }

        $first_key = $page_key . ':anonymous:1';
        if ('anonymous' === $audience && 2 === (int) $request_number && !empty($pending['server_results'][$first_key]['application_cookie_names'])) {
            // A browser would carry that cookie into the second request, so a second clean
            // request would describe a visitor who does not exist.
            $result = self::empty_result($target, $audience, 2, 'application_session_cookie_set', __('The first response set an application cookie, so this URL was not repeated with a contaminated client.', 'super-speedy-performance-analysis'));
        } elseif ('basket' === $audience && !self::woocommerce_active()) {
            $result = self::empty_result($target, $audience, (int) $request_number, 'woocommerce_not_active', __('WooCommerce is not active, so there is no basket to represent.', 'super-speedy-performance-analysis'));
        } else {
            $cookies = self::cookies_for_audience($audience, $pending);
            if (is_wp_error($cookies)) {
                $result = self::empty_result($target, $audience, (int) $request_number, $cookies->get_error_code(), $cookies->get_error_message());
            } else {
                $result = self::server_get($target, $audience, (int) $request_number, $cookies);
            }
        }
        $pending['server_results'][$key] = $result;
        update_option(self::PENDING_OPTION, $pending, false);
        return $result;
    }

    /**
     * The cookies each audience presents. None of them is ever stored in the report.
     *
     * The basket audience carries only the two cookies WooCommerce sets once something is in
     * a basket. There is no session cookie and no basket behind them, and WooCommerce answers
     * an empty basket by clearing the two cookies, so no basket or session is created.
     */
    private static function cookies_for_audience($audience, $pending) {
        if ('basket' === $audience) {
            return array(
                'woocommerce_items_in_cart' => '1',
                'woocommerce_cart_hash' => (string) $pending['basket_cart_hash'],
            );
        }
        if ('customer' === $audience) {
            $cookies = SSPA_Auth::cookies_for('customer', 0);
            if (!$cookies) {
                return new WP_Error('test_customer_unavailable', __('The synthetic test customer account could not be created.', 'super-speedy-performance-analysis'));
            }
            return $cookies;
        }
        return array();
    }

    public static function complete($assessment_id, $browser_results) {
        $pending = self::pending($assessment_id);
        if (is_wp_error($pending)) {
            return $pending;
        }
        $expected = count($pending['targets']) * count(self::server_legs());
        if (count($pending['server_results']) !== $expected) {
            return new WP_Error('sspa_cache_delivery_server_incomplete', __('The server-side cache checks have not finished.', 'super-speedy-performance-analysis'));
        }
        $browser = self::normalise_browser_results($browser_results, $pending['targets']);
        if (is_wp_error($browser)) {
            return $browser;
        }

        $run_id = (int) $pending['run_id'];
        $assessment = array();
        if ($run_id) {
            $cache_safety = SSPA_Report::cache_safety($run_id);
            if (!is_wp_error($cache_safety)) {
                $assessment = $cache_safety['assessment'];
            }
        }
        $origin_profiles = self::origin_profiles($run_id, $pending['targets']);
        $server = array_values($pending['server_results']);
        $cache_system = SSPA_Cache_System::inventory();
        $browser_verdicts = self::page_verdicts($pending['targets'], $browser);
        $audience_verdicts = self::audience_verdicts($pending['targets'], $server, $cache_system);
        $page_cache_status = self::overall_status(array_column($audience_verdicts['anonymous'], 'verdict'));
        $leak_check = self::leak_check($pending['targets'], $server);
        $route_failures = self::route_failures($browser, $server);
        $audiences = self::audience_summary($pending['targets'], $audience_verdicts, $server, $origin_profiles);

        $report = array(
            'schema' => self::SCHEMA,
            'assessment_id' => $assessment_id,
            'generated_at' => gmdate('c'),
            'generated_by' => array(
                'plugin' => 'super-speedy-performance-analysis',
                'version' => SSPA_VERSION,
                'wordpress' => get_bloginfo('version'),
                'php' => PHP_VERSION,
                'site_url' => home_url('/'),
            ),
            'sensitivity' => array(
                'classification' => 'private-site-diagnostic',
                'warning' => __('This report contains exact public page URLs, software versions and local aggregate counts. It contains no response HTML, cookie values, nonce values, customer records or snippet source.', 'super-speedy-performance-analysis'),
            ),
            'run_id' => $run_id,
            'headline' => self::status_label($page_cache_status),
            'detail' => __('Browser visitor-path timing, server-loopback timing for each audience and profiled origin generation are reported as separate measured boundaries.', 'super-speedy-performance-analysis'),
            // Compatibility block for version 2 readers.
            'assessment' => $assessment,
            'page_cache_status' => $page_cache_status,
            'audiences' => $audiences,
            'audience_verdicts' => $audience_verdicts,
            'leak_check' => $leak_check,
            'cache_system' => $cache_system,
            'origin_profiles' => $origin_profiles,
            'delivery_path_observations' => array(
                'browser' => array(
                    'visitor_state' => 'anonymous_browser',
                    'requests' => $browser,
                ),
                'server' => array(
                    'visitor_state' => 'per_audience_server_loopback',
                    'requests' => $server,
                ),
            ),
            'cache_layers' => self::all_layers($browser, $server),
            'representative_pages' => self::representative_pages($pending['targets'], $browser),
            'page_verdicts' => $browser_verdicts,
            'visitor_specific_content_reconnaissance' => $assessment,
            'software_inventory' => self::software_inventory(),
            'scale_inventory' => self::scale_inventory(),
            'source_coverage' => isset($assessment['source_scan']) ? $assessment['source_scan'] : array(),
            'route_failures' => $route_failures,
            'opportunity' => self::opportunity($pending['targets'], $origin_profiles, $browser),
            'difficulty' => array(
                'rating' => isset($assessment['difficulty']) ? $assessment['difficulty'] : 'unknown',
                'points' => isset($assessment['difficulty_points']) ? (int) $assessment['difficulty_points'] : null,
            ),
            'evidence_sufficiency' => $route_failures ? 'qualification_incomplete' : 'staging_design_requires_identity_comparison',
            'limitations' => array(
                'basket_audience_carries_basket_cookies_without_a_basket',
                'customer_audience_is_a_synthetic_account_without_orders',
                'audience_comparison_uses_the_server_loopback_boundary',
                'browser_server_and_origin_boundaries_are_not_interchangeable',
                'anonymous_cache_proof_does_not_approve_live_customer_caching',
            ),
        );
        update_option(self::REPORT_OPTION, $report, false);
        delete_option(self::PENDING_OPTION);
        return $report;
    }

    /** The latest saved check, whichever analysis run was current when it ran. */
    public static function latest() {
        $report = get_option(self::REPORT_OPTION, null);
        if (!is_array($report) || self::SCHEMA !== ($report['schema'] ?? '') || !isset($report['audiences'])) {
            return null;
        }
        return $report;
    }

    /**
     * The saved check for one analysis run. With no run named, the latest check is returned,
     * so a later analysis does not hide it from agents.
     */
    public static function report($run_id = 0) {
        $report = self::latest();
        if (!$report) {
            return new WP_Error('sspa_no_cache_delivery', __('No completed cache performance check was found.', 'super-speedy-performance-analysis'));
        }
        if ($run_id && (int) $run_id !== (int) $report['run_id']) {
            return new WP_Error('sspa_cache_delivery_run_mismatch', __('The saved cache performance check belongs to a different analysis run.', 'super-speedy-performance-analysis'));
        }
        return $report;
    }

    private static function pending($assessment_id) {
        $pending = get_option(self::PENDING_OPTION, null);
        if (!is_array($pending) || empty($pending['assessment_id']) || !hash_equals((string) $pending['assessment_id'], (string) $assessment_id)) {
            return new WP_Error('sspa_cache_delivery_expired', __('This cache check is missing or has been replaced.', 'super-speedy-performance-analysis'));
        }
        if (time() - (int) $pending['created_at'] > HOUR_IN_SECONDS) {
            delete_option(self::PENDING_OPTION);
            return new WP_Error('sspa_cache_delivery_expired', __('This cache check expired. Start it again.', 'super-speedy-performance-analysis'));
        }
        return $pending;
    }

    private static function woocommerce_active() {
        return class_exists('WooCommerce');
    }

    private static function target_by_key($targets, $page_key) {
        foreach ((array) $targets as $target) {
            if ($page_key === $target['page_key']) {
                return $target;
            }
        }
        return null;
    }

    private static function safe_target($url) {
        $parts = wp_parse_url($url);
        $home = wp_parse_url(home_url('/'));
        if (!$parts || !$home || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }
        if (!in_array(strtolower($parts['scheme']), array('http', 'https'), true)
            || strtolower($parts['host']) !== strtolower($home['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return false;
        }
        $path = '/' . ltrim(isset($parts['path']) ? $parts['path'] : '/', '/');
        if (preg_match('#^/(?:wp-admin(?:/|$)|wp-login\.php(?:/|$)|wp-json(?:/|$))#i', $path)) {
            return false;
        }
        if (function_exists('wc_get_page_permalink')) {
            foreach (array('cart', 'checkout', 'myaccount') as $private_page) {
                $private_url = wc_get_page_permalink($private_page);
                if ($private_url && untrailingslashit($url) === untrailingslashit($private_url)) {
                    return false;
                }
            }
        }
        return true;
    }

    private static function empty_result($target, $audience, $request_number, $error_class, $error_message) {
        return array(
            'transport' => 'server_loopback',
            'audience' => $audience,
            'page_key' => $target['page_key'],
            'request_number' => (int) $request_number,
            'url' => $target['url'],
            'http_status' => 0,
            'ttfb_ms' => null,
            'ttfb_source' => 'not_measured',
            'total_ms' => null,
            'error_class' => sanitize_key($error_class),
            'error_message' => substr(sanitize_text_field($error_message), 0, 500),
            'redirects' => array(),
            'headers' => array(),
            'body' => array('bytes' => 0, 'sha256' => null, 'normalised_sha256' => null, 'truncated' => false),
            'cache_layers' => array(),
            'markers' => array(),
            'application_cookie_names' => array(),
            'private_markers' => array('logged_in_body_class' => false, 'test_account_name' => false),
        );
    }

    private static function server_get($target, $audience, $request_number, $cookies) {
        $url = $target['url'];
        $redirects = array();
        $response = null;
        $curl_info = null;
        // WordPress exposes cURL's own timing through the Requests hook. The time to the first
        // response byte is read there; other transports report completion time only.
        $capture = static function ($headers, $info = null) use (&$curl_info) {
            if (is_array($info) && isset($info['starttransfer_time'])) {
                $curl_info = $info;
            }
        };
        add_action('requests-curl.after_request', $capture, 10, 2);
        $started = microtime(true);
        for ($hop = 0; $hop < 4; $hop++) {
            $curl_info = null;
            $response = wp_remote_get($url, array(
                'timeout' => 30,
                'redirection' => 0,
                'reject_unsafe_urls' => true,
                'sslverify' => true,
                'limit_response_size' => self::MAX_BODY_BYTES,
                'user-agent' => 'Super Speedy Performance Analysis/' . SSPA_VERSION . ' cache-delivery-probe',
                'headers' => array('Accept' => 'text/html,application/xhtml+xml'),
                'cookies' => $cookies,
            ));
            if (is_wp_error($response)) {
                remove_action('requests-curl.after_request', $capture, 10);
                $result = self::empty_result($target, $audience, $request_number, $response->get_error_code(), $response->get_error_message());
                $result['total_ms'] = round((microtime(true) - $started) * 1000, 1);
                $result['redirects'] = $redirects;
                return $result;
            }
            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code < 300 || $code >= 400) {
                break;
            }
            $location = wp_remote_retrieve_header($response, 'location');
            $next = $location ? wp_http_validate_url(wp_make_link_relative($location)) : '';
            if (!$next) {
                $next = $location ? esc_url_raw($location) : '';
            }
            if ($next && 0 === strpos($next, '/')) {
                $next = home_url($next);
            }
            if (!$next || !self::safe_target($next)) {
                remove_action('requests-curl.after_request', $capture, 10);
                $redirects[] = array('status' => $code, 'outcome' => 'refused_off_origin_or_private');
                $result = self::empty_result($target, $audience, $request_number, 'off_origin_or_private_redirect', __('The canonical page redirected outside the allowed public site routes.', 'super-speedy-performance-analysis'));
                $result['http_status'] = $code;
                $result['total_ms'] = round((microtime(true) - $started) * 1000, 1);
                $result['redirects'] = $redirects;
                return $result;
            }
            $redirects[] = array('status' => $code, 'url' => esc_url_raw($next));
            $url = $next;
        }
        $total_ms = round((microtime(true) - $started) * 1000, 1);
        remove_action('requests-curl.after_request', $capture, 10);

        $body = (string) wp_remote_retrieve_body($response);
        $headers = self::safe_headers(wp_remote_retrieve_headers($response));
        $markers = self::body_markers($body);
        $normalised = self::normalise_body($body);
        $sent = array_change_key_case((array) $cookies, CASE_LOWER);
        $cookie_names = array();
        foreach ((array) wp_remote_retrieve_cookies($response) as $cookie) {
            if (!is_object($cookie) || empty($cookie->name) || self::infrastructure_cookie($cookie->name)) {
                continue;
            }
            // WooCommerce answers basket cookies with no basket behind them by expiring the
            // same cookies; that is a reply to this audience's own cookies, not new state.
            if (isset($sent[strtolower($cookie->name)])) {
                continue;
            }
            $cookie_names[sanitize_key($cookie->name)] = true;
        }
        $ttfb = is_array($curl_info) && is_numeric($curl_info['starttransfer_time']) && $curl_info['starttransfer_time'] > 0
            ? round((float) $curl_info['starttransfer_time'] * 1000, 1)
            : null;
        return array(
            'transport' => 'server_loopback',
            'audience' => $audience,
            'page_key' => $target['page_key'],
            'request_number' => (int) $request_number,
            'url' => $target['url'],
            'final_url' => esc_url_raw($url),
            'http_status' => (int) wp_remote_retrieve_response_code($response),
            'ttfb_ms' => $ttfb,
            'ttfb_source' => null === $ttfb ? 'completion_time_only' : 'curl_starttransfer_time',
            'total_ms' => $total_ms,
            'error_class' => '',
            'error_message' => '',
            'redirects' => $redirects,
            'headers' => $headers,
            'body' => array(
                'bytes' => strlen($body),
                'sha256' => hash('sha256', $body),
                'normalised_sha256' => hash('sha256', $normalised),
                'truncated' => strlen($body) >= self::MAX_BODY_BYTES,
            ),
            'cache_layers' => self::classify_layers($headers, $markers, 'server_loopback', $request_number),
            'markers' => $markers,
            'application_cookie_names' => array_keys($cookie_names),
            // Checked in memory and kept only as booleans. No HTML, name or cookie is stored.
            'private_markers' => self::private_markers($body),
        );
    }

    /**
     * Signs that a response was produced for a logged-in visitor: WordPress's own body class
     * and the synthetic customer's display name.
     */
    private static function private_markers($body) {
        $name = '';
        $user_id = (int) SSPA_Auth::test_customer_id();
        if ($user_id) {
            $user = get_userdata($user_id);
            $name = $user ? (string) $user->display_name : '';
        }
        return array(
            'logged_in_body_class' => (bool) preg_match('/<body\b[^>]*\bclass\s*=\s*(["\'])(?:(?!\1).)*\blogged-in\b/is', $body),
            'test_account_name' => '' !== $name && false !== strpos($body, $name),
        );
    }

    private static function normalise_browser_results($results, $targets) {
        $expected = count($targets) * self::REQUESTS_PER_URL;
        if (count($results) !== $expected) {
            return new WP_Error('sspa_cache_delivery_browser_incomplete', __('The browser did not return every visitor-path measurement.', 'super-speedy-performance-analysis'));
        }
        $normalised = array();
        $seen = array();
        foreach ($results as $raw) {
            if (!is_array($raw)) {
                return new WP_Error('sspa_cache_delivery_browser_result', __('The browser returned an invalid check result.', 'super-speedy-performance-analysis'));
            }
            $page_key = sanitize_key(isset($raw['page_key']) ? $raw['page_key'] : '');
            $request_number = isset($raw['request_number']) ? (int) $raw['request_number'] : 0;
            $target = self::target_by_key($targets, $page_key);
            $slot = $page_key . ':' . $request_number;
            if (!$target || $request_number < 1 || $request_number > self::REQUESTS_PER_URL || isset($seen[$slot])
                || empty($raw['url']) || untrailingslashit($target['url']) !== untrailingslashit(esc_url_raw($raw['url']))) {
                return new WP_Error('sspa_cache_delivery_browser_result', __('The browser returned a result outside the fixed check targets.', 'super-speedy-performance-analysis'));
            }
            $seen[$slot] = true;
            $headers = self::safe_headers(isset($raw['headers']) && is_array($raw['headers']) ? $raw['headers'] : array());
            $markers = array_values(array_intersect(array_keys(self::marker_patterns()), array_map('sanitize_key', (array) ($raw['markers'] ?? array()))));
            $sha = isset($raw['body_sha256']) && preg_match('/^[a-f0-9]{64}$/', (string) $raw['body_sha256']) ? strtolower($raw['body_sha256']) : null;
            $features = isset($raw['features']) && is_array($raw['features']) ? $raw['features'] : array();
            $entry = array(
                'transport' => 'browser',
                'visitor_state' => 'anonymous_browser',
                'audience' => 'anonymous',
                'page_key' => $page_key,
                'request_number' => $request_number,
                'url' => $target['url'],
                'http_status' => max(0, min(599, (int) ($raw['http_status'] ?? 0))),
                'ttfb_ms' => isset($raw['ttfb_ms']) && is_numeric($raw['ttfb_ms']) ? max(0, round((float) $raw['ttfb_ms'], 1)) : null,
                'total_ms' => isset($raw['total_ms']) && is_numeric($raw['total_ms']) ? max(0, round((float) $raw['total_ms'], 1)) : null,
                'response_bytes' => max(0, (int) ($raw['response_bytes'] ?? 0)),
                'transfer_bytes' => max(0, (int) ($raw['transfer_bytes'] ?? 0)),
                'delivery_source' => in_array(($raw['delivery_source'] ?? ''), array('network', 'browser_http_cache', 'unknown'), true) ? $raw['delivery_source'] : 'unknown',
                // The browser hashes the body after removing the cache plugins' own stamp
                // comments, so a stored copy matches the response that created it.
                'body_sha256' => $sha,
                'headers' => $headers,
                'markers' => $markers,
                'features' => array(
                    'product_loop' => '1' === (string) ($features['product_loop'] ?? ''),
                    'product_options' => '1' === (string) ($features['product_options'] ?? ''),
                    'product_personalisation' => '1' === (string) ($features['product_personalisation'] ?? ''),
                    'express_payment' => '1' === (string) ($features['express_payment'] ?? ''),
                ),
                'error_class' => sanitize_key(isset($raw['error']) ? $raw['error'] : ''),
            );
            $entry['cache_layers'] = self::classify_layers($headers, $markers, 'browser', $request_number);
            if ('browser_http_cache' === $entry['delivery_source']) {
                array_unshift($entry['cache_layers'], array(
                    'layer' => 'browser_http_cache',
                    'position' => 'browser',
                    'evidence_source' => 'resource_timing',
                    'evidence_name' => 'transferSize',
                    'status' => 'HIT',
                    'confidence' => 'served_response',
                    'transport' => 'browser',
                    'request_number' => $request_number,
                ));
            }
            $normalised[] = $entry;
        }
        usort($normalised, function ($a, $b) {
            return strcmp($a['page_key'], $b['page_key']) ?: ($a['request_number'] <=> $b['request_number']);
        });
        return $normalised;
    }

    private static function representative_pages($targets, $browser) {
        $pages = array();
        foreach ($targets as $target) {
            $observed = array_values(array_filter($browser, function ($row) use ($target) { return $row['page_key'] === $target['page_key']; }));
            $features = array(
                'product_loop' => false,
                'product_options' => false,
                'product_personalisation' => false,
                'express_payment' => false,
            );
            foreach ($observed as $row) {
                foreach ($features as $feature => $value) {
                    $features[$feature] = $features[$feature] || !empty($row['features'][$feature]);
                }
            }
            $target['observed_browser_features'] = $features;
            $pages[] = $target;
        }
        return $pages;
    }

    /** Cache response headers worth keeping. Every other header is discarded. */
    public static function header_allowlist() {
        return array(
            'age', 'cache-control', 'vary', 'via', 'cf-cache-status', 'x-cache',
            'x-cache-status', 'x-breeze-cache', 'x-breeze-cache-write', 'x-litespeed-cache',
            'x-runcloud-cache', 'x-fastcgi-cache', 'x-proxy-cache', 'x-srcache-fetch-status',
            'x-varnish', 'x-vercel-cache', 'x-qc-cache', 'cf-ray', 'x-cache-handler',
        );
    }

    private static function safe_headers($headers) {
        if (is_object($headers) && method_exists($headers, 'getAll')) {
            $headers = $headers->getAll();
        }
        $allowed = self::header_allowlist();
        $safe = array();
        foreach ((array) $headers as $name => $value) {
            $name = strtolower(sanitize_key(str_replace('_', '-', (string) $name)));
            if (!in_array($name, $allowed, true)) {
                continue;
            }
            if ('cf-ray' === $name) {
                $safe['cf-ray-present'] = true;
                continue;
            }
            $value = is_array($value) ? implode(', ', $value) : (string) $value;
            $safe[$name] = substr(preg_replace('/[\r\n]+/', ' ', sanitize_text_field($value)), 0, 300);
        }
        return $safe;
    }

    private static function classify_layers($headers, $markers, $transport, $request_number) {
        $map = array(
            'cf-cache-status' => array('cloudflare', 'cdn_edge'),
            'x-breeze-cache' => array('breeze', 'wordpress_php'),
            'x-breeze-cache-write' => array('breeze', 'wordpress_php'),
            'x-litespeed-cache' => array('litespeed', 'reverse_proxy_server'),
            'x-runcloud-cache' => array('runcloud_nginx', 'reverse_proxy_server'),
            'x-fastcgi-cache' => array('fastcgi_cache', 'reverse_proxy_server'),
            'x-cache-status' => array('server_cache', 'reverse_proxy_server'),
            'x-proxy-cache' => array('proxy_cache', 'reverse_proxy_server'),
            'x-srcache-fetch-status' => array('srcache', 'reverse_proxy_server'),
            'x-vercel-cache' => array('vercel', 'cdn_edge'),
            'x-qc-cache' => array('quic_cloud', 'cdn_edge'),
            'x-cache' => array('generic_proxy_cache', 'unknown'),
            'x-varnish' => array('varnish', 'reverse_proxy_server'),
        );
        $layers = array();
        foreach ($map as $header => $definition) {
            if (!isset($headers[$header])) {
                continue;
            }
            $status = self::normalise_status($headers[$header], 'x-breeze-cache-write' === $header);
            $layers[] = array(
                'layer' => $definition[0],
                'position' => $definition[1],
                'evidence_source' => 'response_header',
                'evidence_name' => $header,
                'status' => $status,
                'raw_value' => $headers[$header],
                'confidence' => 'served_response',
                'transport' => $transport,
                'request_number' => $request_number,
            );
        }
        // Cache Enabler sends this header only when its advanced-cache.php drop-in answers
        // from a stored page, so its presence is a served-from-cache response. Confirmed
        // against a real Cache Enabler response on a test site, not inferred.
        if (isset($headers['x-cache-handler']) && 'cache-enabler-engine' === strtolower(trim($headers['x-cache-handler']))) {
            $layers[] = array(
                'layer' => 'cache_enabler',
                'position' => 'wordpress_php',
                'evidence_source' => 'response_header',
                'evidence_name' => 'x-cache-handler',
                'status' => 'HIT',
                'raw_value' => $headers['x-cache-handler'],
                'confidence' => 'served_response',
                'transport' => $transport,
                'request_number' => $request_number,
            );
        }
        if (isset($headers['age']) && is_numeric($headers['age']) && (int) $headers['age'] > 0) {
            $layers[] = array(
                'layer' => 'unidentified_shared_cache',
                'position' => 'unknown',
                'evidence_source' => 'response_header',
                'evidence_name' => 'age',
                'status' => 'HIT',
                'raw_value' => (string) (int) $headers['age'],
                'confidence' => 'served_response',
                'transport' => $transport,
                'request_number' => $request_number,
            );
        }
        foreach ((array) $markers as $marker) {
            $layers[] = array(
                'layer' => sanitize_key($marker),
                'position' => 'wordpress_php',
                'evidence_source' => 'stored_body_marker',
                'evidence_name' => sanitize_key($marker),
                'status' => 'UNKNOWN',
                'confidence' => 'stored_body_marker',
                'transport' => $transport,
                'request_number' => $request_number,
            );
        }
        return $layers;
    }

    private static function normalise_status($value, $write_header = false) {
        if ($write_header && !in_array(strtolower(trim((string) $value)), array('', '0', 'false', 'no', 'off'), true)) {
            return 'WRITE';
        }
        $value = strtoupper((string) $value);
        foreach (array('BYPASS', 'EXPIRED', 'STALE', 'UPDATING', 'REVALIDATED', 'MISS', 'HIT') as $status) {
            if (false !== strpos($value, $status)) {
                return $status;
            }
        }
        return 'UNKNOWN';
    }

    /** HTML comments page-cache plugins stamp into a stored page. */
    private static function marker_patterns() {
        return array(
            'breeze' => '/<!--[^>]*\bBreeze\b[^>]*-->/i',
            'wp_rocket' => '/<!--[^>]*\bWP Rocket\b[^>]*-->/i',
            'wp_optimize' => '/<!--[^>]*\bWP-Optimize\b[^>]*-->/i',
            'w3_total_cache' => '/<!--[^>]*\bW3 Total Cache\b[^>]*-->/i',
            'cache_enabler' => '/<!--[^>]*\bCache Enabler\b[^>]*-->/i',
        );
    }

    private static function body_markers($body) {
        $found = array();
        foreach (self::marker_patterns() as $name => $pattern) {
            if (preg_match($pattern, (string) $body)) {
                $found[] = $name;
            }
        }
        return $found;
    }

    private static function normalise_body($body) {
        return preg_replace(array_values(self::marker_patterns()), '', (string) $body);
    }

    private static function infrastructure_cookie($name) {
        return (bool) preg_match('/^(?:__cf_bm|_cfuvid|cf_clearance|__cflb|__cfwaitingroom|cf_ob_info|cf_use_ob|cf_chl_[a-z0-9_]+|ak_bmsc|bm_sz|bm_sv|_abck)$/i', (string) $name);
    }

    /** Statuses from served-response layers only. Body markers and browser reads do not count. */
    private static function served_statuses($rows) {
        $statuses = array();
        foreach ($rows as $row) {
            // A stored browser response can retain the CDN or server headers from the request
            // which originally filled the browser cache. Those headers do not prove that the
            // named upstream layer served this request.
            if ('browser_http_cache' === ($row['delivery_source'] ?? '')) {
                continue;
            }
            foreach ((array) $row['cache_layers'] as $layer) {
                if ('stored_body_marker' !== $layer['confidence'] && 'browser' !== $layer['position']) {
                    $statuses[] = $layer['status'];
                }
            }
        }
        return $statuses;
    }

    private static function failed_rows($rows) {
        return array_filter($rows, function ($row) { return !empty($row['error_class']) || 200 !== (int) $row['http_status']; });
    }

    private static function status_verdict($statuses) {
        if (in_array('MISS', $statuses, true) && in_array('HIT', $statuses, true)) {
            return 'cache_write_then_hit_confirmed';
        }
        if (in_array('HIT', $statuses, true)) {
            return 'cache_hit_confirmed';
        }
        if (count(array_filter($statuses, function ($status) { return 'BYPASS' === $status; })) >= 2) {
            return 'bypass_confirmed';
        }
        return $statuses ? 'reuse_not_observed' : '';
    }

    /** The anonymous browser leg, one verdict per page. */
    private static function page_verdicts($targets, $browser) {
        $verdicts = array();
        foreach ($targets as $target) {
            $rows = array_values(array_filter($browser, function ($row) use ($target) { return $row['page_key'] === $target['page_key']; }));
            $hash_match = count($rows) === 2 && !empty($rows[0]['body_sha256']) && hash_equals($rows[0]['body_sha256'], (string) $rows[1]['body_sha256']);
            if (count($rows) !== 2 || self::failed_rows($rows) || !$hash_match) {
                $verdict = 'probe_inconclusive';
            } else {
                $verdict = self::status_verdict(self::served_statuses($rows)) ?: 'cache_layer_unidentified';
            }
            $verdicts[] = array(
                'page_key' => $target['page_key'],
                'url' => $target['url'],
                'verdict' => $verdict,
                'browser_body_match' => $hash_match,
                'browser_requests' => count($rows),
            );
        }
        return $verdicts;
    }

    /**
     * One verdict per audience and page from the two server requests of that audience.
     *
     * A page cache that never names itself for a private audience still names itself for the
     * anonymous one, so "the layer that served the anonymous visitor gave this audience no
     * cached response" is recorded as reuse not observed rather than as an unknown layer.
     */
    private static function audience_verdicts($targets, $server, $cache_system) {
        $out = array();
        $anonymous_cached = array();
        $any_layer = false;
        foreach ($server as $row) {
            if (self::served_statuses(array($row))) {
                $any_layer = true;
            }
        }
        $page_cache_software = !empty($cache_system['page_cache_plugins']) || !empty($cache_system['advanced_cache_dropin']);
        foreach (self::AUDIENCES as $audience) {
            $out[$audience] = array();
            foreach ($targets as $target) {
                $rows = array_values(array_filter($server, function ($row) use ($target, $audience) {
                    return $row['page_key'] === $target['page_key'] && $row['audience'] === $audience;
                }));
                $hash_match = count($rows) === 2 && !empty($rows[0]['body']['normalised_sha256'])
                    && hash_equals((string) $rows[0]['body']['normalised_sha256'], (string) $rows[1]['body']['normalised_sha256']);
                $reason = '';
                if (count($rows) !== 2 || self::failed_rows($rows)) {
                    $verdict = 'probe_inconclusive';
                    foreach ($rows as $row) {
                        if (!empty($row['error_class'])) {
                            $reason = $row['error_class'];
                            break;
                        }
                    }
                    $reason = $reason ?: 'http_status_not_200';
                } else {
                    $verdict = self::status_verdict(self::served_statuses($rows));
                    if (in_array($verdict, array('cache_write_then_hit_confirmed', 'cache_hit_confirmed'), true) && !$hash_match) {
                        $verdict = 'probe_inconclusive';
                        $reason = 'cached_bodies_differ';
                    } elseif ('' === $verdict) {
                        if ('anonymous' !== $audience && !empty($anonymous_cached[$target['page_key']])) {
                            $verdict = 'reuse_not_observed';
                            $reason = 'anonymous_cache_layer_not_used_for_this_audience';
                        } elseif (!$any_layer && !$page_cache_software) {
                            $verdict = 'no_page_cache_observed';
                        } else {
                            $verdict = 'cache_layer_unidentified';
                        }
                    }
                }
                if ('anonymous' === $audience) {
                    $anonymous_cached[$target['page_key']] = in_array($verdict, array('cache_write_then_hit_confirmed', 'cache_hit_confirmed'), true);
                }
                $ttfb = array_values(array_filter(array_column($rows, 'ttfb_ms'), 'is_numeric'));
                $total = array_values(array_filter(array_column($rows, 'total_ms'), 'is_numeric'));
                $out[$audience][] = array(
                    'page_key' => $target['page_key'],
                    'url' => $target['url'],
                    'verdict' => $verdict,
                    'cache_state' => self::cache_state($verdict),
                    'reason' => $reason,
                    'body_match' => $hash_match,
                    'layer' => self::served_layer($rows),
                    'server_ttfb_ms' => self::median($ttfb),
                    'server_total_ms' => self::median($total),
                    'logged_in_markers_seen' => (bool) array_filter($rows, function ($row) { return !empty($row['private_markers']['logged_in_body_class']); }),
                );
            }
        }
        return $out;
    }

    private static function served_layer($rows) {
        foreach ($rows as $row) {
            foreach ((array) $row['cache_layers'] as $layer) {
                if ('served_response' === $layer['confidence'] && 'HIT' === $layer['status']) {
                    return $layer['layer'];
                }
            }
        }
        foreach ($rows as $row) {
            foreach ((array) $row['cache_layers'] as $layer) {
                if ('served_response' === $layer['confidence']) {
                    return $layer['layer'];
                }
            }
        }
        return '';
    }

    public static function page_label($page_key) {
        $labels = array(
            'home' => __('Home', 'super-speedy-performance-analysis'),
            'shop' => __('Shop', 'super-speedy-performance-analysis'),
            'product_category' => __('Product category', 'super-speedy-performance-analysis'),
            'product' => __('Product', 'super-speedy-performance-analysis'),
        );
        return isset($labels[$page_key]) ? $labels[$page_key] : $page_key;
    }

    /** cached / not_cached / unknown, the one word the tab shows per page and audience. */
    public static function cache_state($verdict) {
        if (in_array($verdict, array('cache_write_then_hit_confirmed', 'cache_hit_confirmed'), true)) {
            return 'cached';
        }
        if (in_array($verdict, array('bypass_confirmed', 'reuse_not_observed', 'no_page_cache_observed'), true)) {
            return 'not_cached';
        }
        return 'unknown';
    }

    private static function audience_summary($targets, $audience_verdicts, $server, $origin_profiles) {
        $summary = array();
        foreach (self::AUDIENCES as $audience) {
            $rows = $audience_verdicts[$audience];
            $states = array_count_values(array_column($rows, 'cache_state'));
            $cached = (int) ($states['cached'] ?? 0);
            $not_cached = (int) ($states['not_cached'] ?? 0);
            $count = count($rows);
            if ($count && $cached === $count) {
                $headline = 'cached';
            } elseif ($cached > 0) {
                $headline = 'partly_cached';
            } elseif ($not_cached > 0) {
                $headline = 'not_cached';
            } else {
                $headline = 'unknown';
            }
            $summary[$audience] = array(
                'headline' => $headline,
                'pages' => $count,
                'cached_pages' => $cached,
                'not_cached_pages' => $not_cached,
                'unknown_pages' => $count - $cached - $not_cached,
                'median_server_ttfb_ms' => self::median(array_column($rows, 'server_ttfb_ms')),
                'boundary' => 'server_loopback',
            );
        }
        return $summary;
    }

    /**
     * The final anonymous request per page, compared with the anonymous requests before the
     * private ones. A logged-in body class or the test account's name in an anonymous
     * response is direct evidence. A body that was stable for two anonymous requests and
     * changed after the private requests is indirect evidence. A page whose anonymous body
     * differs on every request cannot be compared, so only the markers apply to it.
     */
    private static function leak_check($targets, $server) {
        $pages = array();
        $status = 'no_leak_observed';
        foreach ($targets as $target) {
            $find = function ($audience, $number) use ($server, $target) {
                foreach ($server as $row) {
                    if ($row['page_key'] === $target['page_key'] && $row['audience'] === $audience && (int) $row['request_number'] === $number) {
                        return $row;
                    }
                }
                return null;
            };
            $first = $find('anonymous', 1);
            $second = $find('anonymous', 2);
            $final = $find('final_anonymous', 1);
            $page = array('page_key' => $target['page_key'], 'url' => $target['url'], 'result' => 'no_leak_observed', 'evidence' => array());
            if (!$final || self::failed_rows(array($final))) {
                $page['result'] = 'inconclusive';
            } else {
                if (!empty($final['private_markers']['test_account_name'])) {
                    $page['evidence'][] = 'test_account_name_in_anonymous_response';
                }
                if (!empty($final['private_markers']['logged_in_body_class'])) {
                    $page['evidence'][] = 'logged_in_body_class_in_anonymous_response';
                }
                $stable = $first && $second && !self::failed_rows(array($first, $second))
                    && !empty($first['body']['normalised_sha256'])
                    && hash_equals((string) $first['body']['normalised_sha256'], (string) $second['body']['normalised_sha256']);
                if ($page['evidence']) {
                    $page['result'] = 'logged_in_content_found';
                } elseif ($stable && !hash_equals((string) $second['body']['normalised_sha256'], (string) $final['body']['normalised_sha256'])) {
                    $page['result'] = 'anonymous_page_changed';
                    $page['evidence'][] = 'stable_anonymous_body_changed_after_private_requests';
                } elseif (!$stable) {
                    $page['result'] = 'markers_only';
                }
            }
            $pages[] = $page;
        }
        $results = array_column($pages, 'result');
        if (in_array('logged_in_content_found', $results, true)) {
            $status = 'logged_in_content_found';
        } elseif (in_array('anonymous_page_changed', $results, true)) {
            $status = 'anonymous_page_changed';
        } elseif (in_array('inconclusive', $results, true)) {
            $status = 'inconclusive';
        }
        return array('status' => $status, 'pages' => $pages);
    }

    private static function overall_status($values) {
        if (!$values || in_array('probe_inconclusive', $values, true)) {
            return 'inconclusive';
        }
        $cached = array_intersect($values, array('cache_hit_confirmed', 'cache_write_then_hit_confirmed'));
        if ($cached) {
            return count($cached) === count($values) ? 'confirmed' : 'partially_confirmed';
        }
        if (count(array_unique($values)) === 1 && 'bypass_confirmed' === $values[0]) {
            return 'bypassed';
        }
        if (count(array_unique($values)) === 1 && 'no_page_cache_observed' === $values[0]) {
            return 'no_page_cache_observed';
        }
        return 'not_observed';
    }

    private static function status_label($status) {
        $labels = array(
            'confirmed' => __('Page caching confirmed for anonymous visitors on every checked page', 'super-speedy-performance-analysis'),
            'partially_confirmed' => __('Page caching confirmed for anonymous visitors on some checked pages', 'super-speedy-performance-analysis'),
            'bypassed' => __('The checked anonymous requests were bypassed by the observed cache layer', 'super-speedy-performance-analysis'),
            'no_page_cache_observed' => __('No page cache was observed on the checked pages', 'super-speedy-performance-analysis'),
            'not_observed' => __('Page-cache reuse was not observed', 'super-speedy-performance-analysis'),
            'inconclusive' => __('The cache performance check is inconclusive', 'super-speedy-performance-analysis'),
        );
        return isset($labels[$status]) ? $labels[$status] : $labels['inconclusive'];
    }

    private static function all_layers($browser, $server) {
        $layers = array();
        foreach (array_merge($browser, $server) as $row) {
            foreach ((array) ($row['cache_layers'] ?? array()) as $layer) {
                $layer['page_key'] = $row['page_key'];
                $layer['audience'] = $row['audience'] ?? 'anonymous';
                $layers[] = $layer;
            }
        }
        foreach (SSPA_Cache_System::active_page_cache_plugins() as $plugin) {
            $layers[] = array(
                'layer' => str_replace('-', '_', $plugin['slug']),
                'position' => 'wordpress_php',
                'evidence_source' => 'active_plugin',
                'evidence_name' => $plugin['slug'],
                'status' => 'UNKNOWN',
                'confidence' => 'configured_candidate',
                'transport' => 'inventory',
                'request_number' => null,
                'page_key' => null,
                'audience' => null,
            );
        }
        return $layers;
    }

    private static function route_failures($browser, $server) {
        $failures = array();
        foreach (array_merge($browser, $server) as $row) {
            if (!empty($row['error_class']) || (int) $row['http_status'] < 200 || (int) $row['http_status'] >= 400) {
                $failures[] = array(
                    'page_key' => $row['page_key'],
                    'url' => $row['url'],
                    'transport' => $row['transport'],
                    'audience' => $row['audience'] ?? 'anonymous',
                    'request_number' => (int) $row['request_number'],
                    'http_status' => (int) $row['http_status'],
                    'error_class' => isset($row['error_class']) ? $row['error_class'] : '',
                );
            }
        }
        return $failures;
    }

    /**
     * Profiled origin generation from the analysis run current when the check ran. Anonymous
     * for every page; logged-in customer generation where the run measured it (shop and
     * product).
     */
    private static function origin_profiles($run_id, $targets) {
        if (!$run_id) {
            return array();
        }
        $report = SSPA_Report::build($run_id);
        if (is_wp_error($report)) {
            return array();
        }
        $customer_keys = array('shop' => 'customer-shop', 'product-single' => 'customer-product');
        $origin = array();
        foreach ($targets as $target) {
            $anonymous = array();
            $customer = array();
            foreach ((array) $report['pages'] as $page) {
                if ('anon' === $page['variant'] && $page['page_key'] === $target['origin_page_key'] && null !== $page['generation_ms']) {
                    $anonymous[] = $page['generation_ms'];
                }
                if ('customer' === $page['variant'] && isset($customer_keys[$target['origin_page_key']]) && $page['page_key'] === $customer_keys[$target['origin_page_key']] && null !== $page['generation_ms']) {
                    $customer[] = $page['generation_ms'];
                }
            }
            $origin[] = array(
                'page_key' => $target['page_key'],
                'url' => $target['url'],
                'boundary' => 'origin_generation',
                'generation_ms' => self::median($anonymous),
                'customer_generation_ms' => self::median($customer),
            );
        }
        return $origin;
    }

    private static function opportunity($targets, $origin, $browser) {
        $out = array();
        foreach ($targets as $target) {
            $origin_row = current(array_filter($origin, function ($row) use ($target) { return $row['page_key'] === $target['page_key']; }));
            $browser_rows = array_values(array_filter($browser, function ($row) use ($target) { return $row['page_key'] === $target['page_key'] && null !== $row['ttfb_ms']; }));
            $network_values = array_column(array_filter($browser_rows, function ($row) { return 'network' === $row['delivery_source']; }), 'ttfb_ms');
            $browser_cache_values = array_column(array_filter($browser_rows, function ($row) { return 'browser_http_cache' === $row['delivery_source']; }), 'ttfb_ms');
            $out[] = array(
                'page_key' => $target['page_key'],
                'url' => $target['url'],
                'origin_generation_ms' => $origin_row ? $origin_row['generation_ms'] : null,
                'anonymous_browser_ttfb_ms' => self::median($network_values),
                'anonymous_browser_http_cache_ttfb_ms' => self::median($browser_cache_values),
                'comparison' => 'side_by_side_distinct_boundaries',
            );
        }
        return $out;
    }

    private static function median($values) {
        $values = array_values(array_filter((array) $values, 'is_numeric'));
        if (!$values) {
            return null;
        }
        sort($values, SORT_NUMERIC);
        $count = count($values);
        $middle = (int) floor($count / 2);
        return round($count % 2 ? (float) $values[$middle] : ((float) $values[$middle - 1] + (float) $values[$middle]) / 2, 1);
    }

    private static function software_inventory() {
        if (!function_exists('get_plugins')) {
            require_once SSPA_CORE_DIR . 'wp-admin/includes/plugin.php';
        }
        $plugins = get_plugins();
        $active_files = (array) get_option('active_plugins', array());
        if (is_multisite()) {
            $active_files = array_merge($active_files, array_keys((array) get_site_option('active_sitewide_plugins', array())));
        }
        $active = array();
        foreach (array_unique($active_files) as $file) {
            if (!isset($plugins[$file])) {
                continue;
            }
            $active[] = array(
                'slug' => sanitize_key('.' === dirname($file) ? basename($file, '.php') : dirname($file)),
                'version' => sanitize_text_field($plugins[$file]['Version']),
            );
        }
        $theme = wp_get_theme();
        $mu = function_exists('get_mu_plugins') ? get_mu_plugins() : array();
        $mu_plugins = array();
        foreach ($mu as $data) {
            $mu_plugins[] = array('title' => sanitize_text_field($data['Name']), 'version' => sanitize_text_field($data['Version']));
        }
        $dropins = array();
        if (function_exists('get_dropins')) {
            foreach ((array) get_dropins() as $file => $data) {
                if (!in_array($file, array('object-cache.php', 'advanced-cache.php'), true)) {
                    continue;
                }
                $dropins[$file] = array(
                    'title' => sanitize_text_field(isset($data['Name']) ? $data['Name'] : ''),
                    'version' => sanitize_text_field(isset($data['Version']) ? $data['Version'] : ''),
                );
            }
        }
        return array(
            'wordpress' => get_bloginfo('version'),
            'php' => PHP_VERSION,
            'environment_type' => function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production',
            'theme' => array(
                'slug' => sanitize_key($theme->get_stylesheet()),
                'version' => sanitize_text_field($theme->get('Version')),
                'parent_slug' => $theme->parent() ? sanitize_key($theme->parent()->get_stylesheet()) : null,
            ),
            'active_plugins' => $active,
            'mu_plugins' => $mu_plugins,
            'dropins' => $dropins,
            'code_snippets' => self::code_snippets_inventory($active_files),
        );
    }

    /**
     * Code Snippets stores PHP in the database, where the source scan cannot see it. The count
     * of snippets and their scopes tells a reviewer that unscanned code exists. This calls the
     * plugin's own public read function only on the administrator's click, keeps counts and
     * scope names, and never reads or stores snippet names or code.
     */
    private static function code_snippets_inventory($active_files) {
        $in_use = (bool) array_filter($active_files, function ($file) { return false !== strpos(strtolower($file), 'code-snippets'); });
        $inventory = array(
            'in_use' => $in_use,
            'adapter_available' => false,
            'total_count' => null,
            'active_count' => null,
            'scopes' => array(),
            'code_reviewed' => false,
            'not_reviewed_reason' => $in_use ? 'database_stored_code_not_read' : 'code_snippets_not_active',
        );
        $getter = is_callable('Code_Snippets\\get_snippets') ? 'Code_Snippets\\get_snippets' : null;
        if (!$in_use || !$getter) {
            return $inventory;
        }
        try {
            $snippets = call_user_func($getter);
            if (!is_array($snippets) && !($snippets instanceof Traversable)) {
                return $inventory;
            }
            $total = 0;
            $active = 0;
            $scopes = array();
            foreach ($snippets as $snippet) {
                $total++;
                $is_active = is_object($snippet) && method_exists($snippet, 'is_active') ? $snippet->is_active() : (!empty($snippet->active));
                $active += $is_active ? 1 : 0;
                $scope = is_object($snippet) && method_exists($snippet, 'get_scope') ? $snippet->get_scope() : (is_object($snippet) && isset($snippet->scope) ? $snippet->scope : '');
                if ($scope) {
                    $scopes[sanitize_key($scope)] = true;
                }
            }
            $inventory['adapter_available'] = true;
            $inventory['total_count'] = $total;
            $inventory['active_count'] = $active;
            $inventory['scopes'] = array_keys($scopes);
        } catch (Throwable $e) {
            $inventory['not_reviewed_reason'] = 'code_snippets_adapter_failed';
        }
        return $inventory;
    }

    private static function scale_inventory() {
        $counts = array();
        $product_counts = wp_count_posts('product');
        $variation_counts = wp_count_posts('product_variation');
        $attachment_counts = wp_count_posts('attachment');
        $counts['product'] = $product_counts && isset($product_counts->publish) ? (int) $product_counts->publish : 0;
        $counts['product_variation'] = $variation_counts
            ? (int) ($variation_counts->publish ?? 0) + (int) ($variation_counts->private ?? 0)
            : 0;
        $counts['attachment'] = $attachment_counts && isset($attachment_counts->inherit) ? (int) $attachment_counts->inherit : 0;
        $counts['product_categories'] = taxonomy_exists('product_cat') ? (int) wp_count_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false)) : 0;
        $attribute_count = 0;
        if (function_exists('wc_get_attribute_taxonomies')) {
            $attribute_count = count((array) wc_get_attribute_taxonomies());
        }
        $counts['product_attributes'] = $attribute_count;
        $counts['orders'] = null;
        if (function_exists('wc_get_orders')) {
            $orders = wc_get_orders(array('limit' => 1, 'paginate' => true, 'return' => 'ids', 'status' => array_keys(wc_get_order_statuses())));
            if (is_object($orders) && isset($orders->total)) {
                $counts['orders'] = (int) $orders->total;
            }
        }
        $user_counts = count_users();
        $counts['registered_customers'] = isset($user_counts['avail_roles']['customer']) ? (int) $user_counts['avail_roles']['customer'] : 0;
        return $counts;
    }
}
