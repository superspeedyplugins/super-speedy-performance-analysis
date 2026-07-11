<?php
defined('ABSPATH') || exit;

/**
 * Rules access. Phase 2: bundled snapshot only. Phase 5 adds the signed superspeedy.org
 * feed with this file as offline fallback - keep the accessor API stable.
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

    public static function sector_signatures() {
        $data = self::load();
        return isset($data['sector_signatures']) ? $data['sector_signatures'] : array();
    }
}
