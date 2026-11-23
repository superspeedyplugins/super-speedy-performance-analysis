<?php
// The Pro DNS check on the Tools tab (Thaan, Discord, 23 November 2026: a resolver dropping about
// 40% of lookups added 5 to 10 seconds to wp-admin). Two real UDP DNS servers on loopback
// (.tests/fixtures/dns-server.cjs): one answers everything, one leaves 5 of every 12 queries
// unanswered. The check is pressed the way the screen presses it, through the authenticated
// sspa_dns_check admin-ajax request over HTTP.
//
// Own site, because it installs an MU plugin naming the fixture resolvers:
//   SSPA_SCENARIO=tests-dns-check .tests/run-tests.sh 165-dns-check
// The fixture servers are stopped on every exit path. The site and its MU plugin stay.
defined('ABSPATH') || exit;

$GLOBALS['sspa_165_failures'] = 0;
function sspa_165_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
    if (!$ok) { $GLOBALS['sspa_165_failures']++; }
}
if (false === strpos(home_url(), 'tests-dns-check.')) {
    echo "FAIL: this case runs only on its own site: SSPA_SCENARIO=tests-dns-check (this is " . home_url() . ")\n";
    return;
}

// The AJAX request is another PHP process, so the resolvers reach it through an MU plugin.
$mu_dir = WPMU_PLUGIN_DIR;
if (!is_dir($mu_dir)) { wp_mkdir_p($mu_dir); }
file_put_contents($mu_dir . '/sspa-test-dns-nameservers.php', "<?php\n// Case 165: names the fixture resolvers for the DNS check.\nadd_filter('sspa_dns_check_nameservers', function (\$servers) {\n    \$test = get_option('sspa_test_dns_nameservers');\n    return is_array(\$test) && \$test ? \$test : \$servers;\n});\n");

