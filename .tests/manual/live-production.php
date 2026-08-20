<?php
// Production collector compatibility smoke test, including outage recovery.
//
// Usage, from bash in this plugin repository, against the DISPOSABLE test site only:
//
//   source .tests/env.sh
//   sync_plugin
//   cli eval-file "$CONTAINER_PLUGIN_DIR/.tests/manual/live-production.php" \
//       https://collector.superspeedy.org production
//
// The opt-in token is the bare word `production`, not `--production`: wp-cli parses any
// leading-dash argument as a flag belonging to `eval-file` itself and refuses to pass it
// through to the script.
//
// This writes one real, permanent, synthetic object to the production R2 archive. It accepts
// no host except collector.superspeedy.org, and never prints the installation secret or a
// presigned upload URL.

const SSPA_LIVE_PROD_HOST = 'collector.superspeedy.org';
// Same host, discard port: a genuine connection failure with no mocking and no other host.
const SSPA_LIVE_PROD_OUTAGE = 'https://collector.superspeedy.org:9';

require_once __DIR__ . '/production-test-identity.php';

function sspa_prod_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
    return (bool) $ok;
}

global $wpdb;

$collector = isset($args[0]) ? untrailingslashit(trim((string) $args[0])) : '';
$optin_token = isset($args[1]) ? trim((string) $args[1]) : '';
$parts = wp_parse_url($collector);
$scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
$host = isset($parts['host']) ? strtolower($parts['host']) : '';

if ('https' !== $scheme || SSPA_LIVE_PROD_HOST !== $host || !empty($parts['port'])) {
    sspa_prod_t(false, 'the collector must be exactly https://' . SSPA_LIVE_PROD_HOST);
    return;
}
if (!in_array($optin_token, array('production', '--production'), true)) {
    sspa_prod_t(false, 'refusing to submit to production without the explicit `production` opt-in token');
    return;
}
if (!class_exists('SSPA_Community_Outbox') || !class_exists('SSPA_Community_Worker')) {
    sspa_prod_t(false, 'the community submission classes are not loaded');
    return;
}

$old_collector = get_option('sspa_collector_url', null);
$old_optin = get_option('sspa_share_optin', null);
$now = gmdate('Y-m-d H:i:s');
$run_uuid = wp_generate_uuid4();
$run_id = 0;
$profile_id = 0;
$outbox_id = 0;
$paused = array();
$identity_snapshot = sspa_production_test_identity_begin($collector);

