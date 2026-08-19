<?php
// Stable outbound WordPress HTTP API inventory for Scalability Pro and community evidence.
//
// The raw capture blob is deliberately NOT the contract: this case plants two supported
// capture-schema generations and reads them only through SSPA_Report::http_calls(). It proves
// aggregation, purpose/safety classification and the privacy boundary that removes every query
// value and variable path id. The same local object must be reachable through Abilities and
// WP-CLI, while the opt-in community exporter emits one privacy-safe record per aggregate.

function sspa_http_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

function sspa_http_capture($schema, $is_admin, $calls) {
    return array(
        'schema' => $schema,
        'overview' => array('is_admin' => $is_admin),
        'http' => array(
            'count' => count($calls),
            'total_ms' => array_sum(array_map(function ($call) {
                return isset($call['ms']) ? (float) $call['ms'] : 0;
            }, $calls)),
            'calls' => $calls,
        ),
    );
}

function sspa_http_profile($run_id, $page_key, $variant, $capture) {
    global $wpdb;
    $wpdb->insert(SSPA_Schema::table('profiles'), array(
        'run_id' => $run_id,
        'page_key' => $page_key,
        'url' => home_url('/?sspa-http-contract=' . rawurlencode($page_key)),
        'method' => 'GET',
        'variant' => $variant,
        'plugin_set_hash' => '',
        'object_cache_mode' => 'normal',
        'samples' => wp_json_encode(array(array('wall_ms' => 100, 'code' => 200))),
        'ttfb_ms' => 100,
        'page_gen_ms' => 90,
        'sql_ms' => 5,
        'sql_count' => 5,
        'http_ms' => isset($capture['http']['total_ms']) ? $capture['http']['total_ms'] : 0,
        'http_count' => isset($capture['http']['count']) ? $capture['http']['count'] : 0,
        'peak_mem_bytes' => 1048576,
        'response_code' => 200,
        'profile_blob' => gzcompress(wp_json_encode($capture), 6),
        'created' => gmdate('Y-m-d H:i:s'),
    ));
    return (int) $wpdb->insert_id;
}

function sspa_http_find($calls, $host, $path = null) {
    foreach ((array) $calls as $call) {
        if ($host === $call['host'] && (null === $path || $path === $call['path'])) {
            return $call;
        }
    }
    return null;
}

global $wpdb;
$now = gmdate('Y-m-d H:i:s');
$run_uuid = wp_generate_uuid4();
$wpdb->insert(SSPA_Schema::table('runs'), array(
    'run_uuid' => $run_uuid,
    'blog_id' => 1,
    'run_type' => 'baseline',
    'measurement_version' => 1,
    'trigger_source' => 'test',
    'status' => 'done',
    'plugin_set' => wp_json_encode(array('components' => array(
        array('type' => 'plugin', 'slug' => 'pixel-manager-pro-for-woocommerce', 'version' => '1.64.0'),
        array('type' => 'plugin', 'slug' => 'wplab-update-client', 'version' => '1.0.0'),
        array('type' => 'plugin', 'slug' => 'woocommerce-gateway-stripe', 'version' => '10.8.5'),
        array('type' => 'plugin', 'slug' => 'usage-reporter', 'version' => '1.0.0'),
        array('type' => 'plugin', 'slug' => 'shipping-quoter', 'version' => '1.0.0'),
        array('type' => 'plugin', 'slug' => 'wordpress-update-client', 'version' => '1.0.0'),
        array('type' => 'theme', 'slug' => 'storefront', 'version' => '4.6.0'),
    ))),
    'plugin_set_hash' => md5('http-contract'),
    'started' => $now,
    'finished' => $now,
));
$run_id = (int) $wpdb->insert_id;
$profile_ids = array();

$freemius = array(
    'scheme' => 'https',
    'url' => 'api.freemius.com/v1/installs/987654/updates/latest.json',
    'q' => 'include_upgrade_notice=1&is_premium=true&newer_than=1.2.3&sdk_version=2.12.0&url=https%3A%2F%2Fprivate.example%2F&email=owner%40private.example',
    'method' => 'GET',
    'ms' => 540.1,
    'code' => 200,
    'blocking' => true,
    'sslverify' => false,
    'component' => 'pixel-manager-pro-for-woocommerce',
    'ctype' => 'plugin',
    'caller' => 'Freemius_Api_WordPress::RemoteRequest',
    'trace' => 'Freemius_Api_WordPress::RemoteRequest < fs_dynamic_init',
);
$profile_ids[] = sspa_http_profile($run_id, 'wp-admin-scalability-pro', 'admin', sspa_http_capture(3, true, array(
    $freemius,
    array_merge($freemius, array('ms' => 543.1)),
    array_merge($freemius, array('ms' => 537.1)),
    array(
        'scheme' => 'http', 'url' => 'update.wplab.de/beta/', 'q' => 'wc-api=upgrade-api',
        'method' => 'GET', 'ms' => 120, 'code' => 200, 'blocking' => true, 'sslverify' => true,
        'component' => 'wplab-update-client', 'ctype' => 'plugin',
        'caller' => 'WPLab_Updater::check', 'trace' => 'WPLab_Updater::check',
    ),
    array(
        'scheme' => 'https', 'url' => 'telemetry.vendor.example/collect', 'q' => 'site=https%3A%2F%2Fprivate.example%2F&usage=full',
        'method' => 'POST', 'ms' => 4, 'code' => 204, 'blocking' => true, 'sslverify' => true,
        'component' => 'usage-reporter', 'ctype' => 'plugin',
        'caller' => 'Usage_Reporter::send', 'trace' => 'Usage_Reporter::send',
    ),
)));

