<?php
// Client History charts use the same public series document as the wp-admin adapter.
// This case drives real spot checks on an unchanged active-plugin list, then changes
// only one active plugin's version and proves the two contiguous measured setups split.

defined('ABSPATH') || exit;

$GLOBALS['sspa_62_failures'] = 0;
function sspa_62_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
    if (!$ok) {
        $GLOBALS['sspa_62_failures']++;
    }
}

function sspa_62_drive_run() {
    $run_id = SSPA_Run_Controller::start(array(
        'type' => 'spot',
        'page_keys' => array('home'),
        'user_id' => 1,
    ));
    if (is_wp_error($run_id)) {
        return $run_id;
    }
    $deadline = time() + 180;
    do {
        SSPA_Run_Controller::process_batch($run_id);
        $status = SSPA_Run_Controller::status($run_id);
    } while ($status && in_array($status['status'], array('crawling', 'analysing'), true) && time() < $deadline);
    return $status && 'done' === $status['status'] ? (int) $run_id : new WP_Error(
        'sspa_62_run_failed',
        $status ? 'run ended ' . $status['status'] : 'run status disappeared'
    );
}

function sspa_62_fixture($path, $version) {
    file_put_contents($path, "<?php\n/**\n * Plugin Name: SSPA Setup Series Fixture\n * Version: " . $version . "\n */\n");
    wp_clean_plugins_cache(true);
}

wp_set_current_user(1);
$active_run = SSPA_Run_Controller::active_run_id();
if ($active_run) {
    SSPA_Run_Controller::cancel($active_run);
    SSPA_Run_Controller::process_batch($active_run);
}
if (!function_exists('activate_plugin')) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

$fixture_dir = WP_PLUGIN_DIR . '/sspa-setup-series-fixture';
$fixture_file = $fixture_dir . '/sspa-setup-series-fixture.php';
$series_revision = (string) round(microtime(true) * 1000);
$setup_one_version = '1.0.' . $series_revision;
$setup_two_version = '2.0.' . $series_revision;
wp_mkdir_p($fixture_dir);
sspa_62_fixture($fixture_file, $setup_one_version);
if (!is_plugin_active('sspa-setup-series-fixture/sspa-setup-series-fixture.php')) {
    $activation = activate_plugin('sspa-setup-series-fixture/sspa-setup-series-fixture.php');
    sspa_62_t(!is_wp_error($activation), 'the setup-series fixture is active');
}

$previous_ids = array();
for ($i = 0; $i < 3; $i++) {
    $run_id = sspa_62_drive_run();
    sspa_62_t(!is_wp_error($run_id), 'setup 1 real measurement ' . ($i + 1) . ' completes');
    if (is_wp_error($run_id)) {
        return;
    }
    $previous_ids[] = $run_id;
}

sspa_62_fixture($fixture_file, $setup_two_version);
$current_ids = array();
for ($i = 0; $i < 2; $i++) {
    $run_id = sspa_62_drive_run();
    sspa_62_t(!is_wp_error($run_id), 'setup 2 real measurement ' . ($i + 1) . ' completes');
    if (is_wp_error($run_id)) {
        return;
    }
    $current_ids[] = $run_id;
}

$previous_row = SSPA_Run_Controller::run_row($previous_ids[0]);
$current_row = SSPA_Run_Controller::run_row($current_ids[0]);
sspa_62_t(
    $previous_row['plugin_set_hash'] === $current_row['plugin_set_hash'],
    'the active-plugin-list hash is unchanged across the version update'
);

if (!class_exists('SSPA_History_Series')) {
    sspa_62_t(false, 'History exposes the versioned setup-series document used by the chart');
    return;
}

$document = SSPA_History_Series::build(end($current_ids), 'request_wall_ms');
sspa_62_t(!is_wp_error($document), 'the current run resolves a setup-series document');
if (is_wp_error($document)) {
    echo 'FAIL: series: ' . $document->get_error_message() . "\n";
    return;
}

sspa_62_t(
    'sspa/history-series@1' === $document['schema'],
    'the chart document has a stable versioned schema'
);
sspa_62_t(
    $previous_ids === $document['previous']['run_ids'] && $current_ids === $document['current']['run_ids'],
    'three adjacent old-version runs and two adjacent new-version runs form separate periods'
);
sspa_62_t(
    $document['previous']['fingerprint'] !== $document['current']['fingerprint'],
    'the measured setup fingerprint changes when only an active plugin version changes'
);

$page = isset($document['pages'][0]) ? $document['pages'][0] : array();
sspa_62_t(
    !empty($page['previous']['points']) && !empty($page['current']['points'])
        && null !== $page['previous']['median'] && null !== $page['current']['median'],
    'the public document contains every retained request point and both period medians'
);

sspa_62_fixture($fixture_file, $setup_one_version);
$restored_id = sspa_62_drive_run();
sspa_62_t(!is_wp_error($restored_id), 'the restored setup measurement completes');
if (is_wp_error($restored_id)) {
    return;
}
$restored = SSPA_History_Series::build($restored_id, 'request_wall_ms');
sspa_62_t(
    !is_wp_error($restored)
        && array($restored_id) === $restored['current']['run_ids']
        && $current_ids === $restored['previous']['run_ids']
        && !array_intersect($previous_ids, $restored['current']['run_ids']),
    'returning to setup 1 creates a new period instead of merging non-adjacent setup 1 runs'
);

