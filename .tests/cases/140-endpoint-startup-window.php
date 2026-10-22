<?php
// Additions B and C of .docs/2026-10-22-endpoint-startup-profiling-plan.md.
//
// A real AJAX window observes real HTTP requests to a fixture REST route and a fixture
// admin-ajax action. A real MU plugin trims both to WooCommerce plus the fixture, as a Fast
// Ajax/REST MU plugin does, so Performance Analysis's main plugin is not loaded on them.
// Known costs make the figures checkable:
//   - 30 ms slept by an MU plugin that loads before the traffic observer (pre-MU time, C)
//   - 40 ms slept on plugins_loaded (outside the handler, B)
//   - 30 ms slept in the handler (handler time, B)
// Sleeping is off CPU, so CPU time must sit well below wall time.
//
// Fixtures stay installed afterwards as inspectable evidence; they are inert for every other
// request.

function sspa_140_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

wp_set_current_user(1);
$sspa_140_fixtures = SSPA_PLUGIN_DIR . '.tests/fixtures/endpoint-startup/';
$sspa_140_plugin_dir = WP_PLUGIN_DIR . '/sspa-endpoint-fixture';
wp_mkdir_p($sspa_140_plugin_dir);
copy($sspa_140_fixtures . 'sspa-endpoint-fixture/sspa-endpoint-fixture.php', $sspa_140_plugin_dir . '/sspa-endpoint-fixture.php');
copy($sspa_140_fixtures . 'zz-sspa-test-endpoint-trim.php', WPMU_PLUGIN_DIR . '/zz-sspa-test-endpoint-trim.php');
copy($sspa_140_fixtures . '00-sspa-test-pre-observer-delay.php', WPMU_PLUGIN_DIR . '/00-sspa-test-pre-observer-delay.php');
wp_clean_plugins_cache(false);
$sspa_140_activated = activate_plugin('sspa-endpoint-fixture/sspa-endpoint-fixture.php');
sspa_140_t(!is_wp_error($sspa_140_activated), 'the endpoint fixture plugin activates');
clearstatcache();
sleep(3); // php-fpm revalidates changed PHP files at most every 2s.

$sspa_140_active = SSPA_Traffic_Collection::active();
if ($sspa_140_active) {
    SSPA_Traffic_Collection::stop((int) $sspa_140_active['id'], true);
}

$sspa_140_window = SSPA_Ajax_Profile::start(array('scenario' => 'Endpoint start-up', 'label' => 'Case 140 window'));
sspa_140_t(!is_wp_error($sspa_140_window), 'an AJAX window starts' . (is_wp_error($sspa_140_window) ? ': ' . $sspa_140_window->get_error_message() : ''));
if (is_wp_error($sspa_140_window)) {
    return;
}
sleep(3); // the generated observer must be visible to php-fpm

$sspa_140_client = array();
for ($i = 1; $i <= 5; $i++) {
    $sspa_140_started = microtime(true);
    $sspa_140_r = wp_remote_get(rest_url('sspa-endpoint-fixture/v1/startup/term-' . $i), array('timeout' => 30));
    $sspa_140_client[] = (microtime(true) - $sspa_140_started) * 1000;
    sspa_140_t(!is_wp_error($sspa_140_r) && 200 === wp_remote_retrieve_response_code($sspa_140_r), 'REST fixture request ' . $i . ' returns 200');
}
for ($i = 1; $i <= 3; $i++) {
    $sspa_140_r = wp_remote_get(add_query_arg(array('action' => 'zz_endpoint_fixture', 'term' => 'ajax-' . $i), admin_url('admin-ajax.php')), array('timeout' => 30));
    sspa_140_t(!is_wp_error($sspa_140_r) && 200 === wp_remote_retrieve_response_code($sspa_140_r), 'admin-ajax fixture request ' . $i . ' returns 200');
}
$sspa_140_r = wp_remote_post(rest_url('sspa-endpoint-fixture/v1/write'), array('timeout' => 30));
sspa_140_t(!is_wp_error($sspa_140_r) && 200 === wp_remote_retrieve_response_code($sspa_140_r), 'the POST fixture route returns 200');
SSPA_Ajax_Profile::stop($sspa_140_window['uuid']);