$ok_port = 15353;
$drop_port = 15354;
$procs = array();
foreach (array($ok_port => 'ok', $drop_port => 'drop') as $port => $mode) {
    $pipes = array();
    $procs[] = proc_open(array('node', dirname(__DIR__) . '/fixtures/dns-server.cjs', (string) $port, $mode), array(1 => array('pipe', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes);
    $line = isset($pipes[1]) ? fgets($pipes[1]) : '';
    sspa_165_t(false !== strpos((string) $line, 'listening ' . $port), 'fixture DNS server (' . $mode . ') is listening on 127.0.0.1:' . $port);
}
register_shutdown_function(function () use (&$procs) {
    foreach ($procs as $proc) { if (is_resource($proc)) { proc_terminate($proc); } }
});

delete_option('sspa_dns_check');
update_option('sspa_test_dns_nameservers', array('127.0.0.1:' . $ok_port, '127.0.0.1:' . $drop_port), false);

function sspa_165_cookies($user_id) {
    $expiry = time() + 600;
    $session = WP_Session_Tokens::get_instance($user_id)->create($expiry);
    return array(
        array(
            LOGGED_IN_COOKIE => wp_generate_auth_cookie($user_id, $expiry, 'logged_in', $session),
            AUTH_COOKIE => wp_generate_auth_cookie($user_id, $expiry, 'auth', $session),
        ),
        $session,
    );
}
function sspa_165_post($action, $user_id, $extra = array()) {
    list($cookies, $session) = sspa_165_cookies($user_id);
    wp_set_current_user($user_id);
    $_COOKIE[LOGGED_IN_COOKIE] = $cookies[LOGGED_IN_COOKIE];
    $nonce = wp_create_nonce('sspa_admin');
    $response = wp_remote_post(admin_url('admin-ajax.php'), array('timeout' => 90, 'cookies' => $cookies, 'body' => array_merge(array('action' => $action, 'nonce' => $nonce), $extra)));
    $body = is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_body($response);
    return array('code' => is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response), 'json' => json_decode($body, true), 'raw' => substr(trim(wp_strip_all_tags($body)), 0, 200));
}

$started = microtime(true);
$check = sspa_165_post('sspa_dns_check', 1);
$took = microtime(true) - $started;
sspa_165_t(200 === $check['code'] && !empty($check['json']['success']) && !empty($check['json']['data']['html']), 'Check DNS answers with the section (HTTP ' . $check['code'] . ', ' . $check['raw'] . ')');
$html = isset($check['json']['data']['html']) ? $check['json']['data']['html'] : '';

$saved = get_option('sspa_dns_check');
$servers = is_array($saved) && isset($saved['servers']) ? $saved['servers'] : array();
$by = array();
foreach ($servers as $server) { $by[$server['address']] = $server; }
$good = isset($by['127.0.0.1:' . $ok_port]) ? $by['127.0.0.1:' . $ok_port] : array();
$bad = isset($by['127.0.0.1:' . $drop_port]) ? $by['127.0.0.1:' . $drop_port] : array();

sspa_165_t(2 === count($servers), 'both resolvers were tested (' . count($servers) . ')');
sspa_165_t($good && 12 === $good['sent'] && 12 === $good['answered'] && 0 === $good['unanswered'] && 0 === $good['failed'], 'the healthy resolver answered 12 of 12 (' . wp_json_encode($good) . ')');
sspa_165_t($good && 'healthy' === $good['verdict'], 'the healthy resolver is reported Healthy');
sspa_165_t($bad && 12 === $bad['sent'] && 7 === $bad['answered'] && 5 === $bad['unanswered'], 'the dropping resolver answered 7 of 12 and left 5 unanswered (' . wp_json_encode($bad) . ')');
sspa_165_t($bad && 'dropping' === $bad['verdict'], 'the dropping resolver is reported as dropping lookups');
sspa_165_t($took >= 7 && $took < 25, 'five unanswered lookups cost 1.5 seconds each and the check stays inside its 25 second budget (' . round($took, 1) . ' s)');
sspa_165_t(false !== strpos($html, 'Dropping lookups') && false !== strpos($html, 'Healthy'), 'the section names both verdicts');
sspa_165_t(false !== strpos($html, '5 of 12') && false !== strpos($html, '127.0.0.1:' . $drop_port), 'the section says 5 of 12 lookups to the dropping resolver got no answer');
sspa_165_t((bool) preg_match('/waits? (up to )?\d+ seconds/', wp_strip_all_tags($html)), 'the section says how long PHP waits on a lost lookup');

$tab = sspa_165_post('sspa_render_tab', 1, array('tabs' => 'tools'));
$tools = isset($tab['json']['data']['tabs']['tools']) ? $tab['json']['data']['tabs']['tools'] : '';
sspa_165_t(false !== strpos($tools, 'id="sspa-dns-check"') && false !== strpos($tools, 'sspa-dns-check-run'), 'the Tools tab has the DNS section and its Check DNS button');
sspa_165_t(false !== strpos($tools, '5 of 12') && false !== strpos($tools, 'Dropping lookups'), 'the next Tools render shows the saved result');

$subscriber = username_exists('sspa165sub');
if (!$subscriber) { $subscriber = wp_insert_user(array('user_login' => 'sspa165sub', 'user_pass' => wp_generate_password(), 'user_email' => 'sspa165sub@example.com', 'role' => 'subscriber')); }
$before = get_option('sspa_dns_check');
$refused = sspa_165_post('sspa_dns_check', (int) $subscriber);
wp_set_current_user(1);
wp_cache_delete('sspa_dns_check', 'options');
sspa_165_t(403 === $refused['code'] && empty($refused['json']['success']), 'a subscriber is refused (HTTP ' . $refused['code'] . ')');
sspa_165_t($before === get_option('sspa_dns_check'), 'and the saved result is unchanged');

echo $GLOBALS['sspa_165_failures'] ? "FAILED: {$GLOBALS['sspa_165_failures']}\n" : "ALL PASSED\n";
