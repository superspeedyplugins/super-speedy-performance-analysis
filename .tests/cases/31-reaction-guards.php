<?php
// A plugin reacting to another being excluded must not be able to DO anything - and the
// reaction must be caught, reported, and turned into a learned group.
//
// This is the scalability-pro shape, which the code scanner cannot help with: its
// register_deactivation_hook callback drops database indexes, its deactivate_plugins() call
// lives in a bundled library file the main-file scan never reads, and a dependency check can
// be assembled at run time. Three guards stand between that and a client's database:
//
//  1. the mu-loader catches core's deactivate_plugin/activate_plugin actions at the head and
//     removes every listener core was about to call, so the plugin's own (de)activation
//     routine - the index drop - never runs;
//  2. the profiling wpdb refuses destructive statements outright during isolation cells, so
//     even a reaction coded INLINE (no hook to remove) cannot drop anything;
//  3. the caught reaction becomes a finding and a learned group, so the next sweep excludes
//     the pair together and the reaction can never recur.
//
// Each guard is proven able to fail: the destructive paths are executed for real with the
// guards disarmed first, so a pass here is a pass because the guards worked, not because the
// fixture was harmless.

function sspa_rg_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

global $wpdb;

function sspa_rg_index_exists() {
    global $wpdb;
    return (bool) $wpdb->get_var("SHOW INDEX FROM {$wpdb->options} WHERE Key_name = 'sspa_guard_idx'");
}

// --- Setup: an index to protect, and the two fixtures ---

$wpdb->query("ALTER TABLE {$wpdb->options} ADD INDEX sspa_guard_idx (autoload)");
sspa_rg_t(sspa_rg_index_exists(), 'guard index created');

$sspa_dep_dir = WP_PLUGIN_DIR . '/sspa-guard-dep';
$sspa_reactor_dir = WP_PLUGIN_DIR . '/sspa-guard-reactor';
foreach (array($sspa_dep_dir, $sspa_reactor_dir) as $sspa_d) {
    if (!is_dir($sspa_d)) {
        mkdir($sspa_d);
    }
}

file_put_contents($sspa_dep_dir . '/sspa-guard-dep.php', <<<'PHP'
<?php
/**
 * Plugin Name: SSPA Guard Dependency (test fixture)
 * Version: 1.0.0
 */
add_action('wp_footer', function () {
    global $wpdb;
    $wpdb->get_results("SELECT meta_id FROM {$wpdb->postmeta} LIMIT 50");
});
PHP
);

// The reactor: a deactivation hook that drops an index (scalability-pro's shape), plus an
// INLINE index drop and a self-deactivation when its dependency goes missing. The dependency
// path is assembled at run time, so the code scanner cannot group this pair up front.
file_put_contents($sspa_reactor_dir . '/sspa-guard-reactor.php', <<<'PHP'
<?php
/**
 * Plugin Name: SSPA Guard Reactor (test fixture)
 * Version: 1.0.0
 */
// The explicit basename, never __FILE__: wp eval-file textually replaces __FILE__ in
// the case file before eval'ing, which would silently rewrite it inside this heredoc to
// the CASE's path and register the hook under a name that can never fire.
register_deactivation_hook('sspa-guard-reactor/sspa-guard-reactor.php', function () {
    global $wpdb;
    update_option('sspa_guard_hook_ran', 1, false);
    $wpdb->query("ALTER TABLE {$wpdb->options} DROP INDEX sspa_guard_idx");
});
add_action('plugins_loaded', function () {
    global $wpdb;
    $name = 'sspa-guard-' . 'dep';
    $dep = $name . '/' . $name . '.php';
    if (in_array($dep, (array) get_option('active_plugins', array()), true)) {
        return;
    }
    update_option('sspa_guard_orphaned', (int) get_option('sspa_guard_orphaned', 0) + 1, false);
    // Inline destruction: no hook involved, so only the statement guard can stop it.
    $wpdb->query("ALTER TABLE {$wpdb->options} DROP INDEX sspa_guard_idx");
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    deactivate_plugins('sspa-guard-reactor/sspa-guard-reactor.php');
}, 1);
PHP
);

activate_plugin('sspa-guard-dep/sspa-guard-dep.php');
activate_plugin('sspa-guard-reactor/sspa-guard-reactor.php');
SSPA_Helper_Files::ensure_installed();
delete_option('sspa_guard_hook_ran');
delete_option('sspa_guard_orphaned');
delete_option(SSPA_Dependency_Map::SIGNALS_OPTION);
delete_option(SSPA_Dependency_Map::LEARNED_OPTION);
wp_cache_flush();
sleep(3); // opcache