// Schema 4 stands in for a future storage migration. The public result is driven by semantic
// call fields, not by an exact private blob version.
$profile_ids[] = sspa_http_profile($run_id, 'admin-plugins', 'admin', sspa_http_capture(4, true, array(
    array(
        'scheme' => 'https', 'url' => 'api.wordpress.org/plugins/update-check/1.1/', 'q' => 'plugins=secret-payload',
        'method' => 'POST', 'ms' => 25, 'code' => 200, 'blocking' => true, 'sslverify' => true,
        'component' => 'wordpress-update-client', 'ctype' => 'plugin',
        'caller' => 'wp_update_plugins', 'trace' => 'wp_update_plugins',
    ),
)));
$profile_ids[] = sspa_http_profile($run_id, 'wc-checkout', 'customer', sspa_http_capture(3, false, array(
    array(
        'scheme' => 'https', 'url' => 'api.stripe.com/v1/payment_intents/pi_3AbCdEf0123456789/confirm', 'q' => 'client_secret=should-never-leave',
        'method' => 'POST', 'ms' => 900, 'code' => 200, 'blocking' => true, 'sslverify' => true,
        'component' => 'woocommerce-gateway-stripe', 'ctype' => 'plugin',
        'caller' => 'WC_Stripe_API::request', 'trace' => 'WC_Stripe_API::request',
    ),
)));
$profile_ids[] = sspa_http_profile($run_id, 'admin-edit-order', 'admin', sspa_http_capture(3, true, array(
    array(
        'scheme' => 'https', 'url' => 'quotes.shipping.example/orders/44182/rates', 'q' => 'postcode=SECRET',
        'method' => 'POST', 'ms' => 70, 'code' => 200, 'blocking' => true, 'sslverify' => true,
        'component' => 'shipping-quoter', 'ctype' => 'plugin',
        'caller' => 'Shipping_Quoter::rates', 'trace' => 'Shipping_Quoter::rates',
    ),
)));

sspa_http_t(method_exists('SSPA_Report', 'http_calls'), 'stable SSPA_Report::http_calls surface exists');
if (!method_exists('SSPA_Report', 'http_calls')) {
    foreach ($profile_ids as $profile_id) {
        $wpdb->delete(SSPA_Schema::table('profiles'), array('id' => $profile_id));
    }
    $wpdb->delete(SSPA_Schema::table('runs'), array('id' => $run_id));
    return;
}

$result = SSPA_Report::http_calls($run_id);
sspa_http_t(is_array($result) && 1 === $result['schema'] && $run_id === $result['run_id'], 'local HTTP inventory is versioned and run-bound');
sspa_http_t(!empty($result['complete']) && empty($result['incomplete_reasons']), 'complete coverage is explicit');

$fs = sspa_http_find($result['calls'], 'api.freemius.com', '/v1/installs/{id}/updates/latest.json');
sspa_http_t($fs && 3 === $fs['calls'] && abs(1620.3 - $fs['total_ms']) < 0.01 && 543.1 === $fs['worst_ms'], 'three Freemius checks aggregate by normalised endpoint, method and component');
sspa_http_t($fs && 'licence' === $fs['purpose'] && 'high' === $fs['purpose_confidence'] && 'review' === $fs['block_safety'], 'Freemius install check is a high-confidence licence review');
sspa_http_t($fs && false === $fs['sslverify'] && array('email', 'include_upgrade_notice', 'is_premium', 'newer_than', 'sdk_version', 'url') === $fs['query_keys'], 'method metadata and sorted query-key names survive, values do not');

$wplab = sspa_http_find($result['calls'], 'update.wplab.de');
sspa_http_t($wplab && 'http' === $wplab['scheme'] && 'update' === $wplab['purpose'], 'non-HTTPS WPLab endpoint remains HTTP and is classified as update');
$wporg = sspa_http_find($result['calls'], 'api.wordpress.org');
sspa_http_t($wporg && 'update' === $wporg['purpose'], 'WordPress.org update check is update, not telemetry');
$telemetry = sspa_http_find($result['calls'], 'telemetry.vendor.example');
sspa_http_t($telemetry && 4.0 === $telemetry['total_ms'] && 'telemetry' === $telemetry['purpose'], 'telemetry below the slow-finding threshold still appears');
$stripe = sspa_http_find($result['calls'], 'api.stripe.com');
sspa_http_t($stripe && 'payment' === $stripe['purpose'] && 'never' === $stripe['block_safety'], 'Stripe payment call can never be offered for blocking');
$shipping = sspa_http_find($result['calls'], 'quotes.shipping.example');
sspa_http_t($shipping && 'never' === $shipping['block_safety'] && in_array('order_fulfilment_surface', $shipping['block_safety_reasons'], true), 'order/shipping call is diagnostic evidence but never safe to block');

