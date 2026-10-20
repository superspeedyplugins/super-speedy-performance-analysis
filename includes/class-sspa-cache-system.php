<?php
defined('ABSPATH') || exit;

/**
 * The site's and server's cache system as WordPress and PHP can read it, for the Cache
 * performance tab. Local reads only: active plugins, drop-in headers, PHP's own OPcache and
 * object cache state, and one WooCommerce option. Nothing is sent anywhere.
 */
class SSPA_Cache_System {

    const AJAX_PRICES_SLUG = 'super-speedy-ajax-prices';

    public static function inventory() {
        return array(
            'woocommerce' => class_exists('WooCommerce'),
            'multisite' => is_multisite(),
            'page_cache_plugins' => self::active_page_cache_plugins(),
            'advanced_cache_dropin' => self::advanced_cache_dropin(),
            'server_software' => self::server_software(),
            'object_cache' => self::object_cache(),
            'opcache' => self::opcache(),
            'ajax_prices_active' => in_array(self::AJAX_PRICES_SLUG, self::active_slugs(), true),
            'customer_location' => self::customer_location(),
        );
    }

    /** Active plugin slugs, network-activated ones included. */
    public static function active_slugs() {
        $files = (array) get_option('active_plugins', array());
        if (is_multisite()) {
            $files = array_merge($files, array_keys((array) get_site_option('active_sitewide_plugins', array())));
        }
        $slugs = array();
        foreach ($files as $file) {
            $file = strtolower((string) $file);
            $slugs[] = '.' === dirname($file) ? basename($file, '.php') : dirname($file);
        }
        return array_values(array_unique($slugs));
    }

    /** Active plugin basenames (directory/file.php), network-activated ones included. */
    public static function active_files() {
        $files = (array) get_option('active_plugins', array());
        if (is_multisite()) {
            $files = array_merge($files, array_keys((array) get_site_option('active_sitewide_plugins', array())));
        }
        return array_values(array_unique(array_map('strtolower', array_map('strval', $files))));
    }

    /**
     * Page-cache plugins and page-cache integrations that are active. One list for the whole
     * plugin: the rules snapshot's page-cache category plus its integrations.
     *
     * @return array[] {slug, title, kind: page_cache|integration}
     */
    public static function active_page_cache_plugins() {
        $categories = SSPA_Rules::categories();
        $page_cache = isset($categories['page-cache']) ? (array) $categories['page-cache'] : array();
        $integrations = SSPA_Rules::page_cache_integrations();
        $names = self::plugin_names();
        $out = array();
        foreach (self::active_slugs() as $slug) {
            $kind = in_array($slug, $page_cache, true) ? 'page_cache' : (in_array($slug, $integrations, true) ? 'integration' : '');
            if ('' === $kind) {
                continue;
            }
            $out[] = array(
                'slug' => sanitize_key($slug),
                'title' => isset($names[$slug]) ? $names[$slug] : $slug,
                'kind' => $kind,
            );
        }
        return $out;
    }

    private static function plugin_names() {
        if (!function_exists('get_plugins')) {
            require_once SSPA_CORE_DIR . 'wp-admin/includes/plugin.php';
        }
        $names = array();
        foreach (get_plugins() as $file => $data) {
            $slug = strtolower('.' === dirname($file) ? basename($file, '.php') : dirname($file));
            $names[$slug] = sanitize_text_field($data['Name']);
        }
        return $names;
    }

    /** The advanced-cache.php drop-in's own header, or null when there is none. */
    private static function advanced_cache_dropin() {
        if (!file_exists(SSPA_CONTENT_DIR . '/advanced-cache.php')) {
            return null;
        }
        if (!function_exists('get_dropins')) {
            require_once SSPA_CORE_DIR . 'wp-admin/includes/plugin.php';
        }
        $dropins = get_dropins();
        $data = isset($dropins['advanced-cache.php']) ? $dropins['advanced-cache.php'] : array();
        return array(
            'present' => true,
            'title' => sanitize_text_field(isset($data['Name']) ? $data['Name'] : ''),
            'version' => sanitize_text_field(isset($data['Version']) ? $data['Version'] : ''),
        );
    }

