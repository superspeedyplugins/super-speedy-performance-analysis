<?php
// Production checks must register independently of any historical collector state and
// restore the site's ordinary identity and credentials byte-for-byte afterwards.

require_once dirname(__DIR__) . '/manual/production-test-identity.php';

function sspa_pti_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

$collector = 'https://collector.identity-test.invalid';
$secret_option = 'sspa_collector_secret_' . md5($collector);
$marker_option = 'sspa_collector_registered_' . md5($collector);
$original = array(
    'sspa_install_uuid' => wp_generate_uuid4(),
    $secret_option => str_repeat('ab', 32),
    $marker_option => '2026-08-20 00:00:00',
);
$site_snapshot = array();

foreach ($original as $option_name => $value) {
    $site_snapshot[$option_name] = array(
        'exists' => false !== get_option($option_name, false),
        'value' => get_option($option_name, null),
    );
    update_option($option_name, $value, false);
}

$snapshot = sspa_production_test_identity_begin($collector);
$test_uuid = get_option('sspa_install_uuid');

sspa_pti_t(wp_is_uuid($test_uuid, 4) && $test_uuid !== $original['sspa_install_uuid'], 'production check receives a fresh version-4 installation UUID');
sspa_pti_t(false === get_option($secret_option, false), 'production check cannot reuse an historical collector secret');
sspa_pti_t(false === get_option($marker_option, false), 'production check must exercise collector registration');

update_option($secret_option, str_repeat('cd', 32), false);
update_option($marker_option, '2026-08-20 01:00:00', false);
sspa_production_test_identity_restore($snapshot);

$restored = true;
foreach ($original as $option_name => $value) {
    $restored = $restored && get_option($option_name) === $value;
}
sspa_pti_t($restored, 'the site identity, credential and registration marker are restored exactly');

sspa_production_test_identity_restore($site_snapshot);
