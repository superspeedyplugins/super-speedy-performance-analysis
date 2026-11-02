<?php
/**
 * Plugin Name: zz-tracking-cookie (test fixture)
 * Description: Mimics a server-side tracker: sets _fbp on init for every front-end request that arrives without one.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

class ZZ_Tracking_Cookie {
    public static function param_builder_server_setup() {
        if (is_admin() || wp_doing_ajax() || !empty($_COOKIE['_fbp'])) {
            return;
        }
        setcookie('_fbp', 'fb.1.' . time() . '.' . wp_rand(), time() + 7776000, '/');
    }
}
add_action('init', array('ZZ_Tracking_Cookie', 'param_builder_server_setup'));
