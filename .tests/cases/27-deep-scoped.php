<?php
// A deep run must honour --pages. Without it, re-measuring one plugin you have just fixed
// sweeps every page the last analysis profiled, which is the difference between a couple of
// minutes and hours - and is why a stale measured verdict sits on the Plugins tab unrefreshed.

function sspa_scoped_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

/** The one <tr> of the rendered table that belongs to $component, so assertions cannot
 *  accidentally match text from a different plugin's row. */
function sspa_scoped_row($html, $component) {
    foreach (explode('<tr>', $html) as $row) {
        if (false !== strpos($row, '<code>' . $component . '</code>')) {
            return $row;
        }
    }
    return '';
}

global $wpdb;

// A candidate that is safe to isolate: no dependants, does a little work on every page.
$dir = WP_PLUGIN_DIR . '/sspa-scoped-fixture';
if (!is_dir($dir)) {
    mkdir($dir);
}
file_put_contents($dir . '/sspa-scoped-fixture.php', <<<'PHP'
<?php
/**
 * Plugin Name: SSPA Scoped Fixture (test fixture)
 * Version: 1.0.0
 */
add_action('wp_footer', function () {
    global $wpdb;
    $wpdb->get_results("SELECT meta_id FROM {$wpdb->postmeta} LIMIT 50");
});
PHP
);
activate_plugin('sspa-scoped-fixture/sspa-scoped-fixture.php');
wp_cache_flush();
sleep(3); // opcache

sspa_scoped_t(
    in_array('sspa-scoped-fixture', SSPA_Dependency_Map::isolation_candidates(), true),
    'fixture is eligible for isolation'
);

// --- Source run over several pages, so scoping has something to narrow ---
$source_pages = array('home', 'blog', 'post-single');
$source = SSPA_Run_Controller::start(array('type' => 'spot', 'page_keys' => $source_pages, 'user_id' => 1));
if (is_wp_error($source)) {
    echo 'FAIL: source run: ' . $source->get_error_message() . "\n";
    return;
}
$deadline = time() + 300;
do {
    SSPA_Run_Controller::process_batch($source);
    $s = SSPA_Run_Controller::status($source);
} while ($s && in_array($s['status'], array('crawling', 'analysing'), true) && time() < $deadline);
sspa_scoped_t($s && 'done' === $s['status'], 'source run done: ' . ($s ? $s['status'] : 'null'));

$profiled = $wpdb->get_col($wpdb->prepare(
    'SELECT DISTINCT page_key FROM ' . SSPA_Schema::table('profiles') . " WHERE run_id = %d AND page_key != 'baseline'",
    $source
));
sspa_scoped_t(count($profiled) >= 2, 'source run profiled ' . count($profiled) . ' pages');

// --- Deep run scoped to ONE of them ---
$deep = SSPA_Run_Controller::start(array(
    'type' => 'deep',
    'suspects' => array('sspa-scoped-fixture'),
    'page_keys' => array('home'),
    'user_id' => 1,
));
if (is_wp_error($deep)) {
    echo 'FAIL: scoped deep start: ' . $deep->get_error_message() . "\n";
    return;
}
// The running-analysis feed is built from status(). Its labels are asserted here BEFORE any
// batch runs: a batch works for up to BATCH_SECONDS (15), so a scoped run of six measurements
// completes inside one call and the queue option is already deleted by the time the loop's
// first status() lands. 'current' and 'recent' share one label builder, so checking the first
// queued measurement covers both.
$queued = SSPA_Run_Controller::status($deep);
sspa_scoped_t(
    !empty($queued['current']) && false !== strpos($queued['current'], 'with '),
    'a queued measurement reads as plain English: ' . (isset($queued['current']) ? $queued['current'] : 'none')
);
sspa_scoped_t(
    isset($queued['recent']) && is_array($queued['recent']),
    'status exposes the completed-measurement list the feed appends from'
);
sspa_scoped_t((int) $queued['total'] > 0, 'status reports a measurement total (' . (int) $queued['total'] . ')');

