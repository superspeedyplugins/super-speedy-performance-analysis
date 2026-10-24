<?php
// "Profile this endpoint" on a REST endpoint that needs a login (plan
// .docs/2026-10-24-endpoint-profile-login-plan.md). Reported on demo.superspeedyplugins.com with
// GET /wp-json/wc-analytics/reports: HTTP 401, "?" cards, and "because it was pruned" on a fresh result.
//
// A real AJAX window observes wc-analytics/reports twice: once logged out (refused) and once as the
// admin with the REST nonce WooCommerce Admin sends. Each observation is then profiled through the
// real SSPA_Endpoint_Profile::start(), the same entry point as the AJAX tab's form.

function sspa_145_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
    return $ok;
}

global $wpdb;
if (!class_exists('WooCommerce')) { echo "FAIL: WooCommerce is not active\n"; return; }
wp_set_current_user(1);
$active = SSPA_Traffic_Collection::active();
if ($active) { SSPA_Traffic_Collection::stop((int) $active['id'], true); }
$run = SSPA_Run_Controller::active_run_id();
if ($run) { SSPA_Run_Controller::cancel($run); }
SSPA_Run_Controller::cleanup();

$window = SSPA_Ajax_Profile::start(array('scenario' => 'Endpoint login', 'label' => 'Case 145 window'));
if (!sspa_145_t(!is_wp_error($window), 'an AJAX window starts')) { return; }
sleep(3);
$url = rest_url('wc-analytics/reports');
$exp = time() + 600;
$token = WP_Session_Tokens::get_instance(1)->create($exp);
$cookie = wp_generate_auth_cookie(1, $exp, 'logged_in', $token);
$saved_cookie = $_COOKIE[LOGGED_IN_COOKIE] ?? null;
$_COOKIE[LOGGED_IN_COOKIE] = $cookie;
$nonce = wp_create_nonce('wp_rest');
if (null === $saved_cookie) { unset($_COOKIE[LOGGED_IN_COOKIE]); } else { $_COOKIE[LOGGED_IN_COOKIE] = $saved_cookie; }
$anon_code = wp_remote_retrieve_response_code(wp_remote_get($url, array('timeout' => 60)));
$admin_code = wp_remote_retrieve_response_code(wp_remote_get($url, array('timeout' => 60, 'cookies' => array(new WP_Http_Cookie(array('name' => LOGGED_IN_COOKIE, 'value' => $cookie))), 'headers' => array('X-WP-Nonce' => $nonce))));
SSPA_Ajax_Profile::stop($window['uuid']);
sspa_145_t(401 === (int) $anon_code && 200 === (int) $admin_code, "the store refuses a logged-out request ($anon_code) and answers the admin's ($admin_code)");

$evidence = SSPA_Report::endpoint_evidence((int) $window['collection_id']);
$keys = array();
foreach ((array) (is_wp_error($evidence) ? array() : $evidence['endpoints']) as $endpoint) {
    if ('/wc-analytics/reports' === ($endpoint['identity']['route_pattern'] ?? '')) {
        $keys[$endpoint['identity']['auth_context']] = $endpoint['key'];
    }
}
if (!sspa_145_t(isset($keys['anonymous'], $keys['logged_in']), 'the window observed wc-analytics/reports both logged out and logged in (' . implode(', ', array_keys($keys)) . ')')) { return; }

$profile = function ($key, $as = null) use ($window, $url, $wpdb) {
    $args = array('uuid' => $window['uuid'], 'key' => $key, 'url' => $url, 'samples' => 2, 'confirmed' => true);
    if (null !== $as) { $args['as'] = $as; }
    $started = SSPA_Endpoint_Profile::start($args);
    if (is_wp_error($started)) { return array('error' => $started->get_error_message()); }
    $deadline = time() + 300;
    do {
        SSPA_Run_Controller::process_batch($started['run_id']);
        $status = SSPA_Run_Controller::status($started['run_id']);
    } while ($status && in_array($status['status'], array('queued', 'crawling', 'analysing'), true) && time() < $deadline);
    $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE run_id = %d ORDER BY id DESC LIMIT 1', SSPA_Schema::table('profiles'), (int) $started['run_id']), ARRAY_A);
    $html = $row ? SSPA_Profile_Panel::render((int) $row['id']) : '';
    $text = is_wp_error($html) ? $html->get_error_message() : preg_replace('/\s+/', ' ', wp_strip_all_tags((string) $html));
    return array('started' => $started, 'row' => $row, 'text' => $text);
};

// 1. Observed logged in: profiled as the admin, which must authenticate.
$r = $profile($keys['logged_in']);
$row = $r['row'] ?? null;
sspa_145_t($row && 'admin' === $row['variant'], 'the logged-in observation is profiled as the admin (' . ($row ? $row['variant'] : ($r['error'] ?? 'no profile')) . ')');
sspa_145_t($row && 200 === (int) $row['response_code'] && null !== $row['page_gen_ms'], 'the logged-in profile is answered 200 with a captured generation time (HTTP ' . ($row ? $row['response_code'] : '-') . ', ' . ($row ? var_export($row['page_gen_ms'], true) : '-') . ' ms)');

// 2. Observed logged out, sent as the admin by choice.
$r = $profile($keys['anonymous'], 'admin');
$row = $r['row'] ?? null;
sspa_145_t($row && 'admin' === $row['variant'] && 200 === (int) $row['response_code'], 'choosing "you (logged in)" for the logged-out observation sends it as the admin and gets 200 (' . ($row ? $row['variant'] . ' HTTP ' . $row['response_code'] : ($r['error'] ?? 'no profile')) . ')');
sspa_145_t($row && false !== strpos($r['text'], 'profiled as admin'), 'its result says "profiled as admin"');

// 3. Observed logged out, sent logged out: refused, and the result says why.
$r = $profile($keys['anonymous']);
$row = $r['row'] ?? null;
sspa_145_t($row && 'anon' === $row['variant'] && 401 === (int) $row['response_code'], 'the logged-out observation sent logged out is refused (' . ($row ? $row['variant'] . ' HTTP ' . $row['response_code'] : ($r['error'] ?? 'no profile')) . ')');
sspa_145_t(false === strpos($r['text'], 'because it was pruned'), 'a refused, just-measured request is not described as pruned');
sspa_145_t(false !== strpos($r['text'], 'answered HTTP 401') && false !== strpos($r['text'], 'logged-in user'), 'the result explains the 401 and that the endpoint needs a logged-in user');
