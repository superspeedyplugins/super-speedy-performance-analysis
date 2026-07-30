<?php
/**
 * Plugin Name: Super Speedy Performance Analysis
 * Plugin URI: https://www.superspeedyplugins.com/
 * Description: Analyses your site's performance the way an expert would: profiles your key pages, attributes SQL time, row counts, RAM and query counts to individual plugins and your theme, then isolates the culprits.
 * Version: 0.9.2
 * Author: Dave Hilditch
 * Author URI: https://www.superspeedyplugins.com
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: super-speedy-performance-analysis
 * Requires at least: 6.2
 * Tested up to: 6.9
 * Requires PHP: 7.4
 */

defined('ABSPATH') || exit;

$plugin_data = get_file_data(__FILE__, array('Version' => 'Version'));
define('SSPA_VERSION', $plugin_data['Version']);
define('SSPA_PLUGIN_FILE', __FILE__);
define('SSPA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SSPA_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once SSPA_PLUGIN_DIR . 'defines.php';

// Shared settings/menu/PUC framework, loaded for the shared Super Speedy menu and the
// bundled update-checker library. Deliberately NOT registered via SuperSpeedySettings::init():
// this plugin is free (no licence key, so no licence table and no auth-server gated download)
// and init() would register its own update checker for our slug - PUC fatals when the same
// slug is registered twice, and we register ours below. If the submodule is missing (e.g. a
// zip without submodules) the plugin still works - the admin page falls back to its own
// top-level menu (see class-sspa-admin-page.php).
$sspa_settings = SSPA_PLUGIN_DIR . 'super-speedy-settings/super-speedy-settings.php';
if (file_exists($sspa_settings)) {
    require_once $sspa_settings;
}

// Updates come from superspeedyplugins.com, same metadata convention as the paid plugins
// (/assets/plugins/<slug>.json) but with an ungated download_url because this plugin is free.
// Not GitHub: the repo is private for now.
if (class_exists('SuperSpeedy\\PluginUpdateChecker\\v5\\PucFactory')) {
    $sspa_update_checker = \SuperSpeedy\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://www.superspeedyplugins.com/assets/plugins/super-speedy-performance-analysis.json',
        __FILE__,
        'super-speedy-performance-analysis'
    );
}

require_once SSPA_PLUGIN_DIR . 'includes/class-sspa-schema.php';
require_once SSPA_PLUGIN_DIR . 'includes/class-sspa-install.php';
require_once SSPA_PLUGIN_DIR . 'includes/class-sspa-token.php';
require_once SSPA_PLUGIN_DIR . 'includes/class-sspa-helper-files.php';
require_once SSPA_PLUGIN_DIR . 'includes/class-sspa-catalogue.php';
require_once SSPA_PLUGIN_DIR . 'includes/class-sspa-auth.php';
require_once SSPA_PLUGIN_DIR . 'includes/class-sspa-security-detect.php';
require_once SSPA_PLUGIN_DIR . 'includes/class-sspa-crawler.php';
require_once SSPA_PLUGIN_DIR . 'includes/class-sspa-profile-store.php';
require_once SSPA_PLUGIN_DIR . 'includes/class-sspa-rules.php';
require_once SSPA_PLUGIN_DIR . 'includes/class-sspa-attribution.php';
require_once SSPA_PLUGIN_DIR . 'includes/class-sspa-explain.php';
require_once SSPA_PLUGIN_DIR . 'includes/class-sspa-tools.php';
require_once SSPA_PLUGIN_DIR . 'includes/class-sspa-demographics.php';
require_once SSPA_PLUGIN_DIR . 'includes/class-sspa-analysis-engine.php';
require_once SSPA_PLUGIN_DIR . 'includes/class-sspa-dependency-map.php';
require_once SSPA_PLUGIN_DIR . 'includes/class-sspa-probes.php';
require_once SSPA_PLUGIN_DIR . 'includes/class-sspa-anonymiser.php';
require_once SSPA_PLUGIN_DIR . 'includes/class-sspa-submitter.php';
require_once SSPA_PLUGIN_DIR . 'includes/class-sspa-rules-feed.php';
require_once SSPA_PLUGIN_DIR . 'includes/class-sspa-run-controller.php';
require_once SSPA_PLUGIN_DIR . 'includes/admin/class-sspa-admin-page.php';
require_once SSPA_PLUGIN_DIR . 'includes/admin/class-sspa-insights.php';
require_once SSPA_PLUGIN_DIR . 'includes/class-sspa-report.php';

// Agent surfaces: Abilities API (WP 6.9+; MCP via the adapter plugin) and WP-CLI.
if (function_exists('wp_register_ability')) {
    require_once SSPA_PLUGIN_DIR . 'includes/class-sspa-abilities.php';
    SSPA_Abilities::init();
}
if (defined('WP_CLI') && WP_CLI) {
    require_once SSPA_PLUGIN_DIR . 'includes/cli/class-sspa-cli.php';
    WP_CLI::add_command('sspa', 'SSPA_CLI');
}

register_activation_hook(__FILE__, array('SSPA_Install', 'activate'));
register_deactivation_hook(__FILE__, array('SSPA_Install', 'deactivate'));
add_action('plugins_loaded', array('SSPA_Install', 'maybe_upgrade'));

SSPA_Run_Controller::register();
SSPA_Probes::register();

if (is_admin()) {
    add_action('admin_menu', array('SSPA_Admin_Page', 'addmenu'), 20);
    // Cheap self-heal: reinstall helper files if missing or stale (secret/version change).
    add_action('admin_init', array('SSPA_Helper_Files', 'ensure_installed'));
    SSPA_Admin_Page::register_toggle_prompt();
}
