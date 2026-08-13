<?php
defined('ABSPATH') || exit;

/**
 * Privacy-safe reconnaissance for full-page caching and per-visitor output.
 *
 * The response scanner runs after a measured request has completed. It never stores HTML,
 * cookie values, nonce values or visible customer text. The source inventory runs after the
 * crawl, outside every timing sample, and reports candidates rather than claiming causation.
 */
class SSPA_Cache_Recon {

    const SCHEMA = 1;
    const MAX_SOURCE_FILES = 1200;
    const MAX_SOURCE_BYTES = 67108864; // 64 MB across the whole active stack.
    const MAX_FILE_BYTES = 1048576;
    const MAX_CANDIDATES = 40;

    /** Only shared-cache candidates. Private/system routes would add noise, not evidence. */
    public static function eligible_job($job) {
        if (empty($job['page_key']) || empty($job['url']) || 'anon' !== (isset($job['variant']) ? $job['variant'] : '')) {
            return false;
        }
        if (!empty($job['write']) || !empty($job['checkout']) || !empty($job['ps']) || !empty($job['oc_off'])) {
            return false;
        }
        $excluded = array(
            'baseline', 'mail-probe', '404', 'feed', 'rest-posts', 'search-many', 'search-zero',
            'wc-cart', 'wc-checkout', 'wc-myaccount',
        );
        return !in_array($job['page_key'], $excluded, true)
            && 0 !== strpos($job['page_key'], 'admin-')
            && 0 !== strpos($job['page_key'], 'write-')
            && 0 !== strpos($job['page_key'], 'flow-');
    }

