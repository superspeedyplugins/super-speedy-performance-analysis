<?php
/**
 * Plugin Name: SSPA test fixture - trim PA from the request (issue #47)
 * Description: Test fixture installed by case 139 as an MU plugin. Inert unless a request carries one of its probe arguments.
 * Version: 1.0.0
 *
 * trim_pa_probe=1: removes Performance Analysis's main plugin from option_active_plugins for this
 * request only, exactly as a Fast Ajax/REST trim or a plugin organiser does. The MU file sorts
 * after sspa-loader.php, so the profiler is already armed when this runs, as on a real site.
 *
 * capture_fail_probe=<marker>: a third-party alloptions filter that throws while the profiler's
 * shutdown capture is being written, and nowhere else. Any plugin can throw from a filter; this
 * makes it happen inside SSPA_Capture::finalize() deterministically.
 */
defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- test fixture keyed on a probe argument.
if (isset($_GET['trim_pa_probe'])) {
    add_filter('option_active_plugins', static function ($plugins) {
        return array_values(array_filter((array) $plugins, static function ($file) {
            return 'super-speedy-performance-analysis/super-speedy-performance-analysis.php' !== $file;
        }));
    }, PHP_INT_MAX);
}

if (isset($_GET['capture_fail_probe'])) {
    $sspa_fixture_marker = preg_replace('/[^a-z0-9]/', '', strtolower((string) $_GET['capture_fail_probe']));
    add_filter('alloptions', static function ($alloptions) use ($sspa_fixture_marker) {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (isset($frame['class'], $frame['function']) && 'SSPA_Capture' === $frame['class'] && 'finalize' === $frame['function']) {
                throw new RuntimeException('Fixture alloptions filter failed during shutdown ' . $sspa_fixture_marker);
            }
        }
        return $alloptions;
    });
    unset($sspa_fixture_marker);
}
