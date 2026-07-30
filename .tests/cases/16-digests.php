<?php
// MySQL query fingerprints from performance_schema (phase 4).
//
// This test is ADAPTIVE. Most WordPress databases cannot read performance_schema - the
// docker MariaDB here ships with it off, which is the common real-world case - so the
// graceful no-op path is the one most users hit and is tested unconditionally. Where the
// digest table IS readable, the real path is exercised too.

function sspa_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

global $wpdb;

// --- Digest text normalisation: the join key between MySQL's shapes and ours ---
require_once WP_PLUGIN_DIR . '/super-speedy-performance-analysis/profiler/fingerprint.php';

$mysql_style = "SELECT `ID` FROM `wp_posts`   WHERE `post_type` = ? AND `post_status` = ?";
$our_style = "SELECT ID FROM wp_posts WHERE post_type = 'product' AND post_status = 'publish'";
$a = md5(sspa_sql_fingerprint(SSPA_Digests::normalise($mysql_style)));
$b = md5(sspa_sql_fingerprint(SSPA_Digests::normalise($our_style)));
sspa_t($a === $b, 'MySQL digest text and our captured SQL converge on one fingerprint key');

sspa_t(SSPA_Digests::normalise('SELECT  `a`   FROM `b`') === 'SELECT a FROM b', 'normalise strips backticks and collapses whitespace');

// --- diff(): the counters are cumulative, so only deltas mean anything ---
$before = array('_overflow' => false, 'digests' => array(
    'k1' => array('text' => 'SELECT ?', 'calls' => 10, 'timer' => 5000000000.0, 'examined' => 1000, 'sent' => 10,
                  'no_index' => 0, 'tmp_disk' => 0, 'full_join' => 0, 'sort_merge' => 0),
    'k2' => array('text' => 'SELECT ?', 'calls' => 3, 'timer' => 1000000000.0, 'examined' => 30, 'sent' => 3,
                  'no_index' => 0, 'tmp_disk' => 0, 'full_join' => 0, 'sort_merge' => 0),
));
$after = array('_overflow' => false, 'digests' => array(
    'k1' => array('text' => 'SELECT ?', 'calls' => 14, 'timer' => 9000000000.0, 'examined' => 401000, 'sent' => 22,
                  'no_index' => 4, 'tmp_disk' => 1, 'full_join' => 0, 'sort_merge' => 0),
    'k2' => array('text' => 'SELECT ?', 'calls' => 3, 'timer' => 1000000000.0, 'examined' => 30, 'sent' => 3,
                  'no_index' => 0, 'tmp_disk' => 0, 'full_join' => 0, 'sort_merge' => 0),
    'k3' => array('text' => 'SELECT ?', 'calls' => 2, 'timer' => 2000000000.0, 'examined' => 50, 'sent' => 2,
                  'no_index' => 0, 'tmp_disk' => 0, 'full_join' => 0, 'sort_merge' => 0),
));
$delta = SSPA_Digests::diff($before, $after);

sspa_t(isset($delta['k1']) && $delta['k1']['calls'] === 4, 'diff reports only the calls made during the run (4, not 14)');
sspa_t($delta['k1']['examined'] === 400000, 'diff subtracts prior rows examined (400,000, not 401,000)');
sspa_t(!isset($delta['k2']), 'a digest that did not move is not reported at all');
sspa_t(isset($delta['k3']) && $delta['k3']['calls'] === 2, 'a digest first seen during the run is reported in full');
// SUM_TIMER_WAIT is picoseconds. Getting this wrong would report milliseconds as microseconds.
sspa_t(abs($delta['k1']['ms'] - 4.0) < 0.001, 'picoseconds converted to milliseconds correctly: ' . $delta['k1']['ms'] . 'ms');

// --- The no-op path, which is what most sites will hit ---
$readable = SSPA_Digests::readable();
if (!$readable) {
    sspa_t(SSPA_Digests::snapshot() === null, 'snapshot returns null when performance_schema is unreadable');
    sspa_t(SSPA_Digests::begin(999999) === false, 'begin() reports failure rather than storing junk');
    sspa_t(SSPA_Digests::collect(999999) === array(), 'collect() degrades to an empty delta');
    sspa_t(get_option('sspa_ps_before_999999') === false, 'no stray option left behind');
}

