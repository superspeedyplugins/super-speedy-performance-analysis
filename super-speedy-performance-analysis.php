<?php
/**
 * Plugin Name: Super Speedy Performance Analysis Pro
 * Plugin URI: https://www.superspeedyplugins.com/product/super-speedy-performance-analysis/
 * Description: Analyses your site's performance the way an expert would: profiles your key pages, attributes SQL time, row counts, RAM and query counts to individual plugins and your theme, then isolates the culprits.
 * Version: 0.71.0
 * Author: Dave Hilditch
 * Author URI: https://www.superspeedyplugins.com
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: super-speedy-performance-analysis
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * WC requires at least: 8.2
 * WC tested up to: 11.2.1
 */

defined('ABSPATH') || exit;

// The Pro edition (folder super-speedy-performance-analysis-pro) and the wordpress.org edition
// (folder super-speedy-performance-analysis) are the same plugin and declare the same classes, so
// only one may run. Pro always wins: when Pro is active, the wordpress.org copy stands down before
// it defines anything, whatever order WordPress loads them in (network-activated plugins load
// first, for one). The copy that stands down registers no hooks at all - no activation,
// deactivation or uninstall work - and only tells the administrator to remove it.
$sspa_pro_main = 'super-speedy-performance-analysis-pro/super-speedy-performance-analysis.php';
$sspa_pro_wins = 'super-speedy-performance-analysis-pro' !== dirname(plugin_basename(__FILE__))
    && is_file(WP_PLUGIN_DIR . '/' . $sspa_pro_main)
    && (in_array($sspa_pro_main, (array) get_option('active_plugins', array()), true)
        || (is_multisite() && array_key_exists($sspa_pro_main, (array) get_site_option('active_sitewide_plugins', array()))));
if ($sspa_pro_wins || defined('SSPA_PLUGIN_FILE')) {
    // SSPA-PRO-START
    // Activating Pro while another copy is loaded in the same request. Copies up to 0.65.0 have no
    // stand-down code, so left active beside Pro they would declare every class a second time on
    // the next request: switch that copy off now. Silently, because its deactivation hook would
    // remove the profiler loader and db.php drop-in Pro uses too. Its data stays for Pro.
    register_activation_hook(__FILE__, static function () {
        deactivate_plugins(plugin_basename(SSPA_PLUGIN_FILE), true);
    });
    // SSPA-PRO-END
    add_action('admin_notices', static function () use ($sspa_pro_wins) {
        global $pagenow, $plugin_page;
        if (!current_user_can('activate_plugins') || !('plugins.php' === $pagenow || 'index.php' === $pagenow || 'sspa' === $plugin_page)) {
            return;
        }
        if ($sspa_pro_wins) {
            $message = __('Super Speedy Performance Analysis Pro is active, so the free Super Speedy Performance Analysis plugin is switched off and does nothing. Please deactivate and delete the free plugin. Pro keeps all your analyses and settings.', 'super-speedy-performance-analysis');
        } else {
            $message = sprintf(
                /* translators: %s: folder name of the copy that is running. */
                __('Two copies of Super Speedy Performance Analysis are active. Only the one in the %s folder is running; deactivate and delete the other.', 'super-speedy-performance-analysis'),
                basename(dirname(SSPA_PLUGIN_FILE))
            );
        }
        echo '<div class="notice notice-warning"><p><strong>' . esc_html($message) . '</strong>';
        if ('plugins.php' !== $pagenow) {
            echo ' <a href="' . esc_url(admin_url('plugins.php')) . '">' . esc_html__('Go to Plugins', 'super-speedy-performance-analysis') . '</a>';
        }
        echo '</p></div>';
    });
    unset($sspa_pro_main, $sspa_pro_wins);
    return;
}
unset($sspa_pro_main, $sspa_pro_wins);

// Read from the plugin header rather than duplicated, because the two drifted: the header
// said 0.29.15 while this constant still said 0.29.10, and this constant is what the Markdown
// export, the generated helper files and the download filenames tell the user they are running.
$sspa_header = get_file_data(__FILE__, array('Version' => 'Version'), 'plugin');
define('SSPA_VERSION', '' !== $sspa_header['Version'] ? $sspa_header['Version'] : '0.0.0');
define('SSPA_PLUGIN_FILE', __FILE__);
define('SSPA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SSPA_PLUGIN_URL', plugin_dir_url(__FILE__));
// The location constants are shared with the standalone profiler, which loads without this file.
require_once SSPA_PLUGIN_DIR . 'includes/locations.php';