// ---- C: every window row carries the pre-MU split, existing fields unchanged ----
$sspa_140_rows = SSPA_Ajax_Profile::rows($sspa_140_window);
$sspa_140_rest_rows = array_values(array_filter($sspa_140_rows, static function ($row) {
    return 'rest' === $row['transport'] && false !== strpos($row['endpoint'], 'startup');
}));
sspa_140_t(5 === count($sspa_140_rest_rows), 'five REST fixture rows are retained (' . count($sspa_140_rest_rows) . ')');
$sspa_140_pre_ok = 0;
$sspa_140_wall_ok = 0;
foreach ($sspa_140_rest_rows as $i => $row) {
    $m = json_decode($row['measurement_json'], true);
    $pre = isset($m['pre_mu']) ? $m['pre_mu'] : null;
    if (is_array($pre) && isset($pre['wordpress_timer_to_observer_ms'], $pre['php_start_to_wordpress_timer_ms'])
        && $pre['wordpress_timer_to_observer_ms'] >= 30 && $pre['php_start_to_wordpress_timer_ms'] >= 0
        && 'sspa/ajax-measurement@1' === $m['schema'] && 'mu_observer_to_shutdown' === $m['boundary']) {
        $sspa_140_pre_ok++;
    }
    // wall_ms still starts at the observer: wall plus the pre-observer part fits inside what
    // the client waited for.
    if (is_array($pre) && isset($sspa_140_client[$i]) && ((float) $row['wall_ms'] + (float) $pre['wordpress_timer_to_observer_ms']) <= $sspa_140_client[$i] + 2) {
        $sspa_140_wall_ok++;
    }
}
$sspa_140_first = $sspa_140_rest_rows ? json_decode($sspa_140_rest_rows[0]['measurement_json'], true) : array();
sspa_140_t(5 === $sspa_140_pre_ok, 'every REST row records pre-MU time, at least the 30 ms slept before the observer, with schema and boundary unchanged ('
    . $sspa_140_pre_ok . ' of 5; first ' . wp_json_encode(isset($sspa_140_first['pre_mu']) ? $sspa_140_first['pre_mu'] : null) . ')');
sspa_140_t(5 === $sspa_140_wall_ok, 'wall_ms keeps its meaning: observer to shutdown, so wall plus pre-observer time fits in the client wait (' . $sspa_140_wall_ok . ' of 5)');

// ---- B: the evidence contract exposes what the window stores ----
$sspa_140_report = SSPA_Report::endpoint_evidence($sspa_140_window['collection_id']);
$sspa_140_by = array();
foreach ((array) (is_wp_error($sspa_140_report) ? array() : $sspa_140_report['endpoints']) as $endpoint) {
    $id = $endpoint['identity'];
    $sspa_140_by[$id['transport'] . ':' . ($id['action'] ?: $id['route_pattern']) . ':' . $id['method']] = $endpoint;
}
$sspa_140_rest = null;
foreach ($sspa_140_by as $key => $endpoint) {
    if (0 === strpos($key, 'rest:') && false !== strpos($key, 'startup') && ':GET' === substr($key, -4)) {
        $sspa_140_rest = $endpoint;
    }
}
$sspa_140_ajax = isset($sspa_140_by['admin_ajax:zz_endpoint_fixture:GET']) ? $sspa_140_by['admin_ajax:zz_endpoint_fixture:GET'] : null;
sspa_140_t($sspa_140_rest && $sspa_140_ajax, 'the evidence lists the REST route and the admin-ajax action');

