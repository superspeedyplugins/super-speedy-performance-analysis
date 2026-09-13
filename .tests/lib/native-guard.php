<?php
// Direct eval-file entry must satisfy the same native site/source contract as the runner.
if (!defined('WP_CLI') || !WP_CLI || !defined('ABSPATH')) throw new RuntimeException('Native regression entry requires WP-CLI');
$sspa_guard_workspace = getenv('SUPERSPEEDY_WORKSPACE') ?: getenv('HOME') . '/dev/super-speedy';
$sspa_guard_process = proc_open(array('bash', '-c', 'source "$1"; printf "%s" "$SITES_ROOT"', 'native-root', $sspa_guard_workspace . '/tools/parallel-dev/bin/lib.sh'), array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')), $sspa_guard_pipes);
if (!is_resource($sspa_guard_process)) throw new RuntimeException('Cannot resolve configured native root');
fclose($sspa_guard_pipes[0]);
$sspa_guard_root = stream_get_contents($sspa_guard_pipes[1]);
$sspa_guard_error = stream_get_contents($sspa_guard_pipes[2]);
fclose($sspa_guard_pipes[1]); fclose($sspa_guard_pipes[2]);
if (proc_close($sspa_guard_process) !== 0 || !$sspa_guard_root) throw new RuntimeException('Cannot resolve native root: ' . $sspa_guard_error);
$sspa_guard_scenario = getenv('SSPA_SCENARIO') ?: 'tests-feature-regressions';
if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $sspa_guard_scenario)) throw new RuntimeException('Invalid regression scenario');
$sspa_guard_expected = realpath($sspa_guard_root . '/super-speedy-performance-analysis/' . $sspa_guard_scenario);
$sspa_guard_declared = getenv('SSPA_TEST_SITE_DIR');
if (!$sspa_guard_expected || !$sspa_guard_declared || realpath($sspa_guard_declared) !== $sspa_guard_expected || realpath(ABSPATH) !== $sspa_guard_expected) throw new RuntimeException('Native regression target mismatch');
if (!getenv('SSPA_TEST_SITE_URL') || untrailingslashit(home_url()) !== untrailingslashit(getenv('SSPA_TEST_SITE_URL'))) throw new RuntimeException('Native regression URL mismatch');
if (realpath(WP_PLUGIN_DIR . '/super-speedy-performance-analysis') !== realpath(__DIR__ . '/../..')) throw new RuntimeException('Native regression plugin source mismatch');
