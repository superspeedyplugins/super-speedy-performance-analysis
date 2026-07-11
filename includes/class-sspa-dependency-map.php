<?php
defined('ABSPATH') || exit;

/**
 * Which plugins may safely be virtually excluded. Two sources: core's `Requires Plugins`
 * headers (a plugin that others require is a dependency root - never exclude it while its
 * dependants are active) and the rules snapshot's fragile list (security plugins etc that
 * must never be excluded behind the site owner's back).
 */
class SSPA_Dependency_Map {

    /**
     * @return array slug => [slugs that require it]
     */
    public static function required_by() {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $map = array();
        $active = (array) get_option('active_plugins', array());
        foreach ($active as $file) {
            $headers = get_file_data(WP_PLUGIN_DIR . '/' . $file, array('RequiresPlugins' => 'Requires Plugins'));
            $requires = array_filter(array_map('trim', explode(',', (string) $headers['RequiresPlugins'])));
            $slug = dirname($file) !== '.' ? dirname($file) : basename($file, '.php');
            foreach ($requires as $required_slug) {
                $map[$required_slug][] = $slug;
            }
        }
        return $map;
    }

    public static function fragile() {
        $rules = SSPA_Rules::categories();
        $fragile = isset($rules['security']) ? $rules['security'] : array();
        $extra = SSPA_Rules::fragile();
        return array_values(array_unique(array_merge($fragile, $extra)));
    }

    /**
     * Active plugin slugs that are safe candidates for bisection: not us, not fragile,
     * not a dependency root of another active plugin, not WooCommerce on a Woo site
     * (excluding it fatals most storefront pages - it can still be a single-out suspect,
     * where a fatal is itself recorded as evidence).
     */
    public static function bisect_candidates() {
        $active = array();
        foreach ((array) get_option('active_plugins', array()) as $file) {
            $active[] = dirname($file) !== '.' ? dirname($file) : basename($file, '.php');
        }
        $exclude = array_merge(
            array('super-speedy-performance-analysis'),
            self::fragile(),
            array_keys(self::required_by())
        );
        return array_values(array_diff($active, $exclude));
    }

    /**
     * slug => plugin file (dir/file.php) for the active set.
     */
    public static function slug_to_file() {
        $map = array();
        foreach ((array) get_option('active_plugins', array()) as $file) {
            $slug = dirname($file) !== '.' ? dirname($file) : basename($file, '.php');
            $map[$slug] = $file;
        }
        return $map;
    }
}
