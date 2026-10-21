<?php
defined('ABSPATH') || exit;

/**
 * The bundled offers and the evidence that selects their wording.
 *
 * Phase 2 added the caching service (Cache Everything for Everyone) and Super Speedy Ajax
 * Prices; phase 3 adds Scalability Pro, Super Speedy Search, Super Speedy Filters, Super
 * Speedy Imports and the general fix quote, each selected by its own measured trigger. Offers live in the rules snapshot and change only with releases. Links
 * carry a static placement tag and nothing about the site or the user. Every figure or
 * description shown is a verbatim quotation of a published page, kept with its source URL
 * and the date it was checked.
 *
 * Evidence triggers (plan 2026-10-20, section 3):
 *   C1 shared-cache scan found visitor-specific content
 *   C2 a completed Traffic collection shows basket or logged-in catalogue requests
 *   C3 a measured audience gap, or no page cache and slow anonymous catalogue generation
 *   C4 fit gate: WooCommerce active and not Multisite
 *   P1 prices can vary per visitor; P2 catalogue pages excluded from or missed by the
 *   cache; P3 price code that varies by visitor; P4 as C2
 */
class SSPA_Offers {

    const UTM_SOURCE = 'performance-analysis';
    const UTM_MEDIUM = 'plugin';

    public static function get($id) {
        $offers = SSPA_Rules::offers();
        return isset($offers[$id]) && is_array($offers[$id]) ? $offers[$id] : null;
    }

    /**
     * The offer link for one placement. The tag names the placement only: no host name,
     * user, site or run data.
     */
    public static function url($id, $placement = 'tab') {
        $offer = self::get($id);
        if (!$offer || empty($offer['url'])) {
            return '';
        }
        $campaign = isset($offer['campaigns'][$placement]) ? (string) $offer['campaigns'][$placement] : '';
        if ('' === $campaign) {
            return $offer['url'];
        }
        return add_query_arg(array(
            'utm_source' => self::UTM_SOURCE,
            'utm_medium' => self::UTM_MEDIUM,
            'utm_campaign' => rawurlencode($campaign),
        ), $offer['url']);
    }

    /** Shared Traffic numbers for C2, P4 and the tab, or null without a completed collection. */
    public static function traffic_private_state() {
        static $cached = false;
        if (false !== $cached) {
            return $cached;
        }
        $cached = null;
        if (!class_exists('SSPA_Traffic_Collection')) {
            return $cached;
        }
        $status = SSPA_Traffic_Collection::status();
        if (empty($status['collection']) || 'complete' !== $status['collection']['status']) {
            return $cached;
        }
        $observations = SSPA_Traffic_Collection::observations((int) $status['collection']['id']);
        if (is_wp_error($observations) || empty($observations['cache_fragment_opportunity']['private_state_catalogue'])) {
            return $cached;
        }
        $private = $observations['cache_fragment_opportunity']['private_state_catalogue'];
        $cached = array(
            'collection_id' => (int) $status['collection']['id'],
            'started_at' => (string) $status['collection']['started_at'],
            'requests' => (int) ($private['estimated_requests'] ?? 0),
            'wall_ms' => isset($private['estimated_wall_ms_sum']) ? (float) $private['estimated_wall_ms_sum'] : null,
            'projected_daily_requests' => isset($private['projected_daily_requests']) ? (int) $private['projected_daily_requests'] : null,
        );
        return $cached;
    }

    private static function traffic_threshold() {
        $value = SSPA_Rules::threshold('cache_private_state_daily_requests');
        return null === $value ? 10 : (int) $value;
    }

    /**
     * C1 to C4 for the caching service.
     *
     * @param int        $run_id   Analysis run; 0 means the latest completed one.
     * @param array|null $delivery A saved cache check, or null to read the latest.
     */
    public static function cache_evidence($run_id = 0, $delivery = false) {
        $run_id = $run_id ? (int) $run_id : (int) SSPA_Report::latest_done_run_id();
        $delivery = false === $delivery ? SSPA_Cache_Delivery::latest() : $delivery;
        $out = array('C1' => null, 'C2' => null, 'C3' => null, 'C4' => false, 'fires' => false, 'sentences' => array());

        // C1
        if ($run_id) {
            $safety = SSPA_Report::cache_safety($run_id);
            $assessment = is_wp_error($safety) ? array() : (array) $safety['assessment'];
            if ('visitor_specific_content_review_recommended' === ($assessment['shared_cache_status'] ?? '')) {
                $pages = 0;
                foreach ((array) ($assessment['pages'] ?? array()) as $page) {
                    if (!empty($page['set_cookie_names']) || !empty($page['nonce_names']) || !empty($page['legacy_cookie_reads']) || !empty($page['private_surface_hints'])) {
                        $pages++;
                    }
                }
                $out['C1'] = array('run_id' => $run_id, 'pages' => $pages, 'components' => count((array) ($assessment['candidate_components'] ?? array())));
            }
        }

        // C2
        $traffic = self::traffic_private_state();
        if ($traffic && null !== $traffic['projected_daily_requests'] && $traffic['projected_daily_requests'] >= self::traffic_threshold()) {
            $out['C2'] = $traffic;
        }

        // C3
        $out['C3'] = self::audience_gap($delivery);

        // C4
        $out['C4'] = class_exists('WooCommerce') && !is_multisite();
        $out['fires'] = $out['C4'] && ($out['C1'] || $out['C2'] || $out['C3']);
        if ($out['C4']) {
            $out['sentences'] = self::cache_sentences($out);
        }
        return $out;
    }

    /** C3: the measured gap between audiences, or no page cache with slow generation. */
    private static function audience_gap($delivery) {
        if (!is_array($delivery) || empty($delivery['audience_verdicts'])) {
            return null;
        }
        $verdicts = $delivery['audience_verdicts'];
        $by_page = array();
        foreach (SSPA_Cache_Delivery::AUDIENCES as $audience) {
            foreach ((array) ($verdicts[$audience] ?? array()) as $row) {
                $by_page[$row['page_key']][$audience] = $row;
            }
        }
        foreach (SSPA_Cache_Delivery::CATALOGUE_PAGES as $page_key) {
            if (empty($by_page[$page_key]['anonymous']) || 'cached' !== $by_page[$page_key]['anonymous']['cache_state']) {
                continue;
            }
            $basket = $by_page[$page_key]['basket'] ?? null;
            $customer = $by_page[$page_key]['customer'] ?? null;
            $basket_off = $basket && 'not_cached' === $basket['cache_state'];
            $customer_off = $customer && 'not_cached' === $customer['cache_state'];
            if ($basket_off || $customer_off) {
                return array(
                    'kind' => 'audience_gap',
                    'page_key' => $page_key,
                    'layer' => $by_page[$page_key]['anonymous']['layer'],
                    'anonymous' => $by_page[$page_key]['anonymous'],
                    'basket' => $basket_off ? $basket : null,
                    'customer' => $customer_off ? $customer : null,
                );
            }
        }
        $threshold = SSPA_Rules::threshold('cache_uncached_generation_ms');
        $threshold = null === $threshold ? 500 : (float) $threshold;
        if ('no_page_cache_observed' === ($delivery['page_cache_status'] ?? '')) {
            foreach ((array) ($delivery['origin_profiles'] ?? array()) as $origin) {
                if (in_array($origin['page_key'], SSPA_Cache_Delivery::CATALOGUE_PAGES, true)
                    && null !== $origin['generation_ms'] && (float) $origin['generation_ms'] >= $threshold) {
                    return array(
                        'kind' => 'no_page_cache',
                        'page_key' => $origin['page_key'],
                        'generation_ms' => (float) $origin['generation_ms'],
                        'run_id' => (int) $delivery['run_id'],
                    );
                }
            }
        }
        return null;
    }

