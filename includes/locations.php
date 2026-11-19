<?php
defined('ABSPATH') || exit;

// WordPress locations, defined once under the plugin prefix. Standalone on purpose: the main
// plugin and the MU-armed profiler both require this file, so the profiler never depends on
// the main plugin having loaded. A Fast Ajax/REST trim or a plugin organiser can leave the
// main plugin out of a profiled request; when these lived in the main plugin file, every such
// capture fataled at shutdown on SSPA_CORE_DIR (issue #47, 0.43.14 to 0.49.4).
// Loaded once per request, whichever copy loads it first: with the Pro and wordpress.org editions
// both installed, the profiler or traffic observer can belong to one and the plugin to the other.
if (defined('SSPA_CORE_DIR')) {
    return;
}
define('SSPA_CONTENT_DIR', WP_CONTENT_DIR);
define('SSPA_MU_DIR', WPMU_PLUGIN_DIR);
define('SSPA_PLUGINS_DIR', WP_PLUGIN_DIR);
define('SSPA_CORE_DIR', ABSPATH);

// URL tests against the locations WordPress reports, never against literal core paths: the admin,
// the login page and the REST prefix can all be moved or renamed (wp.org review round 3,
// 19 November 2026). Loaded by the main plugin, the profiler and the traffic observer.

/** The path of a URL or request URI, always starting with '/'. */
function sspa_url_path($url) {
    $path = wp_parse_url((string) $url, PHP_URL_PATH);
    return '/' . ltrim(is_string($path) ? $path : '', '/');
}

/** Whether a URL or request URI is $base's path or below it. */
function sspa_url_is_under($url, $base) {
    $base = untrailingslashit(sspa_url_path($base));
    $path = sspa_url_path($url);
    return $path === $base || 0 === stripos($path, $base . '/');
}

/** Whether a URL is in wp-admin, wherever admin_url() puts it. */
function sspa_is_admin_url($url) {
    return sspa_url_is_under($url, admin_url('/'));
}

/** Whether a URL is the login page, wherever wp_login_url() puts it. */
function sspa_is_login_url($url) {
    return '' !== (string) $url && untrailingslashit(sspa_url_path($url)) === untrailingslashit(sspa_url_path(wp_login_url()));
}

/** Whether a URL is a REST request: under the REST prefix (pretty or index permalinks), or ?rest_route=. */
function sspa_is_rest_url($url) {
    $url = (string) $url;
    return false !== strpos(trailingslashit(sspa_url_path($url)), '/' . trim(rest_get_url_prefix(), '/') . '/')
        || false !== strpos($url, 'rest_route=');
}
