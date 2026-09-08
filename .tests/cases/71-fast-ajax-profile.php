<?php
/** Real administrator-controlled windows; actual HTTP request points are the oracle. */
function sspa_ajax_check($ok, $label) { echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n"; }
if (class_exists('SPRO_Unload')) { $settings = (array) get_option('wpiperf_settings', array()); $settings['ajax_unloads_enabled'] = 0; update_option('wpiperf_settings', $settings, false); SPRO_Unload::write_policy(); }
$active = SSPA_Traffic_Collection::active(); if ($active) { SSPA_Traffic_Collection::stop((int)$active['id'], true); }
$owner = WP_PLUGIN_DIR . '/zz-ajax-owner'; $slow = WP_PLUGIN_DIR . '/zz-ajax-slow';
wp_mkdir_p($owner); wp_mkdir_p($slow);
file_put_contents($owner . '/fixture.php', <<<'FIXTURE'
<?php
/* Plugin Name: AJAX owner fixture
Version: 1.0 */
function zz_ajax_fixture() { global $wpdb; $wpdb->get_var('SELECT 1'); wp_send_json_success(array('observed' => true)); }
add_action('wp_ajax_nopriv_zz_ajax_workflow_fixture', 'zz_ajax_fixture');
add_action('zz_ajax_unused_hook', function () { usleep(900000); });
FIXTURE
);
file_put_contents($slow . '/fixture.php', <<<'FIXTURE'
<?php
/* Plugin Name: AJAX delay fixture
Version: 1.0 */
add_action('init', function () { if (isset($_REQUEST['action']) && $_REQUEST['action'] === 'zz_ajax_workflow_fixture') { usleep(120000); } });
FIXTURE
);
file_put_contents(WP_PLUGIN_DIR . '/aa-ajax-flat.php', "<?php\n/* Plugin Name: Flat AJAX fixture\nVersion: 1.0 */\n");
activate_plugin('aa-ajax-flat.php');
activate_plugin('zz-ajax-owner/fixture.php'); activate_plugin('zz-ajax-slow/fixture.php');
$before = SSPA_Ajax_Profile::start(array('scenario' => 'Fixture action', 'label' => 'Before fixture', 'detail' => true));
sspa_ajax_check(!is_wp_error($before), 'explicit window starts without altering plugin activation');
if (is_wp_error($before)) { echo 'FAIL: ' . $before->get_error_message() . "\n"; return; }
for ($i=0;$i<3;$i++) { $response = wp_remote_post(admin_url('admin-ajax.php'), array('body'=>array('action'=>'zz_ajax_workflow_fixture'), 'timeout'=>30)); sspa_ajax_check(!is_wp_error($response) && wp_remote_retrieve_response_code($response)===200, 'real fixture request returns successfully'); }
SSPA_Ajax_Profile::stop($before['uuid']);
$rows = SSPA_Ajax_Profile::rows($before);
sspa_ajax_check(count($rows)===3, 'three actual registered requests retain immutable profile captures');
$capture = $rows ? json_decode($rows[0]['measurement_json'],true) : array();
sspa_ajax_check(($capture['boundary'] ?? '') === 'mu_observer_to_shutdown' && ($capture['browser_elapsed_ms'] ?? null) === null, 'server timing never claims browser elapsed time');
$plugins = array_column($capture['activity']['plugins'] ?? array(),null,'plugin');
sspa_ajax_check(isset($plugins['zz-ajax-slow/fixture.php']) && count($plugins['zz-ajax-slow/fixture.php']['executed_hooks'])>0, 'init-only fixture has observed execution, independently of I/O');
sspa_ajax_check(isset($plugins['zz-ajax-owner/fixture.php']) && count($plugins['zz-ajax-owner/fixture.php']['registered_hooks'])>0, 'registration-only hook is retained as a registration');
$registered = array_column($plugins['zz-ajax-owner/fixture.php']['registered_hooks'] ?? array(),'hook');
$executed = array_column($plugins['zz-ajax-owner/fixture.php']['executed_hooks'] ?? array(),'hook');
sspa_ajax_check(in_array('zz_ajax_unused_hook',$registered,true) && !in_array('zz_ajax_unused_hook',$executed,true), 'unused registered callback is never labelled as executed');
sspa_ajax_check(($plugins['zz-ajax-owner/fixture.php']['io']['sql_count'] ?? 0)>0, 'fixture SQL attempt is attributed to the executing plugin');
sspa_ajax_check(($plugins['aa-ajax-flat.php']['io']['sql_count'] ?? 0) === 0, 'single-file plugin cannot own another plugin callback or I/O');
$report=SSPA_Report::endpoint_evidence($before['collection_id']);
sspa_ajax_check($report['schema']==='sspa/endpoint-evidence@2' && $report['capture']['detailed_samples']===3, 'SPro receives successor activity contract with exact sampled count');
// Change only the known delay fixture. This baseline test does not pretend to test SPro rule control.
deactivate_plugins('zz-ajax-slow/fixture.php');
$after=SSPA_Ajax_Profile::start(array('scenario'=>'Fixture action','label'=>'After fixture','detail'=>true));
if(is_wp_error($after)){echo 'FAIL: '.$after->get_error_message()."\n";return;}
for($i=0;$i<3;$i++){wp_remote_post(admin_url('admin-ajax.php'),array('body'=>array('action'=>'zz_ajax_workflow_fixture'),'timeout'=>30));}
SSPA_Ajax_Profile::stop($after['uuid']);
$comparison=SSPA_Ajax_Profile::compare($before['uuid'],$after['uuid']);
$page=$comparison['pages'][0] ?? array();
sspa_ajax_check(($page['previous']['samples'] ?? 0)===3 && ($page['current']['samples'] ?? 0)===3, 'before/after chart carries real samples on both sides');
sspa_ajax_check(($page['delta']['absolute'] ?? 0)<-80, 'removing the known 120ms fixture produces a measured reduction');
sspa_ajax_check(count($page['previous']['setups'] ?? array())===1 && count($page['current']['setups'] ?? array())===1 && array_keys($page['previous']['setups'])!==array_keys($page['current']['setups']), 'effective loaded versions make different captured setup identities');
$again=SSPA_Ajax_Profile::compare($before['uuid'],$after['uuid']);
sspa_ajax_check($again===$comparison,'saved baseline comparison reopens unchanged after current plugin configuration changes');
sspa_ajax_check(SSPA_Measurement_Math::delta(0,350)['percent']===null,'zero baseline has no invented percentage');
sspa_ajax_check(is_wp_error(SSPA_Ajax_Profile::compare($before['uuid'],$before['uuid'])),'same window cannot masquerade as before and after');
// Keep plugin fixtures and observations for browser inspection. Final site reflects after state.