    /** Readable names for the cache layers the check identifies. */
    public static function layer_label($layer) {
        $labels = array(
            'cloudflare' => 'Cloudflare',
            'breeze' => 'Breeze',
            'litespeed' => 'LiteSpeed',
            'runcloud_nginx' => 'RunCloud nginx',
            'fastcgi_cache' => 'nginx FastCGI',
            'server_cache' => __('server', 'super-speedy-performance-analysis'),
            'proxy_cache' => __('proxy', 'super-speedy-performance-analysis'),
            'srcache' => 'nginx srcache',
            'vercel' => 'Vercel',
            'quic_cloud' => 'QUIC.cloud',
            'generic_proxy_cache' => __('proxy', 'super-speedy-performance-analysis'),
            'varnish' => 'Varnish',
            'cache_enabler' => 'Cache Enabler',
            'unidentified_shared_cache' => __('unidentified shared', 'super-speedy-performance-analysis'),
        );
        return isset($labels[$layer]) ? $labels[$layer] : __('page', 'super-speedy-performance-analysis');
    }

    private static function ms_text($value) {
        return number_format_i18n((float) $value, 0);
    }

    /** A timing with its boundary: time to first byte where cURL gave one, else completion. */
    private static function server_time($row) {
        return null !== $row['server_ttfb_ms'] ? $row['server_ttfb_ms'] : $row['server_total_ms'];
    }

    private static function cache_sentences($evidence) {
        $sentences = array();
        $gap = $evidence['C3'];
        if ($gap && 'audience_gap' === $gap['kind']) {
            $page = SSPA_Cache_Delivery::page_label($gap['page_key']);
            $layer = self::layer_label($gap['layer']);
            $anonymous = self::ms_text(self::server_time($gap['anonymous']));
            if ($gap['basket'] && $gap['customer']) {
                $sentences[] = sprintf(
                    /* translators: 1: page name, e.g. "Shop", 2: cache name, 3: milliseconds, 4: milliseconds, 5: milliseconds */
                    __('On this site the %1$s page reached the server-side check in %3$s ms from the %2$s cache, but the same page took %4$s ms for a request carrying WooCommerce\'s basket cookies and %5$s ms for a logged-in customer, because the cache was not used for them.', 'super-speedy-performance-analysis'),
                    $page, $layer, $anonymous,
                    self::ms_text(self::server_time($gap['basket'])),
                    self::ms_text(self::server_time($gap['customer']))
                );
            } elseif ($gap['basket']) {
                $sentences[] = sprintf(
                    /* translators: 1: page name, 2: cache name, 3: milliseconds, 4: milliseconds */
                    __('On this site the %1$s page reached the server-side check in %3$s ms from the %2$s cache, but the same page took %4$s ms for a request carrying WooCommerce\'s basket cookies, because the cache was not used for it.', 'super-speedy-performance-analysis'),
                    $page, $layer, $anonymous, self::ms_text(self::server_time($gap['basket']))
                );
            } else {
                $sentences[] = sprintf(
                    /* translators: 1: page name, 2: cache name, 3: milliseconds, 4: milliseconds */
                    __('On this site the %1$s page reached the server-side check in %3$s ms from the %2$s cache, but the same page took %4$s ms for a logged-in customer, because the cache was not used for them.', 'super-speedy-performance-analysis'),
                    $page, $layer, $anonymous, self::ms_text(self::server_time($gap['customer']))
                );
            }
        } elseif ($gap && 'no_page_cache' === $gap['kind']) {
            $sentences[] = sprintf(
                /* translators: 1: page name, 2: milliseconds, 3: analysis number */
                __('No page cache was observed on this site, and the %1$s page took %2$s ms to generate for an anonymous visitor in Analysis #%3$d. Every visitor waits for that generation on every page view.', 'super-speedy-performance-analysis'),
                SSPA_Cache_Delivery::page_label($gap['page_key']),
                self::ms_text($gap['generation_ms']),
                (int) $gap['run_id']
            );
        }
        if ($evidence['C2']) {
            $sentences[] = sprintf(
                /* translators: 1: collection start date, 2: number of page views, 3: seconds */
                __('In the Traffic collection from %1$s, %2$s catalogue page views from visitors with a basket or logged-in customers reached WordPress, costing %3$s seconds of page generation.', 'super-speedy-performance-analysis'),
                mysql2date(get_option('date_format'), $evidence['C2']['started_at']),
                number_format_i18n($evidence['C2']['requests']),
                number_format_i18n(((float) $evidence['C2']['wall_ms']) / 1000, 1)
            );
        }
        if ($evidence['C1']) {
            if ($evidence['C1']['pages'] > 0) {
                $sentences[] = sprintf(
                    /* translators: %d: number of page types */
                    _n('The shared-cache safety scan found visitor-specific content on %d page type, which needs mapping before these visitors can share a cached page.', 'The shared-cache safety scan found visitor-specific content on %d page types, which needs mapping before these visitors can share a cached page.', $evidence['C1']['pages'], 'super-speedy-performance-analysis'),
                    $evidence['C1']['pages']
                );
            } else {
                $sentences[] = sprintf(
                    /* translators: %d: number of plugin or theme components */
                    _n('The shared-cache safety scan found visitor-specific code in %d component, which needs mapping before these visitors can share a cached page.', 'The shared-cache safety scan found visitor-specific code in %d components, which needs mapping before these visitors can share a cached page.', max(1, $evidence['C1']['components']), 'super-speedy-performance-analysis'),
                    max(1, $evidence['C1']['components'])
                );
            }
        }
        return $sentences;
    }

