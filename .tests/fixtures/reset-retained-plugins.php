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
// Case 99's malformed-SQL privacy probe must not run on unrelated anonymous pages.
update_option('zz_sspa_export_review', 0, true);
// Retained case89 MU fixture reads this at template_redirect. Store the neutral value
// autoloaded so unrelated anonymous requests neither sleep nor issue a fixture SQL query.
update_option('sspa_traffic_delay_ms', 0, true);
wp_set_option_autoload('sspa_traffic_delay_ms', true);
wp_cache_flush();

// Cases declare vendor activation themselves (case 88 activates genuine QM).
// Fixture preparation is allowed to archive a test drop-in on this guarded site;
// production code must never displace it. No removed swap API is used here.
if (SSPA_Helper_Files::qm_plugin_active()) {
    deactivate_plugins('query-monitor/query-monitor.php', true);
    echo "ENTRY RESET: deactivated retained Query Monitor fixture\n";
}
if (in_array(SSPA_Helper_Files::dropin_status(), array('qm', 'foreign'), true)) {
    $was_symlink = is_link(SSPA_Helper_Files::dropin_path());
    $retained = SSPA_PLUGIN_DIR . '.data/test-entry-dropins/' . basename(rtrim(ABSPATH, '/'));
    wp_mkdir_p($retained);
    if (!rename(SSPA_Helper_Files::dropin_path(), $retained . '/db.php')) {
        throw new RuntimeException('Cannot archive preceding test drop-in');
    }
    // Discard the old symlink resolution before creating a file at the same path.
    clearstatcache(true, SSPA_Helper_Files::dropin_path());
    SSPA_Helper_Files::ensure_installed();
    echo "ENTRY RESET: archived test drop-in and installed PA drop-in\n";
    // Clearing CLI's realpath does not clear the serving SAPI. Match case 88's
    // documented vendor-link boundary before the next case sends HTTP requests.
    $settle_seconds = $was_symlink ? max(3, (int) ini_get('realpath_cache_ttl') + 5) : 3;
    sleep($settle_seconds);
}
