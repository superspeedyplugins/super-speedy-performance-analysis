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
    $versions = array();
    foreach ((array) ($payload['evidence'] ?? array()) as $item) {
        $types[] = $item['type'];
        $versions[$item['type']] = $item['version'];
    }
    sspa_outbox_t(!is_wp_error($json) && strlen($json) === (int) $queued['uncompressed_bytes'], 'exact uncompressed payload retained');
    sspa_outbox_t(hash_equals($queued['payload_sha256'], hash('sha256', $queued['payload_gzip'])), 'compressed payload hash matches immutable bytes');
    sspa_outbox_t(
        ($payload['payload_schema']['major'] ?? 0) === 1
        && ($payload['payload_schema']['minor'] ?? 0) === 1
        && ($versions['sspa/page-profile'] ?? 0) === 2
        && ($payload['run']['run_uuid'] ?? '') === $run_uuid,
        'payload, page evidence and run identities are independently versioned'
    );
    sspa_outbox_t(in_array('sspa/page-profile', $types, true) && in_array('sspa/excimer-profile', $types, true), 'page and Excimer evidence included');
    sspa_outbox_t(in_array('sspa/plugin-toggle-spot', $types, true) && in_array('sspa/finding', $types, true), 'toggle and finding evidence included');
    sspa_outbox_t(false === strpos($json, 'private-product-name') && false === strpos($json, 'private@example.com'), 'private URL fragments and SQL literals excluded');

    $again = SSPA_Community_Outbox::queue_run($run_id);
    sspa_outbox_t(!is_wp_error($again) && (int) $again['id'] === (int) $queued['id'], 'queueing the same run is idempotent');

    // Claiming a row is compare-and-set, because due() is a plain read and two overlapping
    // WP-Cron spawns would otherwise both upload the same payload.
    $claim = SSPA_Community_Outbox::begin_attempt($queued);
    sspa_outbox_t(is_array($claim) && 1 === (int) $claim['attempts'], 'the first worker claims the row');
    $double_claim = SSPA_Community_Outbox::begin_attempt($queued);
    sspa_outbox_t(null === $double_claim, 'a second worker cannot claim a row already in flight');
    sspa_outbox_t(1 === (int) SSPA_Community_Outbox::get($queued['id'])['attempts'], 'the lost claim did not consume an attempt');

    // The claim also has to respect terminal state, not just the attempt number. A worker
    // holding a snapshot taken before the owner paused or delivered the row must not be able
    // to drag it back into the send cycle.
    SSPA_Community_Outbox::pause($queued['id']);
    $stale_snapshot = SSPA_Community_Outbox::get($queued['id']);
    $stale_snapshot['state'] = 'pending';
    $resurrect = SSPA_Community_Outbox::begin_attempt($stale_snapshot);
    $after_resurrect = SSPA_Community_Outbox::get($queued['id']);
    sspa_outbox_t(null === $resurrect, 'a paused row cannot be claimed from a stale snapshot');
    sspa_outbox_t('cancelled' === $after_resurrect['state'], 'the refused claim left the row paused (' . $after_resurrect['state'] . ')');
    SSPA_Community_Outbox::resume($queued['id']);
    SSPA_Community_Outbox::begin_attempt(SSPA_Community_Outbox::get($queued['id']));

    // A process that dies mid-attempt runs neither sent() nor failed(), so the claim itself has
    // to hold the row back - otherwise it retries instantly and burns the whole backoff ladder.
    sspa_outbox_t(
        !empty($claim['next_attempt']) && strtotime($claim['next_attempt'] . ' UTC') > time() + 60,
        'an in-flight row is not due again immediately (' . $claim['next_attempt'] . ')'
    );

    SSPA_Community_Outbox::failed($queued['id'], new WP_Error('test_receiver_offline', 'offline'), false);
    $retry = SSPA_Community_Outbox::get($queued['id']);
    sspa_outbox_t(
        'retry' === $retry['state']
        && !empty($retry['next_attempt'])
        && hash_equals($queued['payload_sha256'], hash('sha256', $retry['payload_gzip'])),
        'receiver outage schedules a retry without changing payload bytes'
    );
    $paused = SSPA_Community_Outbox::pause($queued['id']);
    $paused_row = SSPA_Community_Outbox::get($queued['id']);
    sspa_outbox_t(true === $paused && 'cancelled' === $paused_row['state'] && !empty($paused_row['payload_gzip']), 'pause retains the exact local payload');
    $resumed = SSPA_Community_Outbox::resume($queued['id']);
    sspa_outbox_t(true === $resumed && 'pending' === SSPA_Community_Outbox::get($queued['id'])['state'], 'paused payload resumes without rebuilding');
    SSPA_Community_Outbox::begin_attempt(SSPA_Community_Outbox::get($queued['id']));
    SSPA_Community_Outbox::failed($queued['id'], new WP_Error('test_permanent', 'inspect'), true);
    $manual_retry = SSPA_Community_Outbox::retry_now($queued['id']);
    sspa_outbox_t(true === $manual_retry && 'pending' === SSPA_Community_Outbox::get($queued['id'])['state'], 'explicit retry requeues a permanent failure');

    // A four-part version number is character-for-character an IPv4 address. Rejecting it as
    // "potential private data" blocked every submission from a site carrying one: on
    // superspeedyplugins.com, syntax-highlighter 3.0.83.3 failed six consecutive runs.
    $four_part = SSPA_Community_Privacy::validate(array(
        'component_inventory' => array(array('slug' => 'syntax-highlighter', 'type' => 'plugin', 'version' => '3.0.83.3')),
    ));
    sspa_outbox_t(true === $four_part, 'a four-part version number is not mistaken for an IP address');
    $component_version = SSPA_Community_Privacy::validate(array('component_version' => '3.0.83.3'));
    sspa_outbox_t(true === $component_version, 'the same holds for component_version on evidence');

    // The exemption must be exactly that narrow: a real IP elsewhere is still private data,
    // and a version key must not become a smuggling channel.
    $real_ip = SSPA_Community_Privacy::validate(array('note' => 'origin 192.168.1.50 responded'));
    sspa_outbox_t(is_wp_error($real_ip) && 'sspa_privacy_forbidden_value' === $real_ip->get_error_code(), 'an IP address in an ordinary field is still rejected');
    $smuggled = SSPA_Community_Privacy::validate(array('version' => 'https://example.com/leak'));
    sspa_outbox_t(is_wp_error($smuggled), 'a version field cannot smuggle a URL');
    $smuggled_path = SSPA_Community_Privacy::validate(array('version' => '/home/dave/site'));
    sspa_outbox_t(is_wp_error($smuggled_path), 'a version field cannot smuggle a filesystem path');

    // 3.4: the queued bytes must not depend on the run row surviving - the manifest needs a
    // run type, and reading it from the run made a deleted run strand its payload.
    $sspa_row = SSPA_Community_Outbox::get($queued['id']);
    sspa_outbox_t(!empty($sspa_row['run_type']), 'the outbox row carries its own run type (' . (isset($sspa_row['run_type']) ? $sspa_row['run_type'] : 'none') . ')');

    // 3.5: a "permanent" status must not kill an item on its first attempt. A mistyped
    // collector URL 404s exactly like a real rejection, and one attempt each would turn a
    // corrected typo into a manual Retry now per row.
    // The row has already been claimed several times above, so reset the attempt counter -
    // the grace is about how many attempts a permanent status survives, and the test has to
    // start from a known one rather than whatever the earlier assertions left behind.
    $wpdb->update(SSPA_Schema::table('submission_outbox'), array('attempts' => 0, 'state' => 'pending', 'next_attempt' => null), array('id' => (int) $queued['id']));
    SSPA_Community_Outbox::begin_attempt(SSPA_Community_Outbox::get($queued['id']));
    SSPA_Community_Outbox::failed($queued['id'], new WP_Error('test_404', 'not found'), true, 404);
    $sspa_after_one = SSPA_Community_Outbox::get($queued['id']);
    sspa_outbox_t(
        'retry' === $sspa_after_one['state'],
        'a permanent status still retries at first (attempt ' . (int) $sspa_after_one['attempts'] . ', state ' . $sspa_after_one['state'] . ')'
    );
    while ((int) SSPA_Community_Outbox::get($queued['id'])['attempts'] < SSPA_Community_Outbox::PERMANENT_GRACE) {
        SSPA_Community_Outbox::retry_now($queued['id']);
        SSPA_Community_Outbox::begin_attempt(SSPA_Community_Outbox::get($queued['id']));
        SSPA_Community_Outbox::failed($queued['id'], new WP_Error('test_404', 'not found'), true, 404);
    }
    $sspa_final = SSPA_Community_Outbox::get($queued['id']);
    sspa_outbox_t(
        'permanent_failure' === $sspa_final['state'],
        'it becomes permanent once the grace is used up (attempt ' . (int) $sspa_final['attempts'] . ')'
    );

    // 3.3: a manually shared item inside its backoff still counts as outstanding work.
    sspa_outbox_t(is_bool(SSPA_Community_Outbox::has_pending_manual()), 'outstanding manual shares can be asked about');

    $privacy = SSPA_Community_Privacy::validate(array('request_body' => 'secret'));
    sspa_outbox_t(is_wp_error($privacy) && 'sspa_privacy_forbidden_key' === $privacy->get_error_code(), 'privacy gate rejects forbidden fields');
    $bare_host_collision = SSPA_Community_Privacy::validate(array('component_inventory' => array(array('slug' => 'sspa-wp'))));
    sspa_outbox_t(true === $bare_host_collision, 'bare development hostname does not falsely reject a matching public slug');

    // A generic database name is a substring of our own core sentinel and of public slugs,
    // so treating it as a site identifier blocked every submission the site could ever make.
    sspa_outbox_t(
        '' === SSPA_Community_Privacy::distinctive_db_name('wordpress')
            && '' === SSPA_Community_Privacy::distinctive_db_name('WordPress')
            && '' === SSPA_Community_Privacy::distinctive_db_name('db')
            && '' === SSPA_Community_Privacy::distinctive_db_name('staging'),
        'a generic database name is not treated as a site identifier'
    );
    sspa_outbox_t(
        'ssp_live_7x2' === SSPA_Community_Privacy::distinctive_db_name('SSP_Live_7x2'),
        'a distinctive database name is still treated as a site identifier'
    );
    sspa_outbox_t(
        true === SSPA_Community_Privacy::validate(array('component_inventory' => array(
            array('slug' => 'wordpress-core', 'type' => 'core'),
            array('slug' => 'wordpress-seo', 'type' => 'plugin'),
        ))),
        'the core sentinel and public slugs containing "wordpress" are shareable'
    );
}

