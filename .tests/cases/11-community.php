<?php
// Community round trip: install the hub on this same site, register, submit anonymised
// data, verify anonymisation, then prove the signed rules feed updates recommendations
// and that tampered feeds are rejected.

function sspa_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

global $wpdb;

// --- Install the hub companion plugin (copied out of our repo's hub/ folder) ---
$src = WP_PLUGIN_DIR . '/super-speedy-performance-analysis/hub';
$dst = WP_PLUGIN_DIR . '/super-speedy-performance-hub';
if (!is_dir($dst)) {
    mkdir($dst);
    mkdir($dst . '/includes');
}
foreach (array('super-speedy-performance-hub.php', 'includes/class-ssph-schema.php', 'includes/class-ssph-keys.php', 'includes/class-ssph-rest.php') as $file) {
    copy($src . '/' . $file, $dst . '/' . $file);
}
$activated = activate_plugin('super-speedy-performance-hub/super-speedy-performance-hub.php');
wp_cache_flush();
sspa_t(!is_wp_error($activated), 'hub plugin activated');
sspa_t(strlen((string) get_option('ssph_pubkey')) > 100, 'hub keypair generated');
sspa_t(is_array(get_option('ssph_rules')) && get_option('ssph_rules'), 'hub rules seeded from bundled snapshot');
sleep(3); // opcache: apache must serve the hub REST routes

// --- Point the analysis plugin at the local hub ---
update_option('sspa_hub_url', home_url(), false);
update_option('sspa_rules_pubkey', get_option('ssph_pubkey'), false);
update_option('sspa_share_optin', 1, false);
delete_option('sspa_install_secret');
$wpdb->delete(SSPH_Schema::table('installs'), array('install_uuid' => SSPA_Anonymiser::install_uuid())); // fresh registration on re-runs

// --- Payload anonymisation contract ---
$payload = SSPA_Anonymiser::build();
if (is_wp_error($payload)) {
    echo 'FAIL: payload: ' . $payload->get_error_message() . "\n";
    return;
}
$json = wp_json_encode($payload);
$host = parse_url(home_url(), PHP_URL_HOST);
sspa_t(strpos($json, (string) $host) === false, 'payload contains no domain');
sspa_t(!preg_match('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', $json), 'payload contains no email addresses');
sspa_t(!empty($payload['install']) && wp_is_uuid($payload['install']), 'random install uuid present');
sspa_t(!empty($payload['profiles']) && !isset($payload['profiles'][0]['url']), 'profiles carry page keys, not URLs');
sspa_t(!empty($payload['site']['sizes']['products']) && strpos($payload['site']['sizes']['products'], '<') === 0, 'counts bucketed (' . $payload['site']['sizes']['products'] . ')');
$has_impacts = !empty($payload['impacts']);
sspa_t($has_impacts, 'measured impacts included (' . count($payload['impacts']) . ')');
foreach ($payload['findings'] as $f) {
    if (isset($f['evidence']['sql'])) {
        sspa_t(false, 'raw SQL leaked in findings evidence');
        break;
    }
}

// --- Submit: register + signed submission ---
$result = SSPA_Submitter::submit();
sspa_t(true === $result, 'submission accepted' . (is_wp_error($result) ? ' - ' . $result->get_error_message() : ''));

$install_row = $wpdb->get_row($wpdb->prepare(
    'SELECT * FROM ' . SSPH_Schema::table('installs') . ' WHERE install_uuid = %s',
    SSPA_Anonymiser::install_uuid()
), ARRAY_A);
sspa_t($install_row !== null, 'install registered at hub');

$submission = $wpdb->get_row('SELECT * FROM ' . SSPH_Schema::table('submissions') . ' ORDER BY id DESC LIMIT 1', ARRAY_A);
sspa_t($submission && 'quarantined' === $submission['status'], 'submission stored quarantined');
$stored = $submission ? json_decode((string) gzuncompress($submission['payload']), true) : null;
sspa_t(is_array($stored) && $stored['install'] === SSPA_Anonymiser::install_uuid(), 'stored payload unpacks and matches install');

if ($has_impacts) {
    $flat = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . SSPH_Schema::table('plugin_impacts'));
    sspa_t($flat >= 1, "impacts flattened at hub ($flat rows)");
}

// --- Auth hardening ---
$replay = wp_remote_post(home_url('/wp-json/ssph/v1/register'), array(
    'sslverify' => false,
    'headers' => array('Content-Type' => 'application/json'),
    'body' => wp_json_encode(array('install' => SSPA_Anonymiser::install_uuid())),
));
sspa_t((int) wp_remote_retrieve_response_code($replay) === 409, 're-registration refused (secret never re-issued)');

$forged = wp_remote_post(home_url('/wp-json/ssph/v1/submissions'), array(
    'sslverify' => false,
    'headers' => array(
        'Content-Type' => 'application/json',
        'X-SSPA-Install' => SSPA_Anonymiser::install_uuid(),
        'X-SSPA-Signature' => str_repeat('0', 64),
    ),
    'body' => $json,
));
sspa_t((int) wp_remote_retrieve_response_code($forged) === 401, 'forged signature rejected');

// --- Rules feed round trip ---
$bundled_body = SSPA_Rules::recommendation('query_loop');
$rules = get_option('ssph_rules');
$rules['recommendations']['query_loop']['body'] = 'COMMUNITY UPDATED: batch your lookups.';
update_option('ssph_rules', $rules, false);
update_option('ssph_rules_version', 2, false);

$refresh = SSPA_Rules_Feed::refresh();
sspa_t(true === $refresh, 'rules feed fetched + signature verified' . (is_wp_error($refresh) ? ' - ' . $refresh->get_error_message() : ''));
SSPA_Rules::flush();
$rec = SSPA_Rules::recommendation('query_loop');
sspa_t(strpos($rec['body'], 'COMMUNITY UPDATED') === 0, 'recommendation text updated from the feed');

// Tamper: modify the cached feed body without re-signing - it must be ignored.
$cached = get_transient(SSPA_Rules_Feed::CACHE_KEY);
$cached['rules']['recommendations']['query_loop']['body'] = 'EVIL: deactivate your security plugin.';
set_transient(SSPA_Rules_Feed::CACHE_KEY, $cached, DAY_IN_SECONDS);
SSPA_Rules::flush();
$rec = SSPA_Rules::recommendation('query_loop');
sspa_t(strpos($rec['body'], 'EVIL') === false, 'tampered feed ignored');
sspa_t($rec['body'] === $bundled_body['body'], 'fell back to bundled snapshot text');

// --- Clean up feed state so other tests see bundled rules ---
delete_transient(SSPA_Rules_Feed::CACHE_KEY);
SSPA_Rules::flush();
update_option('sspa_share_optin', 0, false);
sspa_t(true, 'cleaned up');