    /** P1 to P4 and whether the Ajax Prices offer shows. */
    public static function price_evidence($run_id = 0, $delivery = false) {
        $run_id = $run_id ? (int) $run_id : (int) SSPA_Report::latest_done_run_id();
        $delivery = false === $delivery ? SSPA_Cache_Delivery::latest() : $delivery;
        $woocommerce = class_exists('WooCommerce');
        $out = array('P1' => null, 'P2' => null, 'P3' => null, 'P4' => null, 'show' => false, 'suppressed' => false, 'sentence' => '');
        if (!$woocommerce) {
            return $out;
        }
        $plugins = SSPA_Cache_System::price_affecting_plugins();
        $location = (string) get_option('woocommerce_default_customer_address', '');
        $geolocates = in_array($location, array('geolocation', 'geolocation_ajax'), true);
        if ($plugins || $geolocates) {
            $out['P1'] = array('plugins' => $plugins, 'geolocation' => $geolocates ? $location : '');
        }

        if (is_array($delivery) && !empty($delivery['audience_verdicts']['anonymous'])) {
            $anonymous = array();
            foreach ($delivery['audience_verdicts']['anonymous'] as $row) {
                $anonymous[$row['page_key']] = $row['verdict'];
            }
            $home_cached = isset($anonymous['home']) && 'cached' === SSPA_Cache_Delivery::cache_state($anonymous['home']);
            if ('no_page_cache_observed' === ($delivery['page_cache_status'] ?? '')) {
                $out['P2'] = array('kind' => 'no_page_cache', 'page_key' => 'shop');
            } else {
                foreach (SSPA_Cache_Delivery::CATALOGUE_PAGES as $page_key) {
                    if ($home_cached && isset($anonymous[$page_key]) && in_array($anonymous[$page_key], array('bypass_confirmed', 'reuse_not_observed'), true)) {
                        $out['P2'] = array('kind' => 'excluded', 'page_key' => $page_key);
                        break;
                    }
                }
            }
        }

        if ($run_id) {
            $safety = SSPA_Report::cache_safety($run_id);
            if (!is_wp_error($safety)) {
                foreach ((array) ($safety['assessment']['candidate_components'] ?? array()) as $candidate) {
                    $signals = (array) ($candidate['signals'] ?? array());
                    if (in_array('price_filter', $signals, true) && in_array('segmented_state', $signals, true)) {
                        $out['P3'][] = (string) $candidate['component'];
                    }
                }
            }
        }

        $traffic = self::traffic_private_state();
        if ($traffic && null !== $traffic['projected_daily_requests'] && $traffic['projected_daily_requests'] >= self::traffic_threshold()) {
            $out['P4'] = $traffic;
        }

        $out['suppressed'] = in_array(SSPA_Cache_System::AJAX_PRICES_SLUG, SSPA_Cache_System::active_slugs(), true);
        $out['show'] = $out['P1'] && ($out['P2'] || $out['P3'] || $out['P4']) && !$out['suppressed'];
        if ($out['show']) {
            $out['sentence'] = self::price_sentence($out);
        }
        return $out;
    }

    private static function price_sentence($evidence) {
        $reasons = array();
        if (!empty($evidence['P1']['plugins'])) {
            /* translators: %s: comma-separated plugin names */
            $reasons[] = sprintf(__('active: %s', 'super-speedy-performance-analysis'), implode(', ', $evidence['P1']['plugins']));
        }
        if (!empty($evidence['P1']['geolocation'])) {
            $reasons[] = __('WooCommerce geolocates customers', 'super-speedy-performance-analysis');
        }
        if ($evidence['P2'] && 'excluded' === $evidence['P2']['kind']) {
            $page = SSPA_Cache_Delivery::page_label($evidence['P2']['page_key']);
            /* translators: 1: reasons prices vary, 2: page name */
            return sprintf(__('Prices on this store can differ per visitor (%1$s), and the %2$s page was excluded from the page cache while the home page was cached.', 'super-speedy-performance-analysis'), implode('; ', $reasons), $page);
        }
        if ($evidence['P2'] && 'no_page_cache' === $evidence['P2']['kind']) {
            /* translators: %s: reasons prices vary */
            return sprintf(__('Prices on this store can differ per visitor (%s), and the shop pages were not served from a page cache. Ajax Prices works with a page cache, so the store would need one first.', 'super-speedy-performance-analysis'), implode('; ', $reasons));
        }
        if ($evidence['P3']) {
            /* translators: 1: reasons prices vary, 2: comma-separated component names */
            return sprintf(__('Prices on this store can differ per visitor (%1$s), and the shared-cache safety scan found price code that varies by visitor in %2$s.', 'super-speedy-performance-analysis'), implode('; ', $reasons), implode(', ', $evidence['P3']));
        }
        /* translators: %s: reasons prices vary */
        return sprintf(__('Prices on this store can differ per visitor (%s), and visitors with a basket or logged-in customers reached WordPress on catalogue pages in the latest Traffic collection.', 'super-speedy-performance-analysis'), implode('; ', $reasons));
    }

    /**
     * Everything the Cache performance tab shows in its offer block. The service pitch is
     * always present (Dave, 20 October 2026); evidence sentences lead it only when C4 holds
     * and C1, C2 or C3 fires. Ajax Prices is the primary offer when guests are not cached
     * because catalogue pages are excluded, otherwise the service is primary.
     */
    public static function cache_tab($run_id = 0, $delivery = false) {
        $cache = self::cache_evidence($run_id, $delivery);
        $price = self::price_evidence($run_id, $delivery);
        $service = self::get('cache_service');
        $ajax = self::get('ajax_prices');
        $primary = 'cache_service';
        if ($price['show'] && $price['P2'] && 'excluded' === $price['P2']['kind'] && !$cache['C2'] && !($cache['C3'] && 'audience_gap' === $cache['C3']['kind'])) {
            $primary = 'ajax_prices';
        }
        return array(
            'primary' => $primary,
            'cache_service' => $service ? array(
                'name' => $service['name'],
                'url' => self::url('cache_service', 'tab'),
                'quotes' => (array) ($service['quotes'] ?? array()),
                'figures' => (array) ($service['figures'] ?? array()),
                'supported_systems' => $service['supported_systems'] ?? null,
                // A dismissal hides the evidence-specific wording; the pitch itself stays on
                // this tab (Dave, 20 October 2026, question 2).
                'evidence' => $cache['fires'] && !self::is_dismissed('cache_service') ? $cache['sentences'] : array(),
                'triggers' => $cache,
            ) : null,
            'ajax_prices' => $ajax && $price['show'] && !self::is_dismissed('ajax_prices') ? array(
                'name' => $ajax['name'],
                'url' => self::url('ajax_prices', 'tab'),
                'quotes' => (array) ($ajax['quotes'] ?? array()),
                'sentence' => $price['sentence'],
                'triggers' => $price,
            ) : null,
        );
    }

