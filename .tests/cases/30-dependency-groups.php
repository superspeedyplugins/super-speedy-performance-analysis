<?php
// A plugin that cannot run without another one must be excluded in the SAME cell as it.
//
// Case 29 proves the write guard: a plugin that deactivates itself mid-measurement cannot
// change which plugins the site runs. This case is the other half - not provoking it at all.
// The guard cannot stop the deactivation and activation HOOKS running, only the option write,
// and those hooks are whole installers (options, capabilities, cron). Excluding the pair
// together means the dependant never loads to discover anything is missing.
//
// The fixtures are Rank Math Pro reduced to its shape: the dependency named as a literal in
// the main file, checked against active_plugins, with deactivate_plugins() and
// activate_plugin() calls in the same file.

function sspa_grp_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

global $wpdb;

$sspa_free_dir = WP_PLUGIN_DIR . '/sspa-grp-free';
$sspa_pro_dir = WP_PLUGIN_DIR . '/sspa-grp-pro';
foreach (array($sspa_free_dir, $sspa_pro_dir) as $sspa_d) {
    if (!is_dir($sspa_d)) {
        mkdir($sspa_d);
    }
}

file_put_contents($sspa_free_dir . '/sspa-grp-free.php', <<<'PHP'
<?php
/**
 * Plugin Name: SSPA Group Free (test fixture)
 * Version: 1.0.0
 */
add_action('wp_footer', function () {
    global $wpdb;
    $wpdb->get_results("SELECT meta_id FROM {$wpdb->postmeta} LIMIT 50");
});
PHP
);

// Note the shape: the dependency path is a literal, the check reads active_plugins, and both
// activate_plugin() and deactivate_plugins() appear in the main file. Each fixture records
// that its hooks ran, so the test can assert they did not.
file_put_contents($sspa_pro_dir . '/sspa-grp-pro.php', <<<'PHP'
<?php
/**
 * Plugin Name: SSPA Group Pro (test fixture)
 * Version: 1.0.0
 */
$sspa_grp_free = 'sspa-grp-free/sspa-grp-free.php';
add_action('plugins_loaded', function () use ($sspa_grp_free) {
    if (in_array($sspa_grp_free, (array) get_option('active_plugins', array()), true)) {
        return;
    }
    update_option('sspa_grp_orphaned', (int) get_option('sspa_grp_orphaned', 0) + 1, false);
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    activate_plugin($sspa_grp_free);
    deactivate_plugins('sspa-grp-pro/sspa-grp-pro.php');
}, 1);
PHP
);

activate_plugin('sspa-grp-free/sspa-grp-free.php');
activate_plugin('sspa-grp-pro/sspa-grp-pro.php');
SSPA_Helper_Files::ensure_installed();
delete_option(SSPA_Dependency_Map::SIGNALS_OPTION);
delete_option('sspa_grp_orphaned');
wp_cache_flush();
sleep(3); // opcache

// --- The scan ---

$sspa_signals = SSPA_Dependency_Map::code_signals();
sspa_grp_t(
    isset($sspa_signals['sspa-grp-pro']) && $sspa_signals['sspa-grp-pro']['self_deactivates'],
    'the scan sees the dependant can deactivate itself'
);
sspa_grp_t(
    isset($sspa_signals['sspa-grp-pro']) && $sspa_signals['sspa-grp-pro']['activates_others'],
    'the scan sees it can activate another plugin'
);
sspa_grp_t(
    isset($sspa_signals['sspa-grp-pro']) && in_array('sspa-grp-free', $sspa_signals['sspa-grp-pro']['names'], true),
    'the scan reads the dependency out of the code: ' . (isset($sspa_signals['sspa-grp-pro']) ? implode(',', $sspa_signals['sspa-grp-pro']['names']) : 'none')
);
sspa_grp_t(
    empty($sspa_signals['sspa-grp-free']['names']),
    'the dependency names nothing, so the edge has a direction'
);

