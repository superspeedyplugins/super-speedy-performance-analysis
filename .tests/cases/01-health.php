<?php
// Tables exist, helper files install, health reports green.

function sspa_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

global $wpdb;

foreach (array('runs', 'profiles', 'component_stats', 'findings', 'plugin_impacts', 'site_metrics', 'captures') as $name) {
    $table = SSPA_Schema::table($name);
    sspa_t($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table, "table $name exists");
}

sspa_t(strlen((string) SSPA_Token::secret()) === 64, 'secret generated');

// Self-heal: a crashed earlier test (06 swaps db.php) can leave a fake drop-in behind.
// This is a disposable test site, so reset db.php unconditionally before asserting.
if (SSPA_Helper_Files::dropin_status() !== 'ours' && file_exists(WP_CONTENT_DIR . '/db.php')) {
    unlink(WP_CONTENT_DIR . '/db.php');
}
if (file_exists(WP_CONTENT_DIR . '/db.php.sspa-hold')) {
    unlink(WP_CONTENT_DIR . '/db.php.sspa-hold');
}
SSPA_Helper_Files::ensure_installed();
$health = SSPA_Helper_Files::health();
sspa_t($health['mu'] === true, 'mu-loader installed and current');
sspa_t($health['dropin'] === 'ours', 'db.php shim installed (' . $health['dropin'] . ')');
sspa_t($health['hold'] === false, 'no stale db.php hold');

// The installed files must have placeholders replaced.
$mu = file_get_contents(WPMU_PLUGIN_DIR . '/sspa-loader.php');
sspa_t(strpos($mu, '%%SSPA_') === false, 'mu-loader placeholders replaced');
$shim = file_get_contents(WP_CONTENT_DIR . '/db.php');
sspa_t(strpos($shim, '%%SSPA_') === false, 'shim placeholders replaced');
