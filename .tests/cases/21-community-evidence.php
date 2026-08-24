<?php
// One coherent versioned payload for every current analysis run type.

function sspa_evidence_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

function sspa_evidence_profile($run_id, $page_key, $capture = null) {
    global $wpdb;
    $wpdb->insert(SSPA_Schema::table('profiles'), array(
        'run_id' => $run_id,
        'page_key' => $page_key,
        'url' => home_url('/private-' . $page_key . '/?customer=private@example.com'),
        'method' => ('flow-place-order' === $page_key) ? 'POST' : 'GET',
        'variant' => 'anon',
        'plugin_set_hash' => md5('community-evidence'),
        'object_cache_mode' => 'normal',
        'samples' => wp_json_encode(array(array('wall_ms' => 80, 'code' => 200))),
        'ttfb_ms' => 80,
        'page_gen_ms' => 70,
        'sql_ms' => 8,
        'sql_count' => 12,
        'http_ms' => 3,
        'http_count' => 1,
        'peak_mem_bytes' => 2097152,
        'response_code' => 200,
        'profile_blob' => $capture ? gzcompress(wp_json_encode($capture), 6) : null,
        'created' => gmdate('Y-m-d H:i:s'),
    ));
    return (int) $wpdb->insert_id;
}

function sspa_evidence_run($type, $status = 'done', $notes = null, $share_context = null) {
    global $wpdb;
    $now = gmdate('Y-m-d H:i:s');
    $wpdb->insert(SSPA_Schema::table('runs'), array(
        'run_uuid' => wp_generate_uuid4(),
        'blog_id' => 1,
        'run_type' => $type,
        'measurement_version' => 1,
        'trigger_source' => 'test',
        'status' => $status,
        'plugin_set' => wp_json_encode(array('components' => array(
            array('type' => 'plugin', 'slug' => 'woocommerce', 'version' => '10.1.0'),
            array('type' => 'theme', 'slug' => 'storefront', 'version' => '4.6.0'),
        ))),
        'plugin_set_hash' => md5('community-evidence'),
        'share_context' => $share_context ? wp_json_encode($share_context) : null,
        'started' => $now,
        'finished' => $now,
        'notes' => $notes ? wp_json_encode($notes) : null,
    ));
    return (int) $wpdb->insert_id;
}

function sspa_evidence_payload($run_id, &$outbox_ids) {
    $row = SSPA_Community_Outbox::queue_run($run_id);
    if (is_wp_error($row)) {
        return $row;
    }
    $outbox_ids[] = (int) $row['id'];
    $json = SSPA_Community_Outbox::preview($row);
    return is_wp_error($json) ? $json : json_decode($json, true);
}

function sspa_evidence_of_type($payload, $type) {
    $found = array();
    foreach ((array) ($payload['evidence'] ?? array()) as $item) {
        if ($type === $item['type']) {
            $found[] = $item;
        }
    }
    return $found;
}

global $wpdb;
$old_optin = get_option('sspa_share_optin', null);
$old_consent = get_option('sspa_share_consent_version', null);
update_option('sspa_share_optin', 1, false);
update_option('sspa_share_consent_version', SSPA_Community_Schema::CONSENT_VERSION, false);

$run_ids = array();
$profile_ids = array();
$impact_ids = array();
$outbox_ids = array();
$capture = array('profile' => array(
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
    'phases' => array('render_and_output' => array(
        'total_ms' => 2.5,
        'functions' => array(array('fn' => 'WC_Cart->calculate_totals', 'component' => 'woocommerce', 'self_ms' => 1.5)),
    )),
));

// Baseline.
$baseline_id = sspa_evidence_run('baseline');
$run_ids[] = $baseline_id;
$profile_ids[] = sspa_evidence_profile($baseline_id, 'home', $capture);
$baseline = sspa_evidence_payload($baseline_id, $outbox_ids);
sspa_evidence_t(!is_wp_error($baseline), 'baseline payload built');
if (!is_wp_error($baseline)) {
    sspa_evidence_t(count(sspa_evidence_of_type($baseline, 'sspa/page-profile')) === 1, 'baseline contains its page profile');
    sspa_evidence_t(count(sspa_evidence_of_type($baseline, 'sspa/excimer-profile')) === 1, 'baseline contains Excimer functions, components and phases');
}

// Plugin Impact Analysis with a scoped isolation impact.
$deep_id = sspa_evidence_run('deep');
$run_ids[] = $deep_id;
$profile_ids[] = sspa_evidence_profile($deep_id, 'shop', $capture);
$wpdb->insert(SSPA_Schema::table('plugin_impacts'), array(
    'blog_id' => 1,
    'plugin' => 'woocommerce',
    'page_key' => 'shop',
    'method' => 'single_out',
    'object_cache_mode' => 'normal',
    'delta_ttfb_ms' => 42,
    'delta_sql_ms' => 8,
    'delta_http_ms' => 1,
    'delta_mem_bytes' => 1024,
    'delta_queries' => 3,
    'noise_floor_ms' => 5,
    'confidence' => 'measured',
    'baseline_run_id' => $baseline_id,
    'test_run_id' => $deep_id,
    'created' => gmdate('Y-m-d H:i:s'),
));
$impact_ids[] = (int) $wpdb->insert_id;
$deep = sspa_evidence_payload($deep_id, $outbox_ids);
$deep_impacts = is_wp_error($deep) ? array() : sspa_evidence_of_type($deep, 'sspa/plugin-impact');
sspa_evidence_t(count($deep_impacts) === 1, 'deep payload contains only its scoped plugin impact');
if ($deep_impacts) {
    sspa_evidence_t(
        $deep_impacts[0]['data']['test_run_uuid'] === $deep['run']['run_uuid']
        && $deep_impacts[0]['data']['component_version'] === '10.1.0',
        'deep impact carries run relationship and measurement-time plugin version'
    );
}

