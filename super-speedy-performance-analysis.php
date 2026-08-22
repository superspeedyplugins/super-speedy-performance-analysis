<?php
/**
 * Plugin Name: Super Speedy Performance Analysis
 * Plugin URI: https://www.superspeedyplugins.com/
 * Description: Analyses your site's performance the way an expert would: profiles your key pages, attributes SQL time, row counts, RAM and query counts to individual plugins and your theme, then isolates the culprits.
 * Version: 0.29.12
 * Author: Dave Hilditch
 * Author URI: https://www.superspeedyplugins.com
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: super-speedy-performance-analysis
 * Requires at least: 6.2
 * Tested up to: 7.0
 * Requires PHP: 7.4
 * WC requires at least: 8.2
 * WC tested up to: 11.0.1
 */

defined('ABSPATH') || exit;

define('SSPA_VERSION', '0.29.10');
define('SSPA_PLUGIN_FILE', __FILE__);
define('SSPA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SSPA_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once SSPA_PLUGIN_DIR . 'defines.php';

// SSPA-SELFHOSTED-UPDATER-START
// Everything between these two markers is DELETED by .build/build.sh when it builds the
// wordpress.org edition. wordpress.org forbids a bundled updater that phones home, and PCP
// errors on the mere mention of PucFactory - the class_exists() guard below is not enough,
// because the check is a string scan, not a reachability analysis. The markers keep that a
// mechanical deletion rather than a second copy of this file that would drift.
//
// Shared settings/menu/PUC framework, loaded for the shared Super Speedy menu and the
// bundled update-checker library. Deliberately NOT registered via SuperSpeedySettings::init():
// this plugin is free (no licence key, so no licence table and no auth-server gated download)
// and init() would register its own update checker for our slug - PUC fatals when the same
// slug is registered twice, and we register ours below. If the submodule is missing (e.g. a
// zip without submodules) the plugin still works - the admin page falls back to its own
// top-level menu (see class-sspa-admin-page.php).
$sspa_settings = SSPA_PLUGIN_DIR . 'super-speedy-settings/super-speedy-settings.php';
if (is_admin() && !wp_doing_ajax() && file_exists($sspa_settings)) {
    require_once $sspa_settings;
}

// Updates come from superspeedyplugins.com, same metadata convention as the paid plugins
// (/assets/plugins/<slug>.json) but with an ungated download_url because this plugin is free.
// Not GitHub: the repo is private for now.
//
// Deferred to plugins_loaded on purpose. Since settings 1.5.0 the submodule entrypoint is
// only a facade: it queues itself as a candidate and the winning copy's core - which is what
// actually requires the PUC library - loads at plugins_loaded -9999. Building here at include
// time therefore always found PucFactory absent and built nothing at all, so run just after
// the core at -9998. The class_exists guard stays: a zip without the submodule must still
// activate, simply without update checks.
add_action('plugins_loaded', function () {
    if (!class_exists('SuperSpeedy\\PluginUpdateChecker\\v5\\PucFactory')) {
        return;
    }
    $GLOBALS['sspa_update_checker'] = \SuperSpeedy\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://www.superspeedyplugins.com/assets/plugins/super-speedy-performance-analysis.json',
        SSPA_PLUGIN_DIR . 'super-speedy-performance-analysis.php',
        'super-speedy-performance-analysis'
    );
}, -9998);
// SSPA-SELFHOSTED-UPDATER-END

require_once SSPA_PLUGIN_DIR . 'includes/class-sspa-bootstrap.php';
SSPA_Bootstrap::register();

add_action('before_woocommerce_init', function () {
    if (class_exists('Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

// Agent surfaces: Abilities API (WP 6.9+; MCP via the adapter plugin) and WP-CLI.
$sspa_request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
$sspa_agent_request = (defined('WP_CLI') && WP_CLI)
    || false !== strpos($sspa_request_uri, '/wp-json/')
    || false !== strpos($sspa_request_uri, 'rest_route=');
if ($sspa_agent_request && function_exists('wp_register_ability')) {
    add_action('wp_abilities_api_categories_init', array('SSPA_Abilities', 'register_category'));
    add_action('wp_abilities_api_init', array('SSPA_Abilities', 'register_abilities'));
    add_filter('user_has_cap', array('SSPA_Abilities', 'grant_admin_caps'), 10, 3);
}
if (defined('WP_CLI') && WP_CLI) {
    require_once SSPA_PLUGIN_DIR . 'includes/cli/class-sspa-cli.php';
    require_once SSPA_PLUGIN_DIR . 'includes/traffic/class-sspa-traffic-cli.php';
    WP_CLI::add_command('sspa', 'SSPA_CLI');
    WP_CLI::add_command('sspa traffic', 'SSPA_Traffic_CLI');
}

register_activation_hook(__FILE__, array('SSPA_Install', 'activate'));
register_deactivation_hook(__FILE__, array('SSPA_Install', 'deactivate'));

if (is_admin()) {
    add_action('admin_menu', array('SSPA_Admin_Page', 'addmenu'), 20);
    SSPA_Admin_Page::register_toggle_prompt();
}
