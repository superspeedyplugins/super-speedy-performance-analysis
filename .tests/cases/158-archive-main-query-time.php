<?php
// The archive profile reports the time of the statement an archive query really ran, and the
// cast WordPress applied to each of its meta filters.
//
// Before 0.64.0 every archive record took the cost, component and fingerprint of the statement
// AFTER its own whenever PA had logged a statement of its own earlier in the request: the raw
// log position was looked up in the SQL list after PA's own statements had been removed from
// it. A 305 ms filtered shop query was reported at 0.17 ms (the FOUND_ROWS() that followed it).
// The meta filters' casts were never recorded and the profile's entries carried no meta at all.
//
// Drives a real profiled request through the run controller and reads SSPA_Report's profile.
function sspa_158_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
}

global $wpdb;

$mu = WPMU_PLUGIN_DIR . '/sspa-archive-main-query-time.php';
copy(SSPA_PLUGIN_DIR . '.tests/fixtures/archive-main-query-time.php', $mu);

// --- Fixture on the way in: one category, eight posts, _sspa_t158 = 1..8 ---
$term = term_exists('sspa-t158', 'category');
if (!$term) {
    $term = wp_insert_term('SSPA t158', 'category', array('slug' => 'sspa-t158'));
}
$term_id = (int) (is_array($term) ? $term['term_id'] : $term);
$old = get_posts(array('post_type' => 'post', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'cat' => $term_id));
foreach ($old as $id) {
    wp_delete_post($id, true);
}
for ($i = 1; $i <= 8; $i++) {
    $id = wp_insert_post(array('post_title' => 'SSPA t158 post ' . $i, 'post_status' => 'publish', 'post_type' => 'post', 'post_category' => array($term_id)));
    add_post_meta($id, '_sspa_t158', (string) $i);
}

$url = add_query_arg('sspa_t158', '1', get_term_link($term_id, 'category'));
$run_id = SSPA_Run_Controller::start(array('type' => 'adhoc', 'url' => $url, 'trigger' => 'cli', 'user_id' => 1));
if (is_wp_error($run_id)) {
    sspa_158_t(false, 'ad hoc run of ' . $url . ': ' . $run_id->get_error_message());
    return;
}
$deadline = time() + 180;
do {
    SSPA_Run_Controller::process_batch($run_id);
    $status = SSPA_Run_Controller::status($run_id);
} while ($status && 'crawling' === $status['status'] && time() < $deadline);
echo "Run $run_id of $url\n";

$blob = $wpdb->get_var($wpdb->prepare('SELECT profile_blob FROM ' . SSPA_Schema::table('profiles') . ' WHERE run_id = %d LIMIT 1', $run_id));
$json = $blob ? @gzuncompress($blob) : false;
$capture = json_decode($json ? $json : (string) $blob, true);
sspa_158_t(is_array($capture), 'the profiled request was captured');
if (!is_array($capture)) {
    return;
}

// The statement the main query actually ran, found in the capture's own SQL list by its text.
$record = null;
foreach ((array) $capture['archives']['queries'] as $r) {
    if (!empty($r['main'])) { $record = $r; break; }
}
sspa_158_t(is_array($record), 'the main archive query was recorded');
$own = null;
foreach ((array) $capture['sql']['queries'] as $q) {
    if (is_array($record) && isset($q['sql']) && trim((string) $q['sql']) === trim((string) $record['request'])) { $own = $q; }
}
sspa_158_t(is_array($own) && $own['ms'] >= 100, 'its statement is in the SQL log and took at least 100 ms: ' . ($own ? $own['ms'] : 'not found'));
sspa_158_t(is_array($own) && is_array($record) && $record['fp'] === $own['fp'], 'the record carries its own statement\'s fingerprint: ' . (is_array($record) ? $record['fp'] : 'none'));

$profile = SSPA_Report::archive_profile($run_id);
$entry = null;
foreach (is_wp_error($profile) ? array() : (array) $profile['archives'] as $e) {
    if (!empty($e['main'])) { $entry = $e; break; }
}
sspa_158_t(is_array($entry), 'the archive profile has the main archive');
sspa_158_t(is_array($entry) && null !== $entry['ms'] && $entry['ms'] >= 100, 'the profile reports the main query at its real time (>= 100 ms): ' . (is_array($entry) ? var_export($entry['ms'], true) : 'none'));
sspa_158_t(is_array($entry) && is_array($own) && (float) $entry['ms'] === (float) $own['ms'], 'and that time is its own statement\'s');

$meta = (is_array($entry) && isset($entry['meta'])) ? $entry['meta'] : null;
$clause = null;
foreach ((array) $meta as $m) {
    if (isset($m['key']) && '_sspa_t158' === $m['key']) { $clause = $m; }
}
sspa_158_t(is_array($clause), 'the profile entry lists its meta filter: ' . wp_json_encode($meta));
sspa_158_t(is_array($clause) && 'BETWEEN' === $clause['compare'], 'with its comparison (BETWEEN)');
sspa_158_t(is_array($clause) && isset($clause['cast']) && 'SIGNED' === $clause['cast'], 'and the cast WordPress applied for NUMERIC (SIGNED): ' . (is_array($clause) && isset($clause['cast']) ? $clause['cast'] : 'none'));
echo "Site: $url\n";
