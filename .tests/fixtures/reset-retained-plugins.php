<?php
require_once __DIR__ . '/../lib/native-guard.php';
// A new PHP case starts from ordinary plugins. Finished-case sources/results remain.
$slugs = array('sspa-turnstile-fixture','sspa-bad-plugin','sspa-caller-fixture','sspa-explain-fixture','sspa-digest-fixture','sspa-option-fixture','sspa-scoped-fixture','sspa-panel-fixture','sspa-dep-fixture','sspa-dependant-fixture','sspa-grp-free','sspa-grp-pro','sspa-guard-dep','sspa-guard-reactor','sspa-admin-save-fixture','sspa-workflow-fixture','sspa-blind-plugin','sspa-friendly-plugin','sspa-slow-integration','sspa-mail-observer','sspa-mail-api-mimic','sspa-renamed-session-cookie');
foreach ((array)get_option('active_plugins', array()) as $file) {
    if (in_array(dirname($file), $slugs, true)) {
        // No fixture deactivation hooks: those are exercised explicitly by their own cases.
        deactivate_plugins($file, true);
        echo 'ENTRY RESET: deactivated retained suite fixture ' . $file . "\n";
    }
}
// Retain the SQL fixture, but disarm it before unrelated measurements.
update_option('zz_pa_export_probe_armed', 0, true);
// Retained case89 MU fixture reads this at template_redirect. Store the neutral value
// autoloaded so unrelated anonymous requests neither sleep nor issue a fixture SQL query.
update_option('sspa_traffic_delay_ms', 0, true);
wp_set_option_autoload('sspa_traffic_delay_ms', true);
wp_cache_flush();

// Cases declare vendor activation themselves (case88 activates genuine QM). Return to
// ordinary PA capture at entry and retain the displaced QM file through the real API.
if (SSPA_Helper_Files::qm_plugin_active()) {
    deactivate_plugins('query-monitor/query-monitor.php', true);
    echo "ENTRY RESET: deactivated retained Query Monitor fixture\n";
}
if (SSPA_Helper_Files::dropin_is_stale_qm()) {
    $repaired = SSPA_Helper_Files::replace_stale_qm_dropin();
    if (is_wp_error($repaired)) throw new RuntimeException($repaired->get_error_message());
    echo "ENTRY RESET: archived retained QM drop-in and installed PA drop-in\n";
}
