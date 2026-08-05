<?php
/**
 * Plugin Name: Super Speedy Performance Hub
 * Plugin URI: https://superspeedy.org/
 * Description: Server side of the Super Speedy Performance Analysis community: receives anonymised submissions, serves the signed rules feed. MVP - lives inside the analysis repo for now, graduates to its own repo before superspeedy.org launch. Design: .docs/brainstorm-superspeedy-org-companion.md.
 * Version: 0.1.0
 * Author: Dave Hilditch
 * License: GPLv3
 * Text Domain: super-speedy-performance-hub
 */

defined('ABSPATH') || exit;

define('SSPH_VERSION', '0.1.0');
define('SSPH_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once SSPH_PLUGIN_DIR . 'includes/class-ssph-schema.php';
require_once SSPH_PLUGIN_DIR . 'includes/class-ssph-keys.php';
require_once SSPH_PLUGIN_DIR . 'includes/class-ssph-rest.php';

register_activation_hook(__FILE__, function () {
    SSPH_Schema::create_tables();
    SSPH_Keys::ensure_keypair();
    // Seed the editable rules from the analysis plugin's bundled snapshot when available.
    if (!get_option('ssph_rules')) {
        $snapshot = WP_PLUGIN_DIR . '/super-speedy-performance-analysis/rules/rules-snapshot.json';
        $rules = file_exists($snapshot) ? json_decode((string) file_get_contents($snapshot), true) : array();
        add_option('ssph_rules', is_array($rules) ? $rules : array(), '', false);
        add_option('ssph_rules_version', 1, '', false);
    }
});

add_action('rest_api_init', array('SSPH_Rest', 'register_routes'));
