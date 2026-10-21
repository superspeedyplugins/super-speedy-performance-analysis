<?php
defined('ABSPATH') || exit;

/**
 * Community figures on the Compare tab: superspeedy.org's public cohort and plugin-impact
 * documents, downloaded only when the administrator asks for them, with this site's own cohort
 * and plugins chosen here, locally.
 *
 * The two documents are fixed and parameterless (GET /api/v1/cohorts and /api/v1/plugin-impact),
 * so the requests carry nothing about this site: no query string, no cohort key, and PA's own
 * User-Agent instead of WordPress's default, which contains the site URL. The server and
 * Cloudflare still see the IP address of this web server.
 *
 * Every figure keeps the installation count (n) and confidence label it was published with.
 * superspeedy.org publishes from n = 1; the label says how thin the evidence is.
 */
class SSPA_Community_Figures {

    const API_BASE = 'https://superspeedy.org/api/v1/';

    /** The server's cohort ladder, in relax order (ladder version 1). */
    const LADDER = array('primary_purpose', 'products_band', 'active_plugins_band', 'object_cache', 'database_family');
    const LADDER_VERSION = 1;

    /** Shown first, in this order; every other page type sits behind "Show all". */
    const KEY_PAGES = array('home', 'shop', 'product-cat', 'product-single', 'wc-checkout', 'search-many');

    public static function ajax_load() {
        check_ajax_referer('sspa_admin', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error(__('Permission denied.', 'super-speedy-performance-analysis'), 403); }
        $result = self::load();
        wp_send_json_success(array('state' => $result['state'], 'html' => self::render($result)));
    }

    /**
     * Download both documents and position this site in them.
     *
     * @return array {state: available|not_published|error, ...}
     */
    public static function load() {
        $cohorts = self::fetch('cohorts');
        $impact = self::fetch('plugin-impact');
        foreach (array($cohorts, $impact) as $document) {
            if ('error' === $document['state']) {
                return array('state' => 'error', 'detail' => $document['detail']);
            }
        }
        if ('not_published' === $cohorts['state'] && 'not_published' === $impact['state']) {
            return array('state' => 'not_published');
        }
        $site = self::site();
        return array(
            'state' => 'available',
            'site' => $site,
            'cohorts' => 'available' === $cohorts['state'] ? $cohorts['body'] : null,
            'impact' => 'available' === $impact['state'] ? $impact['body'] : null,
            'pages' => 'available' === $cohorts['state'] ? self::page_rows($cohorts['body'], $site) : array(),
            'plugins' => 'available' === $impact['state'] ? self::plugin_rows($impact['body'], $site['plugins']) : array(),
        );
    }