    /**
     * Reduce a response to names, counts and structural markers. No raw response survives.
     *
     * @param string $body
     * @param array  $headers Lower-case response headers.
     * @param array  $capture SSPA capture, used only for component names.
     * @param bool   $partial True when the browser transport supplied only a prefix.
     */
    public static function scan_response($body, $headers, $capture = array(), $partial = false) {
        $body = (string) $body;
        $headers = is_array($headers) ? $headers : array();
        if (strlen($body) > 5242880) {
            $body = substr($body, 0, 5242880);
            $partial = true;
        }

        $nonce_names = array();
        $nonce_patterns = array(
            '/<input[^>]+name=["\']([^"\']*nonce[^"\']*)["\']/i',
            '/[?&;]((?:_wp)?[a-z0-9_\-]*nonce[a-z0-9_\-]*)=[a-f0-9]{8,12}\b/i',
            '/["\']([a-z0-9_\-]*nonce[a-z0-9_\-]*)["\']\s*[:=]\s*["\'][a-f0-9]{8,12}["\']/i',
            '/data-([a-z0-9_\-]*nonce[a-z0-9_\-]*)=/i',
        );
        foreach ($nonce_patterns as $pattern) {
            if (preg_match_all($pattern, $body, $matches)) {
                foreach ($matches[1] as $name) {
                    $name = strtolower(sanitize_key($name));
                    if ($name) {
                        $nonce_names[$name] = isset($nonce_names[$name]) ? $nonce_names[$name] + 1 : 1;
                    }
                }
            }
        }

        $regions = array();
        if (preg_match_all('/data-ssap-visitor\s*=\s*["\']([a-z0-9_-]+)["\']/i', $body, $matches)) {
            foreach ($matches[1] as $id) {
                $id = sanitize_key($id);
                if ($id) {
                    $regions[$id] = isset($regions[$id]) ? $regions[$id] + 1 : 1;
                }
            }
        }
        $auth = array('in' => 0, 'out' => 0);
        if (preg_match_all('/data-ssap-auth\s*=\s*["\'](in|out)["\']/i', $body, $matches)) {
            foreach ($matches[1] as $state) {
                $auth[strtolower($state)]++;
            }
        }

        $legacy = array();
        $cookie_access = 'document\.cookie|[.]cookie\b|getCookie\s*\(|readCookie\s*\(|Cookies\.get\s*\(|\$\.cookie\s*\(';
        foreach (array('wordpress_logged_in', 'woocommerce_items_in_cart', 'woocommerce_cart_hash') as $name) {
            $quoted = preg_quote($name, '/');
            if (preg_match('/(?:' . $cookie_access . ')[^;<]{0,140}' . $quoted . '|' . $quoted . '[^;<]{0,140}(?:' . $cookie_access . ')/is', $body)) {
                $legacy[] = $name;
            }
        }

        $private_hints = array();
        $hint_patterns = array(
            'wishlist' => 'wish(?:list| list)',
            'shortlist' => 'shortlist',
            'saved_search' => 'saved[-_ ]?(?:search|job|item)',
            'loyalty' => 'loyalty|reward[-_ ]?(?:point|balance)',
            'quote' => 'quote[-_ ]?(?:basket|cart|request)|request[-_ ]?a[-_ ]?quote',
            'booking' => 'booking|appointment',
            'downloads' => 'customer[-_ ]?download|my[-_ ]?download',
            'subscription' => 'my[-_ ]?subscription|subscription[-_ ]?(?:account|manage)',
        );
        // Attribute values and URLs only. Visible prose mentioning "wishlist" is not evidence
        // that a personalised feature exists on this page.
        $tag_attributes = '';
        if (preg_match_all('/<(?:a|form|div|section|aside|button)\b[^>]*(?:href|action|class|id|data-[a-z0-9_-]+)\s*=\s*["\'][^"\']+["\'][^>]*>/i', $body, $tags)) {
            $tag_attributes = implode("\n", array_slice($tags[0], 0, 1000));
        }
        foreach ($hint_patterns as $key => $pattern) {
            if ($tag_attributes && preg_match('/' . $pattern . '/i', $tag_attributes)) {
                $private_hints[] = $key;
            }
        }

        $set_cookie = self::set_cookie_names(isset($headers['set-cookie']) ? $headers['set-cookie'] : array());
        $cache_headers = array();
        foreach (array('cache-control', 'vary', 'age', 'x-cache', 'x-cache-status', 'x-litespeed-cache', 'cf-cache-status', 'x-fastcgi-cache') as $name) {
            if (!isset($headers[$name])) {
                continue;
            }
            $value = is_array($headers[$name]) ? implode(', ', $headers[$name]) : (string) $headers[$name];
            $cache_headers[$name] = substr(preg_replace('/[\r\n]+/', ' ', $value), 0, 300);
        }

        $components = array();
        if (!empty($capture['boot']['render']['components'])) {
            foreach (array_keys((array) $capture['boot']['render']['components']) as $component) {
                $component = sanitize_key($component);
                if ($component && !in_array($component, array('core', 'super-speedy-performance-analysis'), true)) {
                    $components[$component] = true;
                }
            }
        }

        return array(
            'schema' => self::SCHEMA,
            'bytes_scanned' => strlen($body),
            'partial' => (bool) $partial,
            'set_cookie_names' => array_slice($set_cookie, 0, 30),
            'nonce_names' => array_slice($nonce_names, 0, 30, true),
            'legacy_cookie_reads' => array_values(array_unique($legacy)),
            'client_state_reads' => array(
                'cookie' => preg_match_all('/document\.cookie|Cookies\.get\s*\(|getCookie\s*\(/i', $body),
                'local_storage' => preg_match_all('/\blocalStorage\b/i', $body),
                'session_storage' => preg_match_all('/\bsessionStorage\b/i', $body),
            ),
            'coverage' => array(
                'type_a_in' => $auth['in'],
                'type_a_out' => $auth['out'],
                'type_b_regions' => array_slice($regions, 0, 50, true),
                'cart_fragment_markers' => preg_match_all('/data-ssap-frag\s*=|class=["\'][^"\']*ssap-frag/i', $body),
            ),
            'private_surface_hints' => array_values(array_unique($private_hints)),
            'forms' => preg_match_all('/<form\b/i', $body),
            'cache_headers' => $cache_headers,
            'render_components' => array_slice(array_keys($components), 0, 30),
        );
    }

    private static function set_cookie_names($raw) {
        $values = is_array($raw) ? $raw : ('' === (string) $raw ? array() : array($raw));
        $names = array();
        foreach ($values as $value) {
            if (preg_match_all('/(?:^|,\s*)([!#$%&\'*+.^_`|~0-9A-Za-z-]+)=/', (string) $value, $matches)) {
                foreach ($matches[1] as $name) {
                    $name = sanitize_key($name);
                    if ($name) {
                        $names[$name] = true;
                    }
                }
            }
        }
        return array_keys($names);
    }