foreach (array('REST' => $sspa_140_rest, 'admin-ajax' => $sspa_140_ajax) as $label => $endpoint) {
    $o = $endpoint ? $endpoint['observations'] : array();
    $n = 'REST' === $label ? 5 : 3;
    $wall = isset($o['whole_request_wall_ms']['median']) ? $o['whole_request_wall_ms']['median'] : null;
    $handler = isset($o['handler_wall_ms']['median']) ? $o['handler_wall_ms']['median'] : null;
    $outside = isset($o['outside_handler_ms']) ? $o['outside_handler_ms'] : null;
    $cpu = isset($o['cpu_ms']) ? $o['cpu_ms'] : null;
    $pre = isset($o['pre_mu_ms']) ? $o['pre_mu_ms'] : null;
    $php = isset($o['php_start_to_wordpress_ms']) ? $o['php_start_to_wordpress_ms'] : null;
    sspa_140_t(null !== $handler && $handler >= 30, $label . ': handler median includes the 30 ms handler sleep (' . var_export($handler, true) . ')');
    sspa_140_t(is_array($outside) && $n === $outside['samples'] && $outside['median'] >= 40,
        $label . ': outside-handler median includes the 40 ms start-up sleep (' . wp_json_encode($outside) . ')');
    sspa_140_t(is_array($outside) && null !== $wall && null !== $handler && abs($outside['median'] - ($wall - $handler)) <= 15,
        $label . ': outside-handler is wall minus handler (' . var_export($wall, true) . ' - ' . var_export($handler, true) . ')');
    sspa_140_t(is_array($cpu) && $n === $cpu['samples'] && null !== $wall && $cpu['median'] <= $wall - 50,
        $label . ': CPU median sits below wall by at least the 70 ms slept off CPU (' . wp_json_encode($cpu) . ', wall ' . var_export($wall, true) . ')');
    sspa_140_t(is_array($pre) && $n === $pre['samples'] && $pre['median'] >= 30,
        $label . ': pre-MU median includes the 30 ms slept before the observer (' . wp_json_encode($pre) . ')');
    sspa_140_t(is_array($php) && $n === $php['samples'] && $php['median'] >= 0,
        $label . ': PHP start to WordPress timer is recorded (' . wp_json_encode($php) . ')');
    $setups = $endpoint && isset($endpoint['setups']) ? (array) $endpoint['setups'] : array();
    $plugins = isset($setups[0]['plugins']) ? array_keys((array) $setups[0]['plugins']) : array();
    sort($plugins);
    sspa_140_t(1 === count($setups) && $plugins === array('sspa-endpoint-fixture/sspa-endpoint-fixture.php', 'woocommerce/woocommerce.php')
        && $n === (int) $setups[0]['requests'] && !empty($setups[0]['theme']['stylesheet']),
        $label . ': one recorded setup, the trimmed set (WooCommerce and the fixture, no PA) with its theme (' . wp_json_encode($setups) . ')');
}

// ---- B: the AJAX tab shows it ----
$sspa_140_active_count = count((array) get_option('active_plugins', array()));
ob_start();
include SSPA_PLUGIN_DIR . 'includes/admin/tabs/ajax.php';
$sspa_140_html = ob_get_clean();
sspa_140_t(false !== strpos($sspa_140_html, 'sspa-ajax-endpoints'), 'the AJAX tab renders the observed endpoints table');
sspa_140_t(false !== strpos($sspa_140_html, 'sspa-endpoint-fixture/v1/startup'), 'the table lists the REST fixture route');
sspa_140_t(false !== strpos($sspa_140_html, 'zz_endpoint_fixture'), 'the table lists the admin-ajax fixture action');
sspa_140_t(false !== strpos($sspa_140_html, sprintf('2 of %d active plugins loaded', $sspa_140_active_count)),
    'a trimmed endpoint shows its trimmed setup against the active set (2 of ' . $sspa_140_active_count . ')');
sspa_140_t(false !== strpos($sspa_140_html, 'WooCommerce') && false !== strpos($sspa_140_html, 'SSPA test fixture - endpoint start-up'),
    'the recorded plugins are named');
preg_match_all('/<tr[^>]*data-endpoint="([^"]*)"[^>]*>(.*?)<\/tr>/s', $sspa_140_html, $sspa_140_tr, PREG_SET_ORDER);
$sspa_140_buttons = array();
foreach ($sspa_140_tr as $tr) {
    $sspa_140_buttons[html_entity_decode($tr[1])] = false !== strpos($tr[2], 'sspa-endpoint-profile-open');
}
$sspa_140_rest_key = null;
$sspa_140_write_key = null;
foreach ($sspa_140_buttons as $key => $has) {
    if (false !== strpos($key, 'startup')) { $sspa_140_rest_key = $key; }
    if (false !== strpos($key, '/write')) { $sspa_140_write_key = $key; }
}
sspa_140_t($sspa_140_rest_key && $sspa_140_buttons[$sspa_140_rest_key], 'the GET REST row offers "Profile this endpoint"');
sspa_140_t(isset($sspa_140_buttons['admin_ajax:zz_endpoint_fixture']) && $sspa_140_buttons['admin_ajax:zz_endpoint_fixture'], 'the GET admin-ajax row offers "Profile this endpoint"');
sspa_140_t($sspa_140_write_key && !$sspa_140_buttons[$sspa_140_write_key], 'the POST route does not offer profiling');
