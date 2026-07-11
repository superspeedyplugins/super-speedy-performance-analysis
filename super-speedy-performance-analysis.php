<?php
/**
 * Plugin Name: Super Speedy Performance Analysis
 * Plugin URI: https://superspeedy.org/
 * Description: Analyses your site's performance the way an expert would: profiles your key pages, attributes SQL time, row counts, RAM and query counts to individual plugins and your theme, then isolates the culprits.
 * Version: 0.1.0
 * Author: Dave Hilditch
 * Author URI: https://superspeedy.org
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
// this plugin is free (no licence, no superspeedyplugins.com package) and init() would
// register a superspeedyplugins.com update checker for our slug - PUC fatals when the same
// slug is registered twice, and our real updates come from GitHub below. If the submodule is
// missing (e.g. a zip without submodules) the plugin still works - the admin page falls back
// to its own top-level menu (see class-sspa-admin-page.php).
$sspa_settings = SSPA_PLUGIN_DIR . 'super-speedy-settings/super-speedy-settings.php';
if (file_exists($sspa_settings)) {
    require_once $sspa_settings;
}

// Updates come from GitHub releases.
if (class_exists('SuperSpeedy\\PluginUpdateChecker\\v5\\PucFactory')) {
    $sspa_update_checker = \SuperSpeedy\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/dhilditch/super-speedy-performance-analysis/',
        __FILE__,
        'super-speedy-performance-analysis'
    );
    $sspa_update_checker->setBranch('main');
}

require_once SSPA_PLUGIN_DIR . 'includes/class-sspa-schema.php';
require_once SSPA_PLUGIN_DIR . 'includes/class-sspa-install.php';
require_once SSPA_PLUGIN_DIR . 'includes/admin/class-sspa-admin-page.php';

register_activation_hook(__FILE__, array('SSPA_Install', 'activate'));
add_action('plugins_loaded', array('SSPA_Install', 'maybe_upgrade'));

if (is_admin()) {
    add_action('admin_menu', array('SSPA_Admin_Page', 'addmenu'), 20);
}
