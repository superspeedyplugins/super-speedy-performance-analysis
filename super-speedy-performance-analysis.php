<?php
/**
 * Plugin Name: Super Speedy Performance Analysis
 * Plugin URI: https://www.superspeedyplugins.com/
 * Description: Analyses your site's performance the way an expert would: profiles your key pages, attributes SQL time, row counts, RAM and query counts to individual plugins and your theme, then isolates the culprits.
 * Version: 0.35.0
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

// Read from the plugin header rather than duplicated, because the two drifted: the header
// said 0.29.15 while this constant still said 0.29.10, and this constant is what the Markdown
// export, the generated helper files and the download filenames tell the user they are running.
$sspa_header = get_file_data(__FILE__, array('Version' => 'Version'), 'plugin');
define('SSPA_VERSION', '' !== $sspa_header['Version'] ? $sspa_header['Version'] : '0.0.0');
define('SSPA_PLUGIN_FILE', __FILE__);
define('SSPA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SSPA_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once SSPA_PLUGIN_DIR . 'defines.php';

// SSPA-SELFHOSTED-UPDATER-START
// Everything between these two markers is DELETED by .build/build.sh when it builds the
// wordpress.org edition. wordpress.org forbids a bundled updater that phones home, and PCP
// errors on the mere mention of the update checker, so the markers keep that a mechanical
// deletion rather than a second copy of this file that would drift.
//
// The shared settings submodule owns BOTH the Super Speedy menu and the update checker, and
// this plugin registers with it exactly like every other Super Speedy plugin.
//
// It used to build its own update checker instead, and skip registration, because
// registering would have made the submodule build a SECOND checker for this slug and the
// checker fatals on a duplicate. The cost of that was invisible until 22 August 2026: on a
// site where this is the ONLY Super Speedy plugin, nothing ever registered, so the shared
// menu was never created and the whole Super Speedy dashboard - the plugin range, the
// licence table, the panel that leads it - did not exist for exactly the audience the free
// GitHub edition brings in. One checker, owned by the submodule, fixes both.
//
// If the submodule is absent - a git clone without --recurse-submodules, or the wordpress.org
// build - there is deliberately NO update checker at all, and the admin page falls back to
// its own top-level menu (see class-sspa-admin-page.php). A second updater implementation
// living here for that case is exactly what this change removes: an install that cannot
// check for updates should be re-cloned with its submodules, not quietly served by a
// different code path that nobody tests.
$sspa_settings = SSPA_PLUGIN_DIR . 'super-speedy-settings/super-speedy-settings.php';
if (is_admin() && !wp_doing_ajax() && file_exists($sspa_settings)) {
    require_once $sspa_settings;
    if (class_exists('SuperSpeedySettings_1_0')) {
        SuperSpeedySettings_1_0::init(array(
            'plugin_slug' => 'super-speedy-performance-analysis',
            'version'     => SSPA_VERSION,
            'file'        => __FILE__,
        ));
    }
}
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
