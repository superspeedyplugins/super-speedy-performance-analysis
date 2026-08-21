<?php
// EXPLAIN enrichment (phase 2): the only SQL analysis that needs nothing installed and no
// extra grant. Proves the plan is actually read, that its safety rules hold, and that a
// query with no usable index is caught even when it is currently fast.

function sspa_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

global $wpdb;

// --- Safety rules: what must never be explained ---
sspa_t(SSPA_Explain::is_explainable("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post'"), 'plain SELECT is explainable');
sspa_t(!SSPA_Explain::is_explainable("DELETE FROM {$wpdb->posts} WHERE ID = 5"), 'DELETE refused');
sspa_t(!SSPA_Explain::is_explainable("UPDATE {$wpdb->posts} SET post_title = 'x'"), 'UPDATE refused');
sspa_t(!SSPA_Explain::is_explainable("INSERT INTO {$wpdb->posts} (ID) VALUES (1)"), 'INSERT refused');
// A fingerprint has its literals stripped: explaining it would describe a query the site
// never ran, so it must be skipped rather than have values invented for it.
sspa_t(!SSPA_Explain::is_explainable("SELECT ID FROM {$wpdb->posts} WHERE ID = ?"), 'fingerprint (literals stripped) refused');
sspa_t(!SSPA_Explain::is_explainable("SELECT 1; DROP TABLE {$wpdb->posts}"), 'multi-statement refused');

// --- A real plan is produced, and it is a plan, not an execution ---
$posts_before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}");
$plan = SSPA_Explain::explain("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish'");
sspa_t(is_array($plan) && isset($plan['access_type']), 'indexed query explained: type=' . ($plan ? $plan['access_type'] : '?'));
$step_fields = array('table', 'access_type', 'possible_keys', 'key', 'key_length', 'reference', 'estimated_rows', 'filtered', 'extra');
$complete_step = is_array($plan) && !empty($plan['steps']);
foreach ($step_fields as $step_field) {
    $complete_step = $complete_step && array_key_exists($step_field, $plan['steps'][0]);
}
sspa_t($complete_step, 'complete EXPLAIN step fields retained, including null database values');
sspa_t(is_array($plan) && !empty($plan['relevant_indexes'][$wpdb->posts][0]['columns']), 'relevant existing index columns retained in order');
sspa_t($posts_before === (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}"), 'EXPLAIN changed no data');

$join = SSPA_Explain::explain("SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID WHERE p.post_status = 'publish'");
sspa_t(is_array($join) && count($join['steps']) >= 2, 'every table in a join plan is retained (' . (is_array($join) && isset($join['steps']) ? count($join['steps']) : 0) . ' steps)');
sspa_t(is_array($join) && isset($join['relevant_indexes'][$wpdb->posts], $join['relevant_indexes'][$wpdb->postmeta]), 'relevant indexes captured for every parsed join table');

// An unindexed scan: post_content has no index, so this must be a full table scan.
$scan = SSPA_Explain::explain("SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE '%zzz-no-such-content-zzz%'");
sspa_t(is_array($scan) && !empty($scan['scan']), 'full table scan detected: type=' . ($scan ? $scan['access_type'] : '?') . ' key=' . ($scan && $scan['key'] ? $scan['key'] : 'NULL'));
sspa_t(is_array($scan) && SSPA_Explain::summarise($scan) !== null, 'scan summarised in plain English: ' . ($scan ? (string) SSPA_Explain::summarise($scan) : ''));
sspa_t(SSPA_Explain::summarise($plan === null ? null : array('scan' => false, 'key' => 'PRIMARY')) === null, 'a healthy plan produces no complaint');

// A filesort is reported.
$sorted = SSPA_Explain::explain("SELECT ID FROM {$wpdb->posts} ORDER BY post_content");
sspa_t(is_array($sorted), 'ORDER BY query explained');

// Nonsense SQL must be skipped, not fatal.
$broken = SSPA_Explain::explain('SELECT * FROM sspa_no_such_table_at_all');
sspa_t($broken === null, 'unexplainable query returns null instead of erroring');

// --- End to end: the finding is raised, with the plan attached ---
$fixture_dir = WP_PLUGIN_DIR . '/sspa-explain-fixture';
if (!is_dir($fixture_dir)) {
    mkdir($fixture_dir);
}
// Deliberately CHEAP but unindexed: fast on this small database, so it never trips the
// slow-query threshold. Only EXPLAIN can see that it will not scale. It scans postmeta
// (~1k rows here) rather than posts (~132) so it clears unindexed_scan_rows of 500.
$code = <<<'PHP'
<?php
/**
 * Plugin Name: SSPA Explain Fixture (test fixture)
 */
add_action('wp_footer', function () {
    global $wpdb;
    $wpdb->get_results("SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_value LIKE '%sspa-explain-fixture-needle%'");
});
PHP;
file_put_contents($fixture_dir . '/sspa-explain-fixture.php', $code);
activate_plugin('sspa-explain-fixture/sspa-explain-fixture.php');
wp_cache_flush();
sleep(3); // opcache

$run_id = SSPA_Run_Controller::start(array('type' => 'spot', 'page_keys' => array('home'), 'user_id' => 1));
$deadline = time() + 180;
do {
    SSPA_Run_Controller::process_batch($run_id);
    $status = $wpdb->get_var($wpdb->prepare('SELECT status FROM ' . SSPA_Schema::table('runs') . ' WHERE id = %d', $run_id));
} while ($status !== 'done' && $status !== 'failed' && time() < $deadline);
sspa_t($status === 'done', 'spot run completed: ' . $status);

$findings = $wpdb->get_results($wpdb->prepare(
    'SELECT component, evidence FROM ' . SSPA_Schema::table('findings') . " WHERE run_id = %d AND finding_type = 'unindexed_query'",
    $run_id
), ARRAY_A);
sspa_t(count($findings) > 0, 'unindexed_query findings raised (' . count($findings) . ')');

$named = false;
$has_note = false;
$has_steps = false;
$has_indexes = false;
foreach ($findings as $f) {
    $e = json_decode($f['evidence'], true);
    if ($f['component'] === 'sspa-explain-fixture') {
        $named = true;
    }
    if (!empty($e['plan_note'])) {
        $has_note = true;
    }
    if (!empty($e['plan_steps'])) {
        $has_steps = true;
    }
    if (!empty($e['relevant_indexes'])) {
        $has_indexes = true;
    }
}
sspa_t($named, 'the fixture plugin is named for its own unindexed query');
sspa_t($has_note, 'findings carry the plain-English plan note');
sspa_t($has_steps, 'findings retain the complete EXPLAIN plan');
sspa_t($has_indexes, 'findings retain relevant existing index metadata');

// The recommendation copy must exist, or the insight renders with a bare key as its title.
$rec = SSPA_Rules::recommendation('unindexed_query');
sspa_t(!empty($rec['title']) && $rec['title'] !== 'unindexed_query', 'unindexed_query recommendation text present: ' . $rec['title']);

// --- Clean up ---
deactivate_plugins('sspa-explain-fixture/sspa-explain-fixture.php');
unlink($fixture_dir . '/sspa-explain-fixture.php');
rmdir($fixture_dir);
sspa_t(!file_exists($fixture_dir), 'explain fixture removed');
