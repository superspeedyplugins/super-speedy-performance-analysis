<?php
// Two ONE-run analyses compare normally (Dave, 24 November 2026: one run before and one run
// after is a valid before/after; never gate or caveat it for having a single sample).
// Real spot runs of Home with Measurement runs per page = One run, driven by the real run
// controller over loopback, on a stable classic-free theme (Twenty Twenty-Five, as case 83):
//   - Before vs an unchanged After: timing deltas for both sides, output "unchanged", no
//     caveat text in the rendered comparison, the neutral mode label present;
//   - the Before's Home output can be approved as the expected output;
//   - after a real content change (an MU fixture appends a paragraph to every page): output
//     "changed", the declared expectation fails, and the update watchdog's approved-output
//     check (SSPA_Watchdog::page_regressions) reports an "output" finding;
//   - the page summary states the one cold request without telling the user to switch modes.
// 0.71.0 to 0.72.1 gave a one-sample page no output signature, so all of this was refused.
//
// Own site:  SSPA_SCENARIO=tests-single-sample .tests/run-tests.sh 168-one-run-compare
// State is reset on the way IN (content change off, no Home expectation). Nothing is torn down.
defined('ABSPATH') || exit;

$GLOBALS['sspa_168_failures'] = 0;
function sspa_168_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
    if (!$ok) { $GLOBALS['sspa_168_failures']++; }
}
function sspa_168_run() {
    $id = SSPA_Run_Controller::start(array('type' => 'spot', 'page_keys' => array('home'), 'user_id' => 1));
    if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
    $deadline = time() + 180;
    do {
        SSPA_Run_Controller::process_batch($id);
        $status = SSPA_Run_Controller::status($id);
    } while ($status && in_array($status['status'], array('crawling', 'analysing'), true) && time() < $deadline);
    if (!$status || 'done' !== $status['status']) { throw new RuntimeException('spot run ' . $id . ' did not complete'); }
    return (int) $id;
}
function sspa_168_home($comparison) {
    foreach ((is_wp_error($comparison) ? array() : (array) $comparison['pages']) as $page) {
        if ('home' === $page['page_key']) { return $page; }
    }
    return array();
}
function sspa_168_samples($run_id) {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare("SELECT id, samples FROM %i WHERE run_id = %d AND page_key = 'home' ORDER BY id DESC LIMIT 1", SSPA_Schema::table('profiles'), $run_id), ARRAY_A);
    return $row ? array((int) $row['id'], (array) json_decode((string) $row['samples'], true)) : array(0, array());
}
/** Any text that caveats, downgrades or nags a single-sample run. */
function sspa_168_caveats($text) {
    $found = array();
    foreach (array('not evidence', 'no spread', 'at least two', 'single request varies', 'for a firmer', 'set Measurement runs per page to Three runs') as $needle) {
        if (false !== stripos($text, $needle)) { $found[] = $needle; }
    }
    return $found;
}

if ('tests-single-sample' !== basename(rtrim(ABSPATH, '/'))) {
    echo 'FAIL: this case installs a content-changing MU plugin and approves a Home expectation; run it on its own site: SSPA_SCENARIO=tests-single-sample (this is ' . home_url() . ")\n";
    return;
}
wp_set_current_user(1);
$active = SSPA_Run_Controller::active_run_id();
if ($active) { SSPA_Run_Controller::cancel($active); SSPA_Run_Controller::process_batch($active); }

// --- On the way in ---------------------------------------------------------------------------
wp_mkdir_p(WPMU_PLUGIN_DIR);
file_put_contents(WPMU_PLUGIN_DIR . '/sspa-168-content-change.php', "<?php\n// Case 168 fixture: a real content change on every front-end page while the option is on.\nadd_action('wp_footer', function () { if (get_option('sspa_168_changed')) { echo '<p class=\"sspa-168\">Content changed for case 168.</p>'; } });\n");
update_option('sspa_168_changed', 0, true);
sspa_update_option('measurement_runs', SSPA_Measurement::ONE);
$home_identity = SSPA_History_Series::page_identity(array('page_key' => 'home', 'method' => 'GET', 'variant' => 'anon', 'object_cache_mode' => 'normal'));
SSPA_History::clear_assertion($home_identity);
if (!wp_get_theme('twentytwentyfive')->exists()) {
    throw new RuntimeException('Case 168 requires the Twenty Twenty-Five theme');
}
switch_theme('twentytwentyfive');
wp_cache_flush();
sspa_168_t('one' === sspa_get_option('measurement_runs'), 'Measurement runs per page is One run');

