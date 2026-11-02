<?php
/**
 * Plugin Name: zz-page-cache (test fixture)
 * Description: A tiny advanced-cache.php page cache whose key behaviour a test controls. Installed as
 * wp-content/advanced-cache.php on a test site only. Configuration: wp-content/zz-page-cache.json
 *   {"enabled": true, "ignore_params": ["utm_source"], "bypass_params": ["gclid"], "ttl": 600}
 * Every other query parameter is part of the cache key. Like many page caches it refuses to store a
 * response carrying Set-Cookie, and it steps aside for logged-in and basket cookies. It reports itself
 * with X-Cache-Status: HIT, MISS or BYPASS. Profiling requests (X-SSPA-Token or sspa_nc) are never
 * cached or labelled.
 */
defined('ABSPATH') || exit;

(static function () {
    $config_file = WP_CONTENT_DIR . '/zz-page-cache.json';
    $config = is_readable($config_file) ? json_decode((string) file_get_contents($config_file), true) : null;
    if (!is_array($config) || empty($config['enabled'])) {
        return;
    }
    if ('GET' !== ($_SERVER['REQUEST_METHOD'] ?? '') || PHP_SAPI === 'cli') {
        return;
    }
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $path = (string) parse_url($uri, PHP_URL_PATH);
    if (!empty($_SERVER['HTTP_X_SSPA_TOKEN']) || isset($_GET['sspa_nc'])
        || preg_match('#^/(wp-admin|wp-login\.php|wp-json|wp-cron\.php|xmlrpc\.php)#', $path) || isset($_GET['rest_route'])) {
        return;
    }
    foreach (array_keys($_COOKIE) as $name) {
        if (preg_match('/^(wordpress_logged_in_|wp-postpass_|comment_author_|woocommerce_items_in_cart)/', (string) $name)) {
            header('X-Cache-Status: BYPASS');
            return;
        }
    }
    $query = array();
    parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
    foreach ((array) ($config['bypass_params'] ?? array()) as $param) {
        if (isset($query[$param])) {
            header('X-Cache-Status: BYPASS');
            return;
        }
    }
    foreach ((array) ($config['ignore_params'] ?? array()) as $param) {
        unset($query[$param]);
    }
    ksort($query);
    $key = md5(($_SERVER['HTTP_HOST'] ?? '') . $path . '?' . http_build_query($query));
    $dir = WP_CONTENT_DIR . '/cache/zz-page-cache';
    $file = $dir . '/' . $key . '.html';
    $ttl = (int) ($config['ttl'] ?? 600);
    if (is_readable($file) && time() - filemtime($file) < $ttl) {
        header('Content-Type: text/html; charset=UTF-8');
        header('X-Cache-Status: HIT');
        readfile($file);
        exit;
    }
    header('X-Cache-Status: MISS');
    ob_start(static function ($html) use ($dir, $file) {
        if (200 !== http_response_code() || '' === $html || false === stripos($html, '</html>')) {
            return $html;
        }
        foreach (headers_list() as $line) {
            if (0 === stripos($line, 'Set-Cookie:')) {
                return $html; // refuses responses carrying Set-Cookie
            }
        }
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        @file_put_contents($file . '.tmp', $html);
        @rename($file . '.tmp', $file);
        return $html;
    });
})();
