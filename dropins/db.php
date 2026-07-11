<?php
/**
 * Super Speedy Performance Analysis - conditional database drop-in.
 *
 * Inert for normal traffic: without a valid profiling token this file returns without
 * creating $wpdb, so WordPress instantiates its stock class with zero overhead. For
 * token-bearing requests it defines SAVEQUERIES and swaps in a profiling wpdb subclass
 * that records row counts, errors and backtraces per query.
 *
 * Installed and removed automatically by the Super Speedy Performance Analysis plugin.
 * Version: %%SSPA_VERSION%%
 */

// TEMPLATE: placeholders are replaced at install time by SSPA_Helper_Files.
if (!defined('ABSPATH')) {
    exit;
}
if (empty($_SERVER['HTTP_X_SSPA_TOKEN']) || !defined('DB_USER')) {
    return;
}
if (defined('WP_INSTALLING') && WP_INSTALLING) {
    return;
}

if (!function_exists('sspa_token_verify')) {
    // Copy of the canonical implementation in includes/class-sspa-token.php - keep in sync.
    function sspa_token_verify($header_value, $request_path, $secret) {
        $parts = explode('.', (string) $header_value);
        if (count($parts) !== 4) {
            return false;
        }
        list($id, $expiry, $flag_str, $sig) = $parts;
        if (!preg_match('/^[a-f0-9]{32}$/', $id) || (int) $expiry < time()) {
            return false;
        }
        $expected = hash_hmac('sha256', $id . '|' . $expiry . '|' . $flag_str . '|' . $request_path, $secret);
        if (!hash_equals($expected, $sig)) {
            return false;
        }
        $flags = array();
        if ($flag_str !== '-') {
            foreach (explode(';', $flag_str) as $pair) {
                $kv = explode(':', $pair, 2);
                if (count($kv) === 2) {
                    $flags[$kv[0]] = $kv[1];
                }
            }
        }
        return array('id' => $id, 'flags' => $flags);
    }
}

$sspa_shim_tok = sspa_token_verify(
    $_SERVER['HTTP_X_SSPA_TOKEN'],
    isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/',
    '%%SSPA_SECRET%%'
);
if (!$sspa_shim_tok) {
    unset($sspa_shim_tok);
    return;
}

// Cache-impact runs: prevent the object-cache drop-in loading for this request only.
// db.php is the only code that runs early enough to register this filter in time.
if (isset($sspa_shim_tok['flags']['oc']) && '0' === $sspa_shim_tok['flags']['oc']) {
    add_filter('enable_loading_object_cache_dropin', '__return_false');
}

// Baseline requests need no profiling wpdb.
if (!empty($sspa_shim_tok['flags']['bl'])) {
    unset($sspa_shim_tok);
    return;
}

if (!defined('SAVEQUERIES')) {
    define('SAVEQUERIES', true);
}

$sspa_shim_class = '%%SSPA_PLUGIN_DIR%%profiler/class-sspa-profiling-wpdb.php';
if (file_exists($sspa_shim_class)) {
    require_once $sspa_shim_class;
    if (class_exists('SSPA_Profiling_WPDB')) {
        $GLOBALS['wpdb'] = new SSPA_Profiling_WPDB(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
    }
}
unset($sspa_shim_tok, $sspa_shim_class);
