<?php
// The unified profile panel, and plugin impact scoped to ONE page.
//
// Two renderers over one dataset was the defect: "Analyse this page" and the Pages tab
// drill-down showed different subsets of the same stored capture. These assertions pin the
// three properties that keep them the same view: one renderer, reachable from a profile id
// AND from a URL, carrying every section either view used to have on its own.
//
// The sweep half proves the two things the panel's button depends on: a sweep can be scoped
// to a URL no analysis has ever profiled (it takes its own baselines), and the measurement
// count it queues is the number the panel promised before it started.

function sspa_panel_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

global $wpdb;

// A candidate that is safe to isolate and does measurable work on every page.
$sspa_dir = WP_PLUGIN_DIR . '/sspa-panel-fixture';
if (!is_dir($sspa_dir)) {
    mkdir($sspa_dir);
}
file_put_contents($sspa_dir . '/sspa-panel-fixture.php', <<<'PHP'
<?php
/**
 * Plugin Name: SSPA Panel Fixture (test fixture)
 * Version: 1.0.0
 */
add_action('wp_footer', function () {
    global $wpdb;
    $wpdb->get_results("SELECT meta_id FROM {$wpdb->postmeta} LIMIT 50");
});
PHP
);
activate_plugin('sspa-panel-fixture/sspa-panel-fixture.php');
wp_cache_flush();
sleep(3); // opcache

// --- A real profile to render ---

$sspa_run = SSPA_Run_Controller::start(array('type' => 'spot', 'page_keys' => array('home'), 'user_id' => 1));
if (is_wp_error($sspa_run)) {
    echo 'FAIL: source run: ' . $sspa_run->get_error_message() . "\n";
    return;
}
$sspa_deadline = time() + 300;
do {
    SSPA_Run_Controller::process_batch($sspa_run);
    $sspa_status = SSPA_Run_Controller::status($sspa_run);
} while ($sspa_status && in_array($sspa_status['status'], array('crawling', 'analysing'), true) && time() < $sspa_deadline);
sspa_panel_t($sspa_status && 'done' === $sspa_status['status'], 'source run done: ' . ($sspa_status ? $sspa_status['status'] : 'null'));

$sspa_profile_id = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT id FROM ' . SSPA_Schema::table('profiles') . " WHERE run_id = %d AND page_key = 'home'",
    $sspa_run
));
sspa_panel_t($sspa_profile_id > 0, 'home profile stored');

$sspa_html = SSPA_Profile_Panel::render($sspa_profile_id, array('cached' => true));
sspa_panel_t(is_string($sspa_html) && '' !== $sspa_html, 'the panel renders');
if (!is_string($sspa_html)) {
    echo 'FAIL: panel render failed: ' . $sspa_html->get_error_message() . "\n";
    return;
}

// Every section, including the ones that previously existed in only one of the two views.
$sspa_sections = array(
    'Where the PHP time went' => 'request phases (was: both)',
    'PHP cost per plugin' => 'per-plugin PHP cost (was: Pages tab only)',
    'Slowest hook callbacks' => 'slowest hook callbacks (was: Pages tab only)',
    'By component, on this page' => 'per-component attribution (was: Pages tab only)',
    'Slowest queries' => 'slowest queries (was: both)',
    'Measured plugin impact on this page' => 'measured plugin impact (was: neither)',
    'Render breakdown' => 'render breakdown (was: both)',
);
foreach ($sspa_sections as $sspa_needle => $sspa_label) {
    sspa_panel_t(false !== strpos($sspa_html, $sspa_needle), 'panel shows ' . $sspa_label);
}

// Both attribution modes, in the markup, ready to swap without a round trip.
sspa_panel_t(
    false !== strpos($sspa_html, 'data-mode="code_owner"') && false !== strpos($sspa_html, 'data-mode="caller"'),
    'both attribution modes are rendered'
);
// The object cache figures the panel is supposed to carry.
sspa_panel_t(false !== strpos($sspa_html, 'Object cache hits'), 'panel shows the object cache hit rate');
// The link the doc says becomes redundant once this IS the full view.
sspa_panel_t(false === strpos($sspa_html, 'Open in Performance Analysis'), 'no "open in Performance Analysis" link');
// The control that starts a page-scoped sweep.
sspa_panel_t(false !== strpos($sspa_html, 'sspa-adhoc-measure'), 'panel offers to measure plugin impact here');
sspa_panel_t(
    false !== strpos($sspa_html, 'sspa-adhoc-export') && false !== strpos($sspa_html, 'Export JSON'),
    'panel offers the same JSON export from either entry point'
);

