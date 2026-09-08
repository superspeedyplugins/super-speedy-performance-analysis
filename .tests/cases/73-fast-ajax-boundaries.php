<?php
/** Real request method/failure and sampler ceilings, no invented observation rows. */
function sspa_boundary_check($ok,$label){echo($ok?'PASS':'FAIL').': '.$label."\n";}
$active=SSPA_Traffic_Collection::active();if($active)SSPA_Traffic_Collection::stop($active['id'],true);
if(!is_file(WP_PLUGIN_DIR.'/zz-ajax-owner/fixture.php')){echo "FAIL: Prepare case71 fixtures first.\n";return;}
if(class_exists('SPRO_Unload')){$o=(array)get_option('wpiperf_settings');$o['ajax_unloads_enabled']=0;update_option('wpiperf_settings',$o,false);SPRO_Unload::write_policy();}
// Fixture delta is explicit and retained; this endpoint produces a genuine HTTP failure.
$path=WP_PLUGIN_DIR.'/zz-ajax-owner/fixture.php';$source=file_get_contents($path);
if(strpos($source,'zz_ajax_failure_fixture')===false)file_put_contents($path,$source."\nadd_action('wp_ajax_nopriv_zz_ajax_failure_fixture', function () { wp_send_json_error(array('fixture'=>true),503); });\n");
$before=SSPA_Ajax_Profile::start(array('scenario'=>'Bounded fixture','label'=>'POST samples','detail'=>true));
if(is_wp_error($before)){echo 'FAIL: '.$before->get_error_message()."\n";return;}
for($i=0;$i<7;$i++)wp_remote_post(admin_url('admin-ajax.php'),array('body'=>array('action'=>'zz_ajax_workflow_fixture'),'timeout'=>30));
wp_remote_post(admin_url('admin-ajax.php'),array('body'=>array('action'=>'zz_ajax_failure_fixture'),'timeout'=>30));
SSPA_Ajax_Profile::stop($before['uuid']);$rows=SSPA_Ajax_Profile::rows($before);$detailed=0;$identity=0;$failed=0;
foreach($rows as $row){$c=json_decode($row['measurement_json'],true);if($row['endpoint']==='zz_ajax_workflow_fixture'){if(!empty($c['activity']))$detailed++;else$identity++;}if((int)$row['status_code']===503)$failed++;}
sspa_boundary_check($detailed===5&&$identity===2,'per-action ceiling bounds detailed capture while ordinary request samples continue');
sspa_boundary_check($failed===1,'actual failed HTTP request remains in retained measurements');
$after=SSPA_Ajax_Profile::start(array('scenario'=>'Bounded fixture','label'=>'GET samples','detail'=>false));
if(is_wp_error($after)){echo 'FAIL: '.$after->get_error_message()."\n";return;}
wp_remote_get(add_query_arg('action','zz_ajax_workflow_fixture',admin_url('admin-ajax.php')),array('timeout'=>30));SSPA_Ajax_Profile::stop($after['uuid']);
$doc=SSPA_Ajax_Profile::compare($before['uuid'],$after['uuid']);$matched=false;$faults=0;
foreach($doc['pages'] as $page){if($page['delta']['absolute']!==null)$matched=true;$faults+=count($page['previous']['faults']);}
sspa_boundary_check(!$matched,'different HTTP methods or actual instrumentation modes cannot create an apparent speedup');
sspa_boundary_check($faults===1,'comparison and export carry failed samples separately from successful percentiles');
$w=SSPA_Ajax_Profile::start(array('scenario'=>'Expired fixture','label'=>'Expiry fixture'));
if(is_wp_error($w)){echo 'FAIL: '.$w->get_error_message()."\n";return;}
$helper=SSPA_Traffic_Helper::path();$generated=file_get_contents($helper);$generated=preg_replace("/('collect_until' => )\\d+/",'$1'.(time()-2),$generated);file_put_contents($helper,$generated);if(function_exists('opcache_invalidate'))opcache_invalidate($helper,true);
wp_remote_post(admin_url('admin-ajax.php'),array('body'=>array('action'=>'zz_ajax_workflow_fixture'),'timeout'=>30));
sspa_boundary_check(count(SSPA_Ajax_Profile::rows($w))===0,'expired collection writes no AJAX observation during the later outcomes window');
SSPA_Ajax_Profile::stop($w['uuid']);

$selected=SSPA_Ajax_Profile::start(array('scenario'=>'Selection cap fixture','label'=>'Selected only','detail'=>true,'endpoints'=>array('admin_ajax:zz_ajax_failure_fixture')));
if(is_wp_error($selected)){echo 'FAIL: '.$selected->get_error_message()."\n";return;}
for($i=0;$i<6;$i++)wp_remote_post(admin_url('admin-ajax.php'),array('body'=>array('action'=>'zz_ajax_workflow_fixture'),'timeout'=>30));
wp_remote_post(admin_url('admin-ajax.php'),array('body'=>array('action'=>'zz_ajax_failure_fixture'),'timeout'=>30));
SSPA_Ajax_Profile::stop($selected['uuid']);$selected_rows=SSPA_Ajax_Profile::rows($selected);
$selected_capture=$selected_rows?json_decode($selected_rows[0]['measurement_json'],true):array();
sspa_boundary_check(get_option('sspa_ajax_slot_' . $selected['uuid'] . '_' . substr(hash('sha256', 'zz_ajax_workflow_fixture'), 0, 16) . '_0', false) === false, 'unselected action reserves no detail slot');
sspa_boundary_check(count($selected_rows)===1&&!empty($selected_capture['activity']),'unselected endpoints consume no selected detail sample slots or AJAX window points');
