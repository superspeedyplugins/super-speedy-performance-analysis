<?php
defined('ABSPATH') || exit;

/**
 * Rules access: bundled snapshot only.
 */
class SSPA_Rules {

    private static $data = null;

    private static function load() {
        if (self::$data === null) {
            $json = file_get_contents(SSPA_PLUGIN_DIR . 'rules/rules-snapshot.json');
            self::$data = $json ? json_decode($json, true) : array();
            if (!is_array(self::$data)) {
                self::$data = array();
            }

        }
        return self::$data;
    }

    public static function threshold($key) {
        $data = self::load();
        return isset($data['thresholds'][$key]) ? $data['thresholds'][$key] : null;
    }

    /**
     * @return array {title, body, link?}
     */
    public static function recommendation($key) {
        $data = self::load();
        if (isset($data['recommendations'][$key])) {
            return $data['recommendations'][$key];
        }
        return array('title' => $key, 'body' => '');
    }

    public static function categories() {
        $data = self::load();
        return isset($data['categories']) ? $data['categories'] : array();
    }

    /**
     * Meta keys that belong to WordPress core, WooCommerce or a widely-installed plugin, and
     * so identify software rather than a site.
     *
     * Used as an allowlist when sharing an archive profile: a recognised key is shared by name
     * because "_price" says something about WooCommerce, while an unrecognised key is reported
     * as "custom" because "_acme_client_ref" says something about one business. Meta keys would
     * otherwise be the first site-authored strings ever to leave an install - option names are
     * not shared, they only live in the local capture.
     *
     * Updated with the bundled snapshot in plugin releases.
     */
    public static function known_meta_keys() {
        $data = self::load();
        return isset($data['known_meta_keys']) ? (array) $data['known_meta_keys'] : array();
    }

    public static function sector_signatures() {
        $data = self::load();
        return isset($data['sector_signatures']) ? $data['sector_signatures'] : array();
    }

    /**
     * The versioned map from public content types and plugin slugs to canonical site
     * purposes. Versioned because the receiver has to know which mapping produced a
     * classification: a site does not change when we improve the taxonomy, but its label may.
     *
     * The bundled snapshot carries the mapping version.
     *
     * @return array {version, labels[], content_types{}, plugin_slugs{}, content_classes{}}
     */
    public static function purpose_taxonomy() {
        $data = self::load();
        $taxonomy = isset($data['purpose_taxonomy']) ? $data['purpose_taxonomy'] : array();
        return array(
            'version' => isset($taxonomy['version']) ? (int) $taxonomy['version'] : 0,
            'labels' => isset($taxonomy['labels']) ? (array) $taxonomy['labels'] : array(),
            'content_types' => isset($taxonomy['content_types']) ? (array) $taxonomy['content_types'] : array(),
            'plugin_slugs' => isset($taxonomy['plugin_slugs']) ? (array) $taxonomy['plugin_slugs'] : array(),
            'content_classes' => isset($taxonomy['content_classes']) ? (array) $taxonomy['content_classes'] : array(),
        );
    }

    public static function fragile() {
        $data = self::load();
        return isset($data['fragile']) ? $data['fragile'] : array();
    }

    /**
     * Drop the in-memory bundled copy.
     */
    public static function flush() {
        self::$data = null;
    }
}
