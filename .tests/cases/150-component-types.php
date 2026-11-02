<?php
// The Plugins tab lists component_stats rows typed 'plugin'. A theme or MU plugin that only spends
// hook time (no SQL, HTTP or mail) used to be stored as type 'plugin', so the active theme showed up
// on the Plugins tab as a plugin with a Measure button.
//
// A fixture theme and an MU fixture each run a 5 ms init callback and no query. A real spot analysis
// of Home then must store the theme as 'theme' and the MU plugin as 'mu-plugin', and the Plugins tab
// must list the theme as a theme with no Measure button. The fixtures and the run are retained; the previous run is cleared on
// the next entry. The previously active theme is restored at the end so later cases see the same site.

require_once SSPA_PLUGIN_DIR . '.tests/lead-gen/lib.php';
require_once SSPA_PLUGIN_DIR . '.tests/lib/retained-fixtures.php';
global $wpdb;
wp_set_current_user(1);
sspa_retained_reset('150-component-types');

$theme_dir = get_theme_root() . '/sspa-case150-theme';
wp_mkdir_p($theme_dir);
file_put_contents($theme_dir . '/style.css', "/*\nTheme Name: SSPA case 150 fixture theme\nDescription: Test-only. One 5 ms init callback, no queries.\n*/\n");
file_put_contents($theme_dir . '/index.php', "<?php wp_head(); ?><p>case 150</p><?php wp_footer();\n");
file_put_contents($theme_dir . '/functions.php', "<?php\nadd_action('init', static function () { usleep(5000); });\n");
$mu = WPMU_PLUGIN_DIR . '/sspa-case150-mu.php';
wp_mkdir_p(WPMU_PLUGIN_DIR);
file_put_contents($mu, "<?php\n/**\n * Plugin Name: SSPA case 150 fixture - MU hook time only\n */\nadd_action('init', static function () { usleep(5000); });\n");
$previous_theme = get_stylesheet();
switch_theme('sspa-case150-theme');
sleep(3); // php-fpm opcache revalidation

$run_id = SSPA_Run_Controller::start(array('type' => 'spot', 'trigger' => 'manual', 'user_id' => 1, 'page_keys' => array('home')));
if (!lg_t(!is_wp_error($run_id), 'a spot analysis of Home starts' . (is_wp_error($run_id) ? ': ' . $run_id->get_error_message() : ''))) { switch_theme($previous_theme); return; }
sspa_retained_save('150-component-types', array('runs' => array((int) $run_id)));
$deadline = time() + 600;
do {
    SSPA_Run_Controller::process_batch($run_id);
    $status = SSPA_Run_Controller::status($run_id);
} while ($status && in_array($status['status'], array('queued', 'crawling', 'analysing'), true) && time() < $deadline);
switch_theme($previous_theme);
if (!lg_t($status && 'done' === $status['status'], 'the analysis finished (' . ($status ? $status['status'] : 'null') . ')')) { return; }

$types = function ($component) use ($wpdb, $run_id) {
    return $wpdb->get_col($wpdb->prepare('SELECT DISTINCT component_type FROM %i WHERE run_id = %d AND component = %s', SSPA_Schema::table('component_stats'), (int) $run_id, $component));
};
$theme_types = $types('sspa-case150-theme');
lg_t(array('theme') === $theme_types, 'the fixture theme is stored as a theme (' . implode(',', $theme_types) . ')');
$mu_types = $types('mu:sspa-case150-mu');
lg_t(array('mu-plugin') === $mu_types, 'the MU fixture is stored as an MU plugin (' . implode(',', $mu_types) . ')');

$rows = SSPA_Plugins_Table::rows((int) $run_id, 'baseline');
$listed = array();
foreach ((array) $rows['components'] as $row) { $listed[$row['component']][] = $row['component_type']; }
$theme_listed = isset($listed['sspa-case150-theme']) ? $listed['sspa-case150-theme'] : array();
lg_t(array('theme') === $theme_listed, 'the Plugins tab lists the theme once, as a theme (' . implode(',', $theme_listed) . ')');

$tab = lg_tab('plugins');
lg_t('' !== $tab, 'the Plugins tab renders');
lg_t(false === strpos($tab, 'data-plugin="sspa-case150-theme"'), 'the Plugins tab offers no Measure button for the theme');
