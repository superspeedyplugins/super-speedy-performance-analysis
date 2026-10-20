<?php
/**
 * Plugin Name: SSPA test fixture - slow shop page
 * Description: Test-only MU plugin for the Cache performance "no page cache" journey. The
 * WooCommerce shop page really waits 600 ms before rendering, so the analysis measures a
 * genuinely slow catalogue page. Installed only on the retained tests-cache-nocache site.
 */
defined('ABSPATH') || exit;
add_action('template_redirect', static function () {
    if (function_exists('is_shop') && is_shop() && !is_admin()) {
        usleep(600000);
    }
});