    /**
     * The Markdown export pitch. Present only when the caching evidence fires for the
     * exported run (Dave, 20 October 2026, question 3).
     *
     * @return string[] Markdown lines, empty when no evidence fires.
     */
    public static function export_pitch($run_id = 0) {
        $run_id = $run_id ? (int) $run_id : (int) SSPA_Report::latest_done_run_id();
        $delivery = SSPA_Cache_Delivery::report($run_id);
        $evidence = self::cache_evidence($run_id, is_wp_error($delivery) ? null : $delivery);
        $service = self::get('cache_service');
        if (!$evidence['fires'] || !$service || self::is_dismissed('cache_service')) {
            return array();
        }
        $lines = array();
        foreach ($evidence['sentences'] as $sentence) {
            $lines[] = $sentence;
            $lines[] = '';
        }
        $lines[] = 'If you would rather have the cache work implemented and tested for you, Dave offers [' . $service['name'] . '](' . self::url('cache_service', 'export') . '), full-page caching for anonymous visitors, logged-in customers and shoppers with a basket.';
        $lines[] = '';
        return $lines;
    }

    // ---------------- Phase 3: every product, one selection per screen ----------------
    //
    // A match is one trigger firing on measured evidence: which offer, which trigger, the
    // finding it belongs to (0 when the evidence is not a finding), the page, a weight for
    // ranking (milliseconds where there are any) and the sentences shown when it is not inside
    // its finding. A screen shows at most one primary and one secondary offer, never the same
    // product twice, never a product the site runs and never one the user dismissed.

    const DISMISSED_META = 'sspa_dismissed_offers';
    const SEARCH_PAGES = array('search-many', 'search-zero');
    const ADMIN_CATALOGUE_PAGES = array('admin-products', 'admin-orders', 'admin-edit-product', 'admin-new-product');
    const CORE_TABLES = array('posts', 'postmeta', 'term_relationships', 'options', 'wc_product_meta_lookup');
    const IMPORTS_LITE_SLUG = 'super-speedy-imports-lite';

    private static $run_matches = array();

    /** Offer ids the current user chose not to see again. */
    public static function dismissed() {
        $user = get_current_user_id();
        if (!$user) {
            return array();
        }
        $list = get_user_meta($user, self::DISMISSED_META, true);
        return is_array($list) ? array_values(array_unique(array_filter(array_map('sanitize_key', $list)))) : array();
    }

    public static function is_dismissed($id) {
        return in_array($id, self::dismissed(), true);
    }

    public static function ajax_dismiss() {
        check_ajax_referer('sspa_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'super-speedy-performance-analysis'), 403);
        }
        $id = isset($_POST['offer']) ? sanitize_key(wp_unslash($_POST['offer'])) : '';
        if (!self::get($id)) {
            wp_send_json_error(__('Unknown offer.', 'super-speedy-performance-analysis'));
        }
        $list = self::dismissed();
        if (!in_array($id, $list, true)) {
            $list[] = $id;
        }
        update_user_meta(get_current_user_id(), self::DISMISSED_META, $list);
        wp_send_json_success(array('dismissed' => $list));
    }

    /** Whether the site already runs the offered product. */
    public static function product_active($id) {
        $offer = self::get($id);
        if (!$offer) {
            return false;
        }
        $active = SSPA_Cache_System::active_slugs();
        foreach ((array) ($offer['suppress_if_active'] ?? array()) as $slug) {
            if (in_array($slug, $active, true)) {
                return true;
            }
        }
        foreach ((array) ($offer['suppress_if_function'] ?? array()) as $function) {
            if (function_exists($function)) {
                return true;
            }
        }
        return false;
    }

    /** Active plugins in one offer category, display names keyed by slug. */
    public static function active_in($category) {
        $categories = SSPA_Rules::offer_categories();
        $slugs = isset($categories[$category]) ? array_values(array_intersect((array) $categories[$category], SSPA_Cache_System::active_slugs())) : array();
        if (!$slugs) {
            return array();
        }
        $names = SSPA_Cache_System::plugin_names();
        $out = array();
        foreach ($slugs as $slug) {
            $out[$slug] = isset($names[$slug]) ? $names[$slug] : $slug;
        }
        return $out;
    }

    public static function lite_active() {
        return in_array(self::IMPORTS_LITE_SLUG, SSPA_Cache_System::active_slugs(), true);
    }

    private static function limit($key, $default) {
        $value = SSPA_Rules::threshold($key);
        return null === $value ? (float) $default : (float) $value;
    }

    private static function is_archive_page($page_key) {
        return in_array($page_key, array('shop', 'product-cat', 'customer-shop'), true) || 0 === strpos((string) $page_key, 'tax-');
    }

    /** A leading-wildcard LIKE, also in the placeholder-escaped form $wpdb->prepare() leaves. */
    private static function leading_wildcard_like($sql) {
        return (bool) preg_match("/LIKE\s+'(%|\{[0-9a-f]{64}\})/i", (string) $sql);
    }

    private static function core_table($table) {
        global $wpdb;
        $table = trim((string) $table, '` ');
        foreach (self::CORE_TABLES as $name) {
            if (0 === strcasecmp($table, $wpdb->prefix . $name)) {
                return true;
            }
        }
        return false;
    }

    private static function match($offer, $trigger, $args = array()) {
        return array_merge(array(
            'offer' => $offer,
            'trigger' => $trigger,
            'finding_id' => 0,
            'page_key' => '',
            'severity' => 'warn',
            'weight' => 0.0,
            'evidence' => '',
            'free_fix' => '',
            'context' => '',
            'anchor' => '',
            'importer' => '',
        ), $args);
    }

    private static function ms($value) {
        return number_format_i18n((float) $value, 0);
    }

    private static function names($plugins) {
        return implode(', ', array_values($plugins));
    }