    /** Build the one consultant-facing assessment stored as a standalone finding. */
    public static function build_assessment($profiles, $captures, $source = null) {
        $pages = array();
        $observed = array();
        $totals = array(
            'set_cookie_pages' => 0,
            'nonce_pages' => 0,
            'legacy_cookie_pages' => 0,
            'client_state_pages' => 0,
            'private_surface_pages' => 0,
            'type_a_markers' => 0,
            'type_b_regions' => 0,
            'cart_fragment_markers' => 0,
            'partial_pages' => 0,
        );

        foreach ((array) $profiles as $profile) {
            $id = isset($profile['id']) ? (int) $profile['id'] : 0;
            $capture = isset($captures[$id]) ? $captures[$id] : array();
            if (empty($capture['cache_recon'])) {
                continue;
            }
            $scan = $capture['cache_recon'];
            $page = array(
                'page_key' => sanitize_key($profile['page_key']),
                'url' => esc_url_raw(isset($profile['url']) ? $profile['url'] : ''),
                'set_cookie_names' => array_values((array) $scan['set_cookie_names']),
                'nonce_names' => array_keys((array) $scan['nonce_names']),
                'legacy_cookie_reads' => array_values((array) $scan['legacy_cookie_reads']),
                'private_surface_hints' => array_values((array) $scan['private_surface_hints']),
                'partial' => !empty($scan['partial']),
                'coverage' => isset($scan['coverage']) ? $scan['coverage'] : array(),
            );
            $pages[] = $page;

            $totals['set_cookie_pages'] += $page['set_cookie_names'] ? 1 : 0;
            $totals['nonce_pages'] += $page['nonce_names'] ? 1 : 0;
            $totals['legacy_cookie_pages'] += $page['legacy_cookie_reads'] ? 1 : 0;
            $state_reads = array_sum(array_map('intval', (array) $scan['client_state_reads']));
            // localStorage is ubiquitous in modern frontend bundles. It becomes a hazard
            // lead only when the same page also exposes a private feature or a hard-coded
            // cache-sensitive cookie read.
            $relevant_client_state = $state_reads && ($page['private_surface_hints'] || $page['legacy_cookie_reads']);
            $totals['client_state_pages'] += $relevant_client_state ? 1 : 0;
            $totals['private_surface_pages'] += $page['private_surface_hints'] ? 1 : 0;
            $totals['type_a_markers'] += (int) ($page['coverage']['type_a_in'] ?? 0) + (int) ($page['coverage']['type_a_out'] ?? 0);
            $totals['type_b_regions'] += count((array) ($page['coverage']['type_b_regions'] ?? array()));
            $totals['cart_fragment_markers'] += (int) ($page['coverage']['cart_fragment_markers'] ?? 0);
            $totals['partial_pages'] += $page['partial'] ? 1 : 0;
            foreach ((array) $scan['render_components'] as $component) {
                $observed[sanitize_key($component)] = true;
            }
        }

        if (!$pages) {
            return null;
        }

        if (null === $source) {
            $source = self::source_inventory(array_keys($observed));
        }
        $candidates = isset($source['candidates']) ? (array) $source['candidates'] : array();
        $high = count(array_filter($candidates, function ($candidate) {
            return isset($candidate['risk']) && 'high' === $candidate['risk'];
        }));

        $active = array_map('strtolower', (array) get_option('active_plugins', array()));
        $woocommerce = class_exists('WooCommerce') || in_array('woocommerce/woocommerce.php', $active, true);
        $cache_plugin = false;
        foreach ($active as $plugin) {
            if (preg_match('#^(?:wp-rocket|litespeed-cache|w3-total-cache|wp-super-cache|cache-enabler|nginx-helper|cloudflare)/#', $plugin)) {
                $cache_plugin = true;
                break;
            }
        }

        $hazard_count = $totals['set_cookie_pages'] + $totals['nonce_pages']
            + $totals['legacy_cookie_pages'] + $totals['private_surface_pages'] + $high;
        if (!$woocommerce) {
            $qualification = 'outside_woocommerce_service';
        } elseif ($hazard_count > 0) {
            $qualification = 'strong_candidate';
        } else {
            $qualification = 'possible_candidate';
        }

        $difficulty_points = $totals['set_cookie_pages'] * 3
            + $totals['nonce_pages']
            + $totals['legacy_cookie_pages']
            + $totals['private_surface_pages']
            + min(5, $high)
            + (!empty($source['stored_code_not_scanned']) ? 2 : 0)
            + (!empty($source['truncated']) || $totals['partial_pages'] ? 1 : 0);
        $difficulty = ($difficulty_points >= 8) ? 'high' : (($difficulty_points >= 3) ? 'moderate' : 'low');

        $hazards = array();
        $hazard_map = array(
            'set_cookie_pages' => 'set_cookie_on_cache_candidate',
            'nonce_pages' => 'nonces_in_shared_html',
            'legacy_cookie_pages' => 'hard_coded_cookie_reads',
            'client_state_pages' => 'browser_state_driven_output',
            'private_surface_pages' => 'private_features_need_route_review',
            'partial_pages' => 'partial_browser_scan',
        );
        foreach ($hazard_map as $count_key => $hazard) {
            if ($totals[$count_key]) {
                $hazards[] = array('type' => $hazard, 'pages' => (int) $totals[$count_key]);
            }
        }
        if (!empty($source['stored_code_not_scanned'])) {
            $hazards[] = array('type' => 'stored_php_needs_manual_review', 'pages' => 0);
        }

        return array(
            'schema' => self::SCHEMA,
            'qualification' => $qualification,
            'difficulty' => $difficulty,
            'difficulty_points' => $difficulty_points,
            'woocommerce' => $woocommerce,
            'page_cache_plugin_detected' => $cache_plugin,
            'pages_scanned' => count($pages),
            'totals' => $totals,
            'hazards' => $hazards,
            'pages' => array_slice($pages, 0, 40),
            'candidate_components' => array_slice($candidates, 0, self::MAX_CANDIDATES),
            'source_scan' => array(
                'files_scanned' => (int) ($source['files_scanned'] ?? 0),
                'bytes_scanned' => (int) ($source['bytes_scanned'] ?? 0),
                'truncated' => !empty($source['truncated']),
                'stored_code_not_scanned' => !empty($source['stored_code_not_scanned']),
            ),
            'limitations' => array(
                'anonymous_response_only',
                'candidate_components_are_not_proven_owners',
                'type_a_vs_type_b_requires_controlled_identity_comparison',
                'external_javascript_and_edge_changes_not_executed',
            ),
        );
    }

