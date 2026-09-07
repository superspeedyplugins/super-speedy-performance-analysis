<?php
// Exercise real retained runs and the profile-store boundary. No comparison replica.
defined('ABSPATH') || exit;
wp_set_current_user(1);
$GLOBALS['sspa_64_failures'] = 0;
function sspa_64_check($ok, $label) {
    echo ($ok ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$ok) { $GLOBALS['sspa_64_failures']++; }
}
function sspa_64_run() {
    $id = SSPA_Run_Controller::start(array('type' => 'spot', 'page_keys' => array('home'), 'user_id' => 1));
    if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
    $deadline = time() + 180;
    do {
        SSPA_Run_Controller::process_batch($id);
        $status = SSPA_Run_Controller::status($id);
    } while ($status && in_array($status['status'], array('crawling', 'analysing'), true) && time() < $deadline);
    if (!$status || 'done' !== $status['status']) { throw new RuntimeException('Exact-pair fixture run did not complete'); }
    return (int) $id;
}
function sspa_64_page($document, $key) {
    if (is_wp_error($document)) { return array(); }
    foreach ($document['pages'] as $page) {
        if ($key === $page['page_key']) { return $page; }
    }
    return array();
}
try {
    global $wpdb;
    $before = sspa_64_run();
    $after = sspa_64_run();
    sspa_64_check(SSPA_History_Series::setup_fingerprint(SSPA_Run_Controller::run_row($before)) === SSPA_History_Series::setup_fingerprint(SSPA_Run_Controller::run_row($after)), 'the two real runs have the same measured setup');

    // Empty samples are reachable through save() with a completed job lacking samples.
    // Zero and failed samples use the same crawler-result shape save() consumes.
    $profile_ids = array();
    foreach (array('empty' => array(), 'zero' => array(array('wall_ms' => 0, 'code' => 200, 'error' => null, 'cached' => false)), 'failed' => array(array('wall_ms' => 0, 'code' => 0, 'error' => 'http_request_failed', 'error_message' => 'Retained transport explanation', 'cached' => false))) as $key => $samples) {
        $profile_ids[$key] = SSPA_Profile_Store::save($after, array('page_key' => 'pair-' . $key, 'url' => home_url('/'), 'variant' => 'anon', 'blocked_by' => null, 'samples' => $samples));
    }
    $profile_ids['blocked'] = SSPA_Profile_Store::save($after, array('page_key' => 'pair-blocked', 'url' => home_url('/'), 'variant' => 'anon', 'blocked_by' => 'http_403', 'samples' => array(array('wall_ms' => 100, 'code' => 403, 'error' => null, 'cached' => false))));
    $document = SSPA_History_Series::build($after, 'request_wall_ms', $before, 'pair');
    sspa_64_check(!is_wp_error($document) && array($before) === $document['previous']['run_ids'] && array($after) === $document['current']['run_ids'], 'same-setup selection charts exactly the two selected runs');
    sspa_64_check(!is_wp_error($document) && 'pair' === ($document['selection_mode'] ?? '') && $before === ($document['before_run_id'] ?? null) && $after === ($document['after_run_id'] ?? null), 'the document declares its selection mode and exact IDs');
    $home = sspa_64_page($document, 'home');
    sspa_64_check(!empty($home['current']['points'][0]['profile_id']) && !empty($home['previous']['points'][0]['profile_id']), 'both sides retain saved profile IDs for drill-down');
    $empty = sspa_64_page($document, 'pair-empty');
    sspa_64_check($empty && null === $empty['current']['median'] && 1 === $empty['current']['fault_count'] && 'missing' === $empty['current']['faults'][0]['state'] && $profile_ids['empty'] === $empty['current']['faults'][0]['profile_id'], 'an empty sample list is a visible missing measurement linked to its profile');
    $zero = sspa_64_page($document, 'pair-zero');
    sspa_64_check($zero && 0.0 === (float) $zero['current']['median'] && 1 === $zero['current']['point_count'] && null === $zero['delta']['absolute'], 'a valid zero remains a measurement while an absent comparison stays unknown');
    $failed = sspa_64_page($document, 'pair-failed');
    $fault = $failed['current']['faults'][0] ?? array();
    sspa_64_check('transport_error' === ($fault['state'] ?? '') && $profile_ids['failed'] === ($fault['profile_id'] ?? null) && 'Retained transport explanation' === ($fault['evidence']['error_message'] ?? ''), 'a failed sample retains its own transport explanation and profile ID');
    $blocked = sspa_64_page($document, 'pair-blocked');
    sspa_64_check($blocked && null === $blocked['current']['median'] && 'blocked' === $blocked['current']['faults'][0]['state'] && $profile_ids['blocked'] === $blocked['current']['faults'][0]['profile_id'], 'a blocked sample remains visible without contributing a timing point');
    sspa_64_check(!isset($home['current']['points'][0]['evidence']['php_warnings']), 'request points do not invent PHP warnings from the median capture');
    $reverse = SSPA_History_Series::build($before, 'request_wall_ms', $after, 'pair');
    sspa_64_check(!is_wp_error($reverse) && array($after) === $reverse['previous']['run_ids'] && array($before) === $reverse['current']['run_ids'], 'explicit sides remain exact even when the selected Before was recorded later');
    foreach (array(array($after, 0), array(0, $before), array($after, $after), array($after, PHP_INT_MAX)) as $selection) {
        sspa_64_check(is_wp_error(SSPA_History_Series::build($selection[0], 'request_wall_ms', $selection[1], 'pair')), 'invalid exact selection is rejected without baseline substitution: ' . implode('/', $selection));
    }
    sspa_64_check(is_wp_error(SSPA_History_Series::build($after, 'request_wall_ms', $before, 'invalid')), 'unknown selection mode is rejected');
    foreach (SSPA_History_Series::metrics() as $metric => $definition) {
        $series = SSPA_History_Series::build($after, $metric, $before, 'pair');
        sspa_64_check(!is_wp_error($series) && array($before) === $series['previous']['run_ids'] && array($after) === $series['current']['run_ids'], 'metric change preserves exact selection: ' . $metric);
    }
    $setup = SSPA_History_Series::build($after);
    sspa_64_check(!is_wp_error($setup) && in_array($before, $setup['current']['run_ids'], true) && in_array($after, $setup['current']['run_ids'], true), 'default setup mode continues grouping adjacent unchanged runs');

    // Alter only the retained compatibility metadata after successful reads; run rows
    // are freshly read on every build, unlike the immutable cached profile evidence.
    $before_row = SSPA_Run_Controller::run_row($before);
    $components = json_decode($before_row['plugin_set'], true);
    $components['components'][0]['version'] = '64.0.0';
    $wpdb->update(SSPA_Schema::table('runs'), array('plugin_set' => wp_json_encode($components)), array('id' => $before));
    $different = SSPA_History_Series::build($after, 'request_wall_ms', $before, 'pair');
    sspa_64_check(!is_wp_error($different) && array($before) === $different['previous']['run_ids'] && array($after) === $different['current']['run_ids'] && $different['previous']['fingerprint'] !== $different['current']['fingerprint'], 'different saved component versions retain the exact selected pair');
    $wpdb->update(SSPA_Schema::table('runs'), array('plugin_set' => $before_row['plugin_set']), array('id' => $before));
    $wpdb->update(SSPA_Schema::table('runs'), array('measurement_version' => (int) $before_row['measurement_version'] + 100), array('id' => $before));
    sspa_64_check(is_wp_error(SSPA_History_Series::build($after, 'request_wall_ms', $before, 'pair')), 'an incompatible exact baseline is rejected, never replaced by an older setup');
    $wpdb->update(SSPA_Schema::table('runs'), array('measurement_version' => $before_row['measurement_version']), array('id' => $before));
} catch (Throwable $error) {
    sspa_64_check(false, $error->getMessage());
}
if ($GLOBALS['sspa_64_failures']) { exit(1); }