// --- The destructive paths are REAL: run them with no guards armed ---
// A guard test whose fixture could never do damage proves nothing.

deactivate_plugins('sspa-guard-reactor/sspa-guard-reactor.php');
sspa_rg_t(1 === (int) get_option('sspa_guard_hook_ran'), 'unguarded: the deactivation hook really runs');
sspa_rg_t(!sspa_rg_index_exists(), 'unguarded: the deactivation hook really drops the index');
activate_plugin('sspa-guard-reactor/sspa-guard-reactor.php');
$wpdb->query("ALTER TABLE {$wpdb->options} ADD INDEX sspa_guard_idx (autoload)");
delete_option('sspa_guard_hook_ran');
wp_cache_flush();

// --- The statement guard, in isolation from everything else ---

require_once SSPA_PLUGIN_DIR . 'profiler/class-sspa-profiling-wpdb.php';
$sspa_shim = new SSPA_Profiling_WPDB(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);

$GLOBALS['sspa_isolation_cell'] = true;
$GLOBALS['sspa_plugin_reactions'] = array();
$sspa_shim->query("ALTER TABLE {$wpdb->options} DROP INDEX sspa_guard_idx");
sspa_rg_t(sspa_rg_index_exists(), 'guard on: the destructive statement is refused');
sspa_rg_t(1 === count($GLOBALS['sspa_plugin_reactions']), 'guard on: the attempt is recorded');
sspa_rg_t(
    !SSPA_Profiling_WPDB::sspa_is_destructive("SELECT * FROM {$wpdb->options} LIMIT 1")
    && !SSPA_Profiling_WPDB::sspa_is_destructive("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%'")
    && !SSPA_Profiling_WPDB::sspa_is_destructive("ALTER TABLE {$wpdb->options} ADD INDEX another_idx (autoload)")
    && SSPA_Profiling_WPDB::sspa_is_destructive("DROP TABLE {$wpdb->options}")
    && SSPA_Profiling_WPDB::sspa_is_destructive("TRUNCATE {$wpdb->options}")
    && SSPA_Profiling_WPDB::sspa_is_destructive("DELETE FROM {$wpdb->options}"),
    'the destructive classifier: reads, bounded deletes and additive ALTERs pass; DROP, TRUNCATE and whole-table DELETE do not'
);

unset($GLOBALS['sspa_isolation_cell']);
$sspa_shim->query("ALTER TABLE {$wpdb->options} DROP INDEX sspa_guard_idx");
sspa_rg_t(!sspa_rg_index_exists(), 'guard off: the same statement executes - the guard is the only thing blocking it');
$wpdb->query("ALTER TABLE {$wpdb->options} ADD INDEX sspa_guard_idx (autoload)");
$GLOBALS['sspa_plugin_reactions'] = array();

// --- End to end: a sweep provokes the reaction, and nothing lands ---

$sspa_together = SSPA_Dependency_Map::must_exclude_together();
sspa_rg_t(
    empty($sspa_together['sspa-guard-dep']),
    'the scanner cannot see this pair, so the reactor really will be orphaned'
);

$sspa_source = SSPA_Run_Controller::start(array('type' => 'spot', 'page_keys' => array('home'), 'user_id' => 1));
$sspa_deadline = time() + 300;
do {
    SSPA_Run_Controller::process_batch($sspa_source);
    $sspa_s = SSPA_Run_Controller::status($sspa_source);
} while ($sspa_s && in_array($sspa_s['status'], array('crawling', 'analysing'), true) && time() < $sspa_deadline);
sspa_rg_t($sspa_s && 'done' === $sspa_s['status'], 'source run done: ' . ($sspa_s ? $sspa_s['status'] : 'null'));