    /**
     * Scan active code for combinations of visitor-state reads and front-end output sinks.
     * Paths in evidence are component-relative. Source and snippets never leave this site.
     */
    public static function source_inventory($observed_components = array()) {
        $observed = array_fill_keys(array_map('sanitize_key', (array) $observed_components), true);
        $targets = self::source_targets();
        $candidates = array();
        $files_scanned = 0;
        $bytes_scanned = 0;
        $truncated = false;

        foreach ($targets as $target) {
            foreach (self::source_files($target['path']) as $file) {
                if ($files_scanned >= self::MAX_SOURCE_FILES || $bytes_scanned >= self::MAX_SOURCE_BYTES) {
                    $truncated = true;
                    break 2;
                }
                $size = @filesize($file);
                if (!$size || $size > self::MAX_FILE_BYTES || $bytes_scanned + $size > self::MAX_SOURCE_BYTES) {
                    continue;
                }
                $code = @file_get_contents($file);
                if (false === $code) {
                    continue;
                }
                $files_scanned++;
                $bytes_scanned += strlen($code);
                $signals = self::scan_source_text($code, strtolower(pathinfo($file, PATHINFO_EXTENSION)));
                $file_score = self::source_signal_score($signals);
                if ($file_score < 3) {
                    continue;
                }
                $component = $target['component'];
                if (!isset($candidates[$component])) {
                    $candidates[$component] = array(
                        'component' => $component,
                        'risk' => 'low',
                        'score' => 0,
                        'observed_rendering' => isset($observed[$component]),
                        'always_relevant' => !empty($target['always_relevant']),
                        'signals' => array(),
                        'evidence' => array(),
                    );
                }
                foreach ($signals as $signal => $line) {
                    $candidates[$component]['signals'][$signal] = true;
                }
                $candidates[$component]['score'] = max($candidates[$component]['score'], $file_score);
                if (count($candidates[$component]['evidence']) < 8) {
                    $relative = ltrim(str_replace('\\', '/', substr($file, strlen(rtrim($target['path'], '/\\')))), '/');
                    if ('' === $relative) {
                        $relative = basename($file);
                    }
                    $candidates[$component]['evidence'][] = array(
                        'file' => substr($relative, 0, 240),
                        'line' => (int) min($signals),
                        'signals' => array_keys($signals),
                    );
                }
            }
        }

        foreach ($candidates as &$candidate) {
            $signals = array_keys($candidate['signals']);
            $score = $candidate['score'] + ($candidate['observed_rendering'] ? 2 : 0);
            $candidate['score'] = $score;
            $candidate['risk'] = ($score >= 7) ? 'high' : (($score >= 4) ? 'medium' : 'low');
            $candidate['signals'] = $signals;
        }
        unset($candidate);

        // An isolated echo or current-user read somewhere in a plugin is too weak to send a
        // consultant digging. Keep only corroborated candidates or components observed in the
        // render whose combined evidence reaches the same medium threshold.
        $candidates = array_filter($candidates, function ($candidate) {
            $signals = (array) $candidate['signals'];
            $intrinsic = in_array('cookie_setter', $signals, true)
                || (in_array('request_context', $signals, true) && in_array('price_filter', $signals, true));
            return $candidate['score'] >= 3
                && ($candidate['observed_rendering'] || $candidate['always_relevant'] || $intrinsic);
        });

        foreach ($candidates as &$candidate) {
            unset($candidate['always_relevant']);
        }
        unset($candidate);

        usort($candidates, function ($a, $b) {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }
            return strcmp($a['component'], $b['component']);
        });