    /**
     * Every trigger that fires on one profiling run's measured evidence: its findings and its
     * page measurements.
     */
    public static function run_matches($run_id) {
        $run_id = (int) $run_id;
        if (!$run_id) {
            return array();
        }
        if (isset(self::$run_matches[$run_id])) {
            return self::$run_matches[$run_id];
        }
        $filters = self::active_in('product-filter');
        $search_plugins = self::active_in('search');
        $search_context = $search_plugins
            /* translators: %s: comma-separated names of active search plugins */
            ? sprintf(__('Active search plugin: %s. The slow query above ran with it active.', 'super-speedy-performance-analysis'), self::names($search_plugins))
            : '';
        $filter_context = $filters
            /* translators: %s: comma-separated names of active product filter plugins */
            ? sprintf(__('Active product filter plugin: %s.', 'super-speedy-performance-analysis'), self::names($filters))
            : '';
        $rows = SSPA_Insights::top($run_id, 1000);
        $autoload = SSPA_Insights::standalone($run_id, 'autoload_coverage');
        if ($autoload) {
            $rows[] = $autoload;
        }
        $matches = array();
        $product_found = array();
        foreach ($rows as $row) {
            $e = json_decode((string) $row['evidence'], true);
            $e = is_array($e) ? $e : array();
            $page = (string) $row['page_key'];
            $type = (string) $row['finding_type'];
            $rec = (string) $row['recommendation_key'];
            $sql = isset($e['sql']) ? (string) $e['sql'] : (isset($e['fp']) ? (string) $e['fp'] : '');
            $base = array(
                'finding_id' => (int) $row['id'],
                'page_key' => $page,
                'severity' => (string) $row['severity'],
                'weight' => isset($e['ms']) ? (float) $e['ms'] : 0.0,
            );
            $found = null;
            if ('slow_query' === $type && in_array($page, self::SEARCH_PAGES, true) && self::leading_wildcard_like($sql)) {
                $found = self::match('search', 'Q1', $base + array('context' => trim(
                    __('This is the site search query: its leading-wildcard LIKE conditions cannot use an index, so the database reads every post.', 'super-speedy-performance-analysis') . ' ' . $search_context)));
            } elseif ($filters && self::is_archive_page($page)
                && (('slow_query' === $type && false !== stripos($sql, 'term_relationships')) || 'archive_query_unindexed' === $type || 'slow_query_tax' === $rec)) {
                $found = self::match('filters', 'F1', $base + array('context' => $filter_context));
            } elseif ('slow_query' === $type && in_array($rec, array('slow_query_postmeta', 'slow_query_found_rows'), true)) {
                $found = self::match('scalability_pro', 'slow_query_found_rows' === $rec ? 'S1_found_rows' : 'S1', $base);
            } elseif (in_array($type, array('unindexed_query', 'over_examining_query'), true)) {
                $table = isset($e['table']) ? $e['table'] : (isset($e['plan_steps'][0]['table']) ? $e['plan_steps'][0]['table'] : '');
                if (self::core_table($table)) {
                    $found = self::match('scalability_pro', 'S2', $base);
                }
            } elseif ('archive_query_unindexed' === $type && 'warn' === $row['severity']) {
                $found = self::match('scalability_pro', 'S3', $base);
            } elseif ('query_loop' === $type && in_array($page, array('shop', 'product-cat'), true)) {
                $found = self::match('filters', 'F2', array_merge($base, array('weight' => isset($e['sql_ms']) ? (float) $e['sql_ms'] : 0.0, 'context' => $filter_context)));
            } elseif (in_array($type, array('autoload_coverage', 'autoload_bloat'), true)) {
                $found = self::match('scalability_pro', 'S4', array_merge($base, array('anchor' => 'autoload_coverage' === $type ? 'autoload' : '')));
            } elseif ('slow_http' === $type && !empty($e['is_admin'])) {
                $found = self::match('scalability_pro', 'S7', $base);
            }
            if ($found) {
                $matches[] = $found;
                if (!self::product_active($found['offer'])) {
                    $product_found[(int) $row['id']] = true;
                }
            }
            // R1: a critical finding no available product answers.
            if ('critical' === $row['severity'] && empty($product_found[(int) $row['id']])) {
                $matches[] = self::match('fix_quote', 'R1', $base);
            }
        }

        $share_pct = self::limit('offer_sql_share_pct', 15);
        $sql_ms = self::limit('offer_sql_ms', 100);
        $seen = array();
        foreach (SSPA_Report::pages($run_id) as $page) {
            $key = (string) $page['page_key'];
            if (isset($seen[$key])) {
                continue; // rows are slowest first; the first one per page is the worst
            }
            $seen[$key] = true;
            $gen = (float) $page['generation_ms'];
            $sql = (float) $page['sql_ms'];
            $http = (float) $page['http_ms'];
            if ($gen <= 0) {
                continue;
            }
            $share = $sql / $gen * 100;
            /* translators: 1: page key, 2: SQL milliseconds, 3: generation milliseconds, 4: percentage */
            $sentence = sprintf(__('On %1$s, SQL took %2$s ms of %3$s ms of page generation (%4$s%%).', 'super-speedy-performance-analysis'), $key, self::ms($sql), self::ms($gen), number_format_i18n($share, 0));
            if (in_array($key, self::ADMIN_CATALOGUE_PAGES, true) && $sql >= $sql_ms && $share >= $share_pct) {
                $matches[] = self::match('scalability_pro', 'S5', array('page_key' => $key, 'weight' => $sql, 'evidence' => $sentence,
                    'free_fix' => __('Open this screen\'s profile in the Pages tab and inspect its largest SQL groups and their query plans.', 'super-speedy-performance-analysis')));
            }
            if (in_array($key, self::SEARCH_PAGES, true) && $sql >= $sql_ms && $share >= $share_pct) {
                $matches[] = self::match('search', 'Q2', array('page_key' => $key, 'weight' => $sql, 'evidence' => $sentence, 'context' => $search_plugins ? sprintf(
                    /* translators: %s: comma-separated names of active search plugins */
                    __('Active search plugin: %s.', 'super-speedy-performance-analysis'), self::names($search_plugins)) : '',
                    'free_fix' => __('Limit what search reads: search fewer post types, and keep long content out of the search query where visitors do not need it.', 'super-speedy-performance-analysis')));
            }
            if ('admin-orders-search' === $key && class_exists('WooCommerce') && $gen >= self::limit('offer_order_search_ms', 500)
                && $sql >= $http && $sql >= max(0, $gen - $sql - $http)) {
                $matches[] = self::match('search', 'Q3', array('page_key' => $key, 'weight' => $gen, 'evidence' => sprintf(
                    /* translators: 1: generation milliseconds, 2: SQL milliseconds */
                    __('Searching orders in wp-admin took %1$s ms to generate, %2$s ms of it in SQL, the largest share.', 'super-speedy-performance-analysis'), self::ms($gen), self::ms($sql)),
                    'free_fix' => __('Search by order number or the customer\'s exact email address where you can: a free-text order search reads every address and line item.', 'super-speedy-performance-analysis')));
            }
        }
        self::$run_matches[$run_id] = $matches;
        return $matches;
    }

    /** The latest Traffic collection's endpoint evidence, or null. */
    private static function endpoints() {
        static $cached = false;
        if (false !== $cached) {
            return $cached;
        }
        $cached = null;
        if (!class_exists('SSPA_Traffic_Collection') || !SSPA_Traffic_Collection::latest()) {
            return $cached;
        }
        $evidence = SSPA_Report::endpoint_evidence();
        $cached = is_array($evidence) ? $evidence : null;
        return $cached;
    }

    private static function endpoint_name($endpoint) {
        return 'rest' === $endpoint['identity']['transport'] ? (string) $endpoint['identity']['route_pattern'] : (string) $endpoint['identity']['action'];
    }

