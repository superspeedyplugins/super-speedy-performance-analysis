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
 * Version: %%SSPA_DROPIN_VERSION%%
 */

// TEMPLATE: placeholders are replaced at install time by SSPA_Helper_Files.
if (!defined('ABSPATH')) {
    exit;
}
$sspa_token_value = !empty($_SERVER['HTTP_X_SSPA_TOKEN'])
    ? $_SERVER['HTTP_X_SSPA_TOKEN']
    : (!empty($_POST['_sspa_profile_token']) ? $_POST['_sspa_profile_token'] : '');
if (!$sspa_token_value || !defined('DB_USER')) {
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
    $sspa_token_value,
    isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/',
    '%%SSPA_SECRET%%'
);
if (!$sspa_shim_tok) {
    unset($sspa_shim_tok, $sspa_token_value);
    return;
}
unset($sspa_token_value);

// Cache-impact runs: prevent the object-cache drop-in loading for this request only.
// db.php is the only code that runs early enough to register this filter in time.
if (isset($sspa_shim_tok['flags']['oc']) && '0' === $sspa_shim_tok['flags']['oc']) {
    // Managed hosts can load their cache implementation before db.php from a path outside
    // wp-content. Disabling WordPress's drop-in loader in that state makes core load its
    // fallback cache.php over the already-defined functions and fatals on wp_cache_init().
    // Refuse this one synthetic cache-off request and tell the collector exactly why.
    if (function_exists('wp_cache_init')) {
        $GLOBALS['sspa_object_cache_disable_unsupported'] = true;
        if (!headers_sent()) {
            header('X-SSPA-Object-Cache: platform-managed');
        }
    } else {
        add_filter('enable_loading_object_cache_dropin', '__return_false');
    }
}

// Isolation cells (a plugin virtually excluded) arm the destructive-statement guard in the
// profiling wpdb below: a plugin reacting to another being excluded must not be able to
// drop, truncate or wipe anything because we measured something. This is why plugin impact
// analysis requires OUR db.php - without this seat there is nothing between a reaction and
// the database.
if (!empty($sspa_shim_tok['flags']['ps'])) {
    $GLOBALS['sspa_isolation_cell'] = true;
}

// Baseline requests need no profiling wpdb.
if (!empty($sspa_shim_tok['flags']['bl'])) {
    unset($sspa_shim_tok);
    return;
}

if (!defined('SAVEQUERIES')) {
    define('SAVEQUERIES', true);
}

/*
 * Option reads. db.php is the only code that runs early enough to see them all: the first
 * wp_load_alloptions() happens in is_blog_installed() from wp_not_installed(), roughly 300
 * lines before mu-plugins load. Registering this any later misses siteurl, home, blog_charset
 * and active_plugins, and would report the options every single request needs as never read.
 *
 * The generic pre_option filter (WP 6.1+) fires for every get_option() call, before the
 * alloptions/cache/DB lookup, and still fires when a per-option filter already short-circuited.
 * Returning $pre untouched keeps it a pure observer.
 */
if (!function_exists('sspa_record_option_read')) {
    function sspa_record_option_read($pre, $option) {
        $GLOBALS['sspa_option_calls']++;
        if (isset($GLOBALS['sspa_option_reads'][$option])) {
            $GLOBALS['sspa_option_reads'][$option]++;
        } elseif (count($GLOBALS['sspa_option_reads']) < 3000) {
            // Our own bookkeeping rows would otherwise show up as hot options on every profile.
            if (0 !== strpos($option, 'sspa_')) {
                $GLOBALS['sspa_option_reads'][$option] = 1;
            }
        } else {
            $GLOBALS['sspa_option_truncated'] = true;
        }
        return $pre;
    }
}
$GLOBALS['sspa_option_reads'] = array();
$GLOBALS['sspa_option_calls'] = 0;
$GLOBALS['sspa_option_truncated'] = false;
$GLOBALS['sspa_option_coverage'] = 'full';
add_filter('pre_option', 'sspa_record_option_read', 0, 2);

$sspa_shim_class = '%%SSPA_PLUGIN_DIR%%profiler/class-sspa-profiling-wpdb.php';
if (file_exists($sspa_shim_class)) {
    require_once $sspa_shim_class;
    if (class_exists('SSPA_Profiling_WPDB')) {
        $GLOBALS['wpdb'] = new SSPA_Profiling_WPDB(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
    }
}
unset($sspa_shim_tok, $sspa_shim_class);
