<?php
// Tools tab (phase 3): environment detection and generated installation steps.
//
// The most important assertions here are the NEGATIVE ones. This feature exists to tell a
// user what to run; it must never run anything itself, and the steps it generates must be
// specific to this server rather than generic documentation.

function sspa_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

global $wpdb;

// --- Environment detection ---
$env = SSPA_Tools::environment();
sspa_t(!empty($env['php']) && $env['php'] === PHP_VERSION, 'PHP version detected: ' . $env['php']);
sspa_t(!empty($env['sapi']), 'SAPI detected: ' . $env['sapi']);
sspa_t(!empty($env['distro']), 'operating system detected: ' . $env['distro']);
sspa_t(in_array($env['pkg'], array('apt', 'dnf', 'apk', 'brew', 'unknown'), true), 'package manager inferred: ' . $env['pkg']);
sspa_t(!empty($env['mysql']), 'database version detected: ' . $env['mysql']);
sspa_t(strpos($env['restart'], 'restart') !== false, 'restart command generated: ' . $env['restart']);
// The command must match this init system. Alpine has no systemctl, macOS has neither.
if ($env['pkg'] === 'apk') {
    sspa_t(strpos($env['restart'], 'systemctl') === false && strpos($env['restart'], 'rc-service') !== false,
        'Alpine gets an OpenRC command, not systemctl: ' . $env['restart']);
} elseif ($env['os'] === 'Darwin') {
    sspa_t(strpos($env['restart'], 'brew') !== false, 'macOS gets a brew command: ' . $env['restart']);
} else {
    sspa_t(strpos($env['restart'], 'systemctl') !== false, 'systemd host gets a systemctl command: ' . $env['restart']);
}
// Package names must match the distro's convention, not a guessed one.
$deps = SSPA_Tools::install_steps('excimer');
if ($env['pkg'] === 'apk') {
    sspa_t(strpos($deps[0]['code'], 'php' . PHP_MAJOR_VERSION . PHP_MINOR_VERSION . '-pecl-excimer') !== false,
        'Alpine package names carry the full version (php83-pecl-excimer, not php8-...): ' . $deps[0]['code']);
}

// --- performance_schema ladder ---
$ps = SSPA_Tools::performance_schema();
sspa_t(in_array($ps['status'], array(
    SSPA_Tools::STATUS_AVAILABLE, SSPA_Tools::STATUS_BLOCKED, SSPA_Tools::STATUS_MISSING
), true), 'performance_schema status resolved: ' . $ps['status'] . ' (' . $ps['detail'] . ')');
sspa_t($ps['db_user'] !== '', 'database user identified for the GRANT: ' . $ps['db_user']);

// The GRANT must name this site's REAL user, properly quoted, and ask for nothing beyond
// SELECT. A generated GRANT that over-asks would get the ticket refused, rightly.
$grant = SSPA_Tools::grant_sql();
sspa_t(strpos($grant, 'GRANT SELECT ON performance_schema.*') === 0, 'GRANT asks for SELECT on performance_schema only');
sspa_t(strpos($grant, 'ALL PRIVILEGES') === false && stripos($grant, 'INSERT') === false && stripos($grant, 'UPDATE') === false,
    'GRANT asks for no write privilege of any kind');
$user_part = explode('@', $ps['db_user']);
sspa_t(strpos($grant, "'" . $user_part[0] . "'") !== false, 'GRANT names the real database user, quoted');

// --- Capabilities ---
$caps = SSPA_Tools::capabilities();
sspa_t(isset($caps['explain']) && $caps['explain']['status'] === SSPA_Tools::STATUS_ACTIVE, 'EXPLAIN reported as active (it is built and in use)');
sspa_t($caps['explain']['used'] === true, 'EXPLAIN marked as actually used by the plugin');

// Honesty rule both ways: extensions nothing reads must never claim to be in use, and
// excimer (read since phase 5) must track the extension's actual presence in THIS
// process - never a hardcoded yes.
foreach (array('tideways_xhprof', 'spx') as $key) {
    sspa_t(isset($caps[$key]) && $caps[$key]['used'] === false, "$key correctly marked not-yet-used");
}
sspa_t(isset($caps['excimer']) && $caps['excimer']['used'] === extension_loaded('excimer'), 'excimer used-flag tracks the extension (' . (extension_loaded('excimer') ? 'loaded' : 'absent') . ' here)');
// Ordering is messaging: excimer is the headline capability and leads the list.
sspa_t(array_key_first($caps) === 'excimer', 'excimer tops the capability list');
// performance_schema IS read as of phase 4, so it must no longer claim otherwise.
sspa_t($caps['performance_schema']['used'] === true, 'performance_schema marked as used (phase 4 reads it)');

