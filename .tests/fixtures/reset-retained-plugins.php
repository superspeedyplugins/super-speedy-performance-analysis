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
wp_cache_flush();

// Reset only this suite's retained foreign drop-in, preserving it through the real rename API.
$dropin = WP_CONTENT_DIR . '/db.php';
if (is_file($dropin) && strpos(file_get_contents($dropin), 'SSPA retained QM fixture') !== false) {
    $repaired = SSPA_Helper_Files::replace_stale_qm_dropin();
    if (is_wp_error($repaired)) throw new RuntimeException($repaired->get_error_message());
    echo "ENTRY RESET: archived retained synthetic QM drop-in and installed PA drop-in\n";
}
