<?php
require SSPA_PLUGIN_DIR.'.tests/lib/fleet.php';
SSPA_Helper_Files::remove_all();
sspa_fleet_assert(!file_exists(SSPA_Traffic_Authority::path()) && !file_exists(SSPA_Traffic_Helper::path()),'never-used Traffic has neither authority nor observer');
if(file_exists(SSPA_Traffic_Authority::path()) || file_exists(SSPA_Traffic_Helper::path())) throw new RuntimeException('Readonly fixture is not a never-used Traffic site');
chmod(WPMU_PLUGIN_DIR,0555);
clearstatcache();
sspa_fleet_assert(!is_writable(WPMU_PLUGIN_DIR),'the actual MU directory is unwritable');
sspa_fleet_cli(array('plugin','deactivate','super-speedy-performance-analysis'));
$result=json_decode(sspa_fleet_cli(array('eval','echo wp_json_encode(array("inactive"=>!in_array("super-speedy-performance-analysis/super-speedy-performance-analysis.php",get_option("active_plugins",array()),true),"authority"=>file_exists(WPMU_PLUGIN_DIR."/sspa-traffic-authority.json"),"writable"=>is_writable(WPMU_PLUGIN_DIR)));')),true);
sspa_fleet_assert(!empty($result['inactive']) && !$result['authority'] && !$result['writable'],'ordinary deactivation succeeds without creating unused authority or changing permissions');
sspa_fleet_done();
if($GLOBALS['sspa_fleet_fail'])exit(1);
