<?php
// A measurement must never change which plugins the site runs.
//
// Isolation is a READ filter: option_active_plugins is filtered for the profiled request
// only, so the excluded plugin's code never loads. We fire no activation or deactivation
// hooks. But a plugin measured with its dependency excluded can decide, entirely reasonably,
// that it cannot run - and call deactivate_plugins() ON ITSELF. That write goes to the real
// option and outlives the measurement.
//
// Observed on a real site (10th August 2026): a sweep that excluded Rank Math ended with Rank
// Math AND Rank Math Pro deactivated for every visitor, and this plugin's own "you just
// deactivated seo-by-rank-math-pro" prompt offering a spot check for it.
//
// The fixture below is that site, reduced: one dependency, one dependant that removes itself
// when the dependency is missing.
//
// Deliberately the case the code scanner CANNOT see. Since 0.15.0 a dependant that names its
// dependency as a literal is excluded in the same cell as it (case 30), so it never discovers
// anything missing - which would leave this case passing while exercising nothing. This
// fixture assembles the path at run time instead, so it still gets orphaned and the guard is
// still the only thing standing between a measurement and a permanent change. An assertion
// below fails if grouping ever starts covering it.

function sspa_iso_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

global $wpdb;

$sspa_dep_dir = WP_PLUGIN_DIR . '/sspa-dep-fixture';
$sspa_dependant_dir = WP_PLUGIN_DIR . '/sspa-dependant-fixture';
foreach (array($sspa_dep_dir, $sspa_dependant_dir) as $sspa_d) {
    if (!is_dir($sspa_d)) {
        mkdir($sspa_d);
    }
}

file_put_contents($sspa_dep_dir . '/sspa-dep-fixture.php', <<<'PHP'
<?php
/**
 * Plugin Name: SSPA Dependency Fixture (test fixture)
 * Version: 1.0.0
 */
add_action('wp_footer', function () {
    global $wpdb;
    $wpdb->get_results("SELECT meta_id FROM {$wpdb->postmeta} LIMIT 50");
});
PHP
);

// The dependant: exactly the behaviour that took a real site's SEO plugin offline.
file_put_contents($sspa_dependant_dir . '/sspa-dependant-fixture.php', <<<'PHP'
<?php
/**
 * Plugin Name: SSPA Dependant Fixture (test fixture)
 * Version: 1.0.0
 */
add_action('plugins_loaded', function () {
    // Assembled, never written down: this is the dependency the code scanner cannot find.
    $name = 'sspa-dep-' . 'fixture';
    $dep = $name . '/' . $name . '.php';
    if (in_array($dep, (array) get_option('active_plugins', array()), true)) {
        return;
    }
    update_option('sspa_dep_orphaned', (int) get_option('sspa_dep_orphaned', 0) + 1, false);
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    deactivate_plugins('sspa-dependant-fixture/sspa-dependant-fixture.php');
}, 1);
PHP
);

activate_plugin('sspa-dep-fixture/sspa-dep-fixture.php');
activate_plugin('sspa-dependant-fixture/sspa-dependant-fixture.php');
// The mu-loader is regenerated from the plugin's template whenever it differs.
SSPA_Helper_Files::ensure_installed();
wp_cache_flush();
sleep(3); // opcache

$sspa_dep_file = 'sspa-dep-fixture/sspa-dep-fixture.php';
$sspa_dependant_file = 'sspa-dependant-fixture/sspa-dependant-fixture.php';

$sspa_before = (array) get_option('active_plugins', array());
sspa_iso_t(in_array($sspa_dep_file, $sspa_before, true), 'dependency fixture is active before the sweep');
sspa_iso_t(in_array($sspa_dependant_file, $sspa_before, true), 'dependant fixture is active before the sweep');
sspa_iso_t(
    in_array('sspa-dep-fixture', SSPA_Dependency_Map::isolation_candidates(), true),
    'the dependency is an isolation candidate (nothing declares it required)'
);

delete_transient('sspa_plugin_toggled');
delete_option('sspa_dep_orphaned');
delete_option(SSPA_Dependency_Map::SIGNALS_OPTION);

