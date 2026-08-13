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

// Identity is decided by the signed token, not by whatever cookies came along.
// Browser-transport requests are fetched by the run-driving ADMIN's browser with
// credentials included - that is what carries HTTP basic auth and CDN clearance
// through a server-level auth wall - so an anon-variant measurement must
// explicitly ignore those cookies, and a customer-variant one must run as the
// flagged test account. Only ever a privilege DECREASE: anon forces logged-out,
// and customer resolves ONLY to the meta-flagged sspa test account, server-side -
// no user id travels in the token. Loopback requests carry no browser cookies,
// so both are no-ops there. 'admin' and the checkout flow's 'guest' are left
// alone: admin should be the driving admin's real session, and guest manages its
// own synthetic session cookies.
$sspa_variant = isset($sspa_tok['flags']['v']) ? $sspa_tok['flags']['v'] : '';
if ('anon' === $sspa_variant) {
    add_filter('determine_current_user', '__return_zero', PHP_INT_MAX);
} elseif ('customer' === $sspa_variant) {
    add_filter('determine_current_user', function () {
        $sspa_test = get_users(array(
            'meta_key' => 'sspa_test_account',
            'meta_value' => '1',
            'number' => 1,
            'fields' => 'ID',
        ));
        return $sspa_test ? (int) $sspa_test[0] : 0;
    }, PHP_INT_MAX);
}
unset($sspa_variant);
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

            // The write guard above is only half of it: core runs a plugin's OWN activation
            // and deactivation routines BEFORE the option write it guards, and those are
            // whole installers - scalability-pro's deactivation hook drops its database
            // indexes. A dependant that reacts to its dependency being excluded must not get
            // to run any of that because we measured something.
            //
            // Both core paths fire a general action first:
            //   deactivate_plugin -> deactivate_{$file} -> deactivated_plugin -> write
            //   activate_plugin   -> activate_{$file}   -> activated_plugin   -> write
            // so a listener at the head of the first can CATCH the attempt (it becomes a
            // recorded reaction: the cell is reported instead of trusted, and the pair is
            // grouped from the next run on) and then remove every listener core is about to
            // call - its own routine, and third-party observers such as a security plugin's
            // audit log, which would otherwise record a deactivation that never happened.
            if (!function_exists('sspa_catch_plugin_reaction')) {
                function sspa_catch_plugin_reaction($sspa_which, $sspa_plugin) {
                    if (!isset($GLOBALS['sspa_plugin_reactions'])) {
                        $GLOBALS['sspa_plugin_reactions'] = array();
                    }
                    // The backtrace names the REACTOR - the plugin whose code called
                    // (de)activate - which is not the same as the target: Rank Math Pro
                    // reacting calls activate_plugin() on Rank Math. Our own catcher frames
                    // are skipped or attribution lands on this loader instead of the plugin
                    // that actually reacted.
                    $sspa_frames = array();
                    foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 24) as $sspa_f) {
                        if (isset($sspa_f['file']) && __FILE__ !== $sspa_f['file']) {
                            $sspa_frames[] = array(
                                $sspa_f['file'],
                                isset($sspa_f['line']) ? $sspa_f['line'] : 0,
                                (isset($sspa_f['class']) ? $sspa_f['class'] . '::' : '') . $sspa_f['function'],
                            );
                        }
                    }
                    $GLOBALS['sspa_plugin_reactions'][] = array(
                        'op' => $sspa_which,
                        'plugin' => (string) $sspa_plugin,
                        'frames' => $sspa_frames,
                    );
                    remove_all_actions($sspa_which . '_' . $sspa_plugin);
                    remove_all_actions($sspa_which . 'd_plugin');
                    // Clear the general hook too (third-party observers), then re-arm this
                    // catcher so a second attempt in the same request is still caught.
                    $sspa_self = ('deactivate' === $sspa_which) ? 'sspa_catch_deactivation' : 'sspa_catch_activation';
                    remove_all_actions($sspa_which . '_plugin');
                    add_action($sspa_which . '_plugin', $sspa_self, PHP_INT_MIN);
                }
                function sspa_catch_deactivation($sspa_plugin) {
                    sspa_catch_plugin_reaction('deactivate', $sspa_plugin);
                }
                function sspa_catch_activation($sspa_plugin) {
                    sspa_catch_plugin_reaction('activate', $sspa_plugin);
                }
            }
            if (!isset($GLOBALS['sspa_plugin_reactions'])) {
                $GLOBALS['sspa_plugin_reactions'] = array();
            }
            add_action('deactivate_plugin', 'sspa_catch_deactivation', PHP_INT_MIN);
            add_action('activate_plugin', 'sspa_catch_activation', PHP_INT_MIN);
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