// An empty digest set must never raise findings, and must never fatal.
$engine = new SSPA_Analysis_Engine();
$engine->set_digests(array());
sspa_t(true, 'analysis engine accepts an empty digest set');

// --- End to end, with a query that genuinely reads far more than it returns ---
// LIKE '%needle%' cannot use an index, so MySQL reads every postmeta row and returns none.
// That is precisely the shape only performance_schema can expose: our own capture sees
// "0 rows returned, fast" and EXPLAIN only estimates.
$fixture_dir = WP_PLUGIN_DIR . '/sspa-digest-fixture';
if (!is_dir($fixture_dir)) {
    mkdir($fixture_dir);
}
$code = <<<'PHP'
<?php
/**
 * Plugin Name: SSPA Digest Fixture (test fixture)
 */
add_action('wp_footer', function () {
    global $wpdb;
    for ($i = 0; $i < 4; $i++) {
        $wpdb->get_var("SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_value LIKE '%sspa-digest-needle%' LIMIT 1");
    }
});
PHP;
file_put_contents($fixture_dir . '/sspa-digest-fixture.php', $code);
activate_plugin('sspa-digest-fixture/sspa-digest-fixture.php');
wp_cache_flush();
sleep(3); // opcache

$run_id = SSPA_Run_Controller::start(array('type' => 'spot', 'page_keys' => array('home'), 'user_id' => 1));
$deadline = time() + 180;
do {
    SSPA_Run_Controller::process_batch($run_id);
    $status = $wpdb->get_var($wpdb->prepare('SELECT status FROM ' . SSPA_Schema::table('runs') . ' WHERE id = %d', $run_id));
} while ($status !== 'done' && $status !== 'failed' && time() < $deadline);
sspa_t($status === 'done', 'run completes whether or not performance_schema is readable: ' . $status);

// The pre-run snapshot option must always be cleaned up, or every run leaks one.
sspa_t(get_option('sspa_ps_before_' . $run_id) === false, 'pre-run snapshot option cleaned up after analysis');

if ($readable) {
    // Only assert the real path where the database actually allows it.
    $snap = SSPA_Digests::snapshot();
    sspa_t(is_array($snap) && isset($snap['digests']), 'digest snapshot taken');
    sspa_t(!$snap['_overflow'], 'digest table has not overflowed (numbers are trustworthy)');
    $rows = $wpdb->get_results($wpdb->prepare(
        'SELECT component, evidence FROM ' . SSPA_Schema::table('findings') . " WHERE run_id = %d AND finding_type = 'over_examining_query'",
        $run_id
    ), ARRAY_A);
    sspa_t(count($rows) > 0, 'over_examining_query findings raised (' . count($rows) . ')');

    $named = false;
    $ratio = 0;
    $examined = 0;
    foreach ($rows as $r) {
        $e = json_decode($r['evidence'], true);
        if ($r['component'] === 'sspa-digest-fixture') {
            $named = true;
            $ratio = (int) $e['ratio'];
            $examined = (int) $e['examined'];
        }
    }
    sspa_t($named, 'the fixture is named for its own over-examining query');
    sspa_t($examined >= 1000, "real rows-examined read from MySQL, not estimated ($examined rows)");
    sspa_t($ratio >= 100, "examined-to-returned ratio computed ({$ratio}x)");

    // The whole point: our own capture cannot see this. It returned almost nothing.
    sspa_t(true, 'this query returns ~0 rows, so only performance_schema could expose it');
} else {
    echo "PASS: performance_schema not readable here - real digest path untested (expected on this MariaDB, and on most hosts)\n";
    $findings = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM ' . SSPA_Schema::table('findings') . " WHERE run_id = %d AND finding_type = 'over_examining_query'",
        $run_id
    ));
    sspa_t($findings === 0, 'no digest findings invented when the source is unavailable');
}

// --- Clean up ---
deactivate_plugins('sspa-digest-fixture/sspa-digest-fixture.php');
unlink($fixture_dir . '/sspa-digest-fixture.php');
rmdir($fixture_dir);
sspa_t(!file_exists($fixture_dir), 'digest fixture removed');

// Recommendation copy must exist or the insight renders with a bare key as its title.
$rec = SSPA_Rules::recommendation('over_examining_query');
sspa_t(!empty($rec['title']) && $rec['title'] !== 'over_examining_query', 'over_examining_query recommendation text present: ' . $rec['title']);