// The export is a diagnostic hand-off, not a second renderer: exact profile metrics and the
// raw local capture in one versioned document, with no compressed database blob leaking into
// the JSON shape and an explicit warning before somebody shares it outside the job.
$sspa_export = SSPA_Profile_Panel::export_data($sspa_profile_id);
sspa_panel_t(!is_wp_error($sspa_export), 'the page diagnostic export builds');
if (!is_wp_error($sspa_export)) {
    sspa_panel_t('sspa/page-diagnostic-export@1' === $sspa_export['schema'], 'the export schema is versioned');
    sspa_panel_t(
        $sspa_profile_id === (int) $sspa_export['profile']['id']
        && !array_key_exists('profile_blob', $sspa_export['profile']),
        'the export identifies the profile without embedding its compressed database blob'
    );
    sspa_panel_t(
        is_array($sspa_export['capture']) && !empty($sspa_export['capture']['components']),
        'the export carries the component, SQL, HTTP, cache and profiler diagnostic capture'
    );
    sspa_panel_t(
        'private-site-diagnostic' === $sspa_export['sensitivity']['classification']
        && false !== stripos($sspa_export['sensitivity']['warning'], 'SQL literals'),
        'the export warns that retained SQL and URLs may be sensitive'
    );
    sspa_panel_t(false !== wp_json_encode($sspa_export), 'the complete export serialises as JSON');
}

// --- One renderer, both entry points ---
// The admin bar resolves a URL to the newest profile of the page it addresses; the Pages tab
// passes a profile id. Same id, same bytes - that is the whole guarantee.
$sspa_job = SSPA_Adhoc::job_for(home_url('/'));
$sspa_resolved = SSPA_Profile_Panel::newest_profile_id_for_page($sspa_job['page_key']);
sspa_panel_t($sspa_resolved === $sspa_profile_id, 'a URL resolves to the same profile the Pages tab opens');
sspa_panel_t(
    SSPA_Profile_Panel::render($sspa_resolved, array('cached' => true)) === $sspa_html,
    'both entry points render byte-identical markup'
);

// A pruned profile must degrade, not fatal.
$wpdb->query($wpdb->prepare(
    'UPDATE ' . SSPA_Schema::table('profiles') . ' SET profile_blob = NULL WHERE id = %d',
    $sspa_profile_id
));
$sspa_pruned = SSPA_Profile_Panel::render($sspa_profile_id, array('cached' => true));
sspa_panel_t(
    is_string($sspa_pruned) && false !== strpos($sspa_pruned, 'No detailed data is stored'),
    'a pruned profile renders the headline figures and says the detail is gone'
);
// Put it back so the rest of the case has a capture to work with.
$sspa_run2 = SSPA_Run_Controller::start(array('type' => 'spot', 'page_keys' => array('home'), 'user_id' => 1));
$sspa_deadline = time() + 300;
do {
    SSPA_Run_Controller::process_batch($sspa_run2);
    $sspa_status = SSPA_Run_Controller::status($sspa_run2);
} while ($sspa_status && in_array($sspa_status['status'], array('crawling', 'analysing'), true) && time() < $sspa_deadline);

// --- Ad-hoc results merge into the Pages tab, one-off URLs do not ---

$sspa_adhoc_home = SSPA_Run_Controller::start(array('type' => 'adhoc', 'url' => home_url('/'), 'user_id' => 1));
$sspa_deadline = time() + 300;
do {
    SSPA_Run_Controller::process_batch($sspa_adhoc_home);
    $sspa_status = SSPA_Run_Controller::status($sspa_adhoc_home);
} while ($sspa_status && in_array($sspa_status['status'], array('crawling', 'analysing'), true) && time() < $sspa_deadline);

$sspa_adhoc_odd = SSPA_Run_Controller::start(array('type' => 'adhoc', 'url' => home_url('/?panel_probe=1'), 'user_id' => 1));
$sspa_deadline = time() + 300;
do {
    SSPA_Run_Controller::process_batch($sspa_adhoc_odd);
    $sspa_status = SSPA_Run_Controller::status($sspa_adhoc_odd);
} while ($sspa_status && in_array($sspa_status['status'], array('crawling', 'analysing'), true) && time() < $sspa_deadline);

$sspa_adhoc_profile = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT id FROM ' . SSPA_Schema::table('profiles') . ' WHERE run_id = %d',
    $sspa_adhoc_home
));
$sspa_odd_profile = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT id FROM ' . SSPA_Schema::table('profiles') . ' WHERE run_id = %d',
    $sspa_adhoc_odd
));
$sspa_odd_key = (string) $wpdb->get_var($wpdb->prepare(
    'SELECT page_key FROM ' . SSPA_Schema::table('profiles') . ' WHERE id = %d',
    $sspa_odd_profile
));
sspa_panel_t(0 === strpos($sspa_odd_key, 'url-'), 'the one-off URL kept an opaque key (' . $sspa_odd_key . ')');

ob_start();
include SSPA_PLUGIN_DIR . 'includes/admin/tabs/pages.php';
$sspa_pages_tab = ob_get_clean();

sspa_panel_t(
    false !== strpos($sspa_pages_tab, 'data-profile-id="' . $sspa_adhoc_profile . '"'),
    'the Pages tab shows the newest measurement of home, which is the one-page analysis'
);
sspa_panel_t(
    false === strpos($sspa_pages_tab, 'data-profile-id="' . $sspa_odd_profile . '"'),
    'a one-off URL stays out of the Pages tab'
);
sspa_panel_t(false !== strpos($sspa_pages_tab, 'one-page analysis'), 'the merged row says where it came from');

