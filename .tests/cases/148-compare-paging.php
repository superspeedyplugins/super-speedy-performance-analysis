<?php
// Compare tab paging: the run selectors and run table hold 50 runs at a time, older runs stay
// reachable through "Older runs", a run on page 2 can still be compared, and the saved-run
// details Back link returns to the page it was opened from. Runs are created through the real
// run controller and the tab is rendered through the real authenticated sspa_render_tab AJAX
// endpoint over HTTP. Nothing is torn down; each run of this case adds its own runs on the way in.
defined('ABSPATH') || exit;

$GLOBALS['sspa_148_failures'] = 0;
function sspa_148_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
    if (!$ok) { $GLOBALS['sspa_148_failures']++; }
}

function sspa_148_finish($run_id) {
    $deadline = time() + 240;
    do {
        SSPA_Run_Controller::process_batch($run_id);
        $status = SSPA_Run_Controller::status($run_id);
    } while ($status && in_array($status['status'], array('crawling', 'analysing'), true) && time() < $deadline);
    return $status ? $status['status'] : 'missing';
}

wp_set_current_user(1);
$active = SSPA_Run_Controller::active_run_id();
if ($active) {
    SSPA_Run_Controller::cancel($active);
    SSPA_Run_Controller::process_batch($active);
}

// Two completed spot checks, then 55 newer cancelled runs, so both completed runs sit on page 2.
$done = array();
foreach (array(1, 2) as $n) {
    $id = SSPA_Run_Controller::start(array('type' => 'spot', 'page_keys' => array('home'), 'user_id' => 1));
    if (is_wp_error($id)) { sspa_148_t(false, 'start completed run ' . $n . ': ' . $id->get_error_message()); exit; }
    $state = sspa_148_finish((int) $id);
    sspa_148_t('done' === $state, "completed spot check #$id finished ($state)");
    $done[] = (int) $id;
}
$newest = 0;
for ($i = 0; $i < 55; $i++) {
    $id = SSPA_Run_Controller::start(array('type' => 'spot', 'page_keys' => array('home'), 'user_id' => 1));
    if (is_wp_error($id)) { sspa_148_t(false, 'start filler run: ' . $id->get_error_message()); exit; }
    SSPA_Run_Controller::cancel((int) $id);
    SSPA_Run_Controller::process_batch((int) $id);
    $newest = (int) $id;
}
global $wpdb;
$all_ids = array_map('intval', $wpdb->get_col($wpdb->prepare('SELECT id FROM %i ORDER BY id DESC', SSPA_Schema::table('runs'))));
echo 'INFO: ' . count($all_ids) . " retained runs, newest #$newest, completed #{$done[0]} and #{$done[1]}\n";

// Real authenticated HTTP to the tab loader, exactly what the browser posts.
$expiry = time() + 600;
$session = WP_Session_Tokens::get_instance(1)->create($expiry);
$cookies = array(
    LOGGED_IN_COOKIE => wp_generate_auth_cookie(1, $expiry, 'logged_in', $session),
    AUTH_COOKIE => wp_generate_auth_cookie(1, $expiry, 'auth', $session),
);
$_COOKIE[LOGGED_IN_COOKIE] = $cookies[LOGGED_IN_COOKIE];
$nonce = wp_create_nonce('sspa_admin');
function sspa_148_post($body, $cookies) {
    $response = wp_remote_post(admin_url('admin-ajax.php'), array('timeout' => 120, 'cookies' => $cookies, 'body' => $body));
    return is_wp_error($response) ? null : json_decode(wp_remote_retrieve_body($response), true);
}
function sspa_148_tab($page, $nonce, $cookies) {
    $body = array('action' => 'sspa_render_tab', 'nonce' => $nonce, 'tabs' => 'compare');
    if ($page) { $body['sspa_compare_page'] = $page; }
    $json = sspa_148_post($body, $cookies);
    return isset($json['data']['tabs']['compare']) ? $json['data']['tabs']['compare'] : '';
}
function sspa_148_ids($html, $pattern) {
    preg_match_all($pattern, $html, $m);
    return array_map('intval', $m[1]);
}
function sspa_148_select($html, $id) {
    return preg_match('#<select id="' . $id . '">(.*?)</select>#s', $html, $m)
        ? sspa_148_ids($m[1], '#<option value="(\d+)"#') : array();
}

