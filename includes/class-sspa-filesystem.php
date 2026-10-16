<?php
defined('ABSPATH') || exit;

/** Local files only. Direct access matches PHP's permissions without FTP prompts. */
class SSPA_Filesystem {
    public static function direct() {
        if (!class_exists('WP_Filesystem_Direct')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
        }
        return new WP_Filesystem_Direct(false);
    }
    public static function put_contents($path, $content, $mode = 0644) {
        return self::direct()->put_contents($path, $content, $mode);
    }
    public static function get_contents($path, $include_path = false, $context = null, $offset = 0, $length = null) {
        $content = self::direct()->get_contents($path);
        return false === $content ? false : (null === $length ? substr($content, $offset) : substr($content, $offset, $length));
    }
}