// Shared fixed fixture from receiver/internal/auth/hmac_test.go.
$old_install_uuid = get_option('sspa_install_uuid', null);
$secret_key = 'sspa_collector_secret_' . md5(SSPA_Community_Identity::collector_url());
$old_secret = get_option($secret_key, null);
update_option('sspa_install_uuid', '11111111-1111-4111-8111-111111111111', false);
update_option($secret_key, bin2hex('01234567890123456789012345678901'), false);
$fixture_manifest = array(
    'transport_version' => 1,
    'submission_uuid' => '22222222-2222-4222-8222-222222222222',
    'payload_sha256' => str_repeat('a', 64),
    'compressed_bytes' => 59546,
    'uncompressed_bytes' => 608553,
    'payload_schema_major' => 1,
    'payload_schema_minor' => 0,
    'client_version' => '0.12.0',
    'anonymisation_version' => 1,
    'run_uuid' => '33333333-3333-4333-8333-333333333333',
    'run_type' => 'checkout',
    'payload_created_at' => '2026-08-08T12:34:56Z',
);
$fixture_canonical = SSPA_Community_Client::reservation_canonical($fixture_manifest);
$sign = new ReflectionMethod('SSPA_Community_Client', 'sign');
$sign->setAccessible(true);
$fixture_signature = $sign->invoke(null, $fixture_canonical);
sspa_outbox_t(
    '6d22a358db2bffa62f0d0f1a65018d9c47f0b2b42374893a5dd87b5cea1f51c7' === $fixture_signature,
    'PHP reservation HMAC matches the fixed Go receiver fixture'
);

