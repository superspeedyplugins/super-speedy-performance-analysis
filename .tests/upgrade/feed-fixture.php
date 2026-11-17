<?php
/**
 * Plugin Name: SSPA upgrade test - update feed and package stand-in
 *
 * Test-only MU plugin for .tests/upgrade/run.sh. The release under test is not deployed yet, so the
 * update feed (www.superspeedyplugins.com/assets/plugins/<slug>.json) is answered from the option
 * zz_upgrade_feed (the live JSON with only "version" changed). The package download is real unless
 * zz_upgrade_package names a local zip, which is then served for the URL in zz_upgrade_package_url.
 * Every update-related HTTP request is logged to zz_upgrade_log, with the licence header's presence
 * (never its value).
 */
defined('ABSPATH') || exit;

add_filter('pre_http_request', static function ($pre, $args, $url) {
    $log = static function ($entry) {
        $all = get_option('zz_upgrade_log', array());
        $all[] = $entry + array('t' => gmdate('H:i:s'), 'ajax' => wp_doing_ajax());
        update_option('zz_upgrade_log', $all, false);
    };
    $feed = get_option('zz_upgrade_feed');
    if ($feed && preg_match('#/assets/plugins/super-speedy-performance-analysis(-pro)?\.json#', $url, $m)) {
        $log(array('url' => $url, 'served' => 'feed fixture'));
        if (!empty($m[1])) {
            return array('headers' => array(), 'body' => 'Not Found', 'response' => array('code' => 404, 'message' => 'Not Found'), 'cookies' => array(), 'filename' => null);
        }
        return array('headers' => array('content-type' => 'application/json'), 'body' => $feed, 'response' => array('code' => 200, 'message' => 'OK'), 'cookies' => array(), 'filename' => null);
    }
    $package = get_option('zz_upgrade_package');
    $package_url = (string) get_option('zz_upgrade_package_url');
    if (false !== strpos($url, 'super-speedy-performance-analysis') && (false !== strpos($url, '.zip') || false !== strpos($url, '/download/'))) {
        $headers = isset($args['headers']) && is_array($args['headers']) ? $args['headers'] : array();
        $entry = array('url' => $url, 'licence_header' => isset($headers['X-SSP-License-Key']));
        if ('404' === $package && $package_url && 0 === strpos($url, $package_url)) {
            $log($entry + array('served' => '404 stand-in (no asset with this name)'));
            return array('headers' => array(), 'body' => 'Not Found', 'response' => array('code' => 404, 'message' => 'Not Found'), 'cookies' => array(), 'filename' => null);
        }
        if ($package && $package_url && 0 === strpos($url, $package_url) && is_file($package)) {
            if (!empty($args['filename'])) {
                copy($package, $args['filename']);
            }
            $log($entry + array('served' => 'local ' . basename($package)));
            return array('headers' => array(), 'body' => '', 'response' => array('code' => 200, 'message' => 'OK'), 'cookies' => array(), 'filename' => isset($args['filename']) ? $args['filename'] : null);
        }
        $log($entry + array('served' => 'real network'));
    }
    return $pre;
}, 10, 3);

// What the upgrader was handed and where it put it.
add_filter('upgrader_source_selection', static function ($source) {
    $all = get_option('zz_upgrade_log', array());
    $all[] = array('source_selection' => basename(untrailingslashit($source)), 'ajax' => wp_doing_ajax(), 't' => gmdate('H:i:s'));
    update_option('zz_upgrade_log', $all, false);
    return $source;
}, PHP_INT_MAX);
add_action('upgrader_process_complete', static function ($upgrader, $extra) {
    $all = get_option('zz_upgrade_log', array());
    $all[] = array('complete' => $extra, 'result_destination' => isset($upgrader->result['destination_name']) ? $upgrader->result['destination_name'] : null, 't' => gmdate('H:i:s'));
    update_option('zz_upgrade_log', $all, false);
}, 10, 2);