    /** The importer requests a Traffic collection measured, keyed by importer. */
    public static function measured_importers() {
        $evidence = self::endpoints();
        $actions = SSPA_Rules::offer_importer_endpoints();
        $out = array();
        foreach ((array) ($evidence['endpoints'] ?? array()) as $endpoint) {
            $action = (string) ($endpoint['identity']['action'] ?? '');
            if ('' !== $action && isset($actions[$action])) {
                $out[$actions[$action]] = array(
                    'action' => $action,
                    'count' => (int) $endpoint['observations']['count'],
                    'median_ms' => $endpoint['observations']['whole_request_wall_ms']['median'],
                    'p95_ms' => $endpoint['observations']['whole_request_wall_ms']['p95'],
                    'started_at' => $evidence['capture']['started_at'],
                );
            }
        }
        return $out;
    }

    private static function collection_date($iso) {
        return $iso ? date_i18n(get_option('date_format'), strtotime($iso)) : '';
    }

    /**
     * Triggers that belong to the site rather than one analysis run: slow endpoints and import
     * requests in the latest Traffic collection, an active importer, and the dominant plugin
     * of the latest Plugin Impact Analysis.
     */
    public static function site_matches() {
        $matches = array();
        $evidence = self::endpoints();
        $actions = SSPA_Rules::offer_importer_endpoints();
        $p95_limit = self::limit('offer_endpoint_p95_ms', 300);
        $calls_limit = self::limit('offer_endpoint_calls', 50);
        foreach ((array) ($evidence['endpoints'] ?? array()) as $endpoint) {
            $name = self::endpoint_name($endpoint);
            $count = (int) $endpoint['observations']['count'];
            $p95 = (float) $endpoint['observations']['whole_request_wall_ms']['p95'];
            if (isset($actions[$name]) && 'rest' !== $endpoint['identity']['transport']) {
                $matches[] = self::match('imports', 'I3', array('weight' => (float) $endpoint['observations']['whole_request_wall_ms']['sum'], 'importer' => $actions[$name], 'anchor' => 'imports', 'evidence' => sprintf(
                    /* translators: 1: collection start date, 2: number of requests, 3: admin-ajax action name, 4: median milliseconds */
                    _n('In the Traffic collection from %1$s, an importer processed a file in %2$s request to %3$s (median %4$s ms).', 'In the Traffic collection from %1$s, an importer processed a file in %2$s requests to %3$s (median %4$s ms each).', $count, 'super-speedy-performance-analysis'),
                    self::collection_date($evidence['capture']['started_at']), number_format_i18n($count), $name, self::ms($endpoint['observations']['whole_request_wall_ms']['median'])),
                    'free_fix' => __('The Imports tab shows this measurement beside the published import benchmarks.', 'super-speedy-performance-analysis')));
                continue;
            }
            if (0 === strpos($name, 'sspa_') || $count < $calls_limit || $p95 < $p95_limit) {
                continue;
            }
            $labels = array('admin_ajax' => 'admin-ajax', 'wc_ajax' => 'wc-ajax', 'rest' => 'REST');
            $transport = isset($labels[$endpoint['identity']['transport']]) ? $labels[$endpoint['identity']['transport']] : $endpoint['identity']['transport'];
            $matches[] = self::match('scalability_pro', 'S8', array('weight' => $p95, 'anchor' => 'endpoint', 'evidence' => sprintf(
                /* translators: 1: collection start date, 2: transport such as admin-ajax, 3: action or route, 4: number of requests, 5: p95 milliseconds */
                __('In the Traffic collection from %1$s, the %2$s endpoint %3$s answered %4$s requests with a 95th percentile of %5$s ms.', 'super-speedy-performance-analysis'),
                self::collection_date($evidence['capture']['started_at']), $transport, $name, number_format_i18n($count), self::ms($p95)),
                'free_fix' => __('Profile this endpoint on the AJAX tab to see which plugins load and work while it answers.', 'super-speedy-performance-analysis')));
        }

        $importers = self::active_in('importer');
        if ($importers) {
            $first = array_keys($importers)[0];
            $matches[] = self::match('imports', 'I1', array('importer' => $first, 'anchor' => 'imports', 'evidence' => sprintf(
                /* translators: %s: comma-separated importer plugin names */
                __('Importer active on this site: %s.', 'super-speedy-performance-analysis'), self::names($importers)),
                'free_fix' => __('The Imports tab shows your importer\'s own run history beside the published import benchmarks.', 'super-speedy-performance-analysis')));
        }

        $r3 = self::dominant_impact();
        if ($r3) {
            $matches[] = $r3;
        }
        return $matches;
    }

    /** R3: the latest Plugin Impact Analysis names a dominant plugin no product of ours fits. */
    private static function dominant_impact() {
        $deep = SSPA_Report::latest_deep_run();
        if (!$deep || empty($deep['started']) || empty($deep['finished'])) {
            return null;
        }
        $fits = array();
        foreach (SSPA_Rules::offer_categories() as $slugs) {
            $fits = array_merge($fits, (array) $slugs);
        }
        $best = null;
        foreach (SSPA_Report::impacts() as $impact) {
            if ('normal' !== $impact['object_cache_mode'] || null === $impact['delta_generation_ms']
                || $impact['measured_at'] < $deep['started'] || $impact['measured_at'] > $deep['finished']) {
                continue;
            }
            $slug = (string) $impact['plugin'];
            if (in_array($slug, $fits, true) || 0 === strpos($slug, 'super-speedy') || 'scalability-pro' === $slug) {
                continue;
            }
            if (!$best || $impact['delta_generation_ms'] > $best['delta_generation_ms']) {
                $best = $impact;
            }
        }
        if (!$best || $best['delta_generation_ms'] < self::limit('offer_impact_ms', 100)) {
            return null;
        }
        return self::match('fix_quote', 'R3', array('weight' => (float) $best['delta_generation_ms'], 'anchor' => 'deep', 'page_key' => $best['page_key'], 'evidence' => sprintf(
            /* translators: 1: plugin, 2: milliseconds, 3: page key */
            __('The latest Plugin Impact Analysis measured %1$s adding %2$s ms to %3$s.', 'super-speedy-performance-analysis'), $best['plugin'], self::ms($best['delta_generation_ms']), $best['page_key']),
            'free_fix' => __('Ask the plugin\'s author about the cost, or compare the page with the plugin deactivated on a staging copy.', 'super-speedy-performance-analysis')));
    }

