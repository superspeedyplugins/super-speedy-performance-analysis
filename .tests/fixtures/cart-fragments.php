<?php
/**
 * Plugin Name: SSPA test fixture - WooCommerce cart fragments on every page
 * Description: Test-only MU plugin for the Cache performance journey. Enqueues WooCommerce's
 * own wc-cart-fragments script on the front end, as a theme's header mini-basket does, so the
 * basket state of a cached page is refreshed in the browser. Installed only on the retained
 * tests-cache-enabler site during the second Cache Enabler pass.
 */
defined('ABSPATH') || exit;
add_action('wp_enqueue_scripts', static function () {
    wp_enqueue_script('wc-cart-fragments');
}, 20);
