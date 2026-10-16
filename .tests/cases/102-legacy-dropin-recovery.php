<?php
// Recovery of the state versions before 0.43.4 could leave on an upgrade. These are
// fixtures for the OLD state, not calls to a supported displacement operation.
defined('ABSPATH') || exit;
require __DIR__ . '/../lib/native-guard.php';
$GLOBALS['sspa_102_failures'] = 0;
function sspa_102_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
    if (!$ok) { $GLOBALS['sspa_102_failures']++; }
}
$active = SSPA_Run_Controller::active_run_id();
if ($active) { SSPA_Run_Controller::cancel($active); }
$db = WP_CONTENT_DIR . '/db.php';
$oc = WP_CONTENT_DIR . '/object-cache.php';
$db_hold = $db . '.sspa-hold';
$oc_hold = $oc . '.sspa-hold';
$db_fixture = "<?php\n/*\nPlugin Name: Legacy database owner\n*/\n";
$oc_fixture = file_get_contents($oc);
if (!$oc_fixture) { throw new RuntimeException('Legacy recovery test requires the real object-cache drop-in'); }
// Plant an upgrade-mid-hold state, then cancel the real run through the controller.
$run = SSPA_Run_Controller::start(array('type' => 'spot', 'page_keys' => array('home'), 'user_id' => 1));
if (is_wp_error($run)) { throw new RuntimeException($run->get_error_message()); }
file_put_contents($db_hold, $db_fixture);
rename($oc, $oc_hold);
update_option('sspa_dropin_hold', array('owner' => 'Legacy database owner', 'time' => time()), false);
update_option('sspa_oc_hold', time(), false);
SSPA_Run_Controller::cancel($run);
sspa_102_t(file_get_contents($db) === $db_fixture && !file_exists($db_hold), 'cancelling an upgraded run restores its legacy foreign db.php');
sspa_102_t(file_get_contents($oc) === $oc_fixture && !file_exists($oc_hold), 'cancelling an upgraded run restores its legacy object-cache.php');
sspa_102_t(!get_option('sspa_dropin_hold') && !get_option('sspa_oc_hold'), 'successful legacy recovery clears both hold records');
// The persisted queue of an old site-wide cache run must fail visibly on upgrade,
// not continue with an unsupported cache state and produce a misleading comparison.
unlink($db);
SSPA_Helper_Files::ensure_installed();
$legacy_cache = SSPA_Run_Controller::start(array('type' => 'cache_impact', 'page_keys' => array('home'), 'user_id' => 1));
if (is_wp_error($legacy_cache)) { throw new RuntimeException($legacy_cache->get_error_message()); }
$queue = SSPA_Run_Queue::get($legacy_cache);
$queue['oc_mode'] = 'sitewide';
SSPA_Run_Queue::save($legacy_cache, $queue);
file_put_contents($db_hold, $db_fixture);
rename($oc, $oc_hold);
update_option('sspa_dropin_hold', array('owner' => 'Legacy database owner', 'time' => time()), false);
update_option('sspa_oc_hold', time(), false);
SSPA_Run_Controller::process_batch($legacy_cache);
$row = SSPA_Run_Controller::run_row($legacy_cache);
sspa_102_t($row && 'failed' === $row['status'] && strpos($row['notes'], 'no longer supported') !== false, 'an inherited site-wide cache queue fails with an explicit explanation');
sspa_102_t(file_get_contents($db) === $db_fixture && file_get_contents($oc) === $oc_fixture && !file_exists($db_hold) && !file_exists($oc_hold), 'failing the inherited queue restores both legacy holds byte-for-byte');
// Never overwrite a new owner, including a dangling symlink.
file_put_contents($db_hold, $db_fixture . "// held\n");
file_put_contents($oc_hold, $oc_fixture);
sspa_102_t(false === SSPA_Helper_Files::restore_legacy_db_hold() && file_get_contents($db) === $db_fixture && file_exists($db_hold), 'database recovery preserves a conflicting foreign file and its hold');
sspa_102_t(false === SSPA_Helper_Files::restore_legacy_cache_hold() && file_get_contents($oc) === $oc_fixture && file_exists($oc_hold), 'cache recovery preserves a conflicting foreign file and its hold');
unlink($db); unlink($oc);
symlink(WP_CONTENT_DIR . '/missing-legacy-owner-db.php', $db);
symlink(WP_CONTENT_DIR . '/missing-legacy-owner-cache.php', $oc);
sspa_102_t(false === SSPA_Helper_Files::restore_legacy_db_hold() && is_link($db), 'database recovery preserves a conflicting dangling symlink');
sspa_102_t(false === SSPA_Helper_Files::restore_legacy_cache_hold() && is_link($oc), 'cache recovery preserves a conflicting dangling symlink');
SSPA_Helper_Files::ensure_installed();
sspa_102_t(is_link($db) && !file_exists($db), 'helper installation never writes through a foreign dangling symlink');
unlink($db); unlink($oc);
SSPA_Helper_Files::restore_legacy_db_hold();
SSPA_Helper_Files::restore_legacy_cache_hold();
// A legacy symlink can lose its vendor target while held. Recover the link itself.
unlink($db);
symlink(WP_CONTENT_DIR . '/removed-vendor-db.php', $db_hold);
sspa_102_t(SSPA_Helper_Files::restore_legacy_db_hold() && is_link($db) && !is_link($db_hold), 'recovery returns a legacy database symlink even when its vendor target was removed');
unlink($db);
file_put_contents($db, $db_fixture . "// held\n");
// Stale idle holds go back through the boot recovery entry point.
rename($db, $db_hold); rename($oc, $oc_hold);
update_option('sspa_dropin_hold', array('owner' => 'Legacy database owner', 'time' => time()), false);
update_option('sspa_oc_hold', time(), false);
SSPA_Helper_Files::ensure_installed();
SSPA_Helper_Files::stale_hold_check();
sspa_102_t(file_exists($db) && file_exists($oc) && !file_exists($db_hold) && !file_exists($oc_hold), 'boot recovery restores both idle legacy holds');
// Uninstall must restore legacy files regardless of the data-retention choice. It
// must also leave ordinary foreign files alone. Keep the choice, not plugin removal.
$options = get_option('sspa_options', array());
update_option('sspa_options', array_merge($options, array('remove_data_on_uninstall' => false)));
rename($db, $db_hold); rename($oc, $oc_hold);
SSPA_Helper_Files::ensure_installed();
define('WP_UNINSTALL_PLUGIN', 'super-speedy-performance-analysis/super-speedy-performance-analysis.php');
require SSPA_PLUGIN_DIR . 'uninstall.php';
sspa_102_t(file_exists($db) && file_exists($oc) && !file_exists($db_hold) && !file_exists($oc_hold), 'uninstall restores both legacy holds after removing only our helper');
$expected_db = file_get_contents($db);
require SSPA_PLUGIN_DIR . 'uninstall.php';
sspa_102_t(file_get_contents($db) === $expected_db && file_get_contents($oc) === $oc_fixture, 'uninstall preserves foreign database and cache files byte-for-byte');
update_option('sspa_options', $options);
SSPA_Helper_Files::ensure_installed();
exit($GLOBALS['sspa_102_failures'] ? 1 : 0);