    /**
     * Pick at most $limit offers: available (not dismissed, not already running, still in the
     * bundled list), ranked by offer priority, then severity, then measured weight, and never
     * the same offer twice.
     */
    public static function select($matches, $limit = 2) {
        $dismissed = self::dismissed();
        $available = array();
        foreach ($matches as $match) {
            $offer = self::get($match['offer']);
            if (!$offer || in_array($match['offer'], $dismissed, true) || self::product_active($match['offer'])) {
                continue;
            }
            $match['priority'] = isset($offer['priority']) ? (int) $offer['priority'] : 3;
            $available[] = $match;
        }
        $severity = array('critical' => 0, 'warn' => 1, 'info' => 2);
        usort($available, static function ($a, $b) use ($severity) {
            return array($a['priority'], $severity[$a['severity']] ?? 3, -$a['weight']) <=> array($b['priority'], $severity[$b['severity']] ?? 3, -$b['weight']);
        });
        $chosen = array();
        foreach ($available as $match) {
            if (isset($chosen[$match['offer']])) {
                continue;
            }
            $match['role'] = $chosen ? 'secondary' : 'primary';
            $chosen[$match['offer']] = $match;
            if (count($chosen) >= $limit) {
                break;
            }
        }
        return array_values($chosen);
    }

    /** The Overview's offers for one profiling run. */
    public static function overview($run_id) {
        return self::select(array_merge(self::run_matches($run_id), self::site_matches()), 2);
    }

    /**
     * The one offer for a page summary: that page's own triggers in its run, or R2 when a slow
     * page (generation at or above summary_unattributed_min_ms) spent summary_unattributed_pct
     * or more of its generation unattributed.
     *
     * @param array $row     Profile row (page_key, run_id).
     * @param array $summary SSPA_Page_Summary::build() output.
     */
    public static function page($row, $summary) {
        $matches = array();
        foreach (self::run_matches((int) $row['run_id']) as $match) {
            if ($match['page_key'] === $row['page_key'] && 'R1' !== $match['trigger']) {
                $matches[] = $match;
            }
        }
        $gen = isset($summary['generation_ms']) ? (float) $summary['generation_ms'] : 0.0;
        $unattributed = isset($summary['unattributed_ms']) ? (float) $summary['unattributed_ms'] : 0.0;
        if ($gen > 0 && $gen >= self::limit('summary_unattributed_min_ms', 1000) && $unattributed / $gen * 100 >= self::limit('summary_unattributed_pct', 35)) {
            $matches[] = self::match('fix_quote', 'R2', array('page_key' => (string) $row['page_key'], 'weight' => $unattributed, 'evidence' => sprintf(
                /* translators: 1: percentage, 2: milliseconds */
                __('%1$s%% of this page\'s generation (%2$s ms) ran during render without being attributed to a plugin, hook or template.', 'super-speedy-performance-analysis'),
                number_format_i18n($unattributed / $gen * 100, 0), self::ms($unattributed))));
        }
        $chosen = self::select($matches, 1);
        return $chosen ? $chosen[0] : null;
    }

    /** S6 and R4 for a checkout run. */
    public static function checkout($run_id) {
        $matches = array();
        foreach (SSPA_Insights::top((int) $run_id, 1000) as $row) {
            $e = json_decode((string) $row['evidence'], true);
            $e = is_array($e) ? $e : array();
            $base = array('finding_id' => (int) $row['id'], 'page_key' => (string) $row['page_key'], 'severity' => (string) $row['severity'], 'weight' => isset($e['ms']) ? (float) $e['ms'] : 0.0);
            if (in_array($row['finding_type'], array('checkout_purge_order_pages', 'checkout_amp_purge_missing'), true)) {
                $matches[] = self::match('scalability_pro', 'S6', $base);
            } elseif (in_array($row['finding_type'], array('checkout_component_cost', 'checkout_slow_step'), true)) {
                $matches[] = self::match('fix_quote', 'R4', $base);
            }
        }
        return self::select($matches, 2);
    }

    // ---------------- rendering ----------------

    /** The markup offer blocks and quotations use, for wp_kses() when a template echoes them. */
    public static function allowed_html() {
        return array(
            'div' => array('class' => true, 'data-offer' => true, 'data-trigger' => true, 'data-role' => true),
            'p' => array('class' => true),
            'strong' => array(),
            'br' => array(),
            'blockquote' => array('class' => true),
            'cite' => array(),
            'a' => array('href' => true, 'class' => true, 'target' => true, 'rel' => true, 'data-tab' => true),
            'button' => array('type' => true, 'class' => true, 'data-offer' => true),
        );
    }

    /** Copy and figure for one match, from the bundled snapshot. */
    private static function copy_for($match) {
        $offer = self::get($match['offer']);
        $trigger = isset($offer['triggers'][$match['trigger']]) ? $offer['triggers'][$match['trigger']] : array();
        $copy = isset($trigger['copy'], $offer['copy'][$trigger['copy']]) ? $offer['copy'][$trigger['copy']] : null;
        $figure_key = isset($trigger['figure']) ? $trigger['figure'] : null;
        if ('imports' === $match['offer'] && $match['importer'] && isset($offer['importer_figures'][$match['importer']])) {
            $figure_key = $offer['importer_figures'][$match['importer']];
        }
        $figure = $figure_key && isset($offer['figures'][$figure_key]) ? $offer['figures'][$figure_key] : null;
        return array($copy, $figure);
    }

    private static function checked($quote) {
        return mysql2date(get_option('date_format'), $quote['source_checked'] . ' 00:00:00', false);
    }

    /** A verbatim published quotation with its source, date checked and any conditions. */
    public static function quote_html($quote, $class = 'sspa-offer-quote') {
        if (!$quote) {
            return '';
        }
        $html = '<blockquote class="' . esc_attr($class) . '"><p>&ldquo;' . esc_html($quote['text']) . '&rdquo;</p><cite>';
        if (!empty($quote['attribution'])) {
            $html .= esc_html($quote['attribution']) . ', ';
        }
        if (!empty($quote['context'])) {
            $html .= esc_html($quote['context']) . ', ';
        }
        $html .= '<a href="' . esc_url($quote['source_url']) . '" target="_blank" rel="noopener noreferrer">' . esc_html(wp_parse_url($quote['source_url'], PHP_URL_HOST) . wp_parse_url($quote['source_url'], PHP_URL_PATH)) . '</a> ';
        /* translators: %s: date the published page was last checked */
        $html .= esc_html(sprintf(__('(checked %s)', 'super-speedy-performance-analysis'), self::checked($quote)));
        if (!empty($quote['conditions']['text'])) {
            /* translators: %s: the published test conditions, quoted */
            $html .= '<br>' . esc_html(sprintf(__('Published conditions: “%s”', 'super-speedy-performance-analysis'), $quote['conditions']['text']));
        }
        return $html . '</cite></blockquote>';
    }

