<?php
// Real analyses, opted in, must automatically queue one community payload each.
//
// Case 21 proves the exporters can build a payload for every run type, but it inserts its own
// run and profile rows. Cases 05/09/10/17/19 drive the REAL run controller but with sharing
// off, so `SSPA_Community_Outbox::on_terminal_run()` returns immediately. Nothing joined the
// two: an analysis a site actually performs, with sharing on, producing a queued payload.
// That is what this case does.

function sspa_run_int_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

global $wpdb;

$old_optin = get_option('sspa_share_optin', null);
update_option('sspa_share_optin', 1, false);

$outbox_table = SSPA_Schema::table('submission_outbox');
$queued_ids = array();
$run_ids = array();

// due() returns the OLDEST eligible row, so a queue item left behind by earlier work would
// answer the delivery-eligibility assertions instead of this case's own row. Park anything
// pre-existing for the duration and put it back at the end.
$sspa_parked = array();
foreach ($wpdb->get_col('SELECT id FROM ' . $outbox_table . " WHERE state IN ('pending','retry')") as $sspa_pre_existing) {
    if (true === SSPA_Community_Outbox::pause((int) $sspa_pre_existing)) {
        $sspa_parked[] = (int) $sspa_pre_existing;
    }
}
sspa_run_int_t(true, 'parked ' . count($sspa_parked) . ' pre-existing queue item(s) for isolation');

/**
 * Drive a real run to a terminal state, then report what the outbox did about it.
 */
function sspa_run_int_drive($args, $deadline_seconds = 900) {
    $run_id = SSPA_Run_Controller::start($args);
    if (is_wp_error($run_id)) {
        return array('error' => $run_id->get_error_message());
    }
    $deadline = time() + $deadline_seconds;
    do {
        SSPA_Run_Controller::process_batch($run_id);
        $status = SSPA_Run_Controller::status($run_id);
        $running = $status && in_array($status['status'], array('crawling', 'running', 'queued'), true);
    } while ($running && time() < $deadline);
    return array(
        'run_id' => (int) $run_id,
        'status' => $status ? $status['status'] : 'null',
    );
}

/**
 * The evidence types present in a queued payload, from its exact stored bytes.
 */
function sspa_run_int_types($row) {
    $json = SSPA_Community_Outbox::preview($row);
    if (is_wp_error($json)) {
        return array();
    }
    $payload = json_decode($json, true);
    $types = array();
    foreach (isset($payload['evidence']) ? $payload['evidence'] : array() as $evidence) {
        $types[$evidence['type']] = isset($types[$evidence['type']]) ? $types[$evidence['type']] + 1 : 1;
    }
    return $types;
}

// run type => the evidence the payload must carry for that analysis to be worth archiving.
$expected = array(
    'baseline' => array('sspa/site-snapshot', 'sspa/page-profile'),
    'spot' => array('sspa/site-snapshot', 'sspa/page-profile'),
    'adhoc' => array('sspa/site-snapshot', 'sspa/adhoc-page-profile'),
    'cache_impact' => array('sspa/site-snapshot', 'sspa/cache-impact'),
    'deep' => array('sspa/site-snapshot', 'sspa/plugin-impact'),
    'checkout' => array('sspa/site-snapshot', 'sspa/checkout-flow'),
);

$plan = array(
    'adhoc' => array('type' => 'adhoc', 'url' => home_url('/'), 'trigger' => 'manual', 'user_id' => 1),
    'spot' => array('type' => 'spot', 'page_keys' => array('home'), 'user_id' => 1),
    'cache_impact' => array('type' => 'cache_impact', 'page_keys' => array('home'), 'user_id' => 1),
    'checkout' => array('type' => 'checkout', 'user_id' => 1),
    'baseline' => array('type' => 'baseline', 'trigger' => 'manual', 'user_id' => 1),
);
// Deep needs a suspect to measure; use whatever real plugin is active besides our own.
$deep_suspect = '';
foreach ((array) get_option('active_plugins') as $plugin_file) {
    $slug = dirname($plugin_file);
    if ('.' !== $slug && 'super-speedy-performance-analysis' !== $slug) {
        $deep_suspect = $slug;
        break;
    }
}
if ($deep_suspect) {
    $plan['deep'] = array('type' => 'deep', 'suspects' => array($deep_suspect), 'user_id' => 1);
}

