<?php
$GLOBALS['sspa_network_failures'] = 0;
function w3_network_check($condition, $label) { if (!$condition) { $GLOBALS['sspa_network_failures']++; } echo ($condition ? 'PASS' : 'FAIL') . ': ' . $label . "\n"; }
w3_network_check(is_multisite() && current_user_can('manage_network_options'), 'real multisite and network administrator preconditions');
$result = SSPA_Helper_Files::enable_profiler();
w3_network_check(true === $result, 'network administrator can enable the profiler');
$files = array(SSPA_Helper_Files::mu_path(), SSPA_Helper_Files::dropin_path());
$before = array_map('hash_file', array_fill(0, count($files), 'sha256'), $files);
$user = get_user_by('login', 'sspa-w3-site-admin');
if (!$user) { $id = wp_create_user('sspa-w3-site-admin', wp_generate_password(32), 'network-fixture@example.invalid'); $user = new WP_User($id); }
$user->set_role('administrator');
wp_set_current_user($user->ID);
w3_network_check(current_user_can('manage_options') && !current_user_can('manage_network_options'), 'site administrator has no network file capability');
w3_network_check(is_wp_error(SSPA_Helper_Files::enable_profiler()) && is_wp_error(SSPA_Helper_Files::disable_profiler()), 'site administrator cannot enable or disable network-wide profiler files');
$result = SSPA_Helper_Files::ensure_installed(true);
w3_network_check(!$result['mu'] && !$result['dropin'], 'site administrator cannot refresh network-wide helpers');
SSPA_Helper_Files::remove_all();
$after = array_map('hash_file', array_fill(0, count($files), 'sha256'), $files);
w3_network_check($before === $after, 'network helper bytes survive site-administrator removal attempt');
wp_set_current_user(1);
SSPA_Install::deactivate(false);
w3_network_check($before === array_map('hash_file', array_fill(0, count($files), 'sha256'), $files), 'single-site deactivation preserves network-wide profiler helpers');

if ($GLOBALS['sspa_network_failures']) { exit(1); }