// Persistent object-cache comparison.
$cache_id = sspa_evidence_run('cache_impact', 'done', array(
    'type' => 'cache_impact',
    'verified' => true,
    'components' => array('woocommerce' => array(
        'type' => 'plugin', 'on' => 10, 'off' => 30,
        'sql_ms_on' => 5, 'sql_ms_off' => 18, 'saved_pct' => 67,
    )),
));
$run_ids[] = $cache_id;
$profile_ids[] = sspa_evidence_profile($cache_id, 'home', null);
$cache = sspa_evidence_payload($cache_id, $outbox_ids);
$cache_rows = is_wp_error($cache) ? array() : sspa_evidence_of_type($cache, 'sspa/cache-impact');
sspa_evidence_t(count($cache_rows) === 1 && true === $cache_rows[0]['data']['verified'], 'cache-impact payload contains the verified comparison');

// Arbitrary URL page, intentionally with no retained detailed blob.
$adhoc_id = sspa_evidence_run('adhoc');
$run_ids[] = $adhoc_id;
$profile_ids[] = sspa_evidence_profile($adhoc_id, 'url-private-customer-page', null);
$adhoc = sspa_evidence_payload($adhoc_id, $outbox_ids);
$adhoc_rows = is_wp_error($adhoc) ? array() : sspa_evidence_of_type($adhoc, 'sspa/adhoc-page-profile');
$adhoc_pages = is_wp_error($adhoc) ? array() : sspa_evidence_of_type($adhoc, 'sspa/page-profile');
sspa_evidence_t(count($adhoc_rows) === 1 && 'custom-frontend' === $adhoc_rows[0]['data']['page_class'], 'adhoc URL is reduced to a safe page classification');
sspa_evidence_t($adhoc_pages && false === $adhoc_pages[0]['data']['detailed_capture_available'], 'pruned detail is explicit rather than represented as invented zeroes');

// Complete checkout with a payment split and linked page evidence.
$checkout_id = sspa_evidence_run('checkout', 'done', array(
    'inventory' => array('checkout_type' => 'blocks'),
    'flow' => array('payment_mode' => 'no_payment'),
));
$run_ids[] = $checkout_id;
$checkout_capture = $capture;
$checkout_capture['marks'] = array('payment_complete' => 30);
$checkout_capture['mail'] = array('count' => 1, 'total_construct_ms' => 2, 'calls' => array());
$checkout_capture['http'] = array('calls' => array(array('ms' => 3)));
$profile_ids[] = sspa_evidence_profile($checkout_id, 'flow-place-order', $checkout_capture);
$profile_ids[] = sspa_evidence_profile($checkout_id, 'flow-order-received', $capture);
$checkout = sspa_evidence_payload($checkout_id, $outbox_ids);
$checkout_rows = is_wp_error($checkout) ? array() : sspa_evidence_of_type($checkout, 'sspa/checkout-flow');
$checkout_linked = $checkout_rows && !empty($checkout_rows[0]['data']['steps'][0]['page_profile_uuid']);
sspa_evidence_t(count($checkout_rows) === 1 && 2 === $checkout_rows[0]['version'], 'checkout payload uses checkout-flow evidence v2');
sspa_evidence_t($checkout_linked, 'checkout steps link to their page-profile evidence');

// Failed checkout with valid partial evidence remains honest about outcome.
$partial_id = sspa_evidence_run('checkout', 'failed', array('inventory' => array('checkout_type' => 'classic')));
$run_ids[] = $partial_id;
$profile_ids[] = sspa_evidence_profile($partial_id, 'flow-view-cart', $capture);
$partial = sspa_evidence_payload($partial_id, $outbox_ids);
$partial_rows = is_wp_error($partial) ? array() : sspa_evidence_of_type($partial, 'sspa/checkout-flow');
sspa_evidence_t(
    !is_wp_error($partial)
    && 'failed' === $partial['run']['outcome']
    && 'run_failed' === $partial['run']['incomplete_reason']
    && $partial_rows
    && 'failed' === $partial_rows[0]['data']['outcome'],
    'partial checkout is shareable and explicitly failed'
);

$all_json = wp_json_encode(array($baseline, $deep, $cache, $adhoc, $checkout, $partial));
$host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
sspa_evidence_t(false === strpos($all_json, 'private-customer-page') && false === strpos($all_json, 'private@example.com'), 'all run-type payloads exclude URL-derived and customer data');
sspa_evidence_t(!$host || false === strpos($all_json, $host), 'all run-type payloads exclude the site host');

foreach ($outbox_ids as $outbox_id) {
    $wpdb->delete(SSPA_Schema::table('submission_events'), array('outbox_id' => $outbox_id));
    $wpdb->delete(SSPA_Schema::table('submission_outbox'), array('id' => $outbox_id));
}
foreach ($impact_ids as $impact_id) {
    $wpdb->delete(SSPA_Schema::table('plugin_impacts'), array('id' => $impact_id));
}
foreach ($profile_ids as $profile_id) {
    $wpdb->delete(SSPA_Schema::table('profiles'), array('id' => $profile_id));
}
foreach ($run_ids as $run_id) {
    $wpdb->delete(SSPA_Schema::table('runs'), array('id' => $run_id));
}
if (null === $old_optin) {
    delete_option('sspa_share_optin');
} else {
    update_option('sspa_share_optin', $old_optin, false);
}
if (null === $old_consent) {
    delete_option('sspa_share_consent_version');
} else {
    update_option('sspa_share_consent_version', $old_consent, false);
}
wp_clear_scheduled_hook('sspa_submission_worker_event');
sspa_evidence_t(true, 'run-type fixtures cleaned up');
