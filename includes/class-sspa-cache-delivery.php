<?php
defined('ABSPATH') || exit;

/**
 * Bounded, administrator-triggered page-cache delivery qualification.
 *
 * The browser and server transports deliberately remain separate. Browser Resource Timing
 * measures the anonymous visitor path. WordPress HTTP API requests collect supporting server
 * loopback evidence. Neither is treated as origin generation or subtracted from it.
 */
class SSPA_Cache_Delivery {

    const SCHEMA = 'sspa/cache-optimisation-analysis@3';
    const PENDING_OPTION = 'sspa_cache_delivery_pending';
    const REPORT_OPTION = 'sspa_cache_delivery_report';
    const MAX_BODY_BYTES = 5242880;
    const REQUESTS_PER_URL = 2;

    private static function guard() {
        check_ajax_referer('sspa_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You are not allowed to run this assessment.', 'super-speedy-performance-analysis')), 403);
        }
    }

    public static function ajax_prepare() {
        self::guard();
        $run_id = isset($_POST['run_id']) ? (int) $_POST['run_id'] : 0;
        $prepared = self::prepare($run_id);
        if (is_wp_error($prepared)) {
            wp_send_json_error(array('message' => $prepared->get_error_message(), 'code' => $prepared->get_error_code()));
        }
        wp_send_json_success($prepared);
    }

    public static function ajax_server_probe() {
        self::guard();
        $assessment_id = isset($_POST['assessment_id']) ? sanitize_text_field(wp_unslash($_POST['assessment_id'])) : '';
        $page_key = isset($_POST['page_key']) ? sanitize_key(wp_unslash($_POST['page_key'])) : '';
        $request_number = isset($_POST['request_number']) ? (int) $_POST['request_number'] : 0;
        $result = self::probe_server_request($assessment_id, $page_key, $request_number);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message(), 'code' => $result->get_error_code()));
        }
        wp_send_json_success($result);
    }

    public static function ajax_complete() {
        self::guard();
        $assessment_id = isset($_POST['assessment_id']) ? sanitize_text_field(wp_unslash($_POST['assessment_id'])) : '';
        $raw = isset($_POST['browser_results']) ? wp_unslash($_POST['browser_results']) : '[]';
        $browser_results = json_decode((string) $raw, true);
        $report = self::complete($assessment_id, is_array($browser_results) ? $browser_results : array());
        if (is_wp_error($report)) {
            wp_send_json_error(array('message' => $report->get_error_message(), 'code' => $report->get_error_code()));
        }
        wp_send_json_success(array(
            'schema' => $report['schema'],
            'page_cache_status' => $report['page_cache_status'],
            'evidence_sufficiency' => $report['evidence_sufficiency'],
        ));
    }

    /** Fixed, generated public targets. There is no arbitrary URL input. */
    public static function targets() {
        $catalogue = SSPA_Catalogue::build(array('home', 'shop', 'product-cat', 'product-single'));
        $by_key = array();
        foreach ($catalogue as $job) {
            $by_key[$job['page_key']] = $job['url'];
        }

        $category = null;
        if (taxonomy_exists('product_cat')) {
            $terms = get_terms(array(
                'taxonomy' => 'product_cat',
                'hide_empty' => true,
                'orderby' => 'count',
                'order' => 'DESC',
                'number' => 1,
            ));
            if (!is_wp_error($terms) && $terms) {
                $category = $terms[0];
            }
        }

        $product = null;
        if (function_exists('wc_get_products')) {
            $products = wc_get_products(array(
                'status' => 'publish',
                'limit' => 1,
                'orderby' => 'date',
                'order' => 'DESC',
            ));
            if ($products) {
                $product = $products[0];
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
            if ('product_category' === $page_key && $category) {
                $category_query = new WP_Query(array(
                    'post_type' => 'product',
                    'post_status' => 'publish',
                    'posts_per_page' => 1,
                    'fields' => 'ids',
                    'no_found_rows' => false,
                    'tax_query' => array(array(
                        'taxonomy' => 'product_cat',
                        'field' => 'term_id',
                        'terms' => array((int) $category->term_id),
                        'include_children' => true,
                    )),
                ));
                $target['facts'] = array(
                    'direct_product_count' => (int) $category->count,
                    'descendant_product_count' => (int) $category_query->found_posts,
                );
                wp_reset_postdata();
            }
            if ('product' === $page_key && $product) {
                $target['facts'] = array(
                    'product_type' => sanitize_key($product->get_type()),
                    'in_stock' => (bool) $product->is_in_stock(),
                    'purchasable' => (bool) $product->is_purchasable(),
                    'has_purchasable_variations' => $product->is_type('variable') && (bool) $product->get_available_variations('objects'),
                );
            }
            $targets[] = $target;
        }
        return $targets;
    }

    public static function prepare($run_id) {
        $run = SSPA_Run_Controller::run_row((int) $run_id);
        if (!$run || 'done' !== $run['status'] || !in_array($run['run_type'], array('baseline', 'spot'), true)) {
            return new WP_Error('sspa_cache_delivery_run', __('Choose a completed site analysis before checking page-cache delivery.', 'super-speedy-performance-analysis'));
        }
        $targets = self::targets();
        if (4 !== count($targets)) {
            return new WP_Error('sspa_cache_delivery_targets', __('The assessment needs a home page, shop, populated product category and published product.', 'super-speedy-performance-analysis'));
        }
        $assessment_id = wp_generate_uuid4();
        $pending = array(
            'assessment_id' => $assessment_id,
            'run_id' => (int) $run_id,
            'created_at' => time(),
            'targets' => $targets,
            'server_results' => array(),
        );
        update_option(self::PENDING_OPTION, $pending, false);
        return array(
            'assessment_id' => $assessment_id,
            'run_id' => (int) $run_id,
            'targets' => $targets,
            'browser_requests' => count($targets) * self::REQUESTS_PER_URL,
            'server_requests' => count($targets) * self::REQUESTS_PER_URL,
            'request_count' => count($targets) * self::REQUESTS_PER_URL * 2,
        );
    }

    public static function probe_server_request($assessment_id, $page_key, $request_number) {
        $pending = self::pending($assessment_id);
        if (is_wp_error($pending)) {
            return $pending;
        }
        if ($request_number < 1 || $request_number > self::REQUESTS_PER_URL) {
            return new WP_Error('sspa_cache_delivery_request', __('Invalid cache-delivery request number.', 'super-speedy-performance-analysis'));
        }
        $target = self::target_by_key($pending['targets'], $page_key);
        if (!$target) {
            return new WP_Error('sspa_cache_delivery_target', __('That page is not part of this fixed assessment.', 'super-speedy-performance-analysis'));
        }

        $key = $page_key . ':' . $request_number;
        if (isset($pending['server_results'][$key])) {
            return $pending['server_results'][$key];
        }
        if (2 === $request_number && !empty($pending['server_results'][$page_key . ':1']['application_cookie_names'])) {
            $result = array(
                'transport' => 'server_loopback',
                'page_key' => $page_key,
                'request_number' => 2,
                'url' => $target['url'],
                'http_status' => 0,
                'ttfb_ms' => null,
                'total_ms' => null,
                'error_class' => 'application_session_cookie_set',
                'error_message' => __('The first response set an application cookie, so this URL was not repeated with a contaminated client.', 'super-speedy-performance-analysis'),
                'redirects' => array(),
                'headers' => array(),
                'body' => array('bytes' => 0, 'sha256' => null, 'normalised_sha256' => null, 'truncated' => false),
                'cache_layers' => array(),
                'markers' => array(),
                'application_cookie_names' => array(),
            );
        } else {
            $result = self::server_get($target, $request_number);
        }
        $pending['server_results'][$key] = $result;
        update_option(self::PENDING_OPTION, $pending, false);
        return $result;
    }

    public static function complete($assessment_id, $browser_results) {
        $pending = self::pending($assessment_id);
        if (is_wp_error($pending)) {
            return $pending;
        }
        $expected = count($pending['targets']) * self::REQUESTS_PER_URL;
        if (count($pending['server_results']) !== $expected) {
            return new WP_Error('sspa_cache_delivery_server_incomplete', __('The server-side delivery checks have not finished.', 'super-speedy-performance-analysis'));
        }
        $browser = self::normalise_browser_results($browser_results, $pending['targets']);
        if (is_wp_error($browser)) {
            return $browser;
        }

        $cache_safety = SSPA_Report::cache_safety($pending['run_id']);
        if (is_wp_error($cache_safety)) {
            return $cache_safety;
        }
        $assessment = $cache_safety['assessment'];
        $origin_profiles = self::origin_profiles($pending['run_id'], $pending['targets']);
        $server = array_values($pending['server_results']);
        $verdicts = self::page_verdicts($pending['targets'], $browser, $server);
        $page_cache_status = self::overall_status($verdicts);
        $route_failures = self::route_failures($browser, $server);
        $inventory = self::software_inventory();

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
            'run_id' => (int) $pending['run_id'],
            'headline' => self::status_label($page_cache_status),
            'detail' => __('Browser visitor-path timing, server-loopback evidence and profiled origin generation are reported as separate measured boundaries.', 'super-speedy-performance-analysis'),
            // Compatibility block for version 2 readers.
            'assessment' => $assessment,
            'page_cache_status' => $page_cache_status,
            'origin_profiles' => $origin_profiles,
            'delivery_path_observations' => array(
                'browser' => array(
                    'visitor_state' => 'anonymous_browser',
                    'requests' => $browser,
                ),
                'server' => array(
                    'visitor_state' => 'anonymous_server_loopback',
                    'requests' => $server,
                ),
            ),
            'cache_layers' => self::all_layers($browser, $server),
            'representative_pages' => self::representative_pages($pending['targets'], $browser),
            'page_verdicts' => $verdicts,
            'visitor_specific_content_reconnaissance' => $assessment,
            'software_inventory' => $inventory,
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
                'authenticated_customer_not_measured',
                'basket_state_not_measured',
                'browser_server_and_origin_boundaries_are_not_interchangeable',
                'anonymous_cache_proof_does_not_approve_live_customer_caching',
            ),
        );
        update_option(self::REPORT_OPTION, $report, false);
        delete_option(self::PENDING_OPTION);
        return $report;
    }

    public static function report($run_id = 0) {
        $report = get_option(self::REPORT_OPTION, null);
        if (!is_array($report) || self::SCHEMA !== ($report['schema'] ?? '')) {
            return new WP_Error('sspa_no_cache_delivery', __('No completed page-cache delivery assessment was found.', 'super-speedy-performance-analysis'));
        }
        $run_id = $run_id ? (int) $run_id : SSPA_Report::latest_done_run_id();
        if (!$run_id || $run_id !== (int) $report['run_id']) {
            return new WP_Error('sspa_cache_delivery_run_mismatch', __('The saved page-cache delivery evidence belongs to a different analysis run.', 'super-speedy-performance-analysis'));
        }
        return $report;
    }

    private static function pending($assessment_id) {
        $pending = get_option(self::PENDING_OPTION, null);
        if (!is_array($pending) || empty($pending['assessment_id']) || !hash_equals((string) $pending['assessment_id'], (string) $assessment_id)) {
            return new WP_Error('sspa_cache_delivery_expired', __('This page-cache assessment is missing or has been replaced.', 'super-speedy-performance-analysis'));
        }
        if (time() - (int) $pending['created_at'] > HOUR_IN_SECONDS) {
            delete_option(self::PENDING_OPTION);
            return new WP_Error('sspa_cache_delivery_expired', __('This page-cache assessment expired. Start it again.', 'super-speedy-performance-analysis'));
        }
        return $pending;
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

    private static function server_get($target, $request_number) {
        $url = $target['url'];
        $redirects = array();
        $response = null;
        $started = microtime(true);
        for ($hop = 0; $hop < 4; $hop++) {
            $response = wp_remote_get($url, array(
                'timeout' => 30,
                'redirection' => 0,
                'reject_unsafe_urls' => true,
                'sslverify' => true,
                'limit_response_size' => self::MAX_BODY_BYTES,
                'user-agent' => 'Super Speedy Performance Analysis/' . SSPA_VERSION . ' cache-delivery-probe',
                'headers' => array('Accept' => 'text/html,application/xhtml+xml'),
            ));
            if (is_wp_error($response)) {
                return array(
                    'transport' => 'server_loopback',
                    'page_key' => $target['page_key'],
                    'request_number' => $request_number,
                    'url' => $target['url'],
                    'http_status' => 0,
                    'ttfb_ms' => null,
                    'total_ms' => round((microtime(true) - $started) * 1000, 1),
                    'error_class' => sanitize_key($response->get_error_code()),
                    'error_message' => substr(sanitize_text_field($response->get_error_message()), 0, 500),
                    'redirects' => $redirects,
                    'headers' => array(),
                    'body' => array('bytes' => 0, 'sha256' => null, 'normalised_sha256' => null, 'truncated' => false),
                    'cache_layers' => array(),
                    'markers' => array(),
                    'application_cookie_names' => array(),
                );
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
                $redirects[] = array('status' => $code, 'outcome' => 'refused_off_origin_or_private');
                return array(
                    'transport' => 'server_loopback',
                    'page_key' => $target['page_key'],
                    'request_number' => $request_number,
                    'url' => $target['url'],
                    'http_status' => $code,
                    'ttfb_ms' => null,
                    'total_ms' => round((microtime(true) - $started) * 1000, 1),
                    'error_class' => 'off_origin_or_private_redirect',
                    'error_message' => __('The canonical page redirected outside the allowed public site routes.', 'super-speedy-performance-analysis'),
                    'redirects' => $redirects,
                    'headers' => array(),
                    'body' => array('bytes' => 0, 'sha256' => null, 'normalised_sha256' => null, 'truncated' => false),
                    'cache_layers' => array(),
                    'markers' => array(),
                    'application_cookie_names' => array(),
                );
            }
            $redirects[] = array('status' => $code, 'url' => esc_url_raw($next));
            $url = $next;
        }

        $body = (string) wp_remote_retrieve_body($response);
        $headers = self::safe_headers(wp_remote_retrieve_headers($response));
        $markers = self::body_markers($body);
        $normalised = self::normalise_body($body);
        $cookie_names = array();
        foreach ((array) wp_remote_retrieve_cookies($response) as $cookie) {
            if (is_object($cookie) && !empty($cookie->name) && !self::infrastructure_cookie($cookie->name)) {
                $cookie_names[sanitize_key($cookie->name)] = true;
            }
        }
        return array(
            'transport' => 'server_loopback',
            'page_key' => $target['page_key'],
            'request_number' => $request_number,
            'url' => $target['url'],
            'final_url' => esc_url_raw($url),
            'http_status' => (int) wp_remote_retrieve_response_code($response),
            // WordPress HTTP API exposes completion time, not response-start timing.
            'ttfb_ms' => null,
            'total_ms' => round((microtime(true) - $started) * 1000, 1),
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
        );
    }

    private static function normalise_browser_results($results, $targets) {
        $expected = count($targets) * self::REQUESTS_PER_URL;
        if (count($results) !== $expected) {
            return new WP_Error('sspa_cache_delivery_browser_incomplete', __('The browser did not return all eight visitor-path measurements.', 'super-speedy-performance-analysis'));
        }
        $normalised = array();
        $seen = array();
        foreach ($results as $raw) {
            if (!is_array($raw)) {
                return new WP_Error('sspa_cache_delivery_browser_result', __('The browser returned an invalid assessment result.', 'super-speedy-performance-analysis'));
            }
            $page_key = sanitize_key(isset($raw['page_key']) ? $raw['page_key'] : '');
            $request_number = isset($raw['request_number']) ? (int) $raw['request_number'] : 0;
            $target = self::target_by_key($targets, $page_key);
            $slot = $page_key . ':' . $request_number;
            if (!$target || $request_number < 1 || $request_number > self::REQUESTS_PER_URL || isset($seen[$slot])
                || empty($raw['url']) || untrailingslashit($target['url']) !== untrailingslashit(esc_url_raw($raw['url']))) {
                return new WP_Error('sspa_cache_delivery_browser_result', __('The browser returned a result outside the fixed assessment targets.', 'super-speedy-performance-analysis'));
            }
            $seen[$slot] = true;
            $headers = self::safe_headers(isset($raw['headers']) ? $raw['headers'] : array());
            $markers = array_values(array_intersect(array('breeze', 'wp_rocket', 'wp_optimize', 'w3_total_cache', 'cache_enabler'), array_map('sanitize_key', (array) ($raw['markers'] ?? array()))));
            $sha = isset($raw['body_sha256']) && preg_match('/^[a-f0-9]{64}$/', (string) $raw['body_sha256']) ? strtolower($raw['body_sha256']) : null;
            $entry = array(
                'transport' => 'browser',
                'visitor_state' => 'anonymous_browser',
                'page_key' => $page_key,
                'request_number' => $request_number,
                'url' => $target['url'],
                'http_status' => max(0, min(599, (int) ($raw['http_status'] ?? 0))),
                'ttfb_ms' => isset($raw['ttfb_ms']) && is_numeric($raw['ttfb_ms']) ? max(0, round((float) $raw['ttfb_ms'], 1)) : null,
                'total_ms' => isset($raw['total_ms']) && is_numeric($raw['total_ms']) ? max(0, round((float) $raw['total_ms'], 1)) : null,
                'response_bytes' => max(0, (int) ($raw['response_bytes'] ?? 0)),
                'transfer_bytes' => max(0, (int) ($raw['transfer_bytes'] ?? 0)),
                'delivery_source' => in_array(($raw['delivery_source'] ?? ''), array('network', 'browser_http_cache', 'unknown'), true) ? $raw['delivery_source'] : 'unknown',
                'body_sha256' => $sha,
                'headers' => $headers,
                'markers' => $markers,
                'features' => array(
                    'product_loop' => !empty($raw['features']['product_loop']),
                    'product_options' => !empty($raw['features']['product_options']),
                    'product_personalisation' => !empty($raw['features']['product_personalisation']),
                    'express_payment' => !empty($raw['features']['express_payment']),
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

    private static function safe_headers($headers) {
        if (is_object($headers) && method_exists($headers, 'getAll')) {
            $headers = $headers->getAll();
        }
        $allowed = array(
            'age', 'cache-control', 'vary', 'via', 'cf-cache-status', 'x-cache',
            'x-cache-status', 'x-breeze-cache', 'x-breeze-cache-write', 'x-litespeed-cache',
            'x-runcloud-cache', 'x-fastcgi-cache', 'x-proxy-cache', 'x-srcache-fetch-status',
            'x-varnish', 'x-vercel-cache', 'x-qc-cache', 'cf-ray',
        );
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

    private static function body_markers($body) {
        $patterns = array(
            'breeze' => '/<!--[^>]*\bBreeze\b[^>]*-->/i',
            'wp_rocket' => '/<!--[^>]*\bWP Rocket\b[^>]*-->/i',
            'wp_optimize' => '/<!--[^>]*\bWP-Optimize\b[^>]*-->/i',
            'w3_total_cache' => '/<!--[^>]*\bW3 Total Cache\b[^>]*-->/i',
            'cache_enabler' => '/<!--[^>]*\bCache Enabler\b[^>]*-->/i',
        );
        $found = array();
        foreach ($patterns as $name => $pattern) {
            if (preg_match($pattern, (string) $body)) {
                $found[] = $name;
            }
        }
        return $found;
    }

    private static function normalise_body($body) {
        return preg_replace(array(
            '/<!--[^>]*\bBreeze\b[^>]*-->/i',
            '/<!--[^>]*\bWP Rocket\b[^>]*-->/i',
            '/<!--[^>]*\bWP-Optimize\b[^>]*-->/i',
            '/<!--[^>]*\bW3 Total Cache\b[^>]*-->/i',
            '/<!--[^>]*\bCache Enabler\b[^>]*-->/i',
        ), '', (string) $body);
    }

    private static function infrastructure_cookie($name) {
        return (bool) preg_match('/^(?:__cf_bm|_cfuvid|cf_clearance|__cflb|__cfwaitingroom|cf_ob_info|cf_use_ob|cf_chl_[a-z0-9_]+|ak_bmsc|bm_sz|bm_sv|_abck)$/i', (string) $name);
    }

    private static function page_verdicts($targets, $browser, $server) {
        $verdicts = array();
        foreach ($targets as $target) {
            $browser_rows = array_values(array_filter($browser, function ($row) use ($target) { return $row['page_key'] === $target['page_key']; }));
            $server_rows = array_values(array_filter($server, function ($row) use ($target) { return $row['page_key'] === $target['page_key']; }));
            $rows = $browser_rows;
            $hash_match = count($rows) === 2 && !empty($rows[0]['body_sha256']) && hash_equals($rows[0]['body_sha256'], $rows[1]['body_sha256']);
            $statuses = array();
            foreach ($rows as $row) {
                // A stored response can retain the CDN/server headers from the request which
                // originally filled the browser cache. Those headers do not prove that the
                // named upstream layer served this request.
                if ('browser_http_cache' === $row['delivery_source']) {
                    continue;
                }
                foreach ((array) $row['cache_layers'] as $layer) {
                    if ('stored_body_marker' !== $layer['confidence'] && 'browser' !== $layer['position']) {
                        $statuses[] = $layer['status'];
                    }
                }
            }
            if (count($rows) !== 2 || array_filter($rows, function ($row) { return !empty($row['error_class']) || 200 !== (int) $row['http_status']; })) {
                $verdict = 'probe_inconclusive';
            } elseif (!$hash_match) {
                $verdict = 'probe_inconclusive';
            } elseif (in_array('MISS', $statuses, true) && in_array('HIT', $statuses, true)) {
                $verdict = 'cache_write_then_hit_confirmed';
            } elseif (in_array('HIT', $statuses, true)) {
                $verdict = 'cache_hit_confirmed';
            } elseif (count(array_filter($statuses, function ($status) { return 'BYPASS' === $status; })) >= 2) {
                $verdict = 'bypass_confirmed';
            } elseif ($statuses) {
                $verdict = 'reuse_not_observed';
            } else {
                $verdict = 'cache_layer_unidentified';
            }
            $verdicts[] = array(
                'page_key' => $target['page_key'],
                'url' => $target['url'],
                'verdict' => $verdict,
                'browser_body_match' => $hash_match,
                'browser_requests' => count($browser_rows),
                'server_requests' => count($server_rows),
            );
        }
        return $verdicts;
    }

    private static function overall_status($verdicts) {
        $values = array_column($verdicts, 'verdict');
        if (in_array('probe_inconclusive', $values, true)) {
            return 'inconclusive';
        }
        if (array_intersect(array('cache_hit_confirmed', 'cache_write_then_hit_confirmed'), $values)) {
            return count(array_intersect($values, array('cache_hit_confirmed', 'cache_write_then_hit_confirmed'))) === count($values) ? 'confirmed' : 'partially_confirmed';
        }
        if ($values && count(array_unique($values)) === 1 && 'bypass_confirmed' === $values[0]) {
            return 'bypassed';
        }
        return 'not_observed';
    }

    private static function status_label($status) {
        $labels = array(
            'confirmed' => __('Page caching confirmed on all assessed public routes', 'super-speedy-performance-analysis'),
            'partially_confirmed' => __('Page caching confirmed on some assessed public routes', 'super-speedy-performance-analysis'),
            'bypassed' => __('The assessed anonymous requests were bypassed by the observed cache layer', 'super-speedy-performance-analysis'),
            'not_observed' => __('Page-cache reuse was not observed', 'super-speedy-performance-analysis'),
            'inconclusive' => __('Page-cache delivery assessment is inconclusive', 'super-speedy-performance-analysis'),
        );
        return isset($labels[$status]) ? $labels[$status] : $labels['inconclusive'];
    }

    private static function all_layers($browser, $server) {
        $layers = array();
        foreach (array_merge($browser, $server) as $row) {
            foreach ((array) ($row['cache_layers'] ?? array()) as $layer) {
                $layer['page_key'] = $row['page_key'];
                $layers[] = $layer;
            }
        }
        $configured = array(
            'breeze' => 'breeze',
            'wp-rocket' => 'wp_rocket',
            'litespeed-cache' => 'litespeed',
            'w3-total-cache' => 'w3_total_cache',
            'wp-super-cache' => 'wp_super_cache',
            'cache-enabler' => 'cache_enabler',
            'wp-optimize' => 'wp_optimize',
        );
        foreach ((array) get_option('active_plugins', array()) as $plugin_file) {
            $slug = '.' === dirname($plugin_file) ? basename($plugin_file, '.php') : dirname($plugin_file);
            if (!isset($configured[$slug])) {
                continue;
            }
            $layers[] = array(
                'layer' => $configured[$slug],
                'position' => 'wordpress_php',
                'evidence_source' => 'active_plugin',
                'evidence_name' => $slug,
                'status' => 'UNKNOWN',
                'confidence' => 'configured_candidate',
                'transport' => 'inventory',
                'request_number' => null,
                'page_key' => null,
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
                    'request_number' => (int) $row['request_number'],
                    'http_status' => (int) $row['http_status'],
                    'error_class' => isset($row['error_class']) ? $row['error_class'] : '',
                );
            }
        }
        return $failures;
    }

    private static function origin_profiles($run_id, $targets) {
        $report = SSPA_Report::build($run_id);
        if (is_wp_error($report)) {
            return array();
        }
        $wanted = array();
        foreach ($targets as $target) {
            $wanted[$target['origin_page_key']] = $target;
        }
        $origin = array();
        foreach ((array) $report['pages'] as $page) {
            if ('anon' !== $page['variant'] || !isset($wanted[$page['page_key']])) {
                continue;
            }
            $origin[] = array(
                'page_key' => $wanted[$page['page_key']]['page_key'],
                'url' => $wanted[$page['page_key']]['url'],
                'boundary' => 'origin_generation',
                'generation_ms' => $page['generation_ms'],
                'response_code' => $page['response_code'],
                'blocked_by' => $page['blocked_by'],
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
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
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
        foreach ($mu as $file => $data) {
            $mu_plugins[] = array('name' => sanitize_text_field($data['Name']), 'version' => sanitize_text_field($data['Version']));
        }
        $dropins = array();
        if (function_exists('get_dropins')) {
            foreach ((array) get_dropins() as $file => $data) {
                if (!in_array($file, array('object-cache.php', 'advanced-cache.php'), true)) {
                    continue;
                }
                $dropins[$file] = array(
                    'name' => sanitize_text_field(isset($data['Name']) ? $data['Name'] : ''),
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