        $stored_code = false;
        foreach ((array) get_option('active_plugins', array()) as $plugin) {
            if (false !== strpos(strtolower($plugin), 'code-snippets')) {
                $stored_code = true;
                break;
            }
        }

        return array(
            'files_scanned' => $files_scanned,
            'bytes_scanned' => $bytes_scanned,
            'truncated' => $truncated,
            'stored_code_not_scanned' => $stored_code,
            'candidates' => array_values($candidates),
        );
    }

    /** Public pure helper for deterministic tests and future source adapters. */
    public static function scan_source_text($code, $extension = 'php') {
        $code = (string) $code;
        if ('php' === $extension) {
            $code = self::without_php_comments($code);
        }
        $patterns = array(
            'visitor_state' => '/\b(?:is_user_logged_in|get_current_user_id|wp_get_current_user|current_user_can|get_user_meta|determine_current_user)\s*\(/i',
            'commerce_state' => '/WC\s*\(\s*\)\s*->\s*(?:session|cart|customer)|\b(?:wc_get_customer|woocommerce_items_in_cart|woocommerce_cart_hash|wp_woocommerce_session_)\b|\$_COOKIE\b|INPUT_COOKIE/i',
            'segmented_state' => '/\b(?:membership|member_level|wholesale|loyalty|reward_points?|wishlist|shortlist|currency|geolocation|customer_country|tax_location|user_role)\b/i',
            'render_registration' => '/\b(?:add_action|add_filter)\s*\(\s*["\'](?:wp_head|wp_footer|wp_body_open|the_content|the_excerpt|wp_nav_menu_[a-z_]+|widget_[a-z_]+|render_block|woocommerce_[a-z_]+)["\']|\b(?:add_shortcode|register_block_type|register_widget)\s*\(/i',
            'html_output' => '/\b(?:echo|print|printf)\b|return\s+["\']\s*</i',
            'nonce_emitter' => '/\b(?:wp_create_nonce|wp_nonce_field|wp_nonce_url|wp_localize_script)\s*\(/i',
            'cookie_setter' => '/\b(?:setcookie|wc_setcookie|wp_set_auth_cookie)\s*\(|Set-Cookie\s*:/i',
            'client_state' => '/document\.cookie|Cookies\.get\s*\(|getCookie\s*\(|\blocalStorage\b|\bsessionStorage\b/i',
            'request_context' => '/\b(?:is_admin|is_shop|is_product_taxonomy|is_front_page|is_singular|is_main_query|in_the_loop)\s*\(/i',
            'price_filter' => '/woocommerce_(?:get_price_html|variable_price_html|get_stock_html|loop_add_to_cart_link)/i',
        );
        $signals = array();
        foreach ($patterns as $signal => $pattern) {
            if (preg_match($pattern, $code, $match, PREG_OFFSET_CAPTURE)) {
                $signals[$signal] = 1 + substr_count(substr($code, 0, $match[0][1]), "\n");
            }
        }
        return $signals;
    }

    private static function source_signal_score($signals) {
        if (!$signals) {
            return 0;
        }
        $names = array_keys($signals);
        $has_state = (bool) array_intersect(array('visitor_state', 'commerce_state', 'segmented_state'), $names);
        $has_sink = (bool) array_intersect(array('render_registration', 'html_output'), $names);
        $score = 0;
        $score = max($score, in_array('cookie_setter', $names, true) ? 6 : 0);
        $score = max($score, ($has_state && $has_sink) ? 5 : 0);
        $score = max($score, (in_array('nonce_emitter', $names, true) && $has_sink) ? 4 : 0);
        $score = max($score, (in_array('client_state', $names, true) && in_array('segmented_state', $names, true)) ? 4 : 0);
        $score = max($score, (in_array('request_context', $names, true) && in_array('price_filter', $names, true)) ? 5 : 0);
        return $score;
    }

    private static function without_php_comments($code) {
        $out = '';
        foreach (token_get_all($code) as $token) {
            if (is_array($token) && in_array($token[0], array(T_COMMENT, T_DOC_COMMENT), true)) {
                $out .= preg_replace('/[^\r\n]/', ' ', $token[1]);
            } else {
                $out .= is_array($token) ? $token[1] : $token;
            }
        }
        return $out;
    }

    private static function source_targets() {
        $targets = array();
        $active = (array) get_option('active_plugins', array());
        if (is_multisite()) {
            $active = array_merge($active, array_keys((array) get_site_option('active_sitewide_plugins', array())));
        }
        foreach (array_unique($active) as $entry) {
            $slug = ('.' !== dirname($entry)) ? dirname($entry) : basename($entry, '.php');
            // Platform/cache plumbing is expected to contain auth, cookies and output code.
            // It is not where a consultancy starts looking for bespoke personalised regions.
            if (in_array($slug, array(
                'super-speedy-performance-analysis', 'woocommerce', 'redis-cache',
                'super-speedy-ajax-prices', 'ssap-addon-cache-optimisation',
            ), true)) {
                continue;
            }
            $path = ('.' !== dirname($entry)) ? WP_PLUGIN_DIR . '/' . dirname($entry) : WP_PLUGIN_DIR . '/' . $entry;
            if (file_exists($path)) {
                $targets[$slug] = array('component' => sanitize_key($slug), 'path' => $path, 'always_relevant' => false);
            }
        }

        $theme = wp_get_theme();
        if ($theme && $theme->exists()) {
            $targets['theme:' . $theme->get_stylesheet()] = array(
                'component' => sanitize_key($theme->get_stylesheet()),
                'path' => $theme->get_stylesheet_directory(),
                'always_relevant' => true,
            );
            if ($theme->get_template() !== $theme->get_stylesheet()) {
                $parent = wp_get_theme($theme->get_template());
                $targets['theme:' . $parent->get_stylesheet()] = array(
                    'component' => sanitize_key($parent->get_stylesheet()),
                    'path' => $parent->get_stylesheet_directory(),
                    'always_relevant' => true,
                );
            }
        }
        if (defined('WPMU_PLUGIN_DIR') && is_dir(WPMU_PLUGIN_DIR)) {
            $targets['mu-plugins'] = array('component' => 'mu-plugins', 'path' => WPMU_PLUGIN_DIR, 'always_relevant' => true);
        }
        return array_values($targets);
    }

    private static function source_files($path) {
        if (is_file($path)) {
            return preg_match('/\.(?:php|js)$/i', $path) ? array($path) : array();
        }
        if (!is_dir($path)) {
            return array();
        }
        $files = array();
        try {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $item) {
                if (!$item->isFile() || $item->isLink()) {
                    continue;
                }
                $file = str_replace('\\', '/', $item->getPathname());
                if (preg_match('#/(?:vendor|dependencies|node_modules|\.git|\.tests?|tests?|languages)/#i', $file)
                    || preg_match('#/(?:assets/client/admin|includes/admin|admin)/#i', $file)
                    || preg_match('#/(?:patterns)/#i', $file)
                    || 'sspa-loader.php' === basename($file)
                    || preg_match('/\.min\.js$/i', $file)
                    || !preg_match('/\.(?:php|js)$/i', $file)) {
                    continue;
                }
                $files[] = $item->getPathname();
            }
        } catch (UnexpectedValueException $e) {
            return array();
        }
        sort($files);
        return $files;
    }
}
