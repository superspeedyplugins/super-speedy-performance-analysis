<?php
// With Query Monitor's db.php in place we must NOT displace it, and captures should still
// include row counts by riding QM_DB's extended query log (capture mode 'qm').

function sspa_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

global $wpdb;

// Simulate QM's drop-in without needing wp.org network access: a db.php that identifies
// as Query Monitor and defines SAVEQUERIES (we only test coexistence + degraded capture
// mechanics here; the rows-from-QM path needs real QM and is covered manually).
$fake_qm = "<?php\n/**\n * Plugin Name: Query Monitor Database Class (Drop-in)\n */\nif (!defined('SAVEQUERIES')) { define('SAVEQUERIES', true); }\n";
file_put_contents(WP_CONTENT_DIR . '/db.php', $fake_qm);
sleep(3); // let apache's opcache revalidate the swapped drop-in (revalidate_freq=2)

sspa_t(SSPA_Helper_Files::dropin_status() === 'qm', 'QM drop-in detected as qm');

// ensure_installed must not clobber it.
SSPA_Helper_Files::ensure_installed();
sspa_t(strpos(file_get_contents(WP_CONTENT_DIR . '/db.php'), 'Query Monitor') !== false, 'ensure_installed left QM drop-in alone');

// A spot run still completes with degraded/qm capture (no rows guaranteed here since our
// fake defines SAVEQUERIES only - mode should be degraded, run must not fail).
$run_id = SSPA_Run_Controller::start(array('type' => 'spot', 'page_keys' => array('home'), 'user_id' => 1));
if (is_wp_error($run_id)) {
    echo 'FAIL: start(): ' . $run_id->get_error_message() . "\n";
} else {
    $deadline = time() + 120;
    do {
        SSPA_Run_Controller::process_batch($run_id);
        $status = SSPA_Run_Controller::status($run_id);
    } while ($status && 'crawling' === $status['status'] && time() < $deadline);
    sspa_t($status && 'done' === $status['status'], 'spot run completed with foreign-ish db.php');

    $profiles_table = SSPA_Schema::table('profiles');
    $home = $wpdb->get_row($wpdb->prepare("SELECT * FROM $profiles_table WHERE run_id = %d AND page_key = 'home'", $run_id), ARRAY_A);
    sspa_t($home && $home['sql_count'] > 10, 'degraded capture still counts queries (' . ($home ? $home['sql_count'] : '?') . ')');
    sspa_t($home && $home['page_gen_ms'] > 0, 'degraded capture still times the page');

    $blob = $home ? $home['profile_blob'] : null;
    $capture = $blob ? json_decode(gzuncompress($blob), true) : null;
    sspa_t(is_array($capture) && in_array($capture['overview']['capture_mode'], array('degraded', 'qm'), true), 'capture mode degraded/qm: ' . ($capture ? $capture['overview']['capture_mode'] : '?'));
}

// Hold/swap mechanics: displace the foreign drop-in, verify ours goes in, restore.
sspa_t(SSPA_Helper_Files::hold_foreign_dropin() === true, 'foreign drop-in held');
sspa_t(SSPA_Helper_Files::dropin_status() === 'ours', 'our shim installed during hold');
sspa_t(file_exists(WP_CONTENT_DIR . '/db.php.sspa-hold'), 'hold file exists');
sspa_t(SSPA_Helper_Files::restore_held_dropin() === true, 'held drop-in restored');
sspa_t(strpos(file_get_contents(WP_CONTENT_DIR . '/db.php'), 'Query Monitor') !== false, 'original drop-in back in place');
sspa_t(!file_exists(WP_CONTENT_DIR . '/db.php.sspa-hold'), 'hold file gone');

// Clean up: regenerate our real shim from the template (never write back $our_shim - if a
// previous run died mid-test it could itself be a leftover fake).
unlink(WP_CONTENT_DIR . '/db.php');
SSPA_Helper_Files::ensure_installed();
sspa_t(SSPA_Helper_Files::dropin_status() === 'ours', 'our shim restored for other tests');