$sspa_together = SSPA_Dependency_Map::must_exclude_together();
sspa_grp_t(
    isset($sspa_together['sspa-grp-free']) && in_array('sspa-grp-pro', $sspa_together['sspa-grp-free'], true),
    'excluding the dependency is known to take the dependant with it'
);
sspa_grp_t(
    !isset($sspa_together['sspa-grp-pro']),
    'excluding the dependant on its own takes nothing with it'
);

// A dependency root is measurable again now that it is never orphaned on its own.
sspa_grp_t(
    in_array('sspa-grp-free', SSPA_Dependency_Map::isolation_candidates(), true),
    'the dependency is a sweep candidate'
);

// --- Grouping must not become a way round the fragile list ---
// A security plugin is never excluded, orphaned or otherwise. If something it depends on were
// still a candidate, its group would take the security plugin out with it for the test
// requests - the one thing the fragile list promises cannot happen. `wordfence` is on the
// bundled security list, so a fixture using that slug exercises the real rule.
$sspa_fragile_dir = WP_PLUGIN_DIR . '/wordfence';
if (!is_dir($sspa_fragile_dir)) {
    mkdir($sspa_fragile_dir);
}
file_put_contents($sspa_fragile_dir . '/wordfence.php', <<<'PHP'
<?php
/**
 * Plugin Name: SSPA Fragile Fixture (stands in for a security plugin)
 * Version: 1.0.0
 */
$sspa_grp_base = 'sspa-grp-free/sspa-grp-free.php';
add_action('plugins_loaded', function () use ($sspa_grp_base) {
    if (in_array($sspa_grp_base, (array) get_option('active_plugins', array()), true)) {
        return;
    }
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    deactivate_plugins('wordfence/wordfence.php');
}, 1);
PHP
);
activate_plugin('wordfence/wordfence.php');
delete_option(SSPA_Dependency_Map::SIGNALS_OPTION);
wp_cache_flush();
sleep(3);

$sspa_together_fragile = SSPA_Dependency_Map::must_exclude_together();
sspa_grp_t(
    isset($sspa_together_fragile['sspa-grp-free']) && in_array('wordfence', $sspa_together_fragile['sspa-grp-free'], true),
    'the fragile plugin is seen to depend on the fixture'
);
sspa_grp_t(
    !in_array('wordfence', SSPA_Dependency_Map::isolation_candidates(), true),
    'the fragile plugin is never a candidate itself'
);
sspa_grp_t(
    !in_array('sspa-grp-free', SSPA_Dependency_Map::isolation_candidates(), true),
    'nor is what it depends on, because excluding that would take it out too'
);

deactivate_plugins('wordfence/wordfence.php');
@unlink($sspa_fragile_dir . '/wordfence.php');
@rmdir($sspa_fragile_dir);
delete_option(SSPA_Dependency_Map::SIGNALS_OPTION);
wp_cache_flush();
sleep(3);
sspa_grp_t(
    in_array('sspa-grp-free', SSPA_Dependency_Map::isolation_candidates(), true),
    'and it is measurable again once the fragile plugin is gone'
);

// --- The sweep ---

$sspa_source = SSPA_Run_Controller::start(array('type' => 'spot', 'page_keys' => array('home'), 'user_id' => 1));
$sspa_deadline = time() + 300;
do {
    SSPA_Run_Controller::process_batch($sspa_source);
    $sspa_s = SSPA_Run_Controller::status($sspa_source);
} while ($sspa_s && in_array($sspa_s['status'], array('crawling', 'analysing'), true) && time() < $sspa_deadline);
sspa_grp_t($sspa_s && 'done' === $sspa_s['status'], 'source run done: ' . ($sspa_s ? $sspa_s['status'] : 'null'));

