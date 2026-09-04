<?php
// Phase 2 lifecycle: additive schema, atomic active-only observer, idempotent start,
// duration conflicts, bounded outcome stop, emergency stop and update mismatch handling.

function sspa_tl_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

global $wpdb;
SSPA_Traffic_Helper::remove();
$wpdb->query('DELETE FROM ' . SSPA_Schema::table('traffic_events'));
$wpdb->query('DELETE FROM ' . SSPA_Schema::table('traffic_reports'));
$wpdb->query('DELETE FROM ' . SSPA_Schema::table('traffic_actor_work'));
$wpdb->query('DELETE FROM ' . SSPA_Schema::table('traffic_rollups'));
$wpdb->query('DELETE FROM ' . SSPA_Schema::table('traffic_endpoint_observations'));
$wpdb->query('DELETE FROM ' . SSPA_Schema::table('traffic_collections'));
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('sspa_traffic_key_') . '%'));

foreach (array('traffic_collections', 'traffic_events', 'traffic_rollups', 'traffic_actor_work', 'traffic_reports', 'traffic_endpoint_observations') as $name) {
    $table = SSPA_Schema::table($name);
    sspa_tl_t($table === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)), "$name table exists");
}
sspa_tl_t('2.6' === SSPA_Schema::DB_VERSION && '2.6' === get_option('sspa_db_version'), 'database schema version is 2.6');
$event_columns = $wpdb->get_col('SHOW COLUMNS FROM ' . SSPA_Schema::table('traffic_events'));
sspa_tl_t(in_array('automation_code', $event_columns, true) && in_array('ssf_protection_code', $event_columns, true), 'request rows have privacy-safe automation and SSF decision dimensions');

$durations = SSPA_Traffic_Collection::durations();
sspa_tl_t(
    isset($durations['15m'], $durations['1h'], $durations['2h'], $durations['4h'])
        && 15 * MINUTE_IN_SECONDS === $durations['15m']
        && HOUR_IN_SECONDS === $durations['1h']
        && 2 * HOUR_IN_SECONDS === $durations['2h']
        && 4 * HOUR_IN_SECONDS === $durations['4h'],
    'short collection durations map to 15 minutes, one, two and four hours'
);

$started = SSPA_Traffic_Collection::start('1h', 'test');
sspa_tl_t(!is_wp_error($started) && !empty($started['active']), 'one-hour collection starts after database pre-flight');
if (is_wp_error($started)) {
    echo 'FAIL: start error: ' . $started->get_error_message() . "\n";
    return;
}
$id = (int) $started['collection']['id'];
$key_option = SSPA_Traffic_Collection::key_option($id);
$secret = (string) get_option($key_option);
$observer = (string) file_get_contents(SSPA_Traffic_Helper::path());
sspa_tl_t(strlen($secret) === 64 && ctype_xdigit($secret), 'temporary collection HMAC key exists');
sspa_tl_t(false === strpos($observer, $secret), 'generated observer contains no collection secret');
sspa_tl_t(false === strpos($observer, '%%SSPA_') && false !== strpos($observer, "'collection_id' => " . $id), 'generated observer placeholders are replaced');
sspa_tl_t($started['collection']['event_ceiling'] <= floor($started['collection']['disk_ceiling_bytes'] / SSPA_Traffic_Collection::CONSERVATIVE_EVENT_BYTES), 'event ceiling also respects conservative disk ceiling');
sspa_tl_t($started['collection']['preflight_insert_ms_p95'] <= 5.0, 'database append pre-flight passed the 5 ms p95 ceiling');

$actual_duration = strtotime($started['collection']['collect_until']) - strtotime($started['collection']['started_at']);
sspa_tl_t(HOUR_IN_SECONDS === $actual_duration, 'one-hour collection ends exactly one hour after it starts');

$same = SSPA_Traffic_Collection::start('1h', 'test');
$conflict = SSPA_Traffic_Collection::start('2h', 'test');
sspa_tl_t(!is_wp_error($same) && $id === (int) $same['collection']['id'], 'same-duration start is idempotent');
sspa_tl_t(is_wp_error($conflict) && 'sspa_traffic_active_conflict' === $conflict->get_error_code(), 'different duration returns a conflict');