$page1 = sspa_148_tab(0, $nonce, $cookies);
$table1 = sspa_148_ids($page1, '#class="sspa-history-run-link" data-run-id="(\d+)"#');
$after1 = sspa_148_select($page1, 'sspa-history-after');
echo 'INFO: page 1 is ' . strlen($page1) . " bytes\n";
sspa_148_t(array_slice($all_ids, 0, 50) === $table1, 'page 1 run table holds exactly the 50 newest runs, newest first (got ' . count($table1) . ')');
sspa_148_t(array_slice($all_ids, 0, 50) === $after1, 'page 1 After selector holds exactly the 50 newest runs (got ' . count($after1) . ')');
sspa_148_t(count(sspa_148_select($page1, 'sspa-history-before')) <= 51, 'page 1 Before selector is bounded to the page plus the next older run');

$older = preg_match('#class="button sspa-compare-page-link sspa-compare-older" data-compare-page="(\d+)" href="([^"]+)"#', $page1, $om) ? $om : null;
sspa_148_t($older && 2 === (int) $older[1] && false !== strpos(html_entity_decode($older[2]), 'sspa_compare_page=2'), 'page 1 offers an Older runs link to page 2');
sspa_148_t(false === strpos($page1, 'sspa-compare-newer'), 'page 1 offers no Newer runs link');
sspa_148_t(false === strpos($page1, 'data-document="null"') && false !== strpos($page1, 'data-document="{'), 'page 1 still renders the default comparison chart document');

$page2 = sspa_148_tab($older ? (int) $older[1] : 2, $nonce, $cookies);
$table2 = sspa_148_ids($page2, '#class="sspa-history-run-link" data-run-id="(\d+)"#');
sspa_148_t(array_slice($all_ids, 50, 50) === $table2, 'Older runs reaches runs 51-100 (got ' . count($table2) . ')');
sspa_148_t(in_array($done[0], $table2, true) && in_array($done[1], $table2, true), 'both completed runs are on page 2');
sspa_148_t(in_array($done[0], sspa_148_select($page2, 'sspa-history-before'), true) && in_array($done[1], sspa_148_select($page2, 'sspa-history-after'), true), 'page 2 selectors offer the completed pair');
sspa_148_t(false !== strpos($page2, 'sspa-compare-newer'), 'page 2 offers a Newer runs link');

$compare = sspa_148_post(array('action' => 'sspa_history_compare', 'nonce' => $nonce, 'before_run_id' => $done[0], 'after_run_id' => $done[1], 'selection_mode' => 'pair'), $cookies);
sspa_148_t(!empty($compare['success']) && (int) $compare['data']['before_run_id'] === $done[0] && (int) $compare['data']['after_run_id'] === $done[1], 'a page-2 pair compares through the real endpoint');

$detail = sspa_148_post(array('action' => 'sspa_history_run', 'nonce' => $nonce, 'run_id' => $done[1], 'sspa_compare_page' => 2), $cookies);
$back = !empty($detail['data']['html']) && preg_match('#class="button sspa-history-back" href="([^"]+)"#', $detail['data']['html'], $bm) ? html_entity_decode($bm[1]) : '';
sspa_148_t(false !== strpos($back, 'sspa_compare_page=2'), 'the details page of a page-2 run links Back to page 2');

$last_page = (int) ceil(count($all_ids) / 50);
$last = sspa_148_tab($last_page, $nonce, $cookies);
$table_last = sspa_148_ids($last, '#class="sspa-history-run-link" data-run-id="(\d+)"#');
sspa_148_t(array_slice($all_ids, ($last_page - 1) * 50) === $table_last && false === strpos($last, 'sspa-compare-older'), "the last page ($last_page) holds the remaining runs and no Older link");
$beyond = sspa_148_tab($last_page + 5, $nonce, $cookies);
sspa_148_t($table_last === sspa_148_ids($beyond, '#class="sspa-history-run-link" data-run-id="(\d+)"#'), 'a page beyond the end is clamped to the last page');
// Leave the newest pair comparable (two completed Home spot checks) so the History browser
// journeys that read the default pair still have retained Home evidence after this case.
foreach (array(1, 2) as $n) {
    $id = SSPA_Run_Controller::start(array('type' => 'spot', 'page_keys' => array('home'), 'user_id' => 1));
    sspa_148_t(!is_wp_error($id) && 'done' === sspa_148_finish((int) $id), 'trailing completed spot check ' . $n . ' leaves a comparable newest pair');
}
echo $GLOBALS['sspa_148_failures'] ? "INFO: {$GLOBALS['sspa_148_failures']} failure(s)\n" : "INFO: all checks passed\n";
