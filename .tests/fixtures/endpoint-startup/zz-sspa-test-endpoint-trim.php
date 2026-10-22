<?php
/**
 * Plugin Name: SSPA test fixture - Fast trim for the endpoint fixture
 * Description: Test fixture for cases 140 and 141, installed as an MU plugin. On the fixture's own REST route and admin-ajax action only, it trims option_active_plugins to WooCommerce plus the fixture, as a Fast Ajax/REST MU plugin does. Performance Analysis's main plugin is trimmed away. Inert for every other request.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- test fixture keyed on its own route and action.
$sspa_trim_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
$sspa_trim_action = isset($_REQUEST['action']) ? (string) $_REQUEST['action'] : '';
if (false !== strpos($sspa_trim_uri, 'sspa-endpoint-fixture/v1/startup') || 'zz_endpoint_fixture' === $sspa_trim_action) {
    add_filter('option_active_plugins', static function ($plugins) {
        return array_values(array_intersect((array) $plugins, array(
            'woocommerce/woocommerce.php',
            'sspa-endpoint-fixture/sspa-endpoint-fixture.php',
        )));
    }, PHP_INT_MAX);
}
unset($sspa_trim_uri, $sspa_trim_action);