$stopped = SSPA_Traffic_Collection::stop($id, false);
sspa_tl_t(!is_wp_error($stopped) && 'outcome' === $stopped['collection']['status'], 'normal stop ends request collection and enters outcome window');
sspa_tl_t('active' === SSPA_Traffic_Helper::state(), 'bounded outcome observer remains active after normal stop');
$stale_outcome_row = $wpdb->get_row($wpdb->prepare(
    'SELECT * FROM %i WHERE id = %d',
    SSPA_Schema::table('traffic_collections'),
    $id
), ARRAY_A);
$emergency = SSPA_Traffic_Collection::stop($id, true);
sspa_tl_t(!is_wp_error($emergency) && 'stopped' === $emergency['collection']['status'], 'emergency stop ends all observation');
sspa_tl_t('absent' === SSPA_Traffic_Helper::state(), 'emergency stop removes observer and stopped marker');
$reconcile = new ReflectionMethod('SSPA_Traffic_Collection', 'reconcile');
$reconcile->setAccessible(true);
$reconcile->invoke(null, $stale_outcome_row);
$after_stale_reconcile = $wpdb->get_row($wpdb->prepare(
    'SELECT * FROM %i WHERE id = %d',
    SSPA_Schema::table('traffic_collections'),
    $id
), ARRAY_A);
sspa_tl_t(
    SSPA_Traffic_Codes::COLLECTION_STOPPED === (int) $after_stale_reconcile['status_code']
        && SSPA_Traffic_Codes::STOP_EMERGENCY === (int) $after_stale_reconcile['stop_reason_code'],
    'a stale reconciler cannot overwrite the emergency-stop terminal state'
);
sspa_tl_t(false === wp_next_scheduled('sspa_traffic_collection_tick', array($id)), 'terminal stop removes every collection cron restart');
$deleted = SSPA_Traffic_Collection::delete($id);
sspa_tl_t(!is_wp_error($deleted) && !get_option($key_option) && !SSPA_Traffic_Collection::get($id), 'explicit delete removes stopped collection rows and temporary key');
sspa_tl_t((bool) has_action('wp_ajax_sspa_traffic_delete', array('SSPA_Traffic_Ajax', 'delete')), 'destructive deletion is available to administrators');

$second = SSPA_Traffic_Collection::start('4h', 'test');
sspa_tl_t(!is_wp_error($second), 'a later four-hour collection can start after the previous one stops');
if (!is_wp_error($second)) {
    $second_id = (int) $second['collection']['id'];
    $wpdb->update(SSPA_Schema::table('traffic_collections'), array('observer_version' => 0), array('id' => $second_id));
    $mismatch = SSPA_Traffic_Collection::status($second_id);
    sspa_tl_t('incomplete' === $mismatch['collection']['status'] && 'plugin_updated' === $mismatch['collection']['stop_reason'], 'observer contract mismatch stops collection as incomplete');
    sspa_tl_t('absent' === SSPA_Traffic_Helper::state(), 'contract mismatch removes the old observer');
}

$locked = SSPA_Traffic_Collection::start('1h', 'test');
sspa_tl_t(!is_wp_error($locked), 'a collection starts for lifecycle-lock arbitration');
if (is_wp_error($locked)) {
    echo 'FAIL: lifecycle-lock start error: ' . $locked->get_error_code() . ' - ' . $locked->get_error_message() . "\n";
}
if (!is_wp_error($locked)) {
    $locked_id = (int) $locked['collection']['id'];
    $lock_key = 'sspa_traffic_collection_lock_' . $locked_id;
    $lock_owner = SSPA_Atomic_Claim::acquire($lock_key, 300, 'sspa-case-41-worker');
    $blocked_stop = SSPA_Traffic_Collection::stop($locked_id, true);
    $locked_row = $wpdb->get_row($wpdb->prepare(
        'SELECT * FROM %i WHERE id = %d',
        SSPA_Schema::table('traffic_collections'),
        $locked_id
    ), ARRAY_A);
    sspa_tl_t(
        is_wp_error($blocked_stop)
            && 'sspa_traffic_collection_busy' === $blocked_stop->get_error_code()
            && SSPA_Traffic_Codes::COLLECTION_RUNNING === (int) $locked_row['status_code'],
        'stop and reconciliation share one per-collection lifecycle lock'
    );
    SSPA_Atomic_Claim::release($lock_key, $lock_owner);
    SSPA_Traffic_Collection::stop($locked_id, true);
}

$before = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . SSPA_Schema::table('traffic_events'));
wp_remote_get(home_url('/'), array('timeout' => 15));
$after = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . SSPA_Schema::table('traffic_events'));
sspa_tl_t($before === $after, 'inactive collector performs no event write');

foreach ($wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('sspa_traffic_key_') . '%')) as $option) {
    delete_option($option);
}
$wpdb->query('DELETE FROM ' . SSPA_Schema::table('traffic_events'));
$wpdb->query('DELETE FROM ' . SSPA_Schema::table('traffic_endpoint_observations'));
$wpdb->query('DELETE FROM ' . SSPA_Schema::table('traffic_collections'));
