<?php
// Commerce flow evidence: the customer's wait and the shop owner's admin work leave as two
// separate records, and a management sequence that did not finish says so.
//
// Case 33 proves the happy path against a REAL measured purchase - both steps, real timings,
// real status transition. This case covers what a real run on a healthy store cannot produce
// on demand: a security layer blocking wp-admin, a run that only got as far as opening the
// order, and a store measured before order management existed. The profile rows are inserted
// directly because that is the only way to hold a run in those states, and they are inserted
// in the shape the crawler really writes - `blocked_by` is the column it sets when a security
// plugin answers instead of WordPress, and a null `page_gen_ms` is what an unmeasured step
// leaves behind.

function sspa_fe_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

global $wpdb;

$sspa_fe_runs = array();
$sspa_fe_profiles = array();

function sspa_fe_run($notes) {
    global $wpdb, $sspa_fe_runs;
    $now = gmdate('Y-m-d H:i:s');
    $wpdb->insert(SSPA_Schema::table('runs'), array(
        'run_uuid' => wp_generate_uuid4(),
        'blog_id' => 1,
        'run_type' => 'checkout',
        'measurement_version' => 1,
        'trigger_source' => 'test',
        'status' => 'done',
        'plugin_set' => wp_json_encode(array('components' => array(
            array('type' => 'plugin', 'slug' => 'woocommerce', 'version' => '10.1.0'),
        ))),
        'plugin_set_hash' => md5('flow-evidence'),
        'started' => $now,
        'finished' => $now,
        'notes' => wp_json_encode($notes),
    ));
    $id = (int) $wpdb->insert_id;
    $sspa_fe_runs[] = $id;
    return $id;
}

function sspa_fe_step($run_id, $page_key, $args = array()) {
    global $wpdb, $sspa_fe_profiles;
    $wpdb->insert(SSPA_Schema::table('profiles'), array(
        'run_id' => $run_id,
        'page_key' => $page_key,
        // A real order screen URL carries the order id, and the customer's email is in the
        // order. If any of it can reach a payload, it reaches it from here.
        'url' => admin_url('post.php?post=4242&action=edit&_wpnonce=abc123'),
        'method' => isset($args['method']) ? $args['method'] : 'GET',
        'variant' => in_array($page_key, array('flow-view-order', 'flow-complete-order'), true) ? 'admin' : 'anon',
        'plugin_set_hash' => md5('flow-evidence'),
        'object_cache_mode' => 'normal',
        'samples' => wp_json_encode(array(array('wall_ms' => 100, 'code' => 200))),
        'page_gen_ms' => array_key_exists('gen_ms', $args) ? $args['gen_ms'] : 120.0,
        'sql_ms' => 20.0,
        'sql_count' => 40,
        'http_ms' => 0,
        'response_code' => isset($args['code']) ? $args['code'] : 200,
        'blocked_by' => isset($args['blocked_by']) ? $args['blocked_by'] : null,
        'created' => gmdate('Y-m-d H:i:s'),
    ));
    $sspa_fe_profiles[] = (int) $wpdb->insert_id;
}

function sspa_fe_evidence($run_id, $type) {
    $built = SSPA_Community_Exporter::build($run_id);
    if (is_wp_error($built)) {
        return $built;
    }
    $found = array();
    foreach ((array) $built['evidence'] as $item) {
        if ($type === $item['type']) {
            $found[] = $item;
        }
    }
    return $found;
}

$sspa_fe_notes = array(
    'inventory' => array('checkout_type' => 'block', 'hpos' => true),
    'flow' => array('payment_mode' => 'no_payment'),
);

// --- A store measured before order management existed ---

$sspa_old = sspa_fe_run($sspa_fe_notes);
sspa_fe_step($sspa_old, 'flow-view-cart');
sspa_fe_step($sspa_old, 'flow-place-order', array('method' => 'POST'));
sspa_fe_step($sspa_old, 'flow-order-received');
$sspa_old_mgmt = sspa_fe_evidence($sspa_old, 'sspa/order-management-flow');
$sspa_old_flow = sspa_fe_evidence($sspa_old, 'sspa/checkout-flow');
sspa_fe_t(is_array($sspa_old_mgmt) && 0 === count($sspa_old_mgmt), 'a run with no management rows produces no management evidence');
sspa_fe_t(
    is_array($sspa_old_flow) && 1 === count($sspa_old_flow),
    'its customer checkout evidence is unaffected, so historical runs still export'
);