    private static function link_text($offer) {
        /* translators: %s: product or service name */
        return 'service' === $offer['kind'] ? sprintf(__('View the %s', 'super-speedy-performance-analysis'), $offer['name']) : sprintf(__('View %s', 'super-speedy-performance-analysis'), $offer['name']);
    }

    /** The Lite install button target: WordPress's own installer, or '' where the user cannot install. */
    public static function lite_install_url() {
        return current_user_can('install_plugins') ? admin_url('plugin-install.php?tab=plugin-information&plugin=' . self::IMPORTS_LITE_SLUG) : '';
    }

    /** The links an offer ends with. */
    private static function actions_html($match, $placement) {
        $offer = self::get($match['offer']);
        $primary = 'primary' === ($match['role'] ?? 'primary');
        $html = '';
        if ('imports' === $match['offer']) {
            if (!self::lite_active() && self::lite_install_url()) {
                $html .= '<a class="button button-primary sspa-imports-install" href="' . esc_url(self::lite_install_url()) . '">' . esc_html__('Install Super Speedy Imports Lite (free)', 'super-speedy-performance-analysis') . '</a><br>';
            } elseif (!self::lite_active()) {
                $html .= esc_html__('Super Speedy Imports Lite is free in the WordPress plugin directory.', 'super-speedy-performance-analysis') . '<br>';
            }
            $html .= '<a class="sspa-offer-link sspa-imports-pro" href="' . esc_url(self::url('imports', $placement)) . '" target="_blank" rel="noopener noreferrer">' . esc_html(self::link_text($offer)) . '</a>';
        } else {
            $html .= '<a class="button' . ($primary ? ' button-primary' : '') . ' sspa-offer-link" href="' . esc_url(self::url($match['offer'], $placement)) . '" target="_blank" rel="noopener noreferrer">' . esc_html(self::link_text($offer)) . '</a>';
        }
        return $html;
    }

    /**
     * One offer block. Inside a finding the finding and its free fix are already on screen;
     * standalone, the evidence and the free fix come first. Either way the order is finding,
     * free fix, "If you would rather not do this by hand:", offer.
     */
    public static function render($match, $placement, $standalone = false, $lead = true) {
        list($copy, $figure) = self::copy_for($match);
        $role = isset($match['role']) ? $match['role'] : 'primary';
        $html = '<div class="sspa-offer sspa-offer-' . esc_attr($role) . '" data-offer="' . esc_attr($match['offer']) . '" data-trigger="' . esc_attr($match['trigger']) . '" data-role="' . esc_attr($role) . '">';
        if ($standalone && $match['evidence']) {
            $html .= '<p class="sspa-offer-evidence"><strong>' . esc_html($match['evidence']) . '</strong></p>';
        }
        if ($match['context']) {
            $html .= '<p class="sspa-offer-context">' . esc_html($match['context']) . '</p>';
        }
        if ($standalone && $match['free_fix']) {
            $html .= '<p class="sspa-offer-free-fix">' . esc_html($match['free_fix']);
            if ('imports' === $match['anchor'] && 'overview' === $placement) {
                $html .= ' <a href="#imports" class="sspa-goto-tab" data-tab="imports">' . esc_html__('Open the Imports tab', 'super-speedy-performance-analysis') . ' &rarr;</a>';
            }
            $html .= '</p>';
        }
        if ($lead) {
            $html .= '<p class="sspa-offer-lead">' . esc_html__('If you would rather not do this by hand:', 'super-speedy-performance-analysis') . '</p>';
        }
        $html .= self::quote_html($copy) . self::quote_html($figure, 'sspa-offer-quote sspa-offer-figure');
        $html .= '<p class="sspa-offer-actions">' . self::actions_html($match, $placement)
            . ' <button type="button" class="button-link sspa-offer-dismiss" data-offer="' . esc_attr($match['offer']) . '">' . esc_html__('Don\'t show this again', 'super-speedy-performance-analysis') . '</button></p>';
        return $html . '</div><!-- /sspa-offer -->';
    }

    private static function md_quote($quote) {
        if (!$quote) {
            return '';
        }
        $line = '“' . self::md_text($quote['text']) . '”' . (!empty($quote['attribution']) ? ', ' . self::md_text($quote['attribution']) : '')
            . ' ([' . wp_parse_url($quote['source_url'], PHP_URL_HOST) . wp_parse_url($quote['source_url'], PHP_URL_PATH) . '](' . $quote['source_url'] . '), checked ' . $quote['source_checked'] . ')';
        if (!empty($quote['conditions']['text'])) {
            $line .= ' Published conditions: “' . self::md_text($quote['conditions']['text']) . '”';
        }
        return $line;
    }

    private static function md_text($text) {
        return trim(preg_replace('/\s+/', ' ', str_replace(array('[', ']', '`'), array('(', ')', "'"), wp_strip_all_tags((string) $text))));
    }

    /** One offer as Markdown lines for an export. */
    public static function markdown($match, $placement = 'export') {
        list($copy, $figure) = self::copy_for($match);
        $offer = self::get($match['offer']);
        $lines = array();
        if ($match['evidence']) {
            $lines[] = '- Measured: ' . self::md_text($match['evidence']);
        }
        if ($match['context']) {
            $lines[] = '- ' . self::md_text($match['context']);
        }
        if ($match['free_fix']) {
            $lines[] = '- Free fix: ' . self::md_text($match['free_fix']);
        }
        $lines[] = '- If you would rather not do this by hand: ' . self::md_quote($copy) . ' [' . self::md_text($offer['name']) . '](' . self::url($match['offer'], $placement) . ')';
        if ($figure) {
            $lines[] = '  - Published figure: ' . self::md_quote($figure);
        }
        if ('imports' === $match['offer'] && !self::lite_active()) {
            $lines[] = '  - Free edition: Super Speedy Imports Lite, in the WordPress plugin directory as `' . self::IMPORTS_LITE_SLUG . '`.';
        }
        $lines[] = '';
        return $lines;
    }

    /**
     * Offer lines for one export, at most two in all. The caching-service pitch, when its own
     * evidence fires for the run, takes one of the two places.
     *
     * @param string $kind run, page or checkout
     */
    public static function export_lines($kind, $run_id, $page_match = null) {
        $lines = array();
        $limit = 2;
        if ('checkout' !== $kind && $run_id) {
            $pitch = self::export_pitch((int) $run_id);
            if ($pitch) {
                $lines = $pitch;
                $limit = 1;
            }
        }
        if ('run' === $kind) {
            $matches = array_slice(self::overview((int) $run_id), 0, $limit);
        } elseif ('page' === $kind) {
            $matches = $page_match ? array($page_match) : array();
        } else {
            $matches = self::checkout((int) $run_id);
        }
        foreach ($matches as $match) {
            $lines = array_merge($lines, self::markdown($match, 'export'));
        }
        return $lines;
    }
}