// --- Generated steps are specific to THIS server ---
$steps = SSPA_Tools::install_steps('excimer');
$all = '';
foreach ($steps as $s) {
    sspa_t(!empty($s['title']) && !empty($s['why']) && !empty($s['code']), 'step has a title, a reason and a command: ' . $s['title']);
    $all .= $s['code'] . "\n";
}
if (in_array($env['pkg'], array('apt', 'dnf', 'apk'), true)) {
    // Excimer left PECL (frozen at 1.2.6), so packaged distros must get the distro
    // package: one install command, no compile, and NO hand-written ini - the package
    // ships and enables its own, and a leftover manual ini would double-load it.
    sspa_t(count($steps) === 3, 'packaged distro gets the short path (' . count($steps) . ' steps)');
    sspa_t(strpos($all, '-excimer') !== false, 'steps install the distro package');
    if ($env['pkg'] === 'apt') {
        // Multi-PHP boxes (deb.sury.org): unversioned php-excimer installs for the NEWEST
        // PHP on the box, not the one the site runs, so the versioned name must lead.
        sspa_t(strpos($all, 'php' . $env['php_short'] . '-excimer') !== false,
            'apt command targets the PHP version this site runs');
    }
    // Plain `php` on the CLI can be a different version than the site - the verify step
    // must name the versioned binary on platforms that have one.
    if (in_array($env['pkg'], array('apt', 'apk'), true)) {
        sspa_t(!preg_match('/^php -m/m', $all), 'verify step uses the versioned php binary, not plain php');
    }
    sspa_t(strpos($all, 'pecl install') === false, 'no pecl on a distro that packages excimer');
    sspa_t(strpos($all, 'extension=excimer.so') === false, 'no hand-written ini - the package enables itself');
} else {
    // macOS / unrecognised distro: pecl still carries excimer 1.2.6 and stays the fallback.
    sspa_t(count($steps) >= 4, 'excimer fallback steps generated (' . count($steps) . ' steps)');
    sspa_t(strpos($all, 'pecl install excimer') !== false, 'fallback steps include the pecl install line');
    sspa_t(strpos($all, 'extension=excimer.so') !== false, 'fallback steps write a dedicated ini file');
}
sspa_t(strpos($all, $env['restart']) !== false, 'steps include the restart command for this SAPI');

// tideways_xhprof has NEVER been on PECL - "pecl install tideways_xhprof" fails on every
// system - so its steps must be a source build on every platform.
$tw = '';
foreach (SSPA_Tools::install_steps('tideways_xhprof') as $s) {
    $tw .= $s['code'] . "\n";
}
sspa_t(strpos($tw, 'pecl install') === false, 'tideways_xhprof never told to use pecl (it is not on PECL)');
sspa_t(strpos($tw, 'github.com/tideways/php-xhprof-extension') !== false, 'tideways_xhprof built from its real source repo');

$ps_steps = SSPA_Tools::install_steps('performance_schema');
sspa_t(count($ps_steps) >= 1, 'performance_schema steps generated (' . count($ps_steps) . ')');
$ps_all = '';
foreach ($ps_steps as $s) {
    $ps_all .= $s['code'] . "\n";
}
sspa_t(strpos($ps_all, 'GRANT SELECT') !== false, 'performance_schema steps include the GRANT');

// --- The host message ---
$msg = SSPA_Tools::host_message('excimer');
sspa_t(strlen($msg) > 100, 'host message generated for excimer');
sspa_t(strpos($msg, $env['php']) !== false, 'host message states the PHP version');
sspa_t(strpos($msg, 'open source') !== false, 'host message reassures the host about what it is');
sspa_t(strpos(SSPA_Tools::host_message('performance_schema'), 'read-only') !== false, 'performance_schema host message states it is read-only');

// --- The hard rules: this code must never act on the server ---
$source = file_get_contents(WP_PLUGIN_DIR . '/super-speedy-performance-analysis/includes/class-sspa-tools.php');
foreach (array('exec', 'shell_exec', 'passthru', 'proc_open', 'popen', 'system') as $fn) {
    sspa_t(!preg_match('/\b' . $fn . '\s*\(/', $source), "SSPA_Tools never calls $fn()");
}
sspa_t(strpos($source, 'file_put_contents') === false, 'SSPA_Tools writes no files');
sspa_t(!preg_match('/\bini_set\s*\(/', $source), 'SSPA_Tools never calls ini_set()');

// --- The tab renders without notices ---
$tab = WP_PLUGIN_DIR . '/super-speedy-performance-analysis/includes/admin/tabs/tools.php';
ob_start();
$errors = array();
set_error_handler(function ($no, $str) use (&$errors) { $errors[] = $str; return true; });
include $tab;
restore_error_handler();
$html = ob_get_clean();
sspa_t(empty($errors), 'tools tab renders with no PHP notices' . ($errors ? ': ' . implode('; ', array_slice($errors, 0, 3)) : ''));
sspa_t(strpos($html, 'Query plans') !== false, 'tools tab renders the capability table');
sspa_t(strpos($html, 'never installs anything itself') !== false, 'tools tab states the plugin installs nothing');