    /**
     * One GET. A 404 (the route does not exist yet) and the API's own publication_unavailable
     * (the route exists but no build has published the document) are both what superspeedy.org
     * says about publication, so both are reported as "not published yet". Anything else is an
     * error and keeps its detail.
     */
    private static function fetch($name) {
        $response = wp_remote_get(self::API_BASE . $name, array(
            'timeout' => 15,
            'redirection' => 0,
            // WordPress's default User-Agent includes this site's URL.
            'user-agent' => 'Super Speedy Performance Analysis/' . SSPA_VERSION,
            'headers' => array('Accept' => 'application/json'),
        ));
        $url = self::API_BASE . $name;
        if (is_wp_error($response)) {
            /* translators: 1: URL, 2: error message */
            return array('state' => 'error', 'detail' => sprintf(__('%1$s could not be reached: %2$s', 'super-speedy-performance-analysis'), $url, $response->get_error_message()));
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (404 === $status || (503 === $status && is_array($body) && 'publication_unavailable' === ($body['code'] ?? ''))) {
            return array('state' => 'not_published');
        }
        if (200 !== $status) {
            /* translators: 1: URL, 2: HTTP status code */
            return array('state' => 'error', 'detail' => sprintf(__('%1$s answered HTTP %2$d.', 'super-speedy-performance-analysis'), $url, $status));
        }
        if (!is_array($body) || !isset($body['status']) || !isset($body['cells']) || !is_array($body['cells'])) {
            /* translators: %s: URL */
            return array('state' => 'error', 'detail' => sprintf(__('%s did not return a figures document this version understands.', 'super-speedy-performance-analysis'), $url));
        }
        return array('state' => 'available', 'body' => $body);
    }

    /**
     * This site, from its latest completed full scan or spot check: the cohort the payload would
     * carry, the page classes it measured with their own medians, and the active plugins.
     */
    public static function site() {
        global $wpdb;
        $plugins = array();
        foreach ((array) get_option('active_plugins', array()) as $file) {
            $plugins[] = sanitize_key(false === strpos($file, '/') ? basename($file, '.php') : dirname($file));
        }
        $site = array('run_id' => 0, 'cohort' => null, 'taxonomy_version' => null, 'banding_version' => null, 'pages' => array(), 'plugins' => array_values(array_unique($plugins)));
        $run_id = SSPA_Report::latest_done_run_id();
        if (!$run_id) {
            return $site;
        }
        $run = SSPA_Run_Controller::run_row($run_id);
        $site['run_id'] = $run_id;
        if ($run && !empty($run['site_metrics_id'])) {
            $metrics = json_decode((string) $wpdb->get_var($wpdb->prepare('SELECT metrics FROM %i WHERE id = %d', SSPA_Schema::table('site_metrics'), (int) $run['site_metrics_id'])), true);
            if (is_array($metrics)) {
                $dimensions = SSPA_Site_Characteristics::cohort_dimensions($metrics);
                $object_cache = $dimensions['environment']['object_cache'];
                $site['cohort'] = array(
                    'primary_purpose' => isset($dimensions['classification']['primary_purpose']) ? $dimensions['classification']['primary_purpose'] : null,
                    'products_band' => $dimensions['sizes']['products'],
                    'active_plugins_band' => $dimensions['sizes']['active_plugins'],
                    'object_cache' => null === $object_cache ? null : ($object_cache ? 'enabled' : 'disabled'),
                    'database_family' => $dimensions['environment']['database_family'],
                );
                $site['taxonomy_version'] = isset($dimensions['classification']['taxonomy_version']) ? (int) $dimensions['classification']['taxonomy_version'] : null;
                $site['banding_version'] = isset($dimensions['sizes']['banding_version']) ? (int) $dimensions['sizes']['banding_version'] : null;
            }
        }
        $profiles = $wpdb->get_results($wpdb->prepare(
            "SELECT page_key, variant, object_cache_mode, page_gen_ms, ttfb_ms FROM %i WHERE run_id = %d AND plugin_set_hash = '' AND page_gen_ms IS NOT NULL ORDER BY id",
            SSPA_Schema::table('profiles'),
            $run_id
        ), ARRAY_A);
        foreach ($profiles as $profile) {
            $variant = in_array($profile['variant'], array('anon', 'admin', 'guest', 'customer'), true) ? $profile['variant'] : 'other';
            $key = SSPA_Community_Privacy::page_class($profile['page_key'], $profile['variant']) . '|' . $variant . '|' . sanitize_key($profile['object_cache_mode']);
            if (!isset($site['pages'][$key])) {
                $site['pages'][$key] = array(
                    'page_key' => $profile['page_key'],
                    'page_gen_ms' => null === $profile['page_gen_ms'] ? null : (float) $profile['page_gen_ms'],
                    'ttfb_ms' => null === $profile['ttfb_ms'] ? null : (float) $profile['ttfb_ms'],
                );
            }
        }
        return $site;
    }

    /**
     * For each page this site measured, the most specific published cell whose cohort is this
     * site's: rung d matches the site's first d ladder dimensions exactly, at the same taxonomy
     * and banding versions. Without a completed analysis there is no cohort, so every page class
     * is shown for all sites (rung 0).
     */
    public static function page_rows($document, $site) {
        $ladder = isset($document['ladder']['dimensions']) ? (array) $document['ladder']['dimensions'] : array();
        $same_ladder = $ladder === self::LADDER && self::LADDER_VERSION === (int) ($document['ladder']['version'] ?? 0);
        $wanted = $site['pages'];
        if (!$wanted) {
            foreach ($document['cells'] as $cell) {
                $wanted[$cell['page_class'] . '|' . $cell['variant'] . '|' . $cell['object_cache_mode']] = null;
            }
        }
        $rows = array();
        foreach ($wanted as $key => $own) {
            $best = null;
            foreach ($document['cells'] as $cell) {
                if (!is_array($cell) || $cell['page_class'] . '|' . $cell['variant'] . '|' . $cell['object_cache_mode'] !== $key) {
                    continue;
                }
                $rung = (int) $cell['rung'];
                if ($rung > 0 && !($same_ladder && self::in_cohort($cell, $rung, $site))) {
                    continue;
                }
                if (null === $best || $rung > (int) $best['rung']) {
                    $best = $cell;
                }
            }
            if ($best) {
                $rows[] = array('cell' => $best, 'own' => $own, 'key' => in_array($best['page_class'], self::KEY_PAGES, true));
            }
        }
        // Key pages first, in KEY_PAGES order; the rest keep the order the site measured them in.
        $position = array_flip(self::KEY_PAGES);
        $order = array_keys($rows);
        usort($order, static function ($a, $b) use ($rows, $position) {
            $rank_a = isset($position[$rows[$a]['cell']['page_class']]) ? $position[$rows[$a]['cell']['page_class']] : count($position);
            $rank_b = isset($position[$rows[$b]['cell']['page_class']]) ? $position[$rows[$b]['cell']['page_class']] : count($position);
            return $rank_a === $rank_b ? $a - $b : $rank_a - $rank_b;
        });
        $sorted = array();
        foreach ($order as $index) {
            $sorted[] = $rows[$index];
        }
        return $sorted;
    }

    private static function in_cohort($cell, $rung, $site) {
        if (!$site['cohort'] || !isset($cell['cohort']) || !is_array($cell['cohort']) || count($cell['cohort']) !== $rung) {
            return false;
        }
        if (($rung >= 1 && (int) ($cell['taxonomy_version'] ?? 0) !== (int) $site['taxonomy_version'])
            || ($rung >= 2 && (int) ($cell['banding_version'] ?? 0) !== (int) $site['banding_version'])) {
            return false;
        }
        foreach (array_slice(self::LADDER, 0, $rung) as $dimension) {
            if (null === $site['cohort'][$dimension] || !isset($cell['cohort'][$dimension]) || (string) $cell['cohort'][$dimension] !== (string) $site['cohort'][$dimension]) {
                return false;
            }
        }
        return true;
    }

    /** Plugin isolation cells for plugins active on this site only, ordered by plugin and page. */
    public static function plugin_rows($document, $active) {
        $rows = array();
        foreach ($document['cells'] as $cell) {
            if (is_array($cell) && isset($cell['slug']) && in_array((string) $cell['slug'], $active, true)) {
                $rows[] = $cell;
            }
        }
        usort($rows, static function ($a, $b) {
            return strcmp($a['slug'] . '|' . $a['page_class'], $b['slug'] . '|' . $b['page_class']);
        });
        return $rows;
    }

    // ---------------- rendering ----------------

    public static function render($result) {
        ob_start();
        $sspa_figures = $result;
        include SSPA_PLUGIN_DIR . 'includes/admin/partials/community-figures.php';
        return wp_kses(ob_get_clean(), self::allowed_html());
    }

    public static function allowed_html() {
        $cell = array('class' => true, 'scope' => true, 'colspan' => true);
        return array(
            'div' => array('class' => true, 'role' => true),
            'p' => array('class' => true),
            'h3' => array(), 'h4' => array(),
            'a' => array('href' => true, 'target' => true, 'rel' => true),
            'strong' => array(), 'code' => array(), 'br' => array(), 'small' => array(),
            'span' => array('class' => true),
            'ul' => array(), 'li' => array(),
            'table' => array('class' => true),
            'thead' => array(), 'tbody' => array('class' => true, 'hidden' => true),
            'button' => array('type' => true, 'class' => true, 'aria-expanded' => true),
            'tr' => array('data-page-class' => true, 'data-rung' => true, 'data-n' => true, 'data-confidence' => true, 'data-plugin' => true),
            'th' => $cell, 'td' => $cell,
        );
    }

    /** "Early data (1-2 installations)" from the document's own label boundaries. */
    public static function confidence_label($label, $document) {
        $names = array(
            'early_data' => __('Early data', 'super-speedy-performance-analysis'),
            'public_cohort' => __('Small cohort', 'super-speedy-performance-analysis'),
            'descriptive' => __('Descriptive', 'super-speedy-performance-analysis'),
            'comparison' => __('Comparison', 'super-speedy-performance-analysis'),
            'ranking' => __('Ranking', 'super-speedy-performance-analysis'),
        );
        $name = isset($names[$label]) ? $names[$label] : (string) $label;
        foreach ((array) ($document['confidence_labels'] ?? array()) as $band) {
            if (($band['label'] ?? '') !== $label) {
                continue;
            }
            $minimum = (int) $band['minimum_installations'];
            if (isset($band['maximum_installations'])) {
                /* translators: 1: confidence label, 2: lowest installation count, 3: highest installation count */
                return sprintf(__('%1$s (%2$d-%3$d installations)', 'super-speedy-performance-analysis'), $name, $minimum, (int) $band['maximum_installations']);
            }
            /* translators: 1: confidence label, 2: lowest installation count */
            return sprintf(__('%1$s (%2$d+ installations)', 'super-speedy-performance-analysis'), $name, $minimum);
        }
        return $name;
    }

    public static function installations($n) {
        /* translators: %s: number of installations */
        return sprintf(_n('%s installation', '%s installations', (int) $n, 'super-speedy-performance-analysis'), number_format_i18n((int) $n));
    }

    public static function ms($value) {
        return null === $value ? '-' : number_format_i18n((float) $value, 1);
    }

    public static function dimension_label($dimension) {
        $labels = array(
            'primary_purpose' => __('site type', 'super-speedy-performance-analysis'),
            'products_band' => __('product count', 'super-speedy-performance-analysis'),
            'active_plugins_band' => __('active plugins', 'super-speedy-performance-analysis'),
            'object_cache' => __('object cache', 'super-speedy-performance-analysis'),
            'database_family' => __('database', 'super-speedy-performance-analysis'),
        );
        return isset($labels[$dimension]) ? $labels[$dimension] : $dimension;
    }

    /** "ecommerce, <100 products, <10 active plugins, object cache enabled, mariadb". */
    public static function describe_cohort($cohort) {
        if (!$cohort) {
            return __('all sites', 'super-speedy-performance-analysis');
        }
        $parts = array();
        foreach (self::LADDER as $dimension) {
            if (isset($cohort[$dimension]) && null !== $cohort[$dimension]) {
                $parts[] = self::dimension_label($dimension) . ': ' . $cohort[$dimension];
            }
        }
        return implode(', ', $parts);
    }
}