$sspa_sweep = SSPA_Run_Controller::start(array(
    'type' => 'deep',
    'suspects' => array('sspa-guard-dep'),
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
    sspa_rg_t($sspa_s && 'done' === $sspa_s['status'], 'sweep done: ' . ($sspa_s ? $sspa_s['status'] : 'null'));

    wp_cache_flush();
    sspa_rg_t((int) get_option('sspa_guard_orphaned') > 0, 'the reactor found itself orphaned (' . (int) get_option('sspa_guard_orphaned') . ' times)');
    sspa_rg_t(!get_option('sspa_guard_hook_ran'), 'its deactivation routine NEVER ran (hook neutered)');
    sspa_rg_t(sspa_rg_index_exists(), 'the index survived the inline drop (statement guard)');
    $sspa_active = (array) get_option('active_plugins', array());
    sspa_rg_t(
        in_array('sspa-guard-dep/sspa-guard-dep.php', $sspa_active, true)
        && in_array('sspa-guard-reactor/sspa-guard-reactor.php', $sspa_active, true),
        'both plugins are still active'
    );

    // The catch: a finding on the run (which every shared payload carries), notes on the
    // run, and a learned group for the next sweep.
    $sspa_finding = $wpdb->get_row($wpdb->prepare(
        'SELECT * FROM ' . SSPA_Schema::table('findings') . " WHERE run_id = %d AND finding_type = 'isolation_reaction'",
        $sspa_sweep
    ), ARRAY_A);
    sspa_rg_t(is_array($sspa_finding), 'the reaction is a finding on the run');
    if (is_array($sspa_finding)) {
        sspa_rg_t('sspa-guard-reactor' === $sspa_finding['component'], 'the finding names the reactor (' . $sspa_finding['component'] . ')');
        $sspa_ev = json_decode((string) $sspa_finding['evidence'], true);
        sspa_rg_t(is_array($sspa_ev) && 'sspa-guard-dep' === $sspa_ev['excluded'], 'the evidence names the excluded plugin');
        sspa_rg_t(is_array($sspa_ev) && !empty($sspa_ev['ops']['sql']) && !empty($sspa_ev['ops']['deactivate']), 'the evidence records both reaction kinds');

        // A reaction is only useful to superspeedy.org as a PAIR: "something reacted" cannot
        // become a community dependency map. Both share paths are checked, against the row
        // the run actually wrote - the community outbox and the Share tab payload have
        // separate allowlists and one used to drop the excluded plugin.
        $sspa_shared = SSPA_Community_Privacy::finding_evidence((array) $sspa_ev);
        sspa_rg_t(
            isset($sspa_shared['excluded']) && 'sspa-guard-dep' === $sspa_shared['excluded'] && !empty($sspa_shared['ops']['sql']),
            'the community payload carries the pair, not just the reactor'
        );
        $sspa_built = SSPA_Community_Exporter::build($sspa_sweep);
        $sspa_shared_finding = null;
        if (!is_wp_error($sspa_built)) {
            foreach ((array) $sspa_built['evidence'] as $sspa_item) {
                if ('sspa/finding' === $sspa_item['type'] && 'isolation_reaction' === $sspa_item['data']['finding_type']) {
                    $sspa_shared_finding = $sspa_item['data'];
                }
            }
        }
        sspa_rg_t(
            $sspa_shared_finding && 'sspa-guard-dep' === $sspa_shared_finding['evidence']['excluded'],
            'the real submission payload names the excluded plugin'
        );
        // Raw SQL must still never leave the site - only its fingerprint.
        sspa_rg_t(
            $sspa_shared_finding
            && !isset($sspa_shared_finding['evidence']['sql'])
            && !empty($sspa_shared_finding['evidence']['fingerprint'])
            && !isset($sspa_shared['sql']),
            'and it carries the fingerprint of the refused statement, never the statement'
        );

        // What the site owner sees. Without a case of its own this renders as the literal
        // string "isolation_reaction - sspa-guard-reactor".
        $sspa_rendered = SSPA_Insights::render($sspa_finding);
        sspa_rg_t(
            false !== strpos($sspa_rendered['headline'], 'sspa-guard-reactor')
            && false !== strpos($sspa_rendered['headline'], 'sspa-guard-dep')
            && false === strpos($sspa_rendered['headline'], 'isolation_reaction'),
            'the insight names both plugins in plain English: ' . $sspa_rendered['headline']
        );
    }

    // Notified: a sweep can finish with nobody on the analysis screen.
    $sspa_notice = (array) get_option(SSPA_Run_Controller::REACTION_NOTICE_OPTION, array());
    sspa_rg_t(
        isset($sspa_notice['sspa-guard-dep|sspa-guard-reactor']),
        'the admin notice is armed with the pair'
    );
    ob_start();
    wp_set_current_user(1); // the notice is for whoever can manage plugins
    SSPA_Admin_Page::reaction_notice();
    $sspa_notice_html = ob_get_clean();
    sspa_rg_t(
        false !== strpos($sspa_notice_html, 'sspa-guard-reactor') && false !== strpos($sspa_notice_html, 'sspa-guard-dep'),
        'and it renders naming the reactor and the excluded plugin'
    );
    $sspa_notes = json_decode((string) $wpdb->get_var($wpdb->prepare(
        'SELECT notes FROM ' . SSPA_Schema::table('runs') . ' WHERE id = %d',
        $sspa_sweep
    )), true);
    sspa_rg_t(!empty($sspa_notes['reactions']), 'the run notes carry the reaction');

    $sspa_learned = (array) get_option(SSPA_Dependency_Map::LEARNED_OPTION, array());
    sspa_rg_t(
        isset($sspa_learned['sspa-guard-dep']) && in_array('sspa-guard-reactor', $sspa_learned['sspa-guard-dep'], true),
        'the pair is learned'
    );
    $sspa_together = SSPA_Dependency_Map::must_exclude_together();
    sspa_rg_t(
        isset($sspa_together['sspa-guard-dep']) && in_array('sspa-guard-reactor', $sspa_together['sspa-guard-dep'], true),
        'grouping now covers what the scanner could not see'
    );

    // --- The learned group closes the loop: the next sweep never provokes it ---

    $sspa_before = (int) get_option('sspa_guard_orphaned');
    $sspa_again = SSPA_Run_Controller::start(array(
        'type' => 'deep',
        'suspects' => array('sspa-guard-dep'),
        'page_keys' => array('home'),
        'cache_modes' => false,
        'user_id' => 1,
    ));
    if (!is_wp_error($sspa_again)) {
        $sspa_queue = SSPA_Run_Queue::get($sspa_again);
        $sspa_grouped = false;
        foreach ((array) $sspa_queue['jobs'] as $sspa_job) {
            if (!empty($sspa_job['plugin']) && !empty($sspa_job['group']) && in_array('sspa-guard-reactor', (array) $sspa_job['group'], true)) {
                $sspa_grouped = true;
            }
        }
        sspa_rg_t($sspa_grouped, 'the next sweep excludes the learned pair together');

        $sspa_deadline = time() + 300;
        do {
            SSPA_Run_Controller::process_batch($sspa_again);
            $sspa_s = SSPA_Run_Controller::status($sspa_again);
        } while ($sspa_s && in_array($sspa_s['status'], array('crawling', 'analysing'), true) && time() < $sspa_deadline);
        sspa_rg_t($sspa_s && 'done' === $sspa_s['status'], 'second sweep done: ' . ($sspa_s ? $sspa_s['status'] : 'null'));

        wp_cache_flush();
        sspa_rg_t(
            (int) get_option('sspa_guard_orphaned') === $sspa_before,
            'the reactor was never orphaned again (' . (int) get_option('sspa_guard_orphaned') . ' vs ' . $sspa_before . ')'
        );
        $sspa_impact = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . SSPA_Schema::table('plugin_impacts') . " WHERE test_run_id = %d AND plugin = 'sspa-guard-dep'",
            $sspa_again
        ), ARRAY_A);
        sspa_rg_t(is_array($sspa_impact), 'and the pair finally got a verdict');
        if (is_array($sspa_impact)) {
            sspa_rg_t('sspa-guard-reactor' === $sspa_impact['group_members'], 'which records who it covers');
        }
    }
}

// --- Cleanup ---
deactivate_plugins(array('sspa-guard-dep/sspa-guard-dep.php', 'sspa-guard-reactor/sspa-guard-reactor.php'), true);
$wpdb->query("ALTER TABLE {$wpdb->options} DROP INDEX sspa_guard_idx");
@unlink($sspa_dep_dir . '/sspa-guard-dep.php');
@unlink($sspa_reactor_dir . '/sspa-guard-reactor.php');
@rmdir($sspa_dep_dir);
@rmdir($sspa_reactor_dir);
delete_option('sspa_guard_hook_ran');
delete_option('sspa_guard_orphaned');
delete_option(SSPA_Dependency_Map::SIGNALS_OPTION);
delete_option(SSPA_Dependency_Map::LEARNED_OPTION);
unset($GLOBALS['sspa_plugin_reactions']);
sspa_rg_t(!is_dir($sspa_dep_dir) && !is_dir($sspa_reactor_dir) && !sspa_rg_index_exists(), 'fixtures and index removed');
