<?php
// Agent surfaces: the Abilities API pipeline (validate -> permission -> execute ->
// output-validate) and the WP-CLI commands, both returning the stable report schema.

function sspa_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

if (!function_exists('wp_register_ability') || !function_exists('wp_get_ability')) {
    echo "PASS: SKIP - Abilities API not present on this WordPress\n";
    return;
}

wp_set_current_user(1);

// --- Readonly abilities ---
$ability = wp_get_ability('super-speedy-performance/get-status');
sspa_t(is_object($ability), 'get-status ability registered');
$status = $ability ? $ability->execute(array()) : null;
sspa_t(is_array($status) && isset($status['active'], $status['latest_done_run_id']), 'get-status executes through the full pipeline');
sspa_t(is_array($status) && $status['latest_done_run_id'] > 0, 'latest done run visible (' . $status['latest_done_run_id'] . ')');

$report = wp_get_ability('super-speedy-performance/get-report')->execute(array());
sspa_t(is_array($report) && !is_wp_error($report), 'get-report executes');
if (is_array($report)) {
    sspa_t(1 === $report['schema'], 'report schema versioned');
    sspa_t(is_int($report['score']) || $report['score'] === null, 'score typed');
    sspa_t(!empty($report['pages']) && isset($report['pages'][0]['generation_ms']), 'pages with stable keys');
    sspa_t(empty($report['findings']) || !empty($report['findings'][0]['headline']), 'findings carry headlines when the clean run has any');
    sspa_t(empty($report['findings']) || isset($report['findings'][0]['recommendation']['body']), 'findings carry explicit recommendation objects when present');
    sspa_t(isset($report['site']['sector']), 'site block present (' . $report['site']['sector'] . ')');
    sspa_t(isset($report['cache_safety']['assessment']['shared_cache_status']), 'report carries shared-cache safety status');
}

$cache_recon = wp_get_ability('super-speedy-performance/get-cache-safety-report')->execute(array());
sspa_t(is_array($cache_recon) && isset($cache_recon['assessment']['difficulty']), 'get-cache-safety-report returns the dedicated assessment');

$cache_optimisation = wp_get_ability('super-speedy-performance/get-cache-optimisation-analysis')->execute(array());
sspa_t(is_array($cache_optimisation) && 'sspa/shared-cache-safety-report@2' === $cache_optimisation['schema'], 'get-cache-optimisation-analysis returns the complete versioned document');

$findings = wp_get_ability('super-speedy-performance/get-findings')->execute(array());
sspa_t(is_array($findings) && isset($findings['total'], $findings['findings']), 'get-findings shape');

$impacts = wp_get_ability('super-speedy-performance/get-plugin-impacts')->execute(array());
sspa_t(is_array($impacts) && isset($impacts['total']) && $impacts['total'] >= 1, 'get-plugin-impacts returns measured impacts (' . $impacts['total'] . ')');

$metrics = wp_get_ability('super-speedy-performance/get-site-metrics')->execute(array());
sspa_t(is_array($metrics) && 'e-commerce' === $metrics['sector'], 'get-site-metrics sector (' . (is_array($metrics) ? $metrics['sector'] : '?') . ')');