try {
    // Never let an unrelated queued item be the one that goes to the production archive.
    $others = $wpdb->get_col(
        'SELECT id FROM ' . SSPA_Schema::table('submission_outbox') . " WHERE state IN ('pending','retry')"
    );
    foreach ($others as $other_id) {
        if (true === SSPA_Community_Outbox::pause((int) $other_id)) {
            $paused[] = (int) $other_id;
        }
    }
    if ($paused) {
        echo 'temporarily paused ' . count($paused) . " unrelated queued item(s)\n";
    }

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
        'plugin_set_hash' => md5('live-production-test'),
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
        'plugin_set_hash' => md5('live-production-test'),
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

    // Phase 1: queue while the collector is genuinely unreachable.
    update_option('sspa_collector_url', SSPA_LIVE_PROD_OUTAGE, false);
    update_option('sspa_share_optin', 1, false);

    $queued = SSPA_Community_Outbox::queue_run($run_id);
    if (is_wp_error($queued)) {
        sspa_prod_t(false, 'payload queued during the outage: ' . $queued->get_error_message());
        return;
    }
    $outbox_id = (int) $queued['id'];
    $submission_uuid = $queued['submission_uuid'];
    $sha256 = $queued['payload_sha256'];
    $compressed_bytes = (int) $queued['compressed_bytes'];
    sspa_prod_t(true, 'analysis queued a payload with the collector unreachable (' . $compressed_bytes . ' compressed bytes)');

    echo "attempting the unreachable collector (this waits for a real connection failure)...\n";
    delete_option(SSPA_Community_Worker::LOCK_OPTION);
    SSPA_Community_Worker::run();
    $after_outage = SSPA_Community_Outbox::get($outbox_id);
    sspa_prod_t('retry' === $after_outage['state'], 'outage left the item retryable, not failed (state=' . $after_outage['state'] . ', error=' . $after_outage['last_error_code'] . ')');
    sspa_prod_t((int) $after_outage['attempts'] >= 1, 'the failed attempt was recorded');
    sspa_prod_t(
        !empty($after_outage['payload_gzip'])
            && strlen($after_outage['payload_gzip']) === $compressed_bytes
            && hash_equals($sha256, hash('sha256', $after_outage['payload_gzip'])),
        'the exact outbox bytes survived the outage unchanged'
    );
    sspa_prod_t(empty($after_outage['receipt_uuid']), 'no receipt was invented for the unreachable collector');

    // Phase 2: restore production and prove the same UUID and hash receive a receipt.
    update_option('sspa_collector_url', $collector, false);
    $retried = SSPA_Community_Outbox::retry_now($outbox_id);
    if (is_wp_error($retried)) {
        sspa_prod_t(false, 'manual retry: ' . $retried->get_error_message());
        return;
    }
    delete_option(SSPA_Community_Worker::LOCK_OPTION);
    SSPA_Community_Worker::run();
    $sent = SSPA_Community_Outbox::get($outbox_id);
    if ('sent' !== $sent['state']) {
        sspa_prod_t(false, 'production round trip: ' . $sent['last_error_code'] . ' - ' . $sent['last_error_detail']);
        return;
    }
    sspa_prod_t(true, 'the background worker registered, reserved, uploaded and completed against production');
    sspa_prod_t($sent['submission_uuid'] === $submission_uuid, 'the receipted submission UUID is the one queued during the outage');
    sspa_prod_t(hash_equals($sha256, $sent['payload_sha256']), 'the immutable payload hash is unchanged across the outage and retry');
    sspa_prod_t(
        !empty($sent['payload_gzip']) && hash_equals($sha256, hash('sha256', $sent['payload_gzip'])),
        'the retained local bytes still hash to the receipted value'
    );
    sspa_prod_t(wp_is_uuid($sent['receipt_uuid'], 4), 'a receipt UUID is stored locally');
    sspa_prod_t(in_array((int) $sent['last_http_status'], array(200, 201), true), 'completion returned HTTP ' . (int) $sent['last_http_status']);

    $leaked = $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM ' . SSPA_Schema::table('submission_events') . '
         WHERE outbox_id = %d AND (reason_code LIKE %s OR reason_code LIKE %s)',
        $outbox_id,
        '%http%',
        '%secret%'
    ));
    sspa_prod_t(0 === (int) $leaked, 'no URL or credential material entered the local submission events');

    // Ask the collector itself, rather than inferring the archival outcome from the receipt.
    $status = null;
    $processing = '';
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $status = SSPA_Community_Client::status($submission_uuid);
        if (is_wp_error($status)) {
            break;
        }
        $processing = isset($status['processing_status']) ? $status['processing_status'] : '';
        if (!in_array($processing, array('not_queued', 'pending', 'processing'), true)) {
            break;
        }
        sleep(3);
    }
    if (is_wp_error($status)) {
        sspa_prod_t(false, 'collector status query: ' . $status->get_error_code());
    } else {
        sspa_prod_t('archived' === ($status['storage_status'] ?? ''), 'the collector reports storage_status=' . ($status['storage_status'] ?? '(none)'));
        sspa_prod_t(
            isset($status['payload_sha256']) && hash_equals($sha256, (string) $status['payload_sha256']),
            'the collector holds the same payload hash'
        );
        sspa_prod_t(
            isset($status['receipt_uuid']) && $status['receipt_uuid'] === $sent['receipt_uuid'],
            'the collector returns the same receipt UUID'
        );
        sspa_prod_t(
            in_array($processing, array('complete', 'partial', 'unsupported'), true),
            'the manifest worker recorded an explicit outcome: processing_status=' . ($processing ?: '(none)')
        );
    }

    echo "\n";
    echo 'collector=' . $collector . "\n";
    echo 'submission_uuid=' . $sent['submission_uuid'] . "\n";
    echo 'receipt_uuid=' . $sent['receipt_uuid'] . "\n";
    echo 'payload_sha256=' . $sent['payload_sha256'] . "\n";
    echo 'processing_status=' . ($processing ?: '(unknown)') . "\n";
    echo 'compressed_bytes=' . (int) $sent['compressed_bytes'] . "\n";
    echo 'uncompressed_bytes=' . (int) $sent['uncompressed_bytes'] . "\n";
    echo 'payload_schema=' . (int) $sent['payload_schema_major'] . '.' . (int) $sent['payload_schema_minor'] . "\n";
    echo 'client_version=' . $sent['client_version'] . "\n";
    echo 'attempts=' . (int) $sent['attempts'] . "\n";
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
    foreach ($paused as $paused_id) {
        SSPA_Community_Outbox::resume($paused_id);
    }
    // The outage URL is a distinct credential scope; leave nothing behind for it.
    delete_option('sspa_collector_secret_' . md5(SSPA_LIVE_PROD_OUTAGE));
    delete_option('sspa_collector_registered_' . md5(SSPA_LIVE_PROD_OUTAGE));
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
    sspa_production_test_identity_restore($identity_snapshot);
}