foreach ($plan as $label => $args) {
    $before = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $outbox_table);
    $result = sspa_run_int_drive($args);
    if (isset($result['error'])) {
        sspa_run_int_t(false, "$label run started: " . $result['error']);
        continue;
    }
    $run_ids[] = $result['run_id'];
    $terminal = in_array($result['status'], array('done', 'failed'), true);
    sspa_run_int_t($terminal, "$label run reached a terminal state ({$result['status']})");
    if (!$terminal) {
        continue;
    }

    $run = $wpdb->get_row($wpdb->prepare('SELECT run_uuid FROM ' . SSPA_Schema::table('runs') . ' WHERE id = %d', $result['run_id']), ARRAY_A);
    $row = $run ? SSPA_Community_Outbox::for_run_uuid($run['run_uuid']) : null;
    if (!$row) {
        $errors = get_option('sspa_submission_build_errors', array());
        $why = isset($errors[$result['run_id']]) ? $errors[$result['run_id']]['code'] . ' - ' . $errors[$result['run_id']]['detail'] : 'no outbox row and no recorded build error';
        sspa_run_int_t(false, "$label run queued a payload automatically: $why");
        continue;
    }
    $queued_ids[] = (int) $row['id'];
    sspa_run_int_t(true, "$label run queued a payload automatically ({$row['compressed_bytes']} compressed bytes)");

    $after = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $outbox_table);
    sspa_run_int_t(1 === $after - $before, "$label run created exactly one outbox row");
    sspa_run_int_t(
        'pending' === $row['state'] && hash_equals($row['payload_sha256'], hash('sha256', $row['payload_gzip'])),
        "$label payload is pending delivery with matching immutable bytes"
    );

    $types = sspa_run_int_types($row);
    foreach ($expected[$label] as $type) {
        sspa_run_int_t(
            isset($types[$type]) && $types[$type] > 0,
            "$label payload carries $type evidence" . (isset($types[$type]) ? " ({$types[$type]})" : '')
        );
    }
}

// A second attempt on the same runs must not duplicate anything.
$duplicates = 0;
foreach ($run_ids as $run_id) {
    SSPA_Community_Outbox::on_terminal_run($run_id);
}
$dupe_rows = (int) $wpdb->get_var('SELECT COUNT(*) FROM (SELECT run_uuid FROM ' . $outbox_table . ' GROUP BY run_uuid HAVING COUNT(*) > 1) d');
sspa_run_int_t(0 === $dupe_rows, 'repeating the terminal-run hook created no duplicate submissions');

// Opting out must stop new payloads being created for a real run.
update_option('sspa_share_optin', 0, false);
$optout = sspa_run_int_drive(array('type' => 'adhoc', 'url' => home_url('/'), 'trigger' => 'manual', 'user_id' => 1));
if (isset($optout['error'])) {
    sspa_run_int_t(false, 'opted-out run started: ' . $optout['error']);
} else {
    $run_ids[] = $optout['run_id'];
    $optout_run = $wpdb->get_row($wpdb->prepare('SELECT run_uuid FROM ' . SSPA_Schema::table('runs') . ' WHERE id = %d', $optout['run_id']), ARRAY_A);
    sspa_run_int_t(
        $optout_run && !SSPA_Community_Outbox::for_run_uuid($optout_run['run_uuid']),
        'an opted-out real run queues nothing'
    );

    // ...but the owner can still contribute that one analysis explicitly.
    $shared = SSPA_Community_Outbox::share_run($optout['run_id']);
    if (is_wp_error($shared)) {
        sspa_run_int_t(false, 'sharing one analysis while opted out: ' . $shared->get_error_message());
    } else {
        $queued_ids[] = (int) $shared['id'];
        sspa_run_int_t('manual' === $shared['consent_scope'], 'sharing one analysis while opted out records per-run consent');

        // The click IS the consent, so the payload must not report consent version 0 just
        // because the site-wide setting was never accepted.
        $shared_payload = json_decode(SSPA_Community_Outbox::preview($shared), true);
        sspa_run_int_t(
            isset($shared_payload['client']['consent_version'])
                && SSPA_Community_Schema::CONSENT_VERSION === (int) $shared_payload['client']['consent_version'],
            'an explicitly shared payload carries the consent version in force, not 0'
        );
        sspa_run_int_t(!SSPA_Submitter::opted_in(), 'sharing one analysis did not switch on site-wide sharing');

        $due = SSPA_Community_Outbox::due();
        sspa_run_int_t($due && (int) $due['id'] === (int) $shared['id'], 'the explicitly shared analysis is due for delivery while opted out');

        // Everything the owner did NOT choose must stay put while opted out.
        $automatic_due = 0;
        foreach ($queued_ids as $queued_id) {
            $row = SSPA_Community_Outbox::get($queued_id);
            if ($row && 'automatic' === $row['consent_scope'] && in_array($row['state'], array('pending', 'retry'), true)) {
                $automatic_due++;
            }
        }
        sspa_run_int_t(
            $automatic_due > 0 && $due && 'manual' === $due['consent_scope'],
            "opting out holds back the $automatic_due automatically queued analyses and offers only the chosen one"
        );
    }
}

foreach ($queued_ids as $outbox_id) {
    $wpdb->delete(SSPA_Schema::table('submission_events'), array('outbox_id' => $outbox_id));
    $wpdb->delete($outbox_table, array('id' => $outbox_id));
}
foreach ($sspa_parked as $sspa_parked_id) {
    SSPA_Community_Outbox::resume($sspa_parked_id);
}
delete_option('sspa_share_manual_pending');
// Nothing this case queued should ever reach the network afterwards.
wp_clear_scheduled_hook('sspa_submission_worker_event');
if (null === $old_optin) {
    delete_option('sspa_share_optin');
} else {
    update_option('sspa_share_optin', $old_optin, false);
}
delete_option('sspa_submission_build_errors');
sspa_run_int_t(true, 'outbox fixtures cleaned up');
