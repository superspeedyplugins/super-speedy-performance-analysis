<?php
defined('ABSPATH') || exit;

$GLOBALS['sspa_60_fails'] = 0;
function sspa_60_t($ok, $message) {
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $message . "\n";
    if (!$ok) {
        $GLOBALS['sspa_60_fails']++;
    }
}

wp_set_current_user(1);
global $wpdb;

$dropin = WP_CONTENT_DIR . '/db.php';
$hold = WP_CONTENT_DIR . '/db.php.sspa-hold';
$ready = SSPA_PLUGIN_DIR . '.data/case-60-ready.json';
$fake_qm = "<?php\n/**\n * Plugin Name: Query Monitor Database Class (Drop-in)\n */\nif (!defined('SAVEQUERIES')) { define('SAVEQUERIES', true); }\n";
$active_before = array_values((array) get_option('active_plugins', array()));

// Clear only residue from an interrupted earlier run of this case, on the way in.
if (file_exists($ready)) {
    unlink($ready);
}
if (file_exists($hold)) {
    SSPA_Helper_Files::restore_held_dropin();
}
if (file_exists($dropin) && 'ours' === SSPA_Helper_Files::dropin_status()) {
    unlink($dropin);
}
file_put_contents($dropin, $fake_qm);
sleep(3);

$child_code = '$run = SSPA_Run_Controller::start(array("type" => "spot", "page_keys" => array("home"), "user_id" => 1, "swap_dropin" => true));'
    . 'file_put_contents(' . var_export($ready, true) . ', wp_json_encode(array("run_id" => is_wp_error($run) ? 0 : $run, "error" => is_wp_error($run) ? $run->get_error_message() : "")));'
    . 'sleep(120);';
$process = proc_open(array(
    'wp',
    '--path=' . ABSPATH,
    '--url=' . home_url('/'),
    '--skip-themes',
    'eval',
    $child_code,
), array(
    0 => array('pipe', 'r'),
    1 => array('pipe', 'w'),
    2 => array('pipe', 'w'),
), $pipes);

$deadline = microtime(true) + 20;
while (!file_exists($ready) && microtime(true) < $deadline) {
    usleep(100000);
}
$started = file_exists($ready) ? json_decode((string) file_get_contents($ready), true) : array();
$run_id = !empty($started['run_id']) ? (int) $started['run_id'] : 0;
sspa_60_t($run_id > 0, 'a separate controller process starts the real run before it is killed');
sspa_60_t(file_exists($hold) && 'ours' === SSPA_Helper_Files::dropin_status(), 'the killed process reached the foreign drop-in hold');

$killed = false;
if (is_resource($process)) {
    fclose($pipes[0]);
    $killed = proc_terminate($process, 9);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
}
sspa_60_t($killed, 'the controller process is terminated while its run and hold are active');

if ($run_id > 0) {
    $queue = SSPA_Run_Queue::get($run_id);
    if (is_array($queue)) {
        $queue['last_progress'] = time() - (4 * HOUR_IN_SECONDS);
        SSPA_Run_Queue::save($run_id, $queue);
    }
    SSPA_Run_Controller::cleanup();
}

$recovered = $run_id > 0 ? SSPA_Run_Controller::run_row($run_id) : null;
sspa_60_t($recovered && 'failed' === $recovered['status'], 'the stale-run janitor fails the killed run through the real recovery path');
sspa_60_t(!file_exists($hold) && 'qm' === SSPA_Helper_Files::dropin_status(), 'crash recovery restores the exact held foreign drop-in');
sspa_60_t($run_id > 0 && null === SSPA_Run_Queue::get($run_id), 'crash recovery removes the killed run queue and claim');
sspa_60_t($active_before === array_values((array) get_option('active_plugins', array())), 'crash recovery leaves the active plugin list unchanged');

// Prove the recovered site accepts another real run rather than remaining blocked.
$next = SSPA_Run_Controller::start(array('type' => 'spot', 'page_keys' => array('home'), 'user_id' => 1));
sspa_60_t(!is_wp_error($next) && (int) $next > 0, 'a new run can start after crash recovery');
if (!is_wp_error($next)) {
    SSPA_Run_Controller::cancel((int) $next);
}

// Restore the suite's normal SSPA drop-in for later cases. The failed run and its evidence
// deliberately remain in the retained site.
if (file_exists($dropin) && 'qm' === SSPA_Helper_Files::dropin_status()) {
    unlink($dropin);
}
SSPA_Helper_Files::ensure_installed();
if (file_exists($ready)) {
    unlink($ready);
}
sspa_60_t('ours' === SSPA_Helper_Files::dropin_status(), 'the normal profiling shim is restored for subsequent cases');

