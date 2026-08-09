<?php
// Option access tracking: the pre_option observer must be armed by the db.php drop-in (early
// enough to see core's bootstrap reads), the capture must carry per-option read counts, and the
// analysis must turn a real run into an autoload recommendation that names the right rows.

function sspa_opt_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

global $wpdb;

// --- Fixture: one fat autoloaded option nobody reads, one hot option that is not autoloaded ---
// Names deliberately do not start with sspa_, which the collector excludes as its own bookkeeping.
delete_option('sspafix_cold');
delete_option('sspafix_hot');
add_option('sspafix_cold', str_repeat('x', 40000), '', true);
add_option('sspafix_hot', 'read-every-request', '', false);

$fixture_dir = WP_PLUGIN_DIR . '/sspa-option-fixture';
if (!is_dir($fixture_dir)) {
    mkdir($fixture_dir);
}
file_put_contents($fixture_dir . '/sspa-option-fixture.php', <<<'PHP'
<?php
/**
 * Plugin Name: SSPA Option Fixture (test fixture)
 */
add_action('init', function () {
    get_option('sspafix_hot');
});
PHP
);
activate_plugin('sspa-option-fixture/sspa-option-fixture.php');
wp_cache_flush();
sleep(3); // opcache

// --- A run wide enough to clear the coverage floor (autoload_min_pages) ---
$pages = array('home', 'blog', 'post-single', 'post-cat', 'search-many', '404');
$run_id = SSPA_Run_Controller::start(array('type' => 'spot', 'page_keys' => $pages, 'user_id' => 1));
if (is_wp_error($run_id)) {
    echo 'FAIL: run start: ' . $run_id->get_error_message() . "\n";
    return;
}
$deadline = time() + 420;
do {
    SSPA_Run_Controller::process_batch($run_id);
    $status = SSPA_Run_Controller::status($run_id);
} while ($status && in_array($status['status'], array('crawling', 'analysing'), true) && time() < $deadline);
sspa_opt_t($status && 'done' === $status['status'], 'run completed: ' . ($status ? $status['status'] : 'null'));

// --- The capture must carry option reads, at FULL coverage ---
$captures = array();
foreach ($wpdb->get_results($wpdb->prepare(
    'SELECT id, page_key, profile_blob FROM ' . SSPA_Schema::table('profiles') . ' WHERE run_id = %d',
    $run_id
), ARRAY_A) as $p) {
    if (empty($p['profile_blob'])) {
        continue;
    }
    $c = json_decode((string) @gzuncompress($p['profile_blob']), true);
    if (is_array($c) && !empty($c['options']['reads'])) {
        $captures[$p['page_key']] = $c['options'];
    }
}
sspa_opt_t(count($captures) >= 5, 'option reads captured on ' . count($captures) . ' pages');

$one = $captures ? reset($captures) : array();
sspa_opt_t(isset($one['coverage']) && 'full' === $one['coverage'], 'coverage is full, so the drop-in armed the observer (' . ($one['coverage'] ?? 'none') . ')');
sspa_opt_t(!empty($one['distinct']) && $one['distinct'] > 50, 'a realistic number of distinct options recorded (' . ($one['distinct'] ?? 0) . ')');
sspa_opt_t(!empty($one['calls']) && $one['calls'] >= $one['distinct'], 'call count is at least the distinct count (' . ($one['calls'] ?? 0) . ')');

// Only 'full' coverage proves an absent option was really unread. The bootstrap set is the
// evidence: siteurl is read by core before mu-plugins, so it is exactly what a late-armed
// observer would miss - and exactly what must never be recommended for de-autoloading.
sspa_opt_t(isset($one['reads']['siteurl']), 'core bootstrap option siteurl was observed');
sspa_opt_t(!isset($one['reads']['sspafix_cold']), 'the never-read fixture option is absent from reads');

$hot_pages = 0;
foreach ($captures as $o) {
    if (isset($o['reads']['sspafix_hot'])) {
        $hot_pages++;
    }
}
sspa_opt_t($hot_pages === count($captures), "the hot fixture option was read on every page ($hot_pages/" . count($captures) . ')');

// Values must never be recorded - only names and counts.
$blob = wp_json_encode($captures);
sspa_opt_t(false === strpos($blob, 'read-every-request'), 'option VALUES are never captured');

// --- The finding must name the right rows in both directions ---
$finding = $wpdb->get_row($wpdb->prepare(
    'SELECT * FROM ' . SSPA_Schema::table('findings') . " WHERE run_id = %d AND finding_type = 'autoload_coverage'",
    $run_id
), ARRAY_A);
sspa_opt_t((bool) $finding, 'autoload_coverage finding raised');

if ($finding) {
    $e = json_decode($finding['evidence'], true);
    $unread = array_column((array) ($e['unread'] ?? array()), 'name');
    $missing = array_column((array) ($e['missing'] ?? array()), 'name');
    sspa_opt_t(in_array('sspafix_cold', $unread, true), 'the fat unread autoloaded option is named for de-autoloading');
    sspa_opt_t(!in_array('siteurl', $unread, true), 'a bootstrap option core reads is never named for de-autoloading');
    sspa_opt_t(in_array('sspafix_hot', $missing, true), 'the hot non-autoloaded option is named for autoloading');
    sspa_opt_t((int) $e['pages_covered'] >= 5, 'the finding records its page coverage (' . (int) $e['pages_covered'] . ')');

    $rendered = SSPA_Insights::render($finding);
    $sql = $rendered['sql'];
    sspa_opt_t(false !== strpos($sql, 'sspafix_cold') && false !== strpos($sql, "autoload = 'off'") || false !== strpos($sql, "autoload = 'no'"), 'SQL turns autoload off for the unread option');
    sspa_opt_t(false !== strpos($sql, 'sspafix_hot'), 'SQL turns autoload on for the hot option');
    sspa_opt_t(false !== strpos($sql, $wpdb->options), 'SQL names the real prefixed options table');
    sspa_opt_t(false === strpos($sql, 'read-every-request'), 'SQL never contains an option value');

    // The submission gate allow-lists numeric keys, so names must not escape to the collector.
    $safe = SSPA_Community_Privacy::finding_evidence($e);
    sspa_opt_t(!isset($safe['unread']) && !isset($safe['missing']), 'option names are stripped from shared evidence');
    sspa_opt_t(isset($safe['unread_bytes']) && isset($safe['pages_covered']), 'the aggregate numbers survive for sharing');
}

// --- A thin run must NOT produce a recommendation ---
$thin = SSPA_Run_Controller::start(array('type' => 'spot', 'page_keys' => array('home'), 'user_id' => 1));
if (!is_wp_error($thin)) {
    $deadline = time() + 240;
    do {
        SSPA_Run_Controller::process_batch($thin);
        $s2 = SSPA_Run_Controller::status($thin);
    } while ($s2 && in_array($s2['status'], array('crawling', 'analysing'), true) && time() < $deadline);
    $thin_finding = $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM ' . SSPA_Schema::table('findings') . " WHERE run_id = %d AND finding_type = 'autoload_coverage'",
        $thin
    ));
    sspa_opt_t(0 === (int) $thin_finding, 'a single-page run makes no autoload recommendation');
}

// --- Cleanup ---
deactivate_plugins('sspa-option-fixture/sspa-option-fixture.php');
@unlink($fixture_dir . '/sspa-option-fixture.php');
@rmdir($fixture_dir);
delete_option('sspafix_cold');
delete_option('sspafix_hot');
sspa_opt_t(!is_dir($fixture_dir), 'fixture plugin removed');