$sspa_sweep = SSPA_Run_Controller::start(array(
    'type' => 'deep',
    'suspects' => array('sspa-grp-free'),
    'page_keys' => array('home'),
    'cache_modes' => false,
    'user_id' => 1,
));
if (is_wp_error($sspa_sweep)) {
    echo 'FAIL: sweep start: ' . $sspa_sweep->get_error_message() . "\n";
} else {
    // The queued cell, read before any batch runs - a run this small empties its queue inside
    // the first batch, and the queue option is deleted when it finishes.
    $sspa_queue = get_option('sspa_queue_' . $sspa_sweep);
    $sspa_cell = null;
    foreach ((array) $sspa_queue['jobs'] as $sspa_job) {
        if (!empty($sspa_job['plugin'])) {
            $sspa_cell = $sspa_job;
            break;
        }
    }
    sspa_grp_t(
        $sspa_cell && !empty($sspa_cell['group']) && in_array('sspa-grp-pro', (array) $sspa_cell['group'], true),
        'the queued measurement excludes both plugins at once'
    );
    // The running feed builds its lines from this, so a grouped cell has to read as one.
    sspa_grp_t(
        $sspa_cell && false !== strpos(SSPA_Run_Controller::job_label($sspa_cell), 'sspa-grp-free + sspa-grp-pro'),
        'the feed line names both: ' . ($sspa_cell ? SSPA_Run_Controller::job_label($sspa_cell) : 'no cell')
    );

    $sspa_deadline = time() + 300;
    do {
        SSPA_Run_Controller::process_batch($sspa_sweep);
        $sspa_s = SSPA_Run_Controller::status($sspa_sweep);
    } while ($sspa_s && in_array($sspa_s['status'], array('crawling', 'analysing'), true) && time() < $sspa_deadline);
    sspa_grp_t($sspa_s && 'done' === $sspa_s['status'], 'sweep done: ' . ($sspa_s ? $sspa_s['status'] : 'null'));

    // THE assertion: the dependant never loaded to find its dependency missing, so neither
    // its deactivation nor its activation path ever ran.
    wp_cache_flush();
    sspa_grp_t(
        !get_option('sspa_grp_orphaned'),
        'the dependant never found itself orphaned (' . (int) get_option('sspa_grp_orphaned') . ' times)'
    );

    $sspa_impact = $wpdb->get_row($wpdb->prepare(
        'SELECT * FROM ' . SSPA_Schema::table('plugin_impacts') . ' WHERE test_run_id = %d AND plugin = %s',
        $sspa_sweep,
        'sspa-grp-free'
    ), ARRAY_A);
    sspa_grp_t(is_array($sspa_impact), 'the dependency got a measured verdict at all');
    if (is_array($sspa_impact)) {
        sspa_grp_t(
            'sspa-grp-pro' === $sspa_impact['group_members'],
            'the verdict records who it covers: ' . var_export($sspa_impact['group_members'], true)
        );
    }

    // And the site still runs both.
    $sspa_active = (array) get_option('active_plugins', array());
    sspa_grp_t(
        in_array('sspa-grp-free/sspa-grp-free.php', $sspa_active, true)
        && in_array('sspa-grp-pro/sspa-grp-pro.php', $sspa_active, true),
        'both fixtures are still active'
    );

    // The Plugins tab says the verdict covers a pair rather than crediting it to one plugin.
    $sspa_table = SSPA_Plugins_Table::render(SSPA_Plugins_Table::latest_run_id(), 'code_owner');
    sspa_grp_t(
        false !== strpos($sspa_table, 'measured together with sspa-grp-pro'),
        'the Plugins tab names the group behind the verdict'
    );
}

// --- Cleanup ---
deactivate_plugins(array('sspa-grp-free/sspa-grp-free.php', 'sspa-grp-pro/sspa-grp-pro.php'));
@unlink($sspa_free_dir . '/sspa-grp-free.php');
@unlink($sspa_pro_dir . '/sspa-grp-pro.php');
@rmdir($sspa_free_dir);
@rmdir($sspa_pro_dir);
delete_option('sspa_grp_orphaned');
delete_option(SSPA_Dependency_Map::SIGNALS_OPTION);
sspa_grp_t(!is_dir($sspa_free_dir) && !is_dir($sspa_pro_dir), 'fixtures removed');