$json = wp_json_encode($result);
sspa_http_t(false === strpos($json, '987654') && false === strpos($json, '44182') && false === strpos($json, 'pi_3AbCdEf0123456789'), 'variable path ids never reach the public result');
sspa_http_t(false === strpos($json, 'private.example') && false === strpos($json, 'owner%40') && false === strpos($json, 'SECRET') && false === strpos($json, 'should-never-leave'), 'query values and credentials never reach the public result');

if (function_exists('wp_get_ability')) {
    wp_set_current_user(1);
    $ability = wp_get_ability('super-speedy-performance/get-http-calls');
    $ability_result = $ability ? $ability->execute(array('run_id' => $run_id)) : null;
    sspa_http_t(is_object($ability) && $ability_result === $result, 'Abilities API returns the identical HTTP inventory object');
}
if (class_exists('WP_CLI')) {
    $cli_json = WP_CLI::runcommand('sspa http-calls --run-id=' . $run_id . ' --format=json', array('return' => true, 'launch' => true, 'exit_error' => false));
    sspa_http_t(json_decode((string) $cli_json, true) == $result, 'WP-CLI returns the identical HTTP inventory object');
}

$payload = SSPA_Community_Exporter::build($run_id, wp_generate_uuid4(), SSPA_Community_Schema::canonical_time(), 'manual');
$shared = array();
if (is_array($payload)) {
    foreach ((array) $payload['evidence'] as $item) {
        if ('sspa/http-call' === $item['type']) {
            $shared[] = $item;
        }
    }
}
sspa_http_t(is_array($payload) && !is_wp_error($payload) && count($shared) === count($result['calls']), 'opt-in community payload carries every external HTTP aggregate');
$shared_json = wp_json_encode($shared);
sspa_http_t(false === strpos($shared_json, 'private.example') && false === strpos($shared_json, home_url('/')), 'shared HTTP evidence passes the site-identity privacy boundary');

$old_consent = get_option('sspa_share_consent_version', null);
update_option('sspa_share_consent_version', 2, false);
$legacy_consent_payload = SSPA_Community_Exporter::build($run_id, wp_generate_uuid4(), SSPA_Community_Schema::canonical_time(), 'automatic');
$legacy_consent_types = is_array($legacy_consent_payload) ? wp_list_pluck($legacy_consent_payload['evidence'], 'type') : array();
sspa_http_t(is_array($legacy_consent_payload) && 2 === $legacy_consent_payload['client']['consent_version'] && !in_array('sspa/http-call', $legacy_consent_types, true), 'existing version 2 consent never silently widens to HTTP endpoint sharing');
if (null === $old_consent) {
    delete_option('sspa_share_consent_version');
} else {
    update_option('sspa_share_consent_version', $old_consent, false);
}

// A pre-contract capture remains readable but must say that its coverage is incomplete.
$legacy_uuid = wp_generate_uuid4();
$wpdb->insert(SSPA_Schema::table('runs'), array(
    'run_uuid' => $legacy_uuid, 'blog_id' => 1, 'run_type' => 'spot', 'measurement_version' => 1,
    'trigger_source' => 'test', 'status' => 'done', 'plugin_set' => wp_json_encode(array()),
    'plugin_set_hash' => md5('http-legacy'), 'started' => $now, 'finished' => $now,
));
$legacy_run_id = (int) $wpdb->insert_id;
$legacy_profile_id = sspa_http_profile($legacy_run_id, 'home', 'anon', array(
    'schema' => 2,
    'overview' => array('is_admin' => false),
    'http' => array('calls' => array(array(
        'url' => 'legacy.example/check', 'q' => 'site', 'method' => 'GET', 'ms' => 10,
        'code' => 200, 'blocking' => true, 'component' => 'legacy-plugin', 'ctype' => 'plugin',
        'caller' => 'Legacy::check',
    ))),
));
$legacy = SSPA_Report::http_calls($legacy_run_id);
sspa_http_t(is_array($legacy) && empty($legacy['complete']) && in_array('old_capture_schema', $legacy['incomplete_reasons'], true) && in_array('no_wp_admin_profiles', $legacy['incomplete_reasons'], true), 'old storage remains readable and names why coverage is partial');

$wpdb->delete(SSPA_Schema::table('profiles'), array('id' => $legacy_profile_id));
$wpdb->delete(SSPA_Schema::table('runs'), array('id' => $legacy_run_id));
foreach ($profile_ids as $profile_id) {
    $wpdb->delete(SSPA_Schema::table('profiles'), array('id' => $profile_id));
}
$wpdb->delete(SSPA_Schema::table('runs'), array('id' => $run_id));
sspa_http_t(true, 'HTTP contract fixtures removed');