    /** The web server family, reduced to a closed list of words. Never the version string. */
    public static function server_software() {
        $raw = isset($_SERVER['SERVER_SOFTWARE']) ? strtolower(sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE']))) : '';
        if ('' === $raw) {
            return 'unknown';
        }
        foreach (array(
            'openlitespeed' => 'openlitespeed',
            'litespeed' => 'litespeed',
            'nginx' => 'nginx',
            'apache' => 'apache',
            'microsoft-iis' => 'iis',
            'caddy' => 'caddy',
        ) as $needle => $family) {
            if (false !== strpos($raw, $needle)) {
                return $family;
            }
        }
        return 'other';
    }

    private static function object_cache() {
        $capability = SSPA_Object_Cache_Capability::inspect();
        return array(
            'persistent' => !empty($capability['persistent']),
            'management' => isset($capability['category']) ? $capability['category'] : 'unknown',
            'technology' => !empty($capability['persistent']) ? SSPA_Demographics::object_cache_technology() : null,
        );
    }

    /**
     * PHP's OPcache status for the PHP process answering this request. When the host
     * restricts the status API to another path, the tab says it is not readable rather than
     * guessing; calling it anyway would only raise a warning.
     */
    public static function opcache() {
        if (!function_exists('opcache_get_status')) {
            return array('readable' => false, 'enabled' => false, 'reason' => 'extension_not_loaded');
        }
        $restrict = (string) ini_get('opcache.restrict_api');
        if ('' !== $restrict && 0 !== strpos(__FILE__, $restrict)) {
            return array('readable' => false, 'enabled' => null, 'reason' => 'restricted_by_opcache_restrict_api');
        }
        $status = opcache_get_status(false);
        if (!is_array($status)) {
            return array(
                'readable' => false,
                'enabled' => false,
                'reason' => filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN) ? 'status_unavailable' : 'disabled',
            );
        }
        $memory = isset($status['memory_usage']) ? (array) $status['memory_usage'] : array();
        $stats = isset($status['opcache_statistics']) ? (array) $status['opcache_statistics'] : array();
        return array(
            'readable' => true,
            'enabled' => !empty($status['opcache_enabled']),
            'reason' => '',
            'memory_used_bytes' => isset($memory['used_memory']) ? (int) $memory['used_memory'] : null,
            'memory_free_bytes' => isset($memory['free_memory']) ? (int) $memory['free_memory'] : null,
            'memory_wasted_bytes' => isset($memory['wasted_memory']) ? (int) $memory['wasted_memory'] : null,
            'hit_rate' => isset($stats['opcache_hit_rate']) ? round((float) $stats['opcache_hit_rate'], 2) : null,
            'cached_scripts' => isset($stats['num_cached_scripts']) ? (int) $stats['num_cached_scripts'] : null,
            'restarts' => (int) ($stats['oom_restarts'] ?? 0) + (int) ($stats['hash_restarts'] ?? 0) + (int) ($stats['manual_restarts'] ?? 0),
        );
    }

    /** WooCommerce's "Default customer location" setting, or '' without WooCommerce. */
    private static function customer_location() {
        if (!class_exists('WooCommerce')) {
            return '';
        }
        return sanitize_key((string) get_option('woocommerce_default_customer_address', ''));
    }

    /**
     * Active plugins whose basename matches a price-affecting fragment. Our own Ajax Prices
     * add-ons are excluded: they exist to make cached prices correct.
     *
     * @return string[] Plugin names.
     */
    public static function price_affecting_plugins() {
        $rules = SSPA_Rules::price_affecting();
        $names = self::plugin_names();
        $found = array();
        foreach (self::active_files() as $file) {
            $excluded = false;
            foreach ($rules['exclude'] as $fragment) {
                if (false !== strpos($file, strtolower($fragment))) {
                    $excluded = true;
                    break;
                }
            }
            if ($excluded || 0 === strpos($file, self::AJAX_PRICES_SLUG . '/')) {
                continue;
            }
            foreach ($rules['patterns'] as $fragment) {
                if (false !== strpos($file, strtolower($fragment))) {
                    $slug = '.' === dirname($file) ? basename($file, '.php') : dirname($file);
                    $found[$slug] = isset($names[$slug]) ? $names[$slug] : $slug;
                    break;
                }
            }
        }
        return array_values($found);
    }
}