// --- Before and an unchanged After, one run each -----------------------------------------------
$before = sspa_168_run();
$after_same = sspa_168_run();
list($before_profile, $before_samples) = sspa_168_samples($before);
list(, $after_samples) = sspa_168_samples($after_same);
sspa_168_t(1 === count($before_samples) && 1 === count($after_samples), 'each run measured Home exactly once (' . count($before_samples) . ', ' . count($after_samples) . ')');

$same = SSPA_History::compare($before, $after_same);
$home = sspa_168_home($same);
$gen = $home['metrics']['generation_ms'] ?? array();
sspa_168_t(null !== ($gen['before'] ?? null) && null !== ($gen['after'] ?? null) && null !== ($gen['delta'] ?? null), 'Compare shows the generation-time delta between the two single requests (' . wp_json_encode($gen) . ')');
sspa_168_t('unchanged' === ($home['output']['state'] ?? null), 'identical Home output compares as unchanged (got ' . var_export($home['output']['state'] ?? null, true) . ')');
$html = is_wp_error($same) ? '' : SSPA_History::render($same);
sspa_168_t('' !== $html && array() === sspa_168_caveats($html), 'the rendered comparison carries no single-sample caveat (found: ' . implode(', ', sspa_168_caveats($html)) . ')');
sspa_168_t(false !== strpos($html, '1 measured request per page'), 'the comparison still names the mode each run used');

$report = SSPA_Report::page_plugin_usage($before);
$report_home = array();
foreach (is_wp_error($report) ? array() : (array) $report['pages'] as $p) { if ('home' === $p['page_key']) { $report_home = $p; } }
sspa_168_t(true === ($report_home['output_stable'] ?? null) && !empty($report_home['output_signature']), 'the agent report gives a one-run page its output signature (' . wp_json_encode(array('stable' => $report_home['output_stable'] ?? 'missing', 'signature' => $report_home['output_signature'] ?? null)) . ')');

$md = SSPA_Markdown_Export::build('page', $before_profile);
$md_text = is_array($md) && isset($md['markdown']) ? $md['markdown'] : '';
sspa_168_t(false !== strpos($md_text, 'One measured request:') && array() === sspa_168_caveats($md_text), 'the page summary states the one cold request with no nag (found: ' . implode(', ', sspa_168_caveats($md_text)) . ')');

// --- Approve the Before, then a real content change ----------------------------------------------
$approved = SSPA_History::approve_assertion($before, $home_identity);
sspa_168_t(true === $approved, 'a one-run Before can be approved as the expected Home output' . (is_wp_error($approved) ? ' (' . $approved->get_error_message() . ')' : ''));
$quiet = SSPA_Watchdog::page_regressions($before, $after_same);
sspa_168_t(array() === array_filter((array) $quiet, static function ($f) { return 'output' === $f['kind']; }), 'the watchdog finds no output change for the unchanged After');

update_option('sspa_168_changed', 1, true);
wp_cache_flush();
$after_changed = sspa_168_run();
update_option('sspa_168_changed', 0, true);
wp_cache_flush();
$changed = SSPA_History::compare($before, $after_changed);
$home = sspa_168_home($changed);
sspa_168_t('changed' === ($home['output']['state'] ?? null), 'a real content change compares as changed (got ' . var_export($home['output']['state'] ?? null, true) . ')');
sspa_168_t('fail' === ($home['declared']['signature_state'] ?? null), 'the approved Home expectation fails on the changed output (got ' . var_export($home['declared']['signature_state'] ?? null, true) . ')');
$html = is_wp_error($changed) ? '' : SSPA_History::render($changed);
sspa_168_t(array() === sspa_168_caveats($html), 'the changed comparison carries no single-sample caveat');
$findings = SSPA_Watchdog::page_regressions($before, $after_changed);
$output_findings = array_values(array_filter((array) $findings, static function ($f) { return 'output' === $f['kind'] && 'home' === $f['page_key']; }));
sspa_168_t(1 === count($output_findings), 'the watchdog approved-output check fires with one run per page (' . wp_json_encode(wp_list_pluck((array) $findings, 'kind')) . ')');

echo "Runs: before $before, unchanged after $after_same, changed after $after_changed on " . home_url('/') . "\n";
if ($GLOBALS['sspa_168_failures']) {
    echo 'FAIL: ' . $GLOBALS['sspa_168_failures'] . " assertion(s) failed\n";
    exit(1);
}
echo "PASS: case 168\n";