$traffic_status_ability = wp_get_ability('super-speedy-performance/get-traffic-collection-status');
$traffic_observations_ability = wp_get_ability('super-speedy-performance/get-traffic-observations');
$traffic_compare_ability = wp_get_ability('super-speedy-performance/compare-traffic-collections');
sspa_t(is_object($traffic_status_ability) && is_object($traffic_observations_ability) && is_object($traffic_compare_ability), 'traffic status, observations and comparison abilities registered');
$traffic_started = wp_get_ability('super-speedy-performance/start-traffic-collection')->execute(array('duration' => '24h'));
sspa_t(is_array($traffic_started) && !empty($traffic_started['active']), 'start-traffic-collection executes through the full pipeline');
if (is_array($traffic_started) && !empty($traffic_started['collection']['id'])) {
    $traffic_id = (int) $traffic_started['collection']['id'];
    $traffic_status = $traffic_status_ability->execute(array('collection_id' => $traffic_id));
    sspa_t(is_array($traffic_status) && $traffic_id === $traffic_status['collection']['id'], 'get-traffic-collection-status returns typed collection state');
    $traffic_observations = $traffic_observations_ability->execute(array('collection_id' => $traffic_id));
    sspa_t(is_array($traffic_observations) && SSPA_Traffic_Privacy::SCHEMA === $traffic_observations['schema'], 'get-traffic-observations returns the privacy-safe Phase 3 schema');
    $traffic_comparison = $traffic_compare_ability->execute(array('before_collection_id' => $traffic_id, 'after_collection_id' => $traffic_id));
    sspa_t(is_array($traffic_comparison) && 'sspa/traffic-collection-comparison@1' === $traffic_comparison['schema'], 'compare-traffic-collections returns the duration-normalised schema');
    $traffic_stopped = wp_get_ability('super-speedy-performance/stop-traffic-collection')->execute(array('collection_id' => $traffic_id, 'emergency' => true));
    sspa_t(is_array($traffic_stopped) && 'stopped' === $traffic_stopped['collection']['status'], 'stop-traffic-collection emergency stop executes');
}

// --- Permission gate: a subscriber must be refused ---
$subscriber_id = username_exists('sspa-perm-test');
if (!$subscriber_id) {
    $subscriber_id = wp_insert_user(array('user_login' => 'sspa-perm-test', 'user_pass' => wp_generate_password(), 'role' => 'subscriber'));
}
// wp_set_current_user() returns early when the id is unchanged, so the in-memory WP_User
// keeps whatever caps it was loaded with and a later add_cap()/remove_cap() is invisible to
// current_user_can(). Bounce through 0 to force a reload.
$sspa_switch_to = function ($id) {
    wp_set_current_user(0);
    wp_set_current_user((int) $id);
};

// Start from a known cap set. Without this the case is order-dependent in BOTH directions:
// on a fresh site "manage-only user can read reports" failed (the cap was added but never
// picked up), and on any later run the cap left behind by an aborted earlier run made
// "non-admin denied" fail instead. The old Docker environment was long-lived, so the first
// run's failure was seen once and then hidden by its own residue for ever after.
$subscriber = new WP_User((int) $subscriber_id);
$subscriber->remove_cap('sspa_manage');
$subscriber->remove_cap('sspa_execute');
$sspa_switch_to($subscriber_id);
sspa_t(!current_user_can('sspa_manage'), 'precondition: test user starts without sspa_manage');

sspa_t(get_current_user_id() > 0 && !current_user_can('manage_options'), 'low-privilege test user in place');
$denied = wp_get_ability('super-speedy-performance/get-report')->execute(array());
sspa_t(is_wp_error($denied), 'non-admin denied by permission callback');

$subscriber->add_cap('sspa_manage');
$sspa_switch_to($subscriber_id);
sspa_t(current_user_can('sspa_manage'), 'sspa_manage granted and visible to current_user_can()');
$allowed = wp_get_ability('super-speedy-performance/get-report')->execute(array());
$denied_execute = wp_get_ability('super-speedy-performance/run-analysis')->execute(array('type' => 'spot', 'pages' => array('home')));
sspa_t(is_array($allowed) && !is_wp_error($allowed), 'manage-only user can read reports');
sspa_t(is_wp_error($denied_execute), 'manage-only user cannot start analyses');

$subscriber->remove_cap('sspa_manage');
$sspa_switch_to($subscriber_id);
sspa_t(!current_user_can('sspa_manage'), 'cap removed again, so a re-run starts clean');
wp_set_current_user(1);

$unconfirmed_checkout = wp_get_ability('super-speedy-performance/run-checkout-flow')->execute(array());
sspa_t(is_wp_error($unconfirmed_checkout) && 'sspa_confirm_required' === $unconfirmed_checkout->get_error_code(), 'real checkout run refuses without confirm=true');