$deadline = time() + 420;
do {
    SSPA_Run_Controller::process_batch($deep);
    $s = SSPA_Run_Controller::status($deep);
} while ($s && in_array($s['status'], array('crawling', 'analysing'), true) && time() < $deadline);
sspa_scoped_t($s && 'done' === $s['status'], 'scoped deep run done: ' . ($s ? $s['status'] : 'null'));

// The whole point: impacts cover the requested page and nothing else.
$impact_pages = $wpdb->get_col($wpdb->prepare(
    'SELECT DISTINCT page_key FROM ' . SSPA_Schema::table('plugin_impacts') . ' WHERE test_run_id = %d',
    $deep
));
sspa_scoped_t(!empty($impact_pages), 'the scoped run produced impacts (' . count($impact_pages) . ' page(s))');
sspa_scoped_t(array('home') === $impact_pages, 'impacts cover ONLY the requested page: ' . implode(',', $impact_pages));

// It also has to profile fewer pages than the source run, or nothing was actually saved.
$deep_pages = $wpdb->get_col($wpdb->prepare(
    'SELECT DISTINCT page_key FROM ' . SSPA_Schema::table('profiles') . " WHERE run_id = %d AND page_key != 'baseline'",
    $deep
));
sspa_scoped_t(
    count($deep_pages) < count($profiled),
    'the scoped run crawled fewer pages than the source run (' . count($deep_pages) . ' < ' . count($profiled) . ')'
);

// And the version is stamped, so the Plugins tab can date the refreshed verdict.
$version = $wpdb->get_var($wpdb->prepare(
    'SELECT plugin_version FROM ' . SSPA_Schema::table('plugin_impacts') . ' WHERE test_run_id = %d LIMIT 1',
    $deep
));
sspa_scoped_t('1.0.0' === $version, 'refreshed impact carries the measured version (' . var_export($version, true) . ')');

// --- A page-scoped re-measure must not erase the pages it did not touch ---
// Selection is newest-per-plugin-per-page, not "every row of the plugin's latest run": keying
// on the run would make a one-page re-measure look as though the plugin only affects one page.
$sspa_wide = SSPA_Run_Controller::start(array(
    'type' => 'deep',
    'suspects' => array('sspa-scoped-fixture'),
    'user_id' => 1,
));
if (!is_wp_error($sspa_wide)) {
    $deadline = time() + 420;
    do {
        SSPA_Run_Controller::process_batch($sspa_wide);
        $s = SSPA_Run_Controller::status($sspa_wide);
    } while ($s && in_array($s['status'], array('crawling', 'analysing'), true) && time() < $deadline);

    $wide_pages = $wpdb->get_col($wpdb->prepare(
        'SELECT DISTINCT page_key FROM ' . SSPA_Schema::table('plugin_impacts') . ' WHERE test_run_id = %d',
        $sspa_wide
    ));
    sspa_scoped_t(count($wide_pages) >= 2, 'the wide sweep measured ' . count($wide_pages) . ' pages');

    // Now re-measure ONE page. The other page's verdict must survive.
    $sspa_again = SSPA_Run_Controller::start(array(
        'type' => 'deep',
        'suspects' => array('sspa-scoped-fixture'),
        'page_keys' => array('home'),
        'user_id' => 1,
    ));
    $deadline = time() + 420;
    do {
        SSPA_Run_Controller::process_batch($sspa_again);
        $s = SSPA_Run_Controller::status($sspa_again);
    } while ($s && in_array($s['status'], array('crawling', 'analysing'), true) && time() < $deadline);

    $shown = $wpdb->get_results(SSPA_Plugins_Table::latest_impacts_sql('sspa-scoped-fixture'), ARRAY_A);
    $shown_pages = array_values(array_unique(array_column($shown, 'page_key')));
    sort($shown_pages);
    sspa_scoped_t(count($shown_pages) >= 2, 'a page-scoped re-measure keeps the other pages: ' . implode(',', $shown_pages));

    $home_run = 0;
    foreach ($shown as $row) {
        if ('home' === $row['page_key']) {
            $home_run = max($home_run, (int) $row['test_run_id']);
        }
    }
    sspa_scoped_t($home_run === (int) $sspa_again, 'the re-measured page shows the NEWEST measurement');
}