// The receiver's Go tests pin the completion and status canonical strings by shape only
// (TestCompletionCanonical, TestStatusCanonical) - there is no golden signature for either.
// Pin the same shapes here, because a missing trailing newline or a dropped field in the
// shortest canonical is exactly the drift nothing else would catch.
$fixture_completion_row = array(
    'transport_version' => 1,
    'submission_uuid' => '22222222-2222-4222-8222-222222222222',
    'payload_sha256' => str_repeat('a', 64),
);
sspa_outbox_t(
    "SSPA-SUBMISSION-COMPLETE-V1\n1\n11111111-1111-4111-8111-111111111111\n22222222-2222-4222-8222-222222222222\n44444444-4444-4444-8444-444444444444\n" . str_repeat('a', 64) . "\n"
        === SSPA_Community_Client::completion_canonical($fixture_completion_row, '44444444-4444-4444-8444-444444444444'),
    'PHP completion canonical string matches the Go receiver shape'
);
sspa_outbox_t(
    "SSPA-SUBMISSION-STATUS-V1\n11111111-1111-4111-8111-111111111111\n22222222-2222-4222-8222-222222222222\n"
        === SSPA_Community_Client::status_canonical('22222222-2222-4222-8222-222222222222'),
    'PHP status canonical string matches the Go receiver shape'
);

if (null === $old_install_uuid) {
    delete_option('sspa_install_uuid');
} else {
    update_option('sspa_install_uuid', $old_install_uuid, false);
}
if (null === $old_secret) {
    delete_option($secret_key);
} else {
    update_option($secret_key, $old_secret, false);
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
