<?php
/**
 * Plugin Name: Super Speedy Performance Analysis Loader
 * Description: Arms the SSPA profiler for requests carrying a valid signed profiling token. Inert for all other traffic. Installed and removed automatically by the Super Speedy Performance Analysis plugin.
 * Author: Dave Hilditch
 * Version: %%SSPA_VERSION%%
 */

// TEMPLATE: placeholders are replaced at install time by SSPA_Helper_Files.
if (!defined('ABSPATH')) {
    return;
}
if (empty($_SERVER['HTTP_X_SSPA_TOKEN'])) {
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

$sspa_tok = sspa_token_verify(
    $_SERVER['HTTP_X_SSPA_TOKEN'],
    isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/',
    '%%SSPA_SECRET%%'
);
if (!$sspa_tok) {
    return;
}

// Baseline endpoint: answer with near-zero WordPress work so the crawler can measure the
// server's noise floor. Deliberately before single-use marking (no DB write needed).
if (!empty($sspa_tok['flags']['bl'])) {
    header('X-SSPA-Profiled: ' . $sspa_tok['id']);
    header('Content-Type: text/plain');
    echo 'sspa-baseline-ok';
    exit;
}

// Single use: add_option is an INSERT - the second attempt with the same token fails.
if (!add_option('sspa_used_' . $sspa_tok['id'], time(), '', false)) {
    header('X-SSPA-Replay: 1');
    return;
}

header('X-SSPA-Profiled: ' . $sspa_tok['id']);

if (!defined('DONOTCACHEPAGE')) {
    define('DONOTCACHEPAGE', true);
}
// Degraded fallback when the db.php shim is not in place: core SAVEQUERIES still gives
// SQL + time + caller summary (no row counts). The shim defines this earlier when active.
if (!defined('SAVEQUERIES')) {
    define('SAVEQUERIES', true);
}

// Virtual isolation override (deep analysis): the plugin stores {plugins: [files to
// exclude], theme: slug|null} under a hash key; only token-bearing requests ever see the
// filtered set. Real visitors are never affected and no (de)activation hooks fire.
if (!empty($sspa_tok['flags']['ps'])) {
    $sspa_ps_hash = preg_replace('/[^a-f0-9]/', '', $sspa_tok['flags']['ps']);
    $sspa_iso = get_option('sspa_isolation_' . $sspa_ps_hash);
    if (is_array($sspa_iso)) {
        if (!empty($sspa_iso['plugins']) && is_array($sspa_iso['plugins'])) {
            $sspa_exclude = $sspa_iso['plugins'];
            add_filter('option_active_plugins', function ($plugins) use ($sspa_exclude) {
                return array_values(array_diff((array) $plugins, $sspa_exclude));
            });
            add_filter('site_option_active_sitewide_plugins', function ($plugins) use ($sspa_exclude) {
                foreach ($sspa_exclude as $sspa_file) {
                    unset($plugins[$sspa_file]);
                }
                return $plugins;
            });
        }
        if (!empty($sspa_iso['theme'])) {
            $sspa_theme = $sspa_iso['theme'];
            add_filter('template', function () use ($sspa_theme) {
                return $sspa_theme;
            });
            add_filter('stylesheet', function () use ($sspa_theme) {
                return $sspa_theme;
            });
        }
        // Canary: the crawler verifies the override actually applied to this request.
        header('X-SSPA-PS: ' . $sspa_ps_hash);
    }
    unset($sspa_iso, $sspa_ps_hash);
}

$sspa_bootstrap = '%%SSPA_PLUGIN_DIR%%profiler/bootstrap.php';
if (file_exists($sspa_bootstrap)) {
    require_once $sspa_bootstrap;
    sspa_profiler_boot($sspa_tok['id'], $sspa_tok['flags']);
}
unset($sspa_tok, $sspa_bootstrap);