// --- The Plugins tab must date the verdict, and flag it once it goes stale ---
// Three states, all produced by real runs rather than hand-written rows.

// 1. Fresh: the sweep is newer than the analysis being displayed.
$table = SSPA_Plugins_Table::render(SSPA_Plugins_Table::latest_run_id(), 'code_owner');
$row = sspa_scoped_row($table, 'sspa-scoped-fixture');
sspa_scoped_t(false !== strpos($row, 'measured '), 'fresh verdict is dated');
sspa_scoped_t(false === strpos($row, 're-measure to trust this'), 'fresh verdict is not flagged stale');

// 2. Stale by run: a newer analysis lands after the sweep. This is the case that catches
//    rows written before versions were recorded, where there is no version to compare.
$newer = SSPA_Run_Controller::start(array('type' => 'spot', 'page_keys' => array('home'), 'user_id' => 1));
$deadline = time() + 300;
do {
    SSPA_Run_Controller::process_batch($newer);
    $s = SSPA_Run_Controller::status($newer);
} while ($s && in_array($s['status'], array('crawling', 'analysing'), true) && time() < $deadline);

$table = SSPA_Plugins_Table::render(SSPA_Plugins_Table::latest_run_id(), 'code_owner');
$row = sspa_scoped_row($table, 'sspa-scoped-fixture');
sspa_scoped_t(false !== strpos($row, 'before the analysis above'), 'a sweep older than the analysis is flagged stale');
sspa_scoped_t(false !== strpos($row, 'measured '), 'the stale verdict still carries its date');

// 3. Stale by version: the plugin has been updated since it was measured. This is the one
//    that matters most - a fixed plugin still showing the cost of the version that was broken.
file_put_contents($dir . '/sspa-scoped-fixture.php', str_replace('Version: 1.0.0', 'Version: 2.0.0', file_get_contents($dir . '/sspa-scoped-fixture.php')));
wp_cache_flush();
sleep(3);
$updated = SSPA_Run_Controller::start(array('type' => 'spot', 'page_keys' => array('home'), 'user_id' => 1));
$deadline = time() + 300;
do {
    SSPA_Run_Controller::process_batch($updated);
    $s = SSPA_Run_Controller::status($updated);
} while ($s && in_array($s['status'], array('crawling', 'analysing'), true) && time() < $deadline);

sspa_scoped_t(
    '2.0.0' === SSPA_Run_Controller::component_version($updated, 'sspa-scoped-fixture'),
    'the new analysis recorded the updated version'
);
$table = SSPA_Plugins_Table::render(SSPA_Plugins_Table::latest_run_id(), 'code_owner');
$row = sspa_scoped_row($table, 'sspa-scoped-fixture');
sspa_scoped_t(false !== strpos($row, 'measured on version 1.0.0'), 'the verdict names the version it measured');
sspa_scoped_t(false !== strpos($row, 'you now run 2.0.0'), 'the verdict names the version now installed');
sspa_scoped_t(false !== strpos($row, 're-measure to trust this'), 'a version change flags the verdict stale');

// --- A page the source run never profiled must be refused, not silently swept ---
$bad = SSPA_Run_Controller::start(array(
    'type' => 'deep',
    'suspects' => array('sspa-scoped-fixture'),
    'page_keys' => array('page-that-was-never-profiled'),
    'user_id' => 1,
));
sspa_scoped_t(
    is_wp_error($bad) && 'sspa_pages_not_profiled' === $bad->get_error_code(),
    'an unprofiled page is refused (' . (is_wp_error($bad) ? $bad->get_error_code() : 'run started') . ')'
);

// --- Cleanup ---
deactivate_plugins('sspa-scoped-fixture/sspa-scoped-fixture.php');
@unlink($dir . '/sspa-scoped-fixture.php');
@rmdir($dir);
sspa_scoped_t(!is_dir($dir), 'fixture removed');
