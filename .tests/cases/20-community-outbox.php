<?php
// Durable community outbox: versioned evidence, privacy gate, immutable bytes and idempotency.

function sspa_outbox_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

global $wpdb;

$old_optin = get_option('sspa_share_optin', null);
$now = gmdate('Y-m-d H:i:s');
$run_uuid = wp_generate_uuid4();
$component_inventory = array(
    array('type' => 'plugin', 'slug' => 'woocommerce', 'version' => '10.1.0'),
    array('type' => 'theme', 'slug' => 'storefront', 'version' => '4.6.0'),
);

update_option('sspa_share_optin', 1, false);
$wpdb->insert(SSPA_Schema::table('runs'), array(
    'run_uuid' => $run_uuid,
    'blog_id' => 1,
    'run_type' => 'spot',
    'measurement_version' => 1,
    'trigger_source' => 'plugin_toggle',
    'status' => 'done',
    'plugin_set' => wp_json_encode(array('components' => $component_inventory)),
    'plugin_set_hash' => md5('community-outbox-test'),
    'share_context' => wp_json_encode(array(
        'plugin_toggle' => array('slug' => 'woocommerce', 'action' => 'activated'),
    )),
    'started' => $now,
    'finished' => $now,
));
$run_id = (int) $wpdb->insert_id;

$capture = array(
    'profile' => array(
        'collector' => 'excimer',
        'period_ms' => 1,
        'samples' => 3,
        'wall_ms' => 3,
        'functions' => array(array(
            'fn' => 'WC_Cart->calculate_totals',
            'component' => 'woocommerce',
            'ctype' => 'plugin',
            'incl_ms' => 2.5,
            'self_ms' => 1.5,
            'by' => array('WC_Cart->get_cart' => 2.5),
        )),
        'components' => array('woocommerce' => 2.5),
        'phases' => array(),
    ),
);
$wpdb->insert(SSPA_Schema::table('profiles'), array(
    'run_id' => $run_id,
    'page_key' => 'url-private-product-name',
    'url' => home_url('/private-product-name/'),
    'method' => 'GET',
    'variant' => 'anon',
    'plugin_set_hash' => md5('community-outbox-test'),
    'object_cache_mode' => 'normal',
    'samples' => wp_json_encode(array(array('wall_ms' => 120, 'code' => 200))),
    'ttfb_ms' => 120,
    'page_gen_ms' => 100,
    'sql_ms' => 10,
    'sql_count' => 5,
    'peak_mem_bytes' => 1048576,
    'response_code' => 200,
    'profile_blob' => gzcompress(wp_json_encode($capture), 6),
    'created' => $now,
));
$profile_id = (int) $wpdb->insert_id;
$wpdb->insert(SSPA_Schema::table('component_stats'), array(
    'profile_id' => $profile_id,
    'run_id' => $run_id,
    'component' => 'woocommerce',
    'component_type' => 'plugin',
    'query_count' => 5,
    'sql_ms' => 10,
));
$component_id = (int) $wpdb->insert_id;
$wpdb->insert(SSPA_Schema::table('findings'), array(
    'run_id' => $run_id,
    'severity' => 'warn',
    'finding_type' => 'slow_query',
    'component' => 'woocommerce',
    'page_key' => 'url-private-product-name',
    'evidence' => wp_json_encode(array(
        'ms' => 10,
        'sql' => "SELECT * FROM wp_users WHERE user_email = 'private@example.com'",
    )),
    'recommendation_key' => 'slow_query',
    'confidence' => 'measured',
    'created' => $now,
));
$finding_id = (int) $wpdb->insert_id;

$queued = SSPA_Community_Outbox::queue_run($run_id);
sspa_outbox_t(!is_wp_error($queued), 'completed run queued locally');
if (!is_wp_error($queued)) {
    $json = SSPA_Community_Outbox::preview($queued);
    $payload = is_wp_error($json) ? null : json_decode($json, true);
    $types = array();
    foreach ((array) ($payload['evidence'] ?? array()) as $item) {
        $types[] = $item['type'];
    }
    sspa_outbox_t(!is_wp_error($json) && strlen($json) === (int) $queued['uncompressed_bytes'], 'exact uncompressed payload retained');
    sspa_outbox_t(hash_equals($queued['payload_sha256'], hash('sha256', $queued['payload_gzip'])), 'compressed payload hash matches immutable bytes');
    sspa_outbox_t(($payload['payload_schema']['major'] ?? 0) === 1 && ($payload['run']['run_uuid'] ?? '') === $run_uuid, 'payload and run identities are versioned');
    sspa_outbox_t(in_array('sspa/page-profile', $types, true) && in_array('sspa/excimer-profile', $types, true), 'page and Excimer evidence included');
    sspa_outbox_t(in_array('sspa/plugin-toggle-spot', $types, true) && in_array('sspa/finding', $types, true), 'toggle and finding evidence included');
    sspa_outbox_t(false === strpos($json, 'private-product-name') && false === strpos($json, 'private@example.com'), 'private URL fragments and SQL literals excluded');

    $again = SSPA_Community_Outbox::queue_run($run_id);
    sspa_outbox_t(!is_wp_error($again) && (int) $again['id'] === (int) $queued['id'], 'queueing the same run is idempotent');

    SSPA_Community_Outbox::begin_attempt($queued);
    SSPA_Community_Outbox::failed($queued['id'], new WP_Error('test_receiver_offline', 'offline'), false);
    $retry = SSPA_Community_Outbox::get($queued['id']);
    sspa_outbox_t(
        'retry' === $retry['state']
        && !empty($retry['next_attempt'])
        && hash_equals($queued['payload_sha256'], hash('sha256', $retry['payload_gzip'])),
        'receiver outage schedules a retry without changing payload bytes'
    );

    $privacy = SSPA_Community_Privacy::validate(array('request_body' => 'secret'));
    sspa_outbox_t(is_wp_error($privacy) && 'sspa_privacy_forbidden_key' === $privacy->get_error_code(), 'privacy gate rejects forbidden fields');
    $bare_host_collision = SSPA_Community_Privacy::validate(array('component_inventory' => array(array('slug' => 'sspa-wp'))));
    sspa_outbox_t(true === $bare_host_collision, 'bare development hostname does not falsely reject a matching public slug');
}

if (!is_wp_error($queued)) {
    $wpdb->delete(SSPA_Schema::table('submission_events'), array('outbox_id' => (int) $queued['id']));
    $wpdb->delete(SSPA_Schema::table('submission_outbox'), array('id' => (int) $queued['id']));
}
$wpdb->delete(SSPA_Schema::table('findings'), array('id' => $finding_id));
$wpdb->delete(SSPA_Schema::table('component_stats'), array('id' => $component_id));
$wpdb->delete(SSPA_Schema::table('profiles'), array('id' => $profile_id));
$wpdb->delete(SSPA_Schema::table('runs'), array('id' => $run_id));
if (null === $old_optin) {
    delete_option('sspa_share_optin');
} else {
    update_option('sspa_share_optin', $old_optin, false);
}
wp_clear_scheduled_hook('sspa_submission_worker_event');
sspa_outbox_t(true, 'test records cleaned up');
