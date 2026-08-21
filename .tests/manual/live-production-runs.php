<?php
// Deliver one REAL analysis of every run type to the production collector.
//
// Usage, from bash in this plugin repository, against the DISPOSABLE test site only:
//
//   source .tests/env.sh
//   sync_plugin
//   cli eval-file "$CONTAINER_PLUGIN_DIR/.tests/manual/live-production-runs.php" \
//       https://collector.superspeedy.org production
//
// To repeat only selected types after diagnosing a processor failure, pass a comma-separated
// third argument, for example: production spot,baseline
//
// live-production.php proves the transport with a ~1 KB synthetic fixture. This proves the
// thing the plugin will actually do: take analyses this site really performed, of every type,
// and get each one permanently archived with an explicit processing outcome. Payloads here are
// two orders of magnitude larger and carry real evidence volumes.
//
// It uses the per-run consent path, so the site-wide sharing setting stays off throughout.
// Each analysis is a separate permanent object in the production archive.

const SSPA_LIVE_RUNS_HOST = 'collector.superspeedy.org';

require_once __DIR__ . '/production-test-identity.php';

function sspa_runs_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
    return (bool) $ok;
}

global $wpdb;

$collector = isset($args[0]) ? untrailingslashit(trim((string) $args[0])) : '';
$optin_token = isset($args[1]) ? trim((string) $args[1]) : '';
$parts = wp_parse_url($collector);

if ('https' !== strtolower(isset($parts['scheme']) ? $parts['scheme'] : '')
    || SSPA_LIVE_RUNS_HOST !== strtolower(isset($parts['host']) ? $parts['host'] : '')
    || !empty($parts['port'])) {
    sspa_runs_t(false, 'the collector must be exactly https://' . SSPA_LIVE_RUNS_HOST);
    return;
}
if (!in_array($optin_token, array('production', '--production'), true)) {
    sspa_runs_t(false, 'refusing to submit to production without the explicit `production` opt-in token');
    return;
}
$all_run_types = array('adhoc', 'spot', 'cache_impact', 'checkout', 'admin_save', 'baseline', 'deep');
$run_types = $all_run_types;
if (!empty($args[2])) {
    $run_types = array_values(array_unique(array_filter(array_map('sanitize_key', explode(',', (string) $args[2])))));
    if (!$run_types || array_diff($run_types, $all_run_types)) {
        sspa_runs_t(false, 'run types must be a comma-separated subset of: ' . implode(',', $all_run_types));
        return;
    }
}

$old_collector = get_option('sspa_collector_url', null);
$old_optin = get_option('sspa_share_optin', null);
$outbox_table = SSPA_Schema::table('submission_outbox');
$queued = array();
$parked = array();
$results = array();
$identity_snapshot = sspa_production_test_identity_begin($collector);

try {
    update_option('sspa_collector_url', $collector, false);
    // Deliberately left off: this exercises the per-run consent path end to end.
    update_option('sspa_share_optin', 0, false);

    foreach ($wpdb->get_col('SELECT id FROM ' . $outbox_table . " WHERE state IN ('pending','retry')") as $pre_existing) {
        if (true === SSPA_Community_Outbox::pause((int) $pre_existing)) {
            $parked[] = (int) $pre_existing;
        }
    }
    if ($parked) {
        echo 'parked ' . count($parked) . " unrelated queued item(s)\n";
    }

    foreach ($run_types as $run_type) {
        $run_id = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . SSPA_Schema::table('runs') . "
             WHERE run_type = %s AND status = 'done'
               AND id NOT IN (SELECT run_id FROM $outbox_table)
             ORDER BY id DESC LIMIT 1",
            $run_type
        ));
        if (!$run_id) {
            sspa_runs_t(false, "$run_type: no completed unshared run found - run an analysis of this type first");
            continue;
        }

        $shared = SSPA_Community_Outbox::share_run($run_id);
        if (is_wp_error($shared)) {
            sspa_runs_t(false, "$run_type run #$run_id queued: " . $shared->get_error_message());
            continue;
        }
        $queued[] = (int) $shared['id'];
        $evidence = json_decode(SSPA_Community_Outbox::preview($shared), true);
        $evidence_count = isset($evidence['evidence']) ? count($evidence['evidence']) : 0;

        delete_option(SSPA_Community_Worker::LOCK_OPTION);
        SSPA_Community_Worker::run();
        $sent = SSPA_Community_Outbox::get((int) $shared['id']);
        if ('sent' !== $sent['state']) {
            sspa_runs_t(false, "$run_type run #$run_id delivered: " . $sent['last_error_code'] . ' - ' . $sent['last_error_detail']);
            continue;
        }
        sspa_runs_t(
            wp_is_uuid($sent['receipt_uuid'], 4) && hash_equals($shared['payload_sha256'], $sent['payload_sha256']),
            sprintf(
                '%s run #%d archived: %d compressed bytes, %d uncompressed, %d evidence records',
                $run_type,
                $run_id,
                (int) $sent['compressed_bytes'],
                (int) $sent['uncompressed_bytes'],
                $evidence_count
            )
        );

        $processing = '';
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $status = SSPA_Community_Client::status($sent['submission_uuid']);
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
            sspa_runs_t(false, "$run_type collector status query: " . $status->get_error_code());
            continue;
        }
        sspa_runs_t(
            'archived' === ($status['storage_status'] ?? '')
                && 'complete' === $processing,
            sprintf('%s storage_status=%s processing_status=%s', $run_type, $status['storage_status'] ?? '(none)', $processing ?: '(none)')
        );

        $results[] = array(
            'run_type' => $run_type,
            'run_id' => $run_id,
            'submission_uuid' => $sent['submission_uuid'],
            'receipt_uuid' => $sent['receipt_uuid'],
            'payload_sha256' => $sent['payload_sha256'],
            'compressed_bytes' => (int) $sent['compressed_bytes'],
            'evidence' => $evidence_count,
            'processing_status' => $processing,
        );
    }

    sspa_runs_t(!SSPA_Submitter::opted_in(), 'site-wide sharing is still off after delivering every analysis');

    echo "\n";
    foreach ($results as $row) {
        echo implode(' | ', array(
            str_pad($row['run_type'], 13),
            'run #' . $row['run_id'],
            $row['compressed_bytes'] . 'b',
            $row['evidence'] . ' evidence',
            $row['processing_status'],
            'submission=' . $row['submission_uuid'],
            'receipt=' . $row['receipt_uuid'],
        )) . "\n";
    }
} finally {
    foreach ($queued as $outbox_id) {
        $wpdb->delete(SSPA_Schema::table('submission_events'), array('outbox_id' => $outbox_id));
        $wpdb->delete($outbox_table, array('id' => $outbox_id));
    }
    foreach ($parked as $parked_id) {
        SSPA_Community_Outbox::resume($parked_id);
    }
    delete_option('sspa_share_manual_pending');
    wp_clear_scheduled_hook('sspa_submission_worker_event');
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
