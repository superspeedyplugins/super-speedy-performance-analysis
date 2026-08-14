<?php
// Phase 2 hot path: one append-only request row, signed observer, event-id retirement and
// hard timestamp expiry without relying on WP-Cron.

function sspa_th_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

global $wpdb;
SSPA_Traffic_Helper::remove();
$events = SSPA_Schema::table('traffic_events');
$collections = SSPA_Schema::table('traffic_collections');
$wpdb->query("DELETE FROM $events");
$wpdb->query("DELETE FROM $collections");

$result = SSPA_Traffic_Collection::start('24h', 'test');
sspa_th_t(!is_wp_error($result), 'collector starts for hot-path test');
if (is_wp_error($result)) {
    echo 'FAIL: start error: ' . $result->get_error_message() . "\n";
    return;
}
$id = (int) $result['collection']['id'];
$row = SSPA_Traffic_Collection::get($id);
$config = array(
    'collection_id' => $id,
    'collect_until' => strtotime($row['collect_until'] . ' UTC'),
    'outcomes_until' => strtotime($row['outcomes_until'] . ' UTC'),
    'event_id_stop' => (int) $row['event_id_stop'],
    'origin_sample_modulus' => 1,
    'key_option' => SSPA_Traffic_Collection::key_option($id),
);
SSPA_Traffic_Helper::install($config);

$response = wp_remote_get(home_url('/?sspa_traffic_fixture=hot-path'), array('timeout' => 20));
$request_rows = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $events WHERE collection_id = %d AND event_code = %d", $id, SSPA_Traffic_Codes::EVENT_REQUEST));
$request = $wpdb->get_row($wpdb->prepare("SELECT * FROM $events WHERE collection_id = %d AND event_code = %d LIMIT 1", $id, SSPA_Traffic_Codes::EVENT_REQUEST), ARRAY_A);
sspa_th_t(!is_wp_error($response) && 200 === wp_remote_retrieve_response_code($response), 'ordinary front-end request remains successful');
sspa_th_t(1 === $request_rows, 'one ordinary request appends one request event');
sspa_th_t($request && strlen($request['path_key']) === 8, 'request stores only an eight-byte keyed path join');
sspa_th_t($request && (int) $request['observer_us'] >= 0 && (int) $request['query_count'] > 0, 'request records bounded observer and query measurements');

$max_id = (int) $wpdb->get_var("SELECT MAX(id) FROM $events");
$config['event_id_stop'] = $max_id + 1;
$wpdb->update($collections, array('event_id_stop' => $config['event_id_stop']), array('id' => $id));
SSPA_Traffic_Helper::install($config);
wp_remote_get(home_url('/?sspa_traffic_fixture=event-cap'), array('timeout' => 20));
sspa_th_t('event_limit' === SSPA_Traffic_Helper::state(), 'hot path retires itself at the hard event-id boundary');
$limited = SSPA_Traffic_Collection::status($id);
sspa_th_t('incomplete' === $limited['collection']['status'] && 'event_limit' === $limited['collection']['stop_reason'], 'health reconciliation records the event-limit stop');
sspa_th_t('absent' === SSPA_Traffic_Helper::state(), 'health reconciliation cleans the stopped marker');

$next = SSPA_Traffic_Collection::start('24h', 'test');
sspa_th_t(!is_wp_error($next), 'new collection starts after hard-cap retirement');
if (!is_wp_error($next)) {
    $next_id = (int) $next['collection']['id'];
    $next_row = SSPA_Traffic_Collection::get($next_id);
    $expired_config = array(
        'collection_id' => $next_id,
        'collect_until' => time() - 10,
        'outcomes_until' => time() - 5,
        'event_id_stop' => (int) $next_row['event_id_stop'],
        'origin_sample_modulus' => 1,
        'key_option' => SSPA_Traffic_Collection::key_option($next_id),
    );
    SSPA_Traffic_Helper::install($expired_config);
    $before = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $events WHERE collection_id = %d", $next_id));
    wp_remote_get(home_url('/?sspa_traffic_fixture=expired'), array('timeout' => 20));
    $after = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $events WHERE collection_id = %d", $next_id));
    sspa_th_t($before === $after, 'embedded hard timestamp prevents writes with WP-Cron uninvolved');
    SSPA_Traffic_Collection::stop($next_id, true);
}

SSPA_Traffic_Helper::remove();
foreach ($wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('sspa_traffic_key_') . '%')) as $option) {
    delete_option($option);
}
$wpdb->query("DELETE FROM $events");
$wpdb->query("DELETE FROM $collections");
