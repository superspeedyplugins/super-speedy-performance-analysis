<?php
require SSPA_PLUGIN_DIR.'.tests/lib/fleet.php';
sspa_fleet_assert(!is_link(WP_PLUGIN_DIR.'/super-speedy-performance-analysis'),'uninstall regression targets an ordinary copied plugin');
SSPA_Traffic_Collection::deactivate();
SSPA_Traffic_Authority::activate();
$r=SSPA_Traffic_Collection::start('15m');
sspa_fleet_assert(!is_wp_error($r),'copied plugin creates a real traffic collection before uninstall');
$data_paths = array(SSPA_Traffic_Authority::path(), SSPA_Traffic_Helper::config_path(), SSPA_Traffic_Helper::stopped_path());
sspa_fleet_assert(is_file($data_paths[0]), 'actual collection created its uploads authority before uninstall');
sspa_update_option('remove_data_on_uninstall',true);
sspa_fleet_cli(array('plugin','deactivate','super-speedy-performance-analysis'));
sspa_fleet_cli(array('plugin','uninstall','super-speedy-performance-analysis','--skip-delete'));
$remaining_data = json_decode(sspa_fleet_cli(array('eval', 'echo wp_json_encode(array_map("file_exists",' . var_export($data_paths, true) . '));')), true);
sspa_fleet_assert(array(false, false, false) === $remaining_data, 'actual uninstall removes uploads authority, config and stop marker');
// Check exact traffic table through a prepared metadata query, without loading the inactive plugin.
$count=sspa_fleet_cli(array('eval','global $wpdb;echo count($wpdb->get_col($wpdb->prepare("SHOW TABLES LIKE %s",$wpdb->esc_like($wpdb->prefix."sspa_")."%")));'));
sspa_fleet_assert((int)$count===0,'opt-in uninstall actually removed the plugin tables');
$result=json_decode(sspa_fleet_cli(array('eval','global $wpdb;$wpdb->show_errors();$wpdb->suppress_errors(false);require_once ABSPATH."wp-admin/includes/plugin.php";$r=activate_plugin("super-speedy-performance-analysis/super-speedy-performance-analysis.php");echo wp_json_encode(is_wp_error($r)?array("error"=>$r->get_error_message(),"detail"=>$r->get_error_data()):array("activated"=>true));')),true);
sspa_fleet_assert(!empty($result['activated']) && empty($result['error']),'actual reactivation after opt-in uninstall emits no missing-table error');
$count=sspa_fleet_cli(array('eval','global $wpdb;echo count($wpdb->get_col($wpdb->prepare("SHOW TABLES LIKE %s",$wpdb->esc_like($wpdb->prefix."sspa_")."%")));'));
sspa_fleet_assert((int)$count===17,'reactivation recreates all17 plugin tables');
sspa_fleet_done();

if ($GLOBALS['sspa_fleet_fail']) { exit(1); }
