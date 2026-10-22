<?php
/**
 * Plugin Name: SSPA test fixture - work before the MU observer
 * Description: Test fixture for case 140, installed as an MU plugin. Its filename sorts before sspa-traffic-observer.php, so on the endpoint fixture's own requests it sleeps 30 ms after WordPress's timer starts and before the traffic observer loads. Inert for every other request.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- test fixture keyed on its own route and action.
$sspa_pre_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
$sspa_pre_action = isset($_REQUEST['action']) ? (string) $_REQUEST['action'] : '';
if (false !== strpos($sspa_pre_uri, 'sspa-endpoint-fixture/v1/startup') || 'zz_endpoint_fixture' === $sspa_pre_action) {
    usleep(30000);
}
unset($sspa_pre_uri, $sspa_pre_action);
