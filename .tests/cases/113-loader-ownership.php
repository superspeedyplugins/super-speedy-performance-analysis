<?php
require_once SSPA_PLUGIN_DIR . '.tests/lib/fleet.php';
$loader = SSPA_Helper_Files::mu_path();
$backup = $loader . '.w3-test-backup';
$target = dirname($loader) . '/sspa-w3-foreign-missing.php';
if (file_exists($backup) || file_exists($target)) throw new RuntimeException('Unexpected ownership fixture state');
if (file_exists($loader) && !rename($loader, $backup)) throw new RuntimeException('Cannot retain profiler loader');
try {
    if (!symlink($target, $loader)) throw new RuntimeException('Cannot create dangling foreign loader fixture');
    clearstatcache(true, $loader);
    sspa_fleet_assert(is_link($loader) && !file_exists($target), 'fixture is a dangling foreign loader symlink');
    $result = SSPA_Helper_Files::ensure_installed(true);
    clearstatcache(true, $loader);
    sspa_fleet_assert(is_link($loader) && !file_exists($target) && !$result['mu'], 'enabling the profiler preserves a foreign dangling loader and its absent target');
} finally {
    if (is_link($loader)) unlink($loader);
    if (file_exists($target)) unlink($target);
    if (file_exists($backup) && !rename($backup, $loader)) throw new RuntimeException('Cannot restore retained profiler loader');
    clearstatcache(true, $loader);
}
sspa_fleet_done();
