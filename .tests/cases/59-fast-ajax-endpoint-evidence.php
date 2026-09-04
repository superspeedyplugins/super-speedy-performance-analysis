<?php
/** Public Fast Ajax endpoint evidence contract and privacy-safe grouping. */

function sspa_endpoint_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
}

$has_contract = method_exists('SSPA_Report', 'endpoint_evidence');
sspa_endpoint_t($has_contract, 'Performance Analysis exposes the versioned endpoint evidence contract');
sspa_endpoint_t(isset(SSPA_Traffic_Collection::durations()['15m']), 'traffic collection supports SPro\'s bounded 15-minute session');
if (!$has_contract) {
    return;
}

global $wpdb;
$table = SSPA_Schema::table('traffic_endpoint_observations');
sspa_endpoint_t($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table, 'exact endpoint observations have a separate additive table');
sspa_endpoint_t('observer_us' === $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'observer_us'"), 'endpoint observations retain measured collector overhead');

$active = SSPA_Traffic_Collection::active();
if ($active) {
    SSPA_Traffic_Collection::stop((int) $active['id'], true);
}
$started = SSPA_Report::start_endpoint_evidence();
$collection = !is_wp_error($started) ? $started['collection'] : null;
$collection_id = $collection ? (int) $collection['id'] : 0;
sspa_endpoint_t($collection_id > 0, 'public contract starts a bounded 15-minute collection');
if (!$collection_id) {
    if (is_wp_error($started)) {
        echo 'FAIL: collection start error: ' . $started->get_error_message() . "\n";
    }
	return;
}
$status = SSPA_Report::endpoint_evidence_status($collection_id);
sspa_endpoint_t(is_array($status) && !empty($status['active']) && $collection_id === (int) ($status['collection']['id'] ?? 0) && 'running' === ($status['collection']['status'] ?? ''), 'public contract reports the active endpoint collection');

$wpdb->delete($table, array('collection_id' => $collection_id));
$identity = array(
    'transport' => 'admin_ajax',
    'endpoint' => 'sspa_endpoint_contract_fixture',
    'method' => 'POST',
    'context' => 'anonymous',
);
$identity_key = hash('sha256', implode("\0", array_values($identity)));
add_action('wp_ajax_nopriv_sspa_endpoint_contract_fixture', array('SSPA_Report', 'endpoint_evidence_status'));
add_action('wp_ajax_nopriv_sspa_endpoint_contract_fixture', 'sspa_missing_endpoint_callback');
foreach (array(
    array(100, 4, 200, 1000, 50),
    array(200, 8, 200, 1001, 100),
    array(900, 12, 503, 1002, 300),
) as $sample) {
    $wpdb->insert($table, array(
        'collection_id' => $collection_id,
        'identity_key' => hex2bin($identity_key),
        'transport' => $identity['transport'],
        'endpoint' => $identity['endpoint'],
        'http_method' => $identity['method'],
        'auth_context' => $identity['context'],
        'observed_at' => $sample[3],
        'status_code' => $sample[2],
        'wall_ms' => $sample[0],
        'query_count' => $sample[1],
        'observer_us' => $sample[4],
        'boundary' => 'shutdown_fallback',
    ));
}

$report = SSPA_Report::endpoint_evidence($collection_id);
sspa_endpoint_t(is_array($report) && 'sspa/endpoint-evidence@1' === ($report['schema'] ?? ''), 'contract schema is frozen at sspa/endpoint-evidence@1');
$endpoint = is_array($report) && !empty($report['endpoints'][0]) ? $report['endpoints'][0] : array();
sspa_endpoint_t(3 === (int) ($endpoint['observations']['count'] ?? 0), 'three safe numeric observations group under one exact identity');
sspa_endpoint_t(200 === (int) ($endpoint['observations']['whole_request_wall_ms']['median'] ?? 0), 'median wall time is calculated from bounded raw measurements');
sspa_endpoint_t(900 === (int) ($endpoint['observations']['whole_request_wall_ms']['p95'] ?? 0), 'p95 wall time is calculated from bounded raw measurements');
sspa_endpoint_t(1 === (int) ($endpoint['observations']['status_counts']['5xx'] ?? 0), 'status distribution preserves the failed request');
sspa_endpoint_t(300 === (int) ($endpoint['observations']['observer_overhead_us']['p95'] ?? 0), 'p95 observer overhead is exposed from measured endpoint requests');
sspa_endpoint_t('unknown' === ($endpoint['quality']['activity'] ?? ''), 'missing detailed profiling is reported as unknown, never no-work proof');
sspa_endpoint_t(empty($endpoint['plugin_activity']), 'the initial contract does not invent per-plugin activity');
sspa_endpoint_t('partial' === ($endpoint['owners']['resolution'] ?? '') && in_array('super-speedy-performance-analysis/super-speedy-performance-analysis.php', $endpoint['owners']['execution'] ?? array(), true), 'mixed callback ownership is reported as partial rather than falsely complete');

$encoded = wp_json_encode($report);
sspa_endpoint_t(false === strpos($encoded, 'request_body') && false === strpos($encoded, 'response_body') && false === strpos($encoded, 'cookie'), 'public evidence contains no payload or cookie fields');

ob_start();
include dirname(__DIR__, 2) . '/includes/admin/tabs/traffic.php';
$traffic_panel = ob_get_clean();
sspa_endpoint_t(false !== strpos($traffic_panel, 'AJAX and REST endpoint evidence'), 'the Traffic tab owns a detailed endpoint evidence view');
sspa_endpoint_t(false !== strpos($traffic_panel, 'sspa_endpoint_contract_fixture') && false !== strpos($traffic_panel, 'p95 900 ms'), 'the detailed view renders exact identity and measured opportunity from the public report');
sspa_endpoint_t(false !== strpos($traffic_panel, 'Unknown') && false !== strpos($traffic_panel, 'detailed activity was not sampled'), 'the detailed view surfaces missing plugin activity as unknown');

$stopped = SSPA_Report::stop_endpoint_evidence($collection_id);
sspa_endpoint_t(is_array($stopped) && empty($stopped['active']) && 'stopped' === ($stopped['collection']['status'] ?? ''), 'public contract stops and finalises the endpoint collection');
$final_report = SSPA_Report::endpoint_evidence($collection_id);
sspa_endpoint_t(3 === (int) ($final_report['endpoints'][0]['observations']['count'] ?? 0), 'finalised endpoint evidence remains readable through the public contract');

remove_all_actions('wp_ajax_nopriv_sspa_endpoint_contract_fixture');
$wpdb->delete($table, array('collection_id' => $collection_id));