// --- Both steps measured: the complete sequence ---

$sspa_full = sspa_fe_run(array_merge($sspa_fe_notes, array('flow' => array(
    'payment_mode' => 'no_payment',
    'complete_from_status' => 'processing',
    'complete_to_status' => 'completed',
))));
sspa_fe_step($sspa_full, 'flow-preflight');
sspa_fe_step($sspa_full, 'flow-view-cart');
sspa_fe_step($sspa_full, 'flow-place-order', array('method' => 'POST', 'gen_ms' => 400.0));
sspa_fe_step($sspa_full, 'flow-order-received', array('gen_ms' => 80.0));
sspa_fe_step($sspa_full, 'flow-view-order', array('gen_ms' => 300.0));
sspa_fe_step($sspa_full, 'flow-complete-order', array('gen_ms' => 500.0));
sspa_fe_step($sspa_full, 'flow-delete-order', array('gen_ms' => 250.0));

$sspa_mgmt = sspa_fe_evidence($sspa_full, 'sspa/order-management-flow');
$sspa_flow = sspa_fe_evidence($sspa_full, 'sspa/checkout-flow');
sspa_fe_t(is_array($sspa_mgmt) && 1 === count($sspa_mgmt) && 1 === $sspa_mgmt[0]['version'], 'order management leaves as its own v1 evidence record');

if (is_array($sspa_mgmt) && $sspa_mgmt && is_array($sspa_flow) && $sspa_flow) {
    $sspa_m = $sspa_mgmt[0]['data'];
    $sspa_c = $sspa_flow[0]['data'];
    sspa_fe_t('complete' === $sspa_m['outcome'], 'both steps measured reports outcome complete (' . $sspa_m['outcome'] . ')');
    sspa_fe_t(
        abs($sspa_m['management_ms'] - 800.0) < 0.5,
        'the management total is view + complete only, 800ms (' . $sspa_m['management_ms'] . ')'
    );
    sspa_fe_t('flow-complete-order' === $sspa_m['slowest_step'], 'the slowest MANAGEMENT step is named, not the run\'s slowest step (' . $sspa_m['slowest_step'] . ')');
    sspa_fe_t('hpos' === $sspa_m['order_storage'] && 'block' === $sspa_m['checkout_type'], 'the cohort controls travel with it');
    sspa_fe_t(
        'processing' === $sspa_m['from_status'] && 'completed' === $sspa_m['to_status'],
        'the status transition is recorded'
    );

    // The customer total: cart 120 + place-order 400 + order-received 80 = 600. Nothing from
    // the admin screens, nothing from the cleanup delete.
    sspa_fe_t(
        abs($sspa_c['total_ms'] - 600.0) < 0.5,
        'the customer total excludes both management steps AND the harness cleanup, 600ms (' . $sspa_c['total_ms'] . ')'
    );
    $sspa_customer_classes = array();
    $sspa_harness_classes = array();
    foreach ((array) $sspa_c['steps'] as $sspa_step) {
        if ('harness' === $sspa_step['classification']) {
            $sspa_harness_classes[] = $sspa_step['page_class'];
        } else {
            $sspa_customer_classes[] = $sspa_step['page_class'];
        }
    }
    sspa_fe_t(
        !array_intersect(array('flow-view-order', 'flow-complete-order'), $sspa_customer_classes)
        && !array_intersect(array('flow-view-order', 'flow-complete-order'), $sspa_harness_classes),
        'no management step appears anywhere in the customer flow'
    );
    sspa_fe_t(
        in_array('flow-preflight', $sspa_harness_classes, true) && in_array('flow-delete-order', $sspa_harness_classes, true),
        'the harness steps are exported, labelled as harness (' . implode(', ', $sspa_harness_classes) . ')'
    );
}

