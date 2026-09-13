<?php
// R07: every registered PA command is invoked as a separate wp process.
require __DIR__ . '/../lib/fleet.php';
wp_update_post(array('ID' => (int) get_option('woocommerce_checkout_page_id'), 'post_content' => '<!-- wp:woocommerce/checkout /-->'));
wp_cache_flush();
$inventory = json_decode(sspa_fleet_cli(array('eval', '$root=WP_CLI::get_root_command()->get_subcommands();$pa=$root["sspa"]->get_subcommands();$out=array_keys($pa);$traffic=array_keys($pa["traffic"]->get_subcommands());echo wp_json_encode(array($out,$traffic));')), true);
$expected = array('run', 'checkout-flow', 'status', 'findings', 'cache-scan', 'cache-optimisation-report', 'impacts', 'http-calls', 'page-plugin-usage', 'history-compare', 'report', 'traffic');
sort($expected); sort($inventory[0]);
sspa_fleet_assert($inventory[0] === $expected, 'registered PA command inventory equals the invoked matrix');
$traffic_expected = array('start', 'status', 'stop', 'observations', 'compare', 'delete'); sort($traffic_expected); sort($inventory[1]);
sspa_fleet_assert($inventory[1] === $traffic_expected, 'registered traffic command inventory equals the invoked matrix');
$ids = array();
for ($i = 0; $i < 2; $i++) {
    $id = (int) sspa_fleet_cli(array('sspa', 'run', '--type=spot', '--pages=home', '--porcelain'));
    $ids[] = $id;
    $row = SSPA_Run_Controller::run_row($id);
    sspa_fleet_assert($id > 0 && $row['status'] === 'done', 'CLI run persists completed Home measurements');
}
$id = end($ids);
$status = json_decode(sspa_fleet_cli(array('sspa', 'status', '--format=json')), true);
sspa_fleet_assert((int) $status['run_id'] === $id && $status['status'] === 'done', 'CLI status identifies the actual latest run');
$report = json_decode(sspa_fleet_cli(array('sspa', 'report', '--run=' . $id)), true);
sspa_fleet_assert(($report['schema'] ?? '') === SSPA_Report::SCHEMA, 'CLI report carries actual versioned schema');
foreach (array('findings', 'cache-scan', 'cache-optimisation-report') as $command) {
    $result = json_decode(sspa_fleet_cli(array('sspa', $command, '--run=' . $id, '--format=json')), true);
    sspa_fleet_assert(is_array($result), $command . ' returns parsed real report data');
}
$http = json_decode(sspa_fleet_cli(array('sspa', 'http-calls', '--run-id=' . $id)), true);
sspa_fleet_assert(is_array($http) && isset($http['calls']), 'CLI outbound HTTP inventory parses');
$usage = json_decode(sspa_fleet_cli(array('sspa', 'page-plugin-usage', '--run=' . $id)), true);
sspa_fleet_assert(is_array($usage) && !empty($usage), 'CLI page usage carries measured evidence');
$comparison = json_decode(sspa_fleet_cli(array('sspa', 'history-compare', (string) $ids[0], (string) $ids[1])), true);
sspa_fleet_assert(is_array($comparison) && !empty($comparison), 'CLI compares two actually measured runs');
$impacts = json_decode(sspa_fleet_cli(array('sspa', 'impacts', '--format=json')), true);
sspa_fleet_assert(is_array($impacts), 'CLI impacts reads retained measured results');
$order_count = count(wc_get_orders(array('limit' => -1, 'return' => 'ids')));
$preflight = json_decode(sspa_fleet_cli(array('sspa', 'checkout-flow', '--dry-run')), true);
sspa_fleet_assert(!empty($preflight['woocommerce']) && count(wc_get_orders(array('limit' => -1, 'return' => 'ids'))) === $order_count, 'CLI checkout preflight creates no order');
$checkout = (int) sspa_fleet_cli(array('sspa', 'checkout-flow', '--payment=no_payment', '--mail=construct', '--porcelain'));
sspa_fleet_cli(array('sspa', 'checkout-flow', '--payment=no_payment', '--mail=construct', '--no-integrations', '--no-webhooks', '--porcelain'));
sspa_fleet_assert($checkout > 0 && SSPA_Run_Controller::run_row($checkout)['status'] === 'done', 'CLI checkout completes real offline purchase lifecycle');
$collections = array();
for ($i = 0; $i < 2; $i++) {
    $start = json_decode(sspa_fleet_cli(array('sspa', 'traffic', 'start', '--duration=15m', '--format=json')), true);
    $collection = (int) ($start['collection']['id'] ?? 0); $collections[] = $collection;
    sspa_fleet_assert($collection > 0, 'CLI traffic collection persisted');
    sleep(3); // Generated observer must reach the serving FPM opcode cache.
    for ($j = 0; $j < 3; $j++) wp_remote_get(home_url('/?synthetic_observation=' . $j), array('user-agent' => 'Mozilla/5.0 SyntheticRegression'));
    $status = json_decode(sspa_fleet_cli(array('sspa', 'traffic', 'status', '--collection=' . $collection, '--format=json')), true);
    sspa_fleet_assert((int) ($status['collection']['event_count'] ?? 0) >= 3, 'CLI traffic status sees real request events');
    sspa_fleet_cli(array('sspa', 'traffic', 'stop', '--collection=' . $collection, '--emergency', '--format=json'));
    $observations = json_decode(sspa_fleet_cli(array('sspa', 'traffic', 'observations', '--collection=' . $collection)), true);
    sspa_fleet_assert(is_array($observations) && !empty($observations['schema']), 'CLI observations export is versioned');
}
$compare = json_decode(sspa_fleet_cli(array('sspa', 'traffic', 'compare', (string) $collections[0], (string) $collections[1])), true);
sspa_fleet_assert(is_array($compare) && !empty($compare['schema']), 'CLI traffic comparison carries actual retained collections');
// Deletion itself is the behavior under test; retain the two observed collections above.
$empty = json_decode(sspa_fleet_cli(array('sspa', 'traffic', 'start', '--duration=15m', '--format=json')), true);
$delete_id = (int) $empty['collection']['id'];
sspa_fleet_cli(array('sspa', 'traffic', 'stop', '--collection=' . $delete_id, '--emergency', '--format=json'));
sspa_fleet_cli(array('sspa', 'traffic', 'delete', (string) $delete_id, '--yes'));
global $wpdb;
sspa_fleet_assert(!$wpdb->get_var($wpdb->prepare('SELECT id FROM ' . SSPA_Schema::table('traffic_collections') . ' WHERE id=%d', $delete_id)), 'CLI delete removes only the requested empty test collection');
sspa_fleet_done();
