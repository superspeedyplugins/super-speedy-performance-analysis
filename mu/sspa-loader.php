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

// A profiled response must never be stored by any cache layer: it would be served to a
// real visitor, and a stored copy answers later profiling requests without running PHP.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// A fatal on a profiled request - above all in deep analysis, where a plugin is
// virtually excluded and a dependant may break without it - is a MEASUREMENT RESULT
// (the cell is reported unresolved), not a site emergency. Without this, core's fatal
// handler emails the admin a "technical issue" recovery-mode warning blaming whichever
// plugin's file happened to throw. Core sets this same constant for its own loopback
// sandbox tests; it only short-circuits the fatal handler, nothing else. Real visitor
// requests never reach this code, so their protection is unchanged.
if (!defined('WP_SANDBOX_SCRAPING')) {
    define('WP_SANDBOX_SCRAPING', true);
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

// This request is a measurement, not a person using the site. Anything that reacts to what
// happens during a request - our own "you just toggled a plugin" prompt, for one - has to be
// able to tell the difference.
if (!defined('SSPA_PROFILED_REQUEST')) {
    define('SSPA_PROFILED_REQUEST', true);
}

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
// filtered set. Real visitors are never affected and we fire no (de)activation hooks.
if (!empty($sspa_tok['flags']['ps'])) {
    $sspa_ps_hash = preg_replace('/[^a-f0-9]/', '', $sspa_tok['flags']['ps']);
    $sspa_iso = get_option('sspa_isolation_' . $sspa_ps_hash);
    if (is_array($sspa_iso)) {
        if (!empty($sspa_iso['plugins']) && is_array($sspa_iso['plugins'])) {
            $sspa_exclude = $sspa_iso['plugins'];

            // The REAL stored list, read before any filter of ours exists. It is what gets
            // written back below, so it must not be the filtered view.
            $sspa_real_active = get_option('active_plugins', array());
            $sspa_real_network = is_multisite() ? get_site_option('active_sitewide_plugins', array()) : array();

            // WE never deactivate anything - but a plugin measured with its dependency
            // excluded can deactivate ITSELF, for real, and that write would outlive the
            // measurement and take the plugin off the live site. Observed: excluding Rank
            // Math took Rank Math Pro down with it, permanently, on a real site.
            //
            // So while the filter is armed, no write to the plugin lists may persist. The
            // pre_update filter runs before update_option's equality check, so returning the
            // true stored list turns any such write into a no-op on the stored content -
            // whatever the caller believed it was doing.
            add_filter('pre_update_option_active_plugins', function () use ($sspa_real_active) {
                return $sspa_real_active;
            }, PHP_INT_MAX);
            add_filter('pre_update_site_option_active_sitewide_plugins', function () use ($sspa_real_network) {
                return $sspa_real_network;
            }, PHP_INT_MAX);

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

// Option reads: normally armed by the db.php drop-in, which is the only code early enough to
// see core's bootstrap reads and the first wp_load_alloptions(). This fallback covers a site
// whose db.php is absent or owned by another plugin. It marks the coverage as partial so the
// analysis never recommends de-autoloading an option purely because it was read too early.
if (!isset($GLOBALS['sspa_option_reads'])) {
    if (!function_exists('sspa_record_option_read')) {
        function sspa_record_option_read($pre, $option) {
            $GLOBALS['sspa_option_calls']++;
            if (isset($GLOBALS['sspa_option_reads'][$option])) {
                $GLOBALS['sspa_option_reads'][$option]++;
            } elseif (count($GLOBALS['sspa_option_reads']) < 3000) {
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
    $GLOBALS['sspa_option_coverage'] = 'partial';
    add_filter('pre_option', 'sspa_record_option_read', 0, 2);
}

$sspa_bootstrap = '%%SSPA_PLUGIN_DIR%%profiler/bootstrap.php';
if (file_exists($sspa_bootstrap)) {
    require_once $sspa_bootstrap;
    sspa_profiler_boot($sspa_tok['id'], $sspa_tok['flags']);
}
unset($sspa_tok, $sspa_bootstrap);