require_once SSPA_PLUGIN_DIR . 'defines.php';

// Read-only substring detection of the request path: it decides which libraries and agent
// surfaces load. Defined outside the updater block because the wordpress.org build deletes that.
$sspa_request_uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';

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
//
// It also loads for the MCP Adapter's routes and the Abilities API: the library is what
// registers the MCP endpoint that Super Speedy > MCP setup hands to clients, and a REST
// request is not an admin request, so without this the endpoint did not exist on a site
// where this is the only Super Speedy plugin. Front-end, other REST and admin-ajax requests
// still skip it.
$sspa_mcp_request = (bool) preg_match('#/' . preg_quote(trim(rest_get_url_prefix(), '/'), '#') . '/(?:mcp|wp-abilities)/|[?&]rest_route=(?:/|%2F)(?:mcp|wp-abilities)(?:/|%2F)#i', $sspa_request_uri);
$sspa_settings = SSPA_PLUGIN_DIR . 'super-speedy-settings/super-speedy-settings.php';
if (((is_admin() && !wp_doing_ajax()) || $sspa_mcp_request) && file_exists($sspa_settings)) {
    require_once $sspa_settings;
    if (class_exists('SuperSpeedySettings_1_0')) {
        SuperSpeedySettings_1_0::init(array(
            // The folder this copy is installed in: the -pro folder reads the -pro update feed.
            'plugin_slug' => dirname(plugin_basename(__FILE__)),
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
$sspa_agent_request = (defined('WP_CLI') && WP_CLI)
    || sspa_is_rest_url($sspa_request_uri);
if ($sspa_agent_request && function_exists('wp_register_ability')) {
    add_action('wp_abilities_api_categories_init', array('SSPA_Abilities', 'register_category'));
    add_action('wp_abilities_api_init', array('SSPA_Abilities', 'register_abilities'));
    add_filter('user_has_cap', array('SSPA_Abilities', 'grant_admin_caps'), 10, 3);
}
if (defined('WP_CLI') && WP_CLI) {
    require_once SSPA_PLUGIN_DIR . 'includes/cli/class-sspa-cli.php';
    require_once SSPA_PLUGIN_DIR . 'includes/traffic/class-sspa-traffic-cli.php';
    require_once SSPA_PLUGIN_DIR . 'includes/cli/class-sspa-profiler-cli.php';
    WP_CLI::add_command('sspa', 'SSPA_CLI');
    WP_CLI::add_command('sspa traffic', 'SSPA_Traffic_CLI');
    WP_CLI::add_command('sspa profiler', 'SSPA_Profiler_CLI');
}

register_activation_hook(__FILE__, array('SSPA_Install', 'activate'));
// SSPA-PRO-START
// Pro installs and refreshes the profiler without the Enable click (SSPA_Helper_Files::auto_enable()),
// including a loader the wordpress.org copy installed, which names that copy's folder.
add_action('admin_init', array('SSPA_Helper_Files', 'auto_enable_on_admin'));
// The free copy, switched off but still installed: ask for it to be deleted. While it is active
// it shows its own notice instead (the stand-down branch above).
add_action('admin_notices', static function () {
    global $pagenow, $plugin_page;
    $sspa_free = 'super-speedy-performance-analysis/super-speedy-performance-analysis.php';
    if (plugin_basename(__FILE__) === $sspa_free || !current_user_can('delete_plugins')
        || !('plugins.php' === $pagenow || 'index.php' === $pagenow || 'sspa' === $plugin_page)
        || !is_file(WP_PLUGIN_DIR . '/' . $sspa_free) || is_plugin_active($sspa_free)) {
        return;
    }
    echo '<div class="notice notice-warning"><p><strong>' . esc_html__('The free Super Speedy Performance Analysis plugin is still installed. Pro replaces it and keeps all your analyses and settings, so please delete the free plugin.', 'super-speedy-performance-analysis') . '</strong>';
    if ('plugins.php' !== $pagenow) {
        echo ' <a href="' . esc_url(admin_url('plugins.php')) . '">' . esc_html__('Go to Plugins', 'super-speedy-performance-analysis') . '</a>';
    }
    echo '</p></div>';
});
// SSPA-PRO-END
register_deactivation_hook(__FILE__, array('SSPA_Install', 'deactivate'));

if (is_admin()) {
    add_action('admin_menu', array('SSPA_Admin_Page', 'addmenu'), 20);
    SSPA_Admin_Page::register_toggle_prompt();
}
