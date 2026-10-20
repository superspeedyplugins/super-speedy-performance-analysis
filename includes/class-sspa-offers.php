<?php
defined('ABSPATH') || exit;

/**
 * The bundled offers and the evidence that selects their wording.
 *
 * Phase 2 holds two offers: the caching service (Cache Everything for Everyone) and Super
 * Speedy Ajax Prices. Offers live in the rules snapshot and change only with releases. Links
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
                'evidence' => $cache['fires'] ? $cache['sentences'] : array(),
                'triggers' => $cache,
            ) : null,
            'ajax_prices' => $ajax && $price['show'] ? array(
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
        if (!$evidence['fires'] || !$service) {
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
}
