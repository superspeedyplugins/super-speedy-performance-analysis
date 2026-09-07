<?php
// Saved History opens the selected retained run, including older page diagnostics.
defined('ABSPATH') || exit;

function sspa_65_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
}

wp_set_current_user(1);
function sspa_65_endpoint_checks($ids) {
$subscriber = get_user_by('login', 'sspa-history-reader-test');
if (!$subscriber) {
    $subscriber_id = wp_insert_user(array('user_login' => 'sspa-history-reader-test', 'user_pass' => wp_generate_password(32), 'role' => 'subscriber'));
    $subscriber = is_wp_error($subscriber_id) ? null : get_user_by('id', $subscriber_id);
}
sspa_65_t($subscriber instanceof WP_User, 'a retained low-privilege account exists for real endpoint checks');
if ($subscriber) {
    $subscriber->set_role('subscriber');
    foreach (array(1, $subscriber->ID) as $reader_id) {
        wp_set_current_user($reader_id);
        $expiry = time() + 600;
        $session = WP_Session_Tokens::get_instance($reader_id)->create($expiry);
        $cookies = array(
            LOGGED_IN_COOKIE => wp_generate_auth_cookie($reader_id, $expiry, 'logged_in', $session),
            AUTH_COOKIE => wp_generate_auth_cookie($reader_id, $expiry, 'auth', $session),
        );
        $old_cookie = $_COOKIE[LOGGED_IN_COOKIE] ?? null;
        $_COOKIE[LOGGED_IN_COOKIE] = $cookies[LOGGED_IN_COOKIE];
        $nonce = wp_create_nonce('sspa_admin');
        if (null === $old_cookie) { unset($_COOKIE[LOGGED_IN_COOKIE]); }
        else { $_COOKIE[LOGGED_IN_COOKIE] = $old_cookie; }
        foreach (array('sspa_history_run', 'sspa_history_series', 'sspa_history_compare') as $action) {
            $response = wp_remote_post(admin_url('admin-ajax.php'), array(
                'timeout' => 30,
                'cookies' => $cookies,
                'body' => array('action' => $action, 'nonce' => $nonce, 'run_id' => $ids[0], 'before_run_id' => $ids[0], 'after_run_id' => $ids[1], 'selection_mode' => 'pair'),
            ));
            $body = is_wp_error($response) ? null : json_decode(wp_remote_retrieve_body($response), true);
            $code = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);
            sspa_65_t(1 === (int) $reader_id ? (200 === $code && !empty($body['success'])) : (403 === $code && isset($body['success']) && false === $body['success']),
                $action . (1 === (int) $reader_id ? ' accepts the authenticated administrator' : ' denies an authenticated subscriber with a valid nonce'));
        }
        if (1 === (int) $reader_id) {
            foreach (array('bad_nonce' => 403, 'missing_run' => 404) as $probe => $expected_code) {
                $response = wp_remote_post(admin_url('admin-ajax.php'), array(
                    'timeout' => 30, 'cookies' => $cookies,
                    'body' => array('action' => 'sspa_history_run', 'nonce' => 'bad_nonce' === $probe ? 'invalid-test-nonce' : $nonce, 'run_id' => 'missing_run' === $probe ? PHP_INT_MAX : $ids[0]),
                ));
                sspa_65_t(!is_wp_error($response) && $expected_code === wp_remote_retrieve_response_code($response), 'saved report endpoint rejects ' . $probe . ' with HTTP ' . $expected_code);
            }
        }
    }
    wp_set_current_user(1);
}
}
$ids = array();
for ($i = 0; $i < 2; $i++) {
    $id = SSPA_Run_Controller::start(array('type' => 'spot', 'page_keys' => array('home'), 'user_id' => 1));
    if (is_wp_error($id)) {
        sspa_65_t(false, 'saved-view fixture starts: ' . $id->get_error_message());
        return;
    }
    $deadline = time() + 180;
    do {
        SSPA_Run_Controller::process_batch($id);
        $status = SSPA_Run_Controller::status($id);
    } while ($status && in_array($status['status'], array('crawling', 'analysing'), true) && time() < $deadline);
    sspa_65_t($status && 'done' === $status['status'], 'real saved-view measurement completes');
    if (!$status || 'done' !== $status['status']) {
        return;
    }
    $ids[] = (int) $id;
}

ob_start();
include SSPA_PLUGIN_DIR . 'includes/admin/tabs/history.php';
$list = ob_get_clean();
sspa_65_t((bool) preg_match('/<a[^>]+class="sspa-history-run-link"[^>]+data-run-id="' . $ids[0] . '"/', $list), 'the older retained run has a genuine saved-report link');
sspa_65_t(strpos($list, 'id="sspa-history-before"') < strpos($list, 'data-sspa-history-chart'), 'Before and After choices appear before the chart');
sspa_65_t(false !== strpos($list, 'id="sspa-history-mode"'), 'configuration comparison is an explicit choice');
if (!class_exists('SSPA_History_Run_View')) {
    sspa_65_t(false, 'the selected saved run can be read on screen');
    return;
}
$row_before = SSPA_Run_Controller::run_row($ids[0]);
$profiles = SSPA_History_Series::profile_rows($ids[0]);
$html = SSPA_History_Run_View::render($ids[0]);
sspa_65_t(!is_wp_error($html) && false !== strpos($html, 'data-saved-run-id="' . $ids[0] . '"'), 'opening an older run preserves its exact identity');
sspa_65_t(!is_wp_error($html) && false !== strpos($html, esc_html($row_before['started'])) && false !== strpos($html, 'spot'), 'saved report identifies recorded date and analysis type');
sspa_65_t($profiles && false !== strpos($html, 'data-profile-id="' . (int) $profiles[0]['id'] . '"'), 'saved page diagnostics target the older run profile');
sspa_65_t(false !== strpos($html, 'Recorded components') && false !== strpos($html, 'Recorded findings'), 'saved report exposes components and findings');
sspa_65_t($row_before === SSPA_Run_Controller::run_row($ids[0]) && !SSPA_Run_Controller::active_run_id(), 'reading a saved report does not mutate or rerun its analysis');
sspa_65_t(is_wp_error(SSPA_History_Run_View::render(0)), 'zero never substitutes the latest run');
sspa_65_t(is_wp_error(SSPA_History_Run_View::render(PHP_INT_MAX)), 'an unavailable saved run stays unavailable');
wp_set_current_user(0);
sspa_65_t(is_wp_error(SSPA_History_Run_View::render($ids[0])), 'saved evidence cannot be read by an unauthenticated user');
wp_set_current_user(1);
sspa_65_endpoint_checks($ids);