// This case only means anything while the dependant is left to discover the gap on its own.
$sspa_together = SSPA_Dependency_Map::must_exclude_together();
sspa_iso_t(
    empty($sspa_together['sspa-dep-fixture']),
    'grouping does not cover this pair, so the dependant really does get orphaned'
);

// A source run so the sweep has a page to work from, then the sweep itself.
$sspa_source = SSPA_Run_Controller::start(array('type' => 'spot', 'page_keys' => array('home'), 'user_id' => 1));
$sspa_deadline = time() + 300;
do {
    SSPA_Run_Controller::process_batch($sspa_source);
    $sspa_s = SSPA_Run_Controller::status($sspa_source);
} while ($sspa_s && in_array($sspa_s['status'], array('crawling', 'analysing'), true) && time() < $sspa_deadline);
sspa_iso_t($sspa_s && 'done' === $sspa_s['status'], 'source run done: ' . ($sspa_s ? $sspa_s['status'] : 'null'));

$sspa_sweep = SSPA_Run_Controller::start(array(
    'type' => 'deep',
    'suspects' => array('sspa-dep-fixture'),
    'page_keys' => array('home'),
    'cache_modes' => false,
    'user_id' => 1,
));
if (is_wp_error($sspa_sweep)) {
    echo 'FAIL: sweep start: ' . $sspa_sweep->get_error_message() . "\n";
} else {
    $sspa_deadline = time() + 300;
    do {
        SSPA_Run_Controller::process_batch($sspa_sweep);
        $sspa_s = SSPA_Run_Controller::status($sspa_sweep);
    } while ($sspa_s && in_array($sspa_s['status'], array('crawling', 'analysing'), true) && time() < $sspa_deadline);
    sspa_iso_t($sspa_s && 'done' === $sspa_s['status'], 'sweep done: ' . ($sspa_s ? $sspa_s['status'] : 'null'));

    // The measurement still has to have happened - a guard that works by not measuring
    // anything would pass every assertion below and be useless.
    $sspa_cells = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM ' . SSPA_Schema::table('profiles') . " WHERE run_id = %d AND plugin_set_hash <> ''",
        $sspa_sweep
    ));
    sspa_iso_t($sspa_cells > 0, 'the sweep measured the excluded cell (' . $sspa_cells . ')');

    // And the dependant really did try to remove itself - otherwise every assertion below
    // passes without the guard ever being asked to do anything.
    wp_cache_flush();
    sspa_iso_t(
        (int) get_option('sspa_dep_orphaned') > 0,
        'the dependant found itself orphaned and tried to deactivate (' . (int) get_option('sspa_dep_orphaned') . ' times)'
    );

    // THE assertion: the site still runs what it ran before.
    wp_cache_delete('alloptions', 'options');
    wp_cache_delete('active_plugins', 'options');
    $sspa_after = (array) $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'active_plugins'");
    $sspa_after = (array) maybe_unserialize($sspa_after[0]);
    sspa_iso_t(
        in_array($sspa_dependant_file, $sspa_after, true),
        'the dependant is STILL active after being measured without its dependency'
    );
    sspa_iso_t(
        in_array($sspa_dep_file, $sspa_after, true),
        'the excluded dependency is still active'
    );
    sspa_iso_t(count($sspa_after) === count($sspa_before), 'the active plugin list is unchanged (' . count($sspa_after) . ' vs ' . count($sspa_before) . ')');

    // And the site owner is not told they deactivated something they never touched.
    sspa_iso_t(!get_transient('sspa_plugin_toggled'), 'no plugin-toggle prompt was armed by the measurement');
}

// --- Cleanup ---
deactivate_plugins(array($sspa_dep_file, $sspa_dependant_file));
@unlink($sspa_dep_dir . '/sspa-dep-fixture.php');
@unlink($sspa_dependant_dir . '/sspa-dependant-fixture.php');
@rmdir($sspa_dep_dir);
@rmdir($sspa_dependant_dir);
delete_transient('sspa_plugin_toggled');
delete_option('sspa_dep_orphaned');
delete_option(SSPA_Dependency_Map::SIGNALS_OPTION);
sspa_iso_t(!is_dir($sspa_dep_dir) && !is_dir($sspa_dependant_dir), 'fixtures removed');
