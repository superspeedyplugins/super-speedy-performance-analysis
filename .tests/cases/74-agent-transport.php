<?php
// R06: real core Abilities HTTP discovery, independent read/execute capabilities and checkout consent.
require __DIR__ . '/../lib/fleet.php';
$users = array();
foreach (array('denied' => array(), 'reader' => array('sspa_manage'), 'runner' => array('sspa_manage', 'sspa_execute')) as $name => $caps) {
    $login = 'sspa_regression_' . $name;
    $existing = get_user_by('login', $login);
    $id = $existing ? $existing->ID : wp_insert_user(array('user_login' => $login, 'user_pass' => wp_generate_password(30), 'user_email' => $name . '@example.invalid', 'role' => 'subscriber'));
    $user = new WP_User($id);
    foreach (array('sspa_manage', 'sspa_execute') as $cap) $user->remove_cap($cap);
    foreach ($caps as $cap) $user->add_cap($cap);
    foreach (WP_Application_Passwords::get_user_application_passwords($id) as $prior) {
        if ($prior['name'] === 'Synthetic regression transport') WP_Application_Passwords::delete_application_password($id, $prior['uuid']);
    }
    $password = WP_Application_Passwords::create_new_application_password($id, array('name' => 'Synthetic regression transport'));
    $users[$name] = array($login, $password[0]);
}
$request = static function ($identity, $path, $input = null, $method = 'GET') use ($users) {
    $url = rest_url('wp-abilities/v1/abilities' . $path);
    $args = array('method' => $method, 'headers' => array('Authorization' => 'Basic ' . base64_encode(implode(':', $users[$identity])), 'Content-Type' => 'application/json'), 'timeout' => 60);
    if ($input !== null) {
        if ($method === 'GET') $url = add_query_arg(array('input' => $input), $url);
        else $args['body'] = wp_json_encode(array('input' => $input));
    }
    $response = wp_remote_request($url, $args);
    if (is_wp_error($response)) throw new RuntimeException($response->get_error_message());
    return array(wp_remote_retrieve_response_code($response), json_decode(wp_remote_retrieve_body($response), true));
};
$discovery = $request('reader', '');
sspa_fleet_assert($discovery[0] === 200, 'authenticated HTTP tool inventory succeeds');
$names = array_column($discovery[1], 'name');
foreach (SSPA_Abilities::ability_names() as $name) sspa_fleet_assert(in_array($name, $names, true), 'HTTP inventory exposes ' . $name);
$prefix = '/super-speedy-performance/';
$guest = wp_remote_get(rest_url('wp-abilities/v1/abilities' . $prefix . 'get-status/run'));
sspa_fleet_assert(!is_wp_error($guest) && in_array(wp_remote_retrieve_response_code($guest), array(401,403), true), 'unauthenticated HTTP caller cannot read private status');
$status = $request('reader', $prefix . 'get-status/run', array());
sspa_fleet_assert($status[0] === 200 && isset($status[1]['active']), 'read capability executes real status');
$denied = $request('denied', $prefix . 'get-status/run', array());
sspa_fleet_assert($denied[0] === 403, 'valid authenticated low role is denied by capability');
$report = $request('reader', $prefix . 'get-report/run', array());
sspa_fleet_assert($report[0] === 200 && ($report[1]['schema'] ?? '') === SSPA_Report::SCHEMA, 'HTTP report uses actual stable schema');
$before = count(wc_get_orders(array('limit' => -1, 'return' => 'ids')));
$refused = $request('reader', $prefix . 'run-checkout-flow/run', array('confirm' => true), 'POST');
sspa_fleet_assert($refused[0] === 403, 'read-only role cannot execute even with confirmation');
$unconfirmed = $request('runner', $prefix . 'run-checkout-flow/run', array('confirm' => false), 'POST');
sspa_fleet_assert($unconfirmed[0] >= 400 && ($unconfirmed[1]['code'] ?? '') === 'sspa_confirm_required', 'execute capability does not bypass explicit checkout confirmation');
sspa_fleet_assert(count(wc_get_orders(array('limit' => -1, 'return' => 'ids'))) === $before, 'denied requests create no order');
$preview = $request('runner', $prefix . 'run-checkout-flow/run', array('dry_run' => true), 'POST');
sspa_fleet_assert($preview[0] === 200 && !empty($preview[1]['dry_run']), 'real dry-run inventory is exposed over HTTP');
sspa_fleet_assert(count(wc_get_orders(array('limit' => -1, 'return' => 'ids'))) === $before, 'dry run creates no order');
$run = $request('runner', $prefix . 'run-checkout-flow/run', array('confirm' => true, 'mail_mode' => 'suppress', 'allow_integrations' => false, 'allow_webhooks' => false), 'POST');
$id = (int) ($run[1]['run_id'] ?? 0);
sspa_fleet_assert($run[0] === 200 && $id > 0, 'confirmed HTTP request persists an actual run');
if ($id) {
    sspa_fleet_complete($id);
    $flow = $request('reader', $prefix . 'get-checkout-flow/run', array('run_id' => $id));
    sspa_fleet_assert($flow[0] === 200 && (int)($flow[1]['run_id'] ?? 0) === $id && ($flow[1]['total_ms'] ?? 0) > 0, 'reader retrieves exact measured checkout run and timing');
    $order = wc_get_order((int)($flow[1]['order_details']['order_id'] ?? 0));
    sspa_fleet_assert($order && $order->get_status() === 'trash', 'confirmed HTTP run leaves its actual recoverable order in Trash');
    sspa_fleet_assert($order && count($order->get_refunds()) === 1, 'confirmed HTTP run retains its real offline refund');
}
sspa_fleet_done();