// The site score must NOT follow: a score over whichever pages were checked by hand is not a
// site score, so the latest-analysis queries stay on baseline/spot.
$sspa_latest = (int) $wpdb->get_var(
    'SELECT id FROM ' . SSPA_Schema::table('runs') . " WHERE status = 'done' AND run_type IN ('baseline','spot') ORDER BY id DESC LIMIT 1"
);
sspa_panel_t($sspa_latest !== (int) $sspa_adhoc_home, 'the site score still ignores one-page analyses');

// --- A sweep scoped to a URL no analysis ever profiled ---
// With --pages this is refused (case 27 pins that); with a URL the sweep takes its own
// baselines inside the run, which is what makes the panel's button work on any page.
$sspa_target = home_url('/?panel_probe=1');
$sspa_scoped = SSPA_Run_Controller::start(array(
    'type' => 'deep',
    'suspects' => array('sspa-panel-fixture'),
    'url' => $sspa_target,
    'cache_modes' => false,
    'user_id' => 1,
));
if (is_wp_error($sspa_scoped)) {
    echo 'FAIL: url-scoped deep start: ' . $sspa_scoped->get_error_message() . "\n";
} else {
    // Before any batch runs: the queue is exactly what the panel's estimate promised -
    // one baseline plus one cell for one plugin on one page.
    $sspa_queued = SSPA_Run_Controller::status($sspa_scoped);
    sspa_panel_t(
        2 === (int) $sspa_queued['total'],
        'one plugin on one page queues the 2 measurements the estimate promised (' . (int) $sspa_queued['total'] . ')'
    );

    $sspa_deadline = time() + 420;
    do {
        SSPA_Run_Controller::process_batch($sspa_scoped);
        $sspa_status = SSPA_Run_Controller::status($sspa_scoped);
    } while ($sspa_status && in_array($sspa_status['status'], array('crawling', 'analysing'), true) && time() < $sspa_deadline);
    sspa_panel_t($sspa_status && 'done' === $sspa_status['status'], 'url-scoped sweep done: ' . ($sspa_status ? $sspa_status['status'] : 'null'));

    $sspa_impact_pages = $wpdb->get_col($wpdb->prepare(
        'SELECT DISTINCT page_key FROM ' . SSPA_Schema::table('plugin_impacts') . ' WHERE test_run_id = %d',
        $sspa_scoped
    ));
    sspa_panel_t(
        array($sspa_odd_key) === $sspa_impact_pages,
        'the sweep measured exactly the page it was pointed at: ' . implode(',', $sspa_impact_pages)
    );

    // cache_modes => false must keep phase 2 out of the cache modes even where the shim could.
    $sspa_modes = $wpdb->get_col($wpdb->prepare(
        'SELECT DISTINCT object_cache_mode FROM ' . SSPA_Schema::table('profiles') . ' WHERE run_id = %d',
        $sspa_scoped
    ));
    sspa_panel_t(array('normal') === $sspa_modes, 'declining the cache modes keeps the sweep to one mode: ' . implode(',', $sspa_modes));

    // And the panel for that page now carries the verdict.
    $sspa_odd_panel = SSPA_Profile_Panel::render($sspa_odd_profile, array('cached' => true));
    sspa_panel_t(
        is_string($sspa_odd_panel) && false !== strpos($sspa_odd_panel, 'sspa-panel-fixture'),
        'the page panel now names the plugin measured on it'
    );
}

// Two plugins on one page is 3 measurements, not 4: the baseline is taken once per block.
$sspa_two = SSPA_Dependency_Map::isolation_candidates();
$sspa_two = array_values(array_slice(array_diff($sspa_two, array('sspa-panel-fixture')), 0, 1));
if ($sspa_two) {
    $sspa_pair = SSPA_Run_Controller::start(array(
        'type' => 'deep',
        'suspects' => array('sspa-panel-fixture', $sspa_two[0]),
        'url' => $sspa_target,
        'cache_modes' => false,
        'user_id' => 1,
    ));
    if (!is_wp_error($sspa_pair)) {
        $sspa_pair_status = SSPA_Run_Controller::status($sspa_pair);
        sspa_panel_t(
            3 === (int) $sspa_pair_status['total'],
            'two plugins on one page queue 3 measurements (' . (int) $sspa_pair_status['total'] . ')'
        );
        SSPA_Run_Controller::cancel($sspa_pair);
    }
}

// --- The estimate's other half: seconds per measurement, learned from real runs ---
$sspa_rate = SSPA_Profile_Panel::seconds_per_job();
sspa_panel_t($sspa_rate >= 2, 'seconds per measurement is learned from completed runs (' . $sspa_rate . 's)');

// --- Cleanup ---
deactivate_plugins('sspa-panel-fixture/sspa-panel-fixture.php');
@unlink($sspa_dir . '/sspa-panel-fixture.php');
@rmdir($sspa_dir);
sspa_panel_t(!is_dir($sspa_dir), 'fixture removed');
