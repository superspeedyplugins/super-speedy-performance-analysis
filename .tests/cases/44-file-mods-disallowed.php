<?php
// DISALLOW_FILE_MODS: when the site owner forbids plugins from writing files, this plugin
// must not write any, must say WHY, and must still be able to remove its own.
//
// Added 16 August 2026 alongside the guard itself. Query Monitor gates its db.php symlink
// on the same constant; this plugin writes two files outside its own folder
// (wp-content/db.php and mu-plugins/sspa-loader.php), so it has strictly more to honour.
//
// The constant is defined at the top of this file, which is exactly what wp-config.php does.
// Each case file runs in its own `wp` process, so no other case sees it.
//
// The helper files are SNAPSHOTTED and restored byte-for-byte at the end. They have to be
// removed for the "did not write" assertions to mean anything, and they cannot be
// reinstalled afterwards because ensure_installed() is (correctly) refusing in this process.

define('DISALLOW_FILE_MODS', true);

function sspa_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

$sspa_mu = SSPA_Helper_Files::mu_path();
$sspa_dropin = SSPA_Helper_Files::dropin_path();

// Snapshot before touching anything. null = it was not there to begin with.
$sspa_saved_mu = file_exists($sspa_mu) ? file_get_contents($sspa_mu) : null;
$sspa_saved_dropin = (SSPA_Helper_Files::dropin_status() === 'ours') ? file_get_contents($sspa_dropin) : null;

// ---------------------------------------------------------------- preconditions
// Remove our helper files, so ensure_installed() has real work to do. Without this it would
// return true from the "content already matches" branch and the case would pass whether or
// not the guard exists - testing nothing.
if (file_exists($sspa_mu)) {
    unlink($sspa_mu);
}
if (SSPA_Helper_Files::dropin_status() === 'ours') {
    unlink($sspa_dropin);
}
sspa_t(!file_exists($sspa_mu), 'precondition: mu-loader absent, so a write would be observable');
sspa_t(SSPA_Helper_Files::file_mods_blocked() === true, 'file_mods_blocked() true with the constant set');

// ---------------------------------------------------------------- writes refused
$sspa_res = SSPA_Helper_Files::ensure_installed();
sspa_t($sspa_res['mu'] === false && $sspa_res['dropin'] === false, 'ensure_installed() reports it installed nothing');
sspa_t(!file_exists($sspa_mu), 'mu-loader was NOT written to disk');
sspa_t(SSPA_Helper_Files::dropin_status() === 'absent', 'db.php was NOT written to disk');

sspa_t(SSPA_Helper_Files::hold_foreign_dropin() === false, 'hold_foreign_dropin() refuses');
sspa_t(SSPA_Helper_Files::hold_object_cache() === false, 'hold_object_cache() refuses');
sspa_t(!file_exists(WP_CONTENT_DIR . '/db.php.sspa-hold'), 'no hold file created');

$sspa_qm = SSPA_Helper_Files::replace_stale_qm_dropin();
sspa_t(is_wp_error($sspa_qm) && $sspa_qm->get_error_code() === 'sspa_file_mods_disallowed',
    'replace_stale_qm_dropin() returns sspa_file_mods_disallowed');

$sspa_obs = SSPA_Traffic_Helper::install(array(
    'collection_id' => 1, 'collect_until' => time() + 60, 'outcomes_until' => time() + 60,
    'event_id_stop' => 100, 'origin_sample_modulus' => 1, 'key_option' => 'sspa_test_key',
));
sspa_t(is_wp_error($sspa_obs) && $sspa_obs->get_error_code() === 'sspa_file_mods_disallowed',
    'traffic observer install refuses with sspa_file_mods_disallowed');
sspa_t(!file_exists(WPMU_PLUGIN_DIR . '/sspa-traffic-observer.php'), 'traffic observer was NOT written');

// ---------------------------------------------------------------- health reports the real reason
$sspa_health = SSPA_Helper_Files::health();
sspa_t(!empty($sspa_health['file_mods_blocked']), 'health() carries file_mods_blocked');
sspa_t($sspa_health['mu'] === false, 'health() reports the loader as not installed');

// ---------------------------------------------------------------- a run is blocked, with the right reason
// The point of the flag: the user must not be sent to chmod a directory whose permissions
// are fine. Profiling genuinely cannot work without the loader, so the run must stop.
$sspa_run = SSPA_Run_Controller::start(array('type' => 'spot', 'page_keys' => array('home')));
sspa_t(is_wp_error($sspa_run) && $sspa_run->get_error_code() === 'sspa_file_mods_disallowed',
    'a run is blocked with sspa_file_mods_disallowed');
if (is_wp_error($sspa_run)) {
    $sspa_msg = $sspa_run->get_error_message();
    sspa_t(strpos($sspa_msg, 'DISALLOW_FILE_MODS') !== false, 'the message names DISALLOW_FILE_MODS');
    sspa_t(strpos($sspa_msg, 'not writable') === false, 'the message does not blame directory permissions');
}

// ---------------------------------------------------------------- removal is NOT blocked
// Deliberately asymmetric. Refusing to remove our own files would strand a db.php the owner
// can no longer get rid of from the UI, which is worse than the thing the constant prevents.
file_put_contents($sspa_dropin, "<?php\n// " . SSPA_Helper_Files::SIGNATURE . "\n");
sspa_t(SSPA_Helper_Files::dropin_status() === 'ours', 'planted a drop-in of ours to remove');
SSPA_Helper_Files::remove_all();
sspa_t(!file_exists($sspa_dropin), 'remove_all() STILL removes our files under DISALLOW_FILE_MODS');

// ---------------------------------------------------------------- restore the snapshot
if ($sspa_saved_mu !== null) {
    file_put_contents($sspa_mu, $sspa_saved_mu);
}
if ($sspa_saved_dropin !== null) {
    file_put_contents($sspa_dropin, $sspa_saved_dropin);
}
sspa_t($sspa_saved_mu === null || file_get_contents($sspa_mu) === $sspa_saved_mu,
    'mu-loader restored byte-for-byte for the following cases');
sspa_t($sspa_saved_dropin === null || file_get_contents($sspa_dropin) === $sspa_saved_dropin,
    'db.php shim restored byte-for-byte for the following cases');