// --- Blocked by a security layer ---

$sspa_blocked = sspa_fe_run($sspa_fe_notes);
sspa_fe_step($sspa_blocked, 'flow-place-order', array('method' => 'POST'));
sspa_fe_step($sspa_blocked, 'flow-view-order', array('gen_ms' => null, 'code' => 403, 'blocked_by' => 'wordfence'));
sspa_fe_step($sspa_blocked, 'flow-complete-order', array('gen_ms' => null, 'code' => 403, 'blocked_by' => 'wordfence'));
$sspa_blocked_mgmt = sspa_fe_evidence($sspa_blocked, 'sspa/order-management-flow');
if (is_array($sspa_blocked_mgmt) && $sspa_blocked_mgmt) {
    $sspa_b = $sspa_blocked_mgmt[0]['data'];
    sspa_fe_t('blocked' === $sspa_b['outcome'], 'a blocked management sequence stays in the payload, marked blocked (' . $sspa_b['outcome'] . ')');
    sspa_fe_t(
        !empty($sspa_b['steps'][0]['blocked']) && null === $sspa_b['steps'][0]['generation_ms'],
        'its steps carry no invented timing - blocked, with a null measurement'
    );
    sspa_fe_t(
        !isset($sspa_b['blocked_by']) && false === strpos(wp_json_encode($sspa_b), 'wordfence'),
        'and the payload does not name the security plugin as part of the flow record'
    );
} else {
    sspa_fe_t(false, 'a blocked management sequence still produces evidence');
}

// --- Only half the sequence ran ---

$sspa_partial = sspa_fe_run($sspa_fe_notes);
sspa_fe_step($sspa_partial, 'flow-place-order', array('method' => 'POST'));
sspa_fe_step($sspa_partial, 'flow-view-order', array('gen_ms' => 210.0));
$sspa_partial_mgmt = sspa_fe_evidence($sspa_partial, 'sspa/order-management-flow');
if (is_array($sspa_partial_mgmt) && $sspa_partial_mgmt) {
    $sspa_p = $sspa_partial_mgmt[0]['data'];
    sspa_fe_t('partial' === $sspa_p['outcome'], 'opening the order but never completing it reports partial (' . $sspa_p['outcome'] . ')');
    sspa_fe_t(1 === count($sspa_p['steps']) && null === $sspa_p['to_status'], 'with the one step it did measure and no invented transition');
} else {
    sspa_fe_t(false, 'a partial management sequence still produces evidence');
}

// --- A store's own order status, which is named after its business ---

$sspa_custom = sspa_fe_run(array(
    'inventory' => array('checkout_type' => 'classic', 'hpos' => false),
    'flow' => array(
        'payment_mode' => 'no_payment',
        'complete_from_status' => 'wc-awaiting-pallet-collection',
        'complete_to_status' => 'completed',
    ),
));
sspa_fe_step($sspa_custom, 'flow-place-order', array('method' => 'POST'));
sspa_fe_step($sspa_custom, 'flow-view-order', array('gen_ms' => 150.0));
sspa_fe_step($sspa_custom, 'flow-complete-order', array('gen_ms' => 250.0));
$sspa_custom_mgmt = sspa_fe_evidence($sspa_custom, 'sspa/order-management-flow');
if (is_array($sspa_custom_mgmt) && $sspa_custom_mgmt) {
    $sspa_cs = $sspa_custom_mgmt[0]['data'];
    sspa_fe_t(
        'other' === $sspa_cs['from_status'] && 'completed' === $sspa_cs['to_status'],
        'a bespoke order status is canonicalised to "other" (' . $sspa_cs['from_status'] . ')'
    );
    sspa_fe_t('posts' === $sspa_cs['order_storage'], 'legacy order storage is reported as posts (' . $sspa_cs['order_storage'] . ')');
} else {
    sspa_fe_t(false, 'the custom-status run produces management evidence');
}

// --- Nothing identifying, in any of them ---

