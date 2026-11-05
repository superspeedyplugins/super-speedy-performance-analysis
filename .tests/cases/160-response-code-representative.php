<?php
// A profile's response_code must be one of its samples' real HTTP codes
// (.docs/2026-11-05-response-code-median.md). Run 36 on the 1M-product benchmark had one sample at 200
// and one at 502 (the PHP connection reset while MariaDB restarted) and was stored as HTTP 351, the
// median of the two. These are those two samples, as the crawler produced them, saved through the real
// SSPA_Profile_Store::save() into a real run's profiles.

defined('ABSPATH') || exit;

$GLOBALS['sspa_160_failures'] = 0;
function sspa_160_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
    if (!$ok) { $GLOBALS['sspa_160_failures']++; }
}

global $wpdb;
wp_set_current_user(1);
$active = SSPA_Run_Controller::active_run_id();
if ($active) { SSPA_Run_Controller::cancel($active); SSPA_Run_Controller::process_batch($active); }
$run_id = SSPA_Run_Controller::start(array('type' => 'spot', 'page_keys' => array('home'), 'user_id' => 1));
$deadline = time() + 180;
do {
    SSPA_Run_Controller::process_batch($run_id);
    $status = SSPA_Run_Controller::status($run_id);
} while ($status && in_array($status['status'], array('queued', 'crawling', 'analysing'), true) && time() < $deadline);

$ok_sample = array('wall_ms' => 5320.2, 'code' => 200, 'error' => null, 'error_message' => null, 'cached' => false,
    'body_hash' => 'c60630042d8bf5f7119dabd61824c06b', 'blocked_by' => null, 'blocked_confidence' => null, 'blocked_reason' => null,
    'capture' => array('overview' => array('gen_ms' => 5137.2, 'peak_mem' => 31457280), 'sql' => array('total_ms' => 4541.0, 'count' => 214)));
$failed_sample = array('wall_ms' => 3975.8, 'code' => 502, 'error' => 'no_canary', 'error_message' => null, 'cached' => false,
    'body_hash' => null, 'blocked_by' => null, 'blocked_confidence' => null, 'blocked_reason' => null);

function sspa_160_save($run_id, $key, $samples) {
    global $wpdb;
    $profile_id = SSPA_Profile_Store::save($run_id, array(
        'page_key' => $key, 'url' => home_url('/?case160=' . $key), 'variant' => 'anon', 'blocked_by' => null, 'samples' => $samples,
    ));
    return (int) $wpdb->get_var($wpdb->prepare('SELECT response_code FROM %i WHERE id = %d', SSPA_Schema::table('profiles'), $profile_id));
}

$even = sspa_160_save($run_id, 'case-160-even', array($ok_sample, $failed_sample));
sspa_160_t(in_array($even, array(200, 502), true), "run 36's samples (200 and 502) store one of their own codes, not their median (stored $even)");
sspa_160_t(502 === $even, 'an even split between success and failure reports the failure');
$majority = sspa_160_save($run_id, 'case-160-majority', array($ok_sample, $ok_sample, $failed_sample));
sspa_160_t(200 === $majority, "two successes and one failure report the common code, 200 (stored $majority)");
$all_ok = sspa_160_save($run_id, 'case-160-ok', array($ok_sample, $ok_sample, $ok_sample));
sspa_160_t(200 === $all_ok, 'three successes report 200');

if ($GLOBALS['sspa_160_failures']) { exit(1); }