// --- run-analysis ability starts an async run ---
$started = wp_get_ability('super-speedy-performance/run-analysis')->execute(array('type' => 'spot', 'pages' => array('home')));
sspa_t(is_array($started) && !empty($started['run_id']), 'run-analysis starts a run (' . (is_array($started) ? $started['run_id'] : '?') . ')');
if (is_array($started)) {
    $deadline = time() + 180;
    do {
        SSPA_Run_Controller::process_batch($started['run_id']);
        $s = SSPA_Run_Controller::status($started['run_id']);
    } while ($s && in_array($s['status'], array('crawling', 'analysing'), true) && time() < $deadline);
    sspa_t($s && 'done' === $s['status'], 'ability-started run completes');
}

// --- submit-results refuses without owner opt-in ---
update_option('sspa_share_optin', 0, false);
$refused = wp_get_ability('super-speedy-performance/submit-results')->execute(array());
sspa_t(is_wp_error($refused), 'submit-results refuses without owner opt-in');

// --- WP-CLI commands (subprocess - proves registration end to end) ---
if (class_exists('WP_CLI')) {
    $json = WP_CLI::runcommand('sspa report', array('return' => true, 'launch' => true, 'exit_error' => false));
    $cli_report = json_decode((string) $json, true);
    sspa_t(is_array($cli_report) && 1 === $cli_report['schema'], 'wp sspa report emits the same schema');

    $json = WP_CLI::runcommand('sspa findings --format=json', array('return' => true, 'launch' => true, 'exit_error' => false));
    $cli_findings = json_decode((string) $json, true);
    sspa_t(is_array($cli_findings), 'wp sspa findings --format=json parses');

    $json = WP_CLI::runcommand('sspa impacts --format=json', array('return' => true, 'launch' => true, 'exit_error' => false));
    $cli_impacts = json_decode((string) $json, true);
    sspa_t(is_array($cli_impacts) && count($cli_impacts) >= 1, 'wp sspa impacts --format=json returns measured impacts');

    $json = WP_CLI::runcommand('sspa cache-scan --format=json', array('return' => true, 'launch' => true, 'exit_error' => false));
    $cli_cache = json_decode((string) $json, true);
    sspa_t(is_array($cli_cache) && isset($cli_cache['assessment']['shared_cache_status']), 'wp sspa cache-scan --format=json returns the assessment');

    $json = WP_CLI::runcommand('sspa cache-optimisation-report --format=json', array('return' => true, 'launch' => true, 'exit_error' => false));
    $cli_cache_named = json_decode((string) $json, true);
    sspa_t(is_array($cli_cache_named) && 'sspa/shared-cache-safety-report@2' === $cli_cache_named['schema'], 'wp sspa cache-optimisation-report emits the complete versioned document');

    $json = WP_CLI::runcommand('sspa traffic status --format=json', array('return' => true, 'launch' => true, 'exit_error' => false));
    $cli_traffic = json_decode((string) $json, true);
    sspa_t(is_array($cli_traffic) && array_key_exists('active', $cli_traffic), 'wp sspa traffic status --format=json parses');

    if (!empty($traffic_id)) {
        $json = WP_CLI::runcommand('sspa traffic compare ' . $traffic_id . ' ' . $traffic_id, array('return' => true, 'launch' => true, 'exit_error' => false));
        $cli_comparison = json_decode((string) $json, true);
        sspa_t(is_array($cli_comparison) && 'sspa/traffic-collection-comparison@1' === $cli_comparison['schema'], 'wp sspa traffic compare emits the duration-normalised schema');
    }

    $out = WP_CLI::runcommand('sspa status --format=json', array('return' => true, 'launch' => true, 'exit_error' => false));
    $cli_status = json_decode((string) $out, true);
    sspa_t(is_array($cli_status) && isset($cli_status['status']), 'wp sspa status --format=json parses');
} else {
    echo "PASS: SKIP - WP_CLI not available in this context\n";
}
