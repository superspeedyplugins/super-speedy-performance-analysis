<?php
// Table names go through $wpdb->prepare()'s %i identifier placeholder, not string
// concatenation. Added 16 August 2026 with the sweep that converted ~150 query sites.
//
// %i arrived in WordPress 6.2, which is already this plugin's floor, so no version bump was
// needed. It backtick-quotes the identifier, which is why the queries below still work.
//
// Two things are asserted, and the second is the one that matters: that the rewritten
// queries still return the RIGHT ROWS. A query that runs without error but silently selects
// nothing would satisfy "no SQL error" while being completely broken, and several of these
// feed the Plugins tab.

function sspa_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

global $wpdb;
$sspa_prev_suppress = $wpdb->suppress_errors(true);

// ---------------------------------------------------------------- %i is actually available
$sspa_probe = $wpdb->prepare('SELECT 1 FROM %i LIMIT 1', $wpdb->prefix . 'sspa_runs');
sspa_t(strpos($sspa_probe, '`' . $wpdb->prefix . 'sspa_runs`') !== false,
    '%i backtick-quotes the identifier (WP >= 6.2)');

// ---------------------------------------------------------------- fixture rows
// Real rows through the real schema. Two plugins on one page, so a filter that ignores its
// argument returns 2 and a filter that works returns 1 - the difference is what proves it.
$sspa_runs = SSPA_Schema::table('runs');
$sspa_impacts = SSPA_Schema::table('plugin_impacts');

// Clear the PREVIOUS run's fixtures, on the way IN. Determinism comes from clearing at
// startup, never at the end: the site is left exactly as the tests left it so Dave can log
// in and inspect what they produced, and a case that tidies up destroys that evidence.
$wpdb->delete($sspa_impacts, array('page_key' => 'sspa-fixture-page'));

$wpdb->insert($sspa_runs, array(
    'run_uuid' => wp_generate_uuid4(), 'run_type' => 'deep', 'status' => 'done',
    'started' => gmdate('Y-m-d H:i:s'), 'finished' => gmdate('Y-m-d H:i:s'),
));
$sspa_run_id = (int) $wpdb->insert_id;
sspa_t($sspa_run_id > 0, 'fixture run inserted');

foreach (array('sspa-fixture-alpha', 'sspa-fixture-beta') as $sspa_slug) {
    $wpdb->insert($sspa_impacts, array(
        'test_run_id' => $sspa_run_id, 'baseline_run_id' => $sspa_run_id,
        'plugin' => $sspa_slug, 'page_key' => 'sspa-fixture-page', 'method' => 'exclusion',
        'object_cache_mode' => 'normal', 'delta_ttfb_ms' => 12.5, 'confidence' => 'high',
        'created' => gmdate('Y-m-d H:i:s'),
    ));
}

$sspa_q = function ($plugin = '', $page = '') use ($wpdb) {
    return $wpdb->get_results(SSPA_Plugins_Table::latest_impacts_sql($plugin, $page), ARRAY_A);
};

// ---------------------------------------------------------------- the filters discriminate
$sspa_page_rows = $sspa_q('', 'sspa-fixture-page');
sspa_t(count($sspa_page_rows) === 2, 'page filter returns both fixture rows (got ' . count($sspa_page_rows) . ')');
sspa_t($wpdb->last_error === '', 'page-filtered query ran without a SQL error');

$sspa_one = $sspa_q('sspa-fixture-alpha');
sspa_t(count($sspa_one) === 1, 'plugin filter narrows to one row (got ' . count($sspa_one) . ')');
sspa_t(count($sspa_one) === 1 && $sspa_one[0]['plugin'] === 'sspa-fixture-alpha', 'and it is the right row');

sspa_t(count($sspa_q('sspa-fixture-nope')) === 0, 'an unknown plugin returns nothing');
sspa_t(count($sspa_q('sspa-fixture-alpha', 'sspa-fixture-page')) === 1, 'plugin + page together match');
sspa_t(count($sspa_q('sspa-fixture-alpha', 'no-such-page')) === 0, 'plugin + wrong page match nothing');

// ---------------------------------------------------------------- values stay values
// The old code concatenated; a quote in the value would have changed the query's shape.
// Under prepare() this must come back as "no such plugin", not as an error or every row.
$sspa_inject = $sspa_q("sspa-fixture-alpha' OR '1'='1");
sspa_t(count($sspa_inject) === 0, "a quote in the plugin name is a literal, not syntax");
sspa_t($wpdb->last_error === '', 'and it caused no SQL error');

// ---------------------------------------------------------------- the rewritten readers work
$wpdb->last_error = '';
$sspa_impact_rows = SSPA_Report::impacts();
sspa_t($wpdb->last_error === '', 'SSPA_Report::impacts() runs clean');
$sspa_found = false;
foreach ((array) $sspa_impact_rows as $sspa_row) {
    if (isset($sspa_row['plugin']) && $sspa_row['plugin'] === 'sspa-fixture-alpha') {
        $sspa_found = true;
    }
}
sspa_t($sspa_found, 'SSPA_Report::impacts() returns the fixture row');

foreach (array(
    'SSPA_Report::latest_done_run_id' => function () { return SSPA_Report::latest_done_run_id(); },
    'SSPA_Plugins_Table::latest_run_id' => function () { return SSPA_Plugins_Table::latest_run_id(); },
    'SSPA_Community_Outbox::counts' => function () { return SSPA_Community_Outbox::counts(); },
    'SSPA_Community_Outbox::queue_status' => function () { return SSPA_Community_Outbox::queue_status(); },
    'SSPA_Community_Outbox::history' => function () { return SSPA_Community_Outbox::history(); },
    'SSPA_Demographics::latest' => function () { return SSPA_Demographics::latest(); },
    'SSPA_Profile_Panel::seconds_per_job' => function () { return SSPA_Profile_Panel::seconds_per_job(); },
    'SSPA_Attribution::component_rows' => function () use ($sspa_run_id) { return SSPA_Attribution::component_rows($sspa_run_id, 'code_owner'); },
) as $sspa_label => $sspa_fn) {
    $wpdb->last_error = '';
    $sspa_fn();
    sspa_t($wpdb->last_error === '', "$sspa_label runs without a SQL error");
}

// ---------------------------------------------------------------- deliberately NO clean up
// The fixture run and its two impact rows stay in the database. They are cleared at the top
// of this file on the next run, so the assertions above remain deterministic while the
// evidence stays inspectable in wp-admin.
sspa_t(count($sspa_q('', 'sspa-fixture-page')) === 2, 'fixture rows left in place for inspection');

$wpdb->suppress_errors($sspa_prev_suppress);
