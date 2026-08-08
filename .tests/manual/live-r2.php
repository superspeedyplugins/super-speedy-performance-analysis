<?php
// Manual PHP-client -> receiver -> R2 compatibility check.
// Usage: wp eval-file .../.tests/manual/live-r2.php http://host.docker.internal:8788

function sspa_live_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

global $wpdb;

$collector = isset($args[0]) ? untrailingslashit($args[0]) : '';
$host = strtolower((string) wp_parse_url($collector, PHP_URL_HOST));
if (!in_array($host, array('localhost', '127.0.0.1', 'host.docker.internal'), true)) {
    echo "FAIL: pass a local development receiver URL\n";
    return;
}

$old_collector = get_option('sspa_collector_url', null);
$old_optin = get_option('sspa_share_optin', null);
$now = gmdate('Y-m-d H:i:s');
$run_uuid = wp_generate_uuid4();
$run_id = 0;
$profile_id = 0;
$outbox_id = 0;

try {
    update_option('sspa_collector_url', $collector, false);
    update_option('sspa_share_optin', 1, false);
    $wpdb->insert(SSPA_Schema::table('runs'), array(
        'run_uuid' => $run_uuid,
        'blog_id' => 1,
        'run_type' => 'spot',
        'measurement_version' => 1,
        'trigger_source' => 'integration_test',
        'status' => 'done',
        'plugin_set' => wp_json_encode(array('components' => array(
            array('type' => 'plugin', 'slug' => 'woocommerce', 'version' => '10.1.0'),
        ))),
        'plugin_set_hash' => md5('live-r2-test'),
        'started' => $now,
        'finished' => $now,
    ));
    $run_id = (int) $wpdb->insert_id;
    $capture = array('profile' => array(
        'collector' => 'excimer',
        'period_ms' => 1,
        'samples' => 2,
        'wall_ms' => 2,
        'functions' => array(array(
            'fn' => 'WC_Cart->calculate_totals',
            'component' => 'woocommerce',
            'ctype' => 'plugin',
            'incl_ms' => 2,
            'self_ms' => 1,
            'by' => array(),
        )),
        'components' => array('woocommerce' => 2),
        'phases' => array(),
    ));
    $wpdb->insert(SSPA_Schema::table('profiles'), array(
        'run_id' => $run_id,
        'page_key' => 'wc-cart',
        'url' => home_url('/cart/'),
        'method' => 'GET',
        'variant' => 'anon',
        'plugin_set_hash' => md5('live-r2-test'),
        'object_cache_mode' => 'normal',
        'samples' => wp_json_encode(array(array('wall_ms' => 82, 'code' => 200))),
        'ttfb_ms' => 82,
        'page_gen_ms' => 70,
        'sql_ms' => 8,
        'sql_count' => 12,
        'peak_mem_bytes' => 2097152,
        'response_code' => 200,
        'profile_blob' => gzcompress(wp_json_encode($capture), 6),
        'created' => $now,
    ));
    $profile_id = (int) $wpdb->insert_id;

    $queued = SSPA_Community_Outbox::queue_run($run_id);
    if (is_wp_error($queued)) {
        sspa_live_t(false, 'payload queued: ' . $queued->get_error_message());
        return;
    }
    $outbox_id = (int) $queued['id'];
    sspa_live_t(true, 'payload queued (' . (int) $queued['compressed_bytes'] . ' compressed bytes)');

    $attempt = SSPA_Community_Outbox::begin_attempt($queued);
    $result = SSPA_Community_Client::submit($attempt);
    if (is_wp_error($result)) {
        sspa_live_t(false, 'receiver round trip: ' . $result->get_error_code() . ' - ' . $result->get_error_message());
        return;
    }
    SSPA_Community_Outbox::sent($outbox_id, $result['receipt_uuid'], $result['http_status']);
    $sent = SSPA_Community_Outbox::get($outbox_id);
    sspa_live_t('sent' === $sent['state'], 'receiver receipt verified and outbox marked sent');
    sspa_live_t(wp_is_uuid($sent['receipt_uuid'], 4), 'receipt UUID stored locally');
    echo 'submission_uuid=' . $sent['submission_uuid'] . "\n";
    echo 'receipt_uuid=' . $sent['receipt_uuid'] . "\n";
    echo 'payload_sha256=' . $sent['payload_sha256'] . "\n";
} finally {
    if ($outbox_id) {
        $wpdb->delete(SSPA_Schema::table('submission_events'), array('outbox_id' => $outbox_id));
        $wpdb->delete(SSPA_Schema::table('submission_outbox'), array('id' => $outbox_id));
    }
    if ($profile_id) {
        $wpdb->delete(SSPA_Schema::table('profiles'), array('id' => $profile_id));
    }
    if ($run_id) {
        $wpdb->delete(SSPA_Schema::table('runs'), array('id' => $run_id));
    }
    if (null === $old_collector) {
        delete_option('sspa_collector_url');
    } else {
        update_option('sspa_collector_url', $old_collector, false);
    }
    if (null === $old_optin) {
        delete_option('sspa_share_optin');
    } else {
        update_option('sspa_share_optin', $old_optin, false);
    }
}
