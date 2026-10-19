<?php
require_once __DIR__ . '/../lib/retained-fixtures.php';
sspa_retained_reset('115');
function sspa_w5b_fault_t($ok, $label) { echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n"; }
if (realpath(WPMU_PLUGIN_DIR . '/sspa-w5b-faults.php') !== realpath(__DIR__ . '/../fixtures/w5b-faults.php')
    && !copy(__DIR__ . '/../fixtures/w5b-faults.php', WPMU_PLUGIN_DIR . '/sspa-w5b-faults.php')) { throw new RuntimeException('Cannot install the isolated failure fixture.'); }
require_once WPMU_PLUGIN_DIR . '/sspa-w5b-faults.php';
wp_set_current_user(1);
// Clear on the way in: case 80 leaves a deliberately unsupported checkout page behind, and this
// case must reach the setup failure rather than the unsupported-checkout refusal. Same restore as case 19.
$sspa_w5b_checkout_page = (int) get_option('woocommerce_checkout_page_id');
if ($sspa_w5b_checkout_page) {
    wp_update_post(array('ID' => $sspa_w5b_checkout_page, 'post_content' => '<!-- wp:woocommerce/checkout /-->'));
}
$admin_email = get_option('admin_email');
$expiry = time() + 300;
$token = WP_Session_Tokens::get_instance(1)->create($expiry);
$cookies = array(LOGGED_IN_COOKIE => wp_generate_auth_cookie(1, $expiry, 'logged_in', $token), AUTH_COOKIE => wp_generate_auth_cookie(1, $expiry, 'auth', $token));
$_COOKIE[LOGGED_IN_COOKIE] = $cookies[LOGGED_IN_COOKIE];
try {
    update_option('sspa_w5b_invalid_email', true, false);
    $r = wp_remote_post(admin_url('admin-ajax.php'), array('cookies' => $cookies, 'timeout' => 30, 'body' => array('action' => 'sspa_workflow_launch', 'nonce' => wp_create_nonce('sspa_admin'), 'object_type' => 'order', 'transport' => 'rest')));
    $body = json_decode(wp_remote_retrieve_body($r), true);
    echo 'AJAX observed: ' . wp_remote_retrieve_response_code($r) . ' ' . wp_json_encode($body) . "\n";
    sspa_w5b_fault_t(is_array($body) && false === $body['success'] && false !== strpos($body['data']['message'] ?? '', 'administrator email'), 'workflow AJAX surfaces invalid administrator email as JSON error');
    try { $result = SSPA_Checkout_Flow::run(array('run_id' => 0)); }
    catch (Throwable $e) { $result = null; echo 'OBSERVED: ' . $e->getMessage() . "\n"; }
    echo 'Checkout observed: ' . (is_array($result) ? $result['outcome'] : (is_wp_error($result) ? $result->get_error_message() : 'threw')) . "\n";
    sspa_w5b_fault_t(is_wp_error($result) || (is_array($result) && 'exception' === $result['outcome']), 'checkout setup returns a visible failure instead of throwing');
} finally {
    delete_option('sspa_w5b_invalid_email');
    WP_Session_Tokens::get_instance(1)->destroy($token);
}
register_post_type('sspa_hidden_fixture', array('public' => true, 'show_ui' => true, 'exclude_from_search' => true));
$id = SSPA_Probes::create_temp_copy('sspa_hidden_fixture', true);
wp_update_post(array('ID' => $id, 'post_date' => gmdate('Y-m-d H:i:s', time() - 7200), 'post_date_gmt' => gmdate('Y-m-d H:i:s', time() - 7200)));
SSPA_Run_Controller::cleanup();
sspa_w5b_fault_t(!get_post($id), 'hourly cleanup finds orphan workflow objects excluded from search');
SSPA_Probes::delete_temp($id);
unregister_post_type('sspa_hidden_fixture');
// The original 0.43.18 ZIP produced this registry without created_at on tests-w5b-legacy.
// Preserve the same real object and remove only that newer format field for migration.
$launch = SSPA_Workflow_Analysis::launch('page', 0, 'rest');
if (is_wp_error($launch)) { sspa_w5b_fault_t(false, $launch->get_error_message()); }
else {
    $entry = get_option(SSPA_Workflow_Analysis::TEMP_OPTION);
    unset($entry['created_at']);
    update_option(SSPA_Workflow_Analysis::TEMP_OPTION, $entry, false);
    SSPA_Run_Controller::cleanup();
    sspa_w5b_fault_t(get_post($launch['object_id']) && get_option(SSPA_Workflow_Analysis::TEMP_OPTION), 'fresh pre-upgrade workflow registry is preserved for its fifteen-minute launch window');
    SSPA_Workflow_Analysis::cleanup();
}
// A write job can fail before its temporary object ID is returned to the batch driver.
$run = SSPA_Run_Controller::start(array('type' => 'baseline', 'page_keys' => array('home'), 'include_writes' => true, 'user_id' => 1, 'share' => false));
if (is_wp_error($run)) { sspa_w5b_fault_t(false, $run->get_error_message()); }
else {
    update_option('sspa_w5b_empty_temp', true, false);
    $thrown = ''; $deadline = time() + 90;
    try {
        do {
            try { SSPA_Run_Controller::process_batch($run); } catch (Throwable $error) { $thrown = $error->getMessage(); break; }
            $status = SSPA_Run_Controller::status($run);
        } while ($status && 'crawling' === $status['status'] && time() < $deadline);
        $row = SSPA_Run_Controller::run_row($run);
        sspa_w5b_fault_t(!$thrown && 'failed' === $row['status'] && false !== strpos($row['notes'], 'Content, title, and excerpt are empty'), 'batch callback failure fails its run with the real error');
    } finally {
        delete_option('sspa_w5b_empty_temp');
        $row = SSPA_Run_Controller::run_row($run);
        if ($row && in_array($row['status'], array('queued', 'crawling', 'analysing', 'cancelling'), true)) { SSPA_Run_Controller::cancel($run); }
    }
}