$sspa_all = array();
foreach (array($sspa_old, $sspa_full, $sspa_blocked, $sspa_partial, $sspa_custom) as $sspa_run_id) {
    $sspa_built = SSPA_Community_Exporter::build($sspa_run_id);
    $sspa_all[] = is_wp_error($sspa_built) ? array('error' => $sspa_built->get_error_message()) : $sspa_built;
}
$sspa_all_json = wp_json_encode($sspa_all);
$sspa_leaks = array();
foreach (array('4242', '_wpnonce', 'abc123', 'post.php', 'awaiting-pallet-collection') as $sspa_needle) {
    if (false !== strpos($sspa_all_json, $sspa_needle)) {
        $sspa_leaks[] = $sspa_needle;
    }
}
sspa_fe_t(!$sspa_leaks, 'no order id, nonce, admin URL or bespoke status reaches any payload' . ($sspa_leaks ? ': ' . implode(', ', $sspa_leaks) : ''));

$sspa_invalid = array();
foreach ($sspa_all as $sspa_index => $sspa_payload) {
    if (isset($sspa_payload['error'])) {
        $sspa_invalid[] = 'payload ' . $sspa_index . ' failed to build: ' . $sspa_payload['error'];
        continue;
    }
    $sspa_check = SSPA_Community_Privacy::validate($sspa_payload);
    if (is_wp_error($sspa_check)) {
        $sspa_invalid[] = 'payload ' . $sspa_index . ': ' . $sspa_check->get_error_message();
    }
}
sspa_fe_t(!$sspa_invalid, 'every flow payload passes the privacy scan' . ($sspa_invalid ? ': ' . implode('; ', $sspa_invalid) : ''));

// --- An archived payload is never rewritten by newer code ---

$sspa_old_optin = get_option('sspa_share_optin', null);
update_option('sspa_share_optin', 1, false);
$sspa_outbox = SSPA_Community_Outbox::queue_run($sspa_full);
if (is_wp_error($sspa_outbox)) {
    sspa_fe_t(false, 'the run queues for delivery: ' . $sspa_outbox->get_error_message());
} else {
    $sspa_outbox_id = (int) $sspa_outbox['id'];
    $sspa_stored = SSPA_Community_Outbox::get($sspa_outbox_id);
    $sspa_hash_before = $sspa_stored['payload_sha256'];
    // Reading it back, and asking to queue the same run again, must leave the bytes alone:
    // the archive is the record of what was actually sent, not a view rebuilt from today's
    // code.
    SSPA_Community_Outbox::preview($sspa_stored);
    SSPA_Community_Outbox::queue_run($sspa_full);
    $sspa_after = SSPA_Community_Outbox::get($sspa_outbox_id);
    sspa_fe_t(
        $sspa_after && $sspa_after['payload_sha256'] === $sspa_hash_before,
        'an archived payload keeps its exact bytes when the run is queued again'
    );
    $sspa_replayed = SSPA_Community_Outbox::preview($sspa_after);
    $sspa_replayed = is_wp_error($sspa_replayed) ? null : json_decode($sspa_replayed, true);
    sspa_fe_t(
        $sspa_replayed && isset($sspa_replayed['payload_schema']['minor']),
        'and still decodes to its stored payload, schema and all'
    );
    $wpdb->delete(SSPA_Schema::table('submission_events'), array('outbox_id' => $sspa_outbox_id));
    $wpdb->delete(SSPA_Schema::table('submission_outbox'), array('id' => $sspa_outbox_id));
}
if (null === $sspa_old_optin) {
    delete_option('sspa_share_optin');
} else {
    update_option('sspa_share_optin', $sspa_old_optin, false);
}

// --- Cleanup ---

foreach ($sspa_fe_profiles as $sspa_profile_id) {
    $wpdb->delete(SSPA_Schema::table('profiles'), array('id' => $sspa_profile_id));
}
foreach ($sspa_fe_runs as $sspa_run_id) {
    $wpdb->delete(SSPA_Schema::table('submission_outbox'), array('run_id' => $sspa_run_id));
    $wpdb->delete(SSPA_Schema::table('runs'), array('id' => $sspa_run_id));
}
wp_clear_scheduled_hook('sspa_submission_worker_event');
sspa_fe_t(true, 'flow fixtures cleaned up');