$chart_html = SSPA_History_Chart::render($restored);
$embedded_document = array();
if (preg_match('/<script type="application\/json" class="sspa-history-chart-document">(.*?)<\/script>/s', $chart_html, $embedded_match)) {
    $embedded_document = json_decode($embedded_match[1], true);
}
sspa_62_t(
    false !== strpos($chart_html, 'data-sspa-history-chart')
        && false !== strpos($chart_html, 'Previous setup')
        && false !== strpos($chart_html, 'Current setup')
        && false !== strpos($chart_html, 'View chart data')
        && SSPA_History_Series::SCHEMA === (isset($embedded_document['schema']) ? $embedded_document['schema'] : ''),
    'the History adapter renders the chart mount and accessible table from the same document'
);

foreach (SSPA_History_Series::metrics() as $metric_key => $metric_definition) {
    $metric_document = SSPA_History_Series::build($restored_id, $metric_key);
    sspa_62_t(
        !is_wp_error($metric_document)
            && $metric_key === $metric_document['metric']['key']
            && $metric_definition['source'] === $metric_document['metric']['source'],
        $metric_definition['label'] . ' keeps its declared evidence source'
    );
}

// A newer corrupt/incompatible saved candidate must not replace the latest usable chart.
$incompatible_id = sspa_62_drive_run();
sspa_62_t(!is_wp_error($incompatible_id), 'the newer compatibility-candidate measurement completes');
if (!is_wp_error($incompatible_id)) {
    global $wpdb;
    $incompatible_row = SSPA_Run_Controller::run_row($incompatible_id);
    $profile = $wpdb->get_row($wpdb->prepare(
        'SELECT id, samples FROM %i WHERE run_id = %d ORDER BY id ASC LIMIT 1',
        SSPA_Schema::table('profiles'),
        $incompatible_id
    ), ARRAY_A);
    $samples = json_decode((string) $profile['samples'], true);
    $samples[0]['error'] = 'timeout';
    $samples[0]['error_message'] = 'Deliberate History chart fixture failure';
    $samples[0]['code'] = 0;
    unset($samples[1]['wall_ms']);
    $wpdb->update(SSPA_Schema::table('profiles'), array('samples' => wp_json_encode($samples)), array('id' => (int) $profile['id']));
    $wpdb->update(
        SSPA_Schema::table('runs'),
        array('measurement_version' => (int) $incompatible_row['measurement_version'] + 100),
        array('id' => $incompatible_id)
    );
    $automatic = SSPA_History_Series::build(0, 'request_wall_ms');
    sspa_62_t(
        !is_wp_error($automatic) && (int) $restored_id === (int) $automatic['anchor_run_id'],
        'automatic History selection skips a newer incompatible measurement candidate'
    );
    sspa_62_t(
        !SSPA_History_Series::is_compatible_run_id($incompatible_id),
        'an exact quick-comparison baseline cannot bind an incompatible run'
    );
    $wpdb->update(
        SSPA_Schema::table('runs'),
        array('measurement_version' => (int) $incompatible_row['measurement_version']),
        array('id' => $incompatible_id)
    );
    sspa_62_t(
        SSPA_History_Series::is_compatible_run_id($incompatible_id),
        'an exact quick-comparison baseline can bind a compatible run'
    );
    $fault_document = SSPA_History_Series::build($incompatible_id, 'request_wall_ms');
    $fault_page = is_wp_error($fault_document) || empty($fault_document['pages'][0]) ? array() : $fault_document['pages'][0];
    $fault_states = empty($fault_page['current']['faults']) ? array() : wp_list_pluck($fault_page['current']['faults'], 'state');
    sort($fault_states, SORT_STRING);
    sspa_62_t(
        !empty($fault_page)
            && array('missing', 'transport_error') === $fault_states
            && 2 === (int) $fault_page['current']['fault_count']
            && count($fault_page['current']['points']) === (int) $fault_page['current']['point_count'],
        'transport errors and missing measurements keep distinct states and cannot contribute to the timing median'
    );
    $fault_html = SSPA_History_Chart::render($fault_document);
    sspa_62_t(
        false !== strpos($fault_html, 'transport error') && false !== strpos($fault_html, 'missing measurement'),
        'the accessible chart table names each failed evidence state'
    );
}

// An unreadable run is a period boundary. It cannot silently join known A runs on either side.
$unknown_boundary_id = sspa_62_drive_run();
$boundary_anchor_id = sspa_62_drive_run();
sspa_62_t(!is_wp_error($unknown_boundary_id) && !is_wp_error($boundary_anchor_id), 'the unknown-boundary fixture measurements complete');
if (!is_wp_error($unknown_boundary_id) && !is_wp_error($boundary_anchor_id)) {
    $unknown_row = SSPA_Run_Controller::run_row($unknown_boundary_id);
    $wpdb->update(SSPA_Schema::table('runs'), array('plugin_set' => ''), array('id' => $unknown_boundary_id));
    $boundary_document = SSPA_History_Series::build($boundary_anchor_id, 'request_wall_ms');
    sspa_62_t(
        !is_wp_error($boundary_document) && array($boundary_anchor_id) === $boundary_document['current']['run_ids'],
        'a run without a versioned setup breaks contiguity instead of joining setup periods across it'
    );
    $wpdb->update(SSPA_Schema::table('runs'), array('plugin_set' => $unknown_row['plugin_set']), array('id' => $unknown_boundary_id));
}

if ($GLOBALS['sspa_62_failures']) {
    echo 'FAIL: ' . $GLOBALS['sspa_62_failures'] . " setup-series assertion(s) failed\n";
} else {
    echo "PASS: measured setup periods are ready for the client History chart\n";
}
