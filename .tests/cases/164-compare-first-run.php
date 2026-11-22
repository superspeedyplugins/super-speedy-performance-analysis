<?php
// Compare after the FIRST analysis: a site with exactly one saved run loads the Compare tab and
// shows the first-run message, and with a second run shows the picker and a comparison. Until
// 0.68.4 the one-run case fataled (SSPA_History::render() read a WP_Error as an array) and the tab
// said "Could not load this tab: Unexpected server response."
//
// Own site, because it needs a run count of exactly one:
//   SSPA_SCENARIO=tests-compare-first-run .tests/run-tests.sh 164-compare-first-run
// Saved runs are cleared on the way IN. Nothing is torn down.
defined('ABSPATH') || exit;

$GLOBALS['sspa_164_failures'] = 0;
function sspa_164_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
    if (!$ok) { $GLOBALS['sspa_164_failures']++; }
}
function sspa_164_finish($run_id) {
    $deadline = time() + 240;
    do {
        SSPA_Run_Controller::process_batch($run_id);
        $status = SSPA_Run_Controller::status($run_id);
    } while ($status && in_array($status['status'], array('crawling', 'analysing'), true) && time() < $deadline);
    return $status ? $status['status'] : 'missing';
}

global $wpdb;
if (false === strpos(home_url(), 'tests-compare-first-run.')) {
    echo "FAIL: this case clears saved runs and runs only on its own site: SSPA_SCENARIO=tests-compare-first-run (this is " . home_url() . ")\n";
    return;
}
wp_set_current_user(1);
$active = SSPA_Run_Controller::active_run_id();
if ($active) { SSPA_Run_Controller::cancel($active); SSPA_Run_Controller::process_batch($active); }
// On the way in: no saved runs.
$wpdb->query($wpdb->prepare('DELETE FROM %i', SSPA_Schema::table('runs')));
sspa_164_t(0 === (int) SSPA_History_Series::run_count(), 'no saved runs on the way in');

// The tab exactly as the browser loads it: authenticated admin-ajax over HTTP.
$expiry = time() + 600;
$session = WP_Session_Tokens::get_instance(1)->create($expiry);
$cookies = array(
    LOGGED_IN_COOKIE => wp_generate_auth_cookie(1, $expiry, 'logged_in', $session),
    AUTH_COOKIE => wp_generate_auth_cookie(1, $expiry, 'auth', $session),
);
$_COOKIE[LOGGED_IN_COOKIE] = $cookies[LOGGED_IN_COOKIE];
$nonce = wp_create_nonce('sspa_admin');
function sspa_164_tab($nonce, $cookies) {
    $response = wp_remote_post(admin_url('admin-ajax.php'), array('timeout' => 120, 'cookies' => $cookies, 'body' => array('action' => 'sspa_render_tab', 'nonce' => $nonce, 'tabs' => 'compare')));
    $body = is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_body($response);
    $json = json_decode($body, true);
    return array(
        'ok' => !empty($json['success']) && isset($json['data']['tabs']['compare']),
        'html' => isset($json['data']['tabs']['compare']) ? $json['data']['tabs']['compare'] : '',
        'raw' => substr(trim(wp_strip_all_tags($body)), 0, 200),
    );
}

$first = SSPA_Run_Controller::start(array('type' => 'spot', 'page_keys' => array('home'), 'user_id' => 1));
sspa_164_t(!is_wp_error($first) && 'done' === sspa_164_finish((int) $first), 'the first analysis completes');
$tab = sspa_164_tab($nonce, $cookies);
sspa_164_t($tab['ok'], 'with one saved run the Compare tab loads (response: ' . ($tab['ok'] ? 'success' : ('' === $tab['raw'] ? 'empty' : $tab['raw'])) . ')');
sspa_164_t(false !== strpos($tab['html'], 'This is your first saved point in time.'), 'and says this is the first saved point in time');
sspa_164_t(false === strpos($tab['html'], 'No saved analysis exists'), 'and shows no comparison error');
sspa_164_t(false !== strpos($tab['html'], 'data-run-id="' . (int) $first . '"'), 'the saved run is listed');

$second = SSPA_Run_Controller::start(array('type' => 'spot', 'page_keys' => array('home'), 'user_id' => 1));
sspa_164_t(!is_wp_error($second) && 'done' === sspa_164_finish((int) $second), 'a second analysis completes');
$tab = sspa_164_tab($nonce, $cookies);
sspa_164_t($tab['ok'], 'with two saved runs the Compare tab loads');
sspa_164_t(false !== strpos($tab['html'], 'id="sspa-history-before"') && false !== strpos($tab['html'], 'id="sspa-history-after"'), 'and offers the Before and After picker');
sspa_164_t(false === strpos($tab['html'], 'This is your first saved point in time.'), 'and no longer shows the first-run message');
sspa_164_t(false === strpos($tab['html'], 'No saved analysis exists'), 'and compares the two runs without an error');

echo $GLOBALS['sspa_164_failures'] ? "FAILED: {$GLOBALS['sspa_164_failures']}\n" : "All passed\n";
