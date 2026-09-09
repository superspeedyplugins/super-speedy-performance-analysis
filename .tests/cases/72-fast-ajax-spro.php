<?php
/** Real SPro generated MU policy provenance; versions stay unchanged across keep-list edits. */
function sspa_spro_check($ok,$label){echo($ok?'PASS':'FAIL').': '.$label."\n";}
if(!class_exists('SPRO_Fast_Ajax')){echo "FAIL: Install the SPro endpoint feature on this dedicated integration site.\n";return;}
if(!is_file(WP_PLUGIN_DIR.'/zz-ajax-owner/fixture.php')){echo "FAIL: Run case71 fixture preparation first.\n";return;}
$active=SSPA_Traffic_Collection::active();if($active)SSPA_Traffic_Collection::stop($active['id'],true);
activate_plugin('zz-ajax-owner/fixture.php');activate_plugin('zz-ajax-slow/fixture.php');
$o=(array)get_option('wpiperf_settings',array());$o['unload_feature_enabled']=1;$o['ajax_unloads_beta_enabled']=1;$o['ajax_unloads_enabled']=1;update_option('wpiperf_settings',$o,false);
function sspa_spro_rule($keep){$r=SPRO_Fast_Ajax::save_choices(array(array('transport'=>'admin_ajax','endpoint'=>'zz_ajax_workflow_fixture','method'=>'POST','context'=>'anon','enabled'=>true,'keep_plugins'=>$keep,'review_selection'=>true,'acknowledge_write'=>true)));if(is_wp_error($r))throw new RuntimeException($r->get_error_message());SPRO_Unload::write_policy();}
$owner='zz-ajax-owner/fixture.php';$slow='zz-ajax-slow/fixture.php';
sspa_spro_rule(array($owner,$slow));
$before=SSPA_Ajax_Profile::start(array('scenario'=>'SPro repeated fixture','label'=>'SPro before','detail'=>false));
if(is_wp_error($before)){echo 'FAIL: '.$before->get_error_message()."\n";return;}
for($i=0;$i<3;$i++)wp_remote_post(admin_url('admin-ajax.php'),array('body'=>array('action'=>'zz_ajax_workflow_fixture'),'timeout'=>30));
SSPA_Ajax_Profile::stop($before['uuid']);
sspa_spro_rule(array($owner));
$after=SSPA_Ajax_Profile::start(array('scenario'=>'SPro repeated fixture','label'=>'SPro after','detail'=>false));
if(is_wp_error($after)){echo 'FAIL: '.$after->get_error_message()."\n";return;}
for($i=0;$i<3;$i++)wp_remote_post(admin_url('admin-ajax.php'),array('body'=>array('action'=>'zz_ajax_workflow_fixture'),'timeout'=>30));
SSPA_Ajax_Profile::stop($after['uuid']);
$doc=SSPA_Ajax_Profile::compare($before['uuid'],$after['uuid']);$page=$doc['pages'][0]??array();
$bs=$page['previous']['points'][0]['evidence']['setup']??array();$as=$page['current']['points'][0]['evidence']['setup']??array();
sspa_spro_check(($bs['spro']['schema']??'')==='spro/endpoint-context@1'&&($as['spro']['schema']??'')==='spro/endpoint-context@1','real generated SPro provider survives normal plugin unloading');
sspa_spro_check(!empty($bs['spro']['optimisation_enabled'])&&!empty($as['spro']['optimisation_enabled']),'both windows capture actual enabled endpoint policies');
sspa_spro_check(isset($bs['plugins'][$slow])&&!isset($as['plugins'][$slow]),'actual loaded set reflects selected endpoint plugin removal');
sspa_spro_check(($bs['spro']['policy_fingerprint']??'')!==($as['spro']['policy_fingerprint']??''),'unchanged installed versions with changed keep-list create distinct policy boundaries');
sspa_spro_check(($page['delta']['absolute']??0)<-80,'real selective AJAX loading measurably removes the fixture delay');
sspa_spro_check(in_array($slow,get_option('active_plugins'),true),'endpoint optimisation leaves the plugin active for ordinary requests');
// Change the policy inside another window: retain separate periods but refuse mixed headline delta.
$mixed=SSPA_Ajax_Profile::start(array('scenario'=>'SPro repeated fixture','label'=>'SPro mixed setup'));
wp_remote_post(admin_url('admin-ajax.php'),array('body'=>array('action'=>'zz_ajax_workflow_fixture'),'timeout'=>30));
sspa_spro_rule(array($owner,$slow));
wp_remote_post(admin_url('admin-ajax.php'),array('body'=>array('action'=>'zz_ajax_workflow_fixture'),'timeout'=>30));
SSPA_Ajax_Profile::stop($mixed['uuid']);$mixed_doc=SSPA_Ajax_Profile::compare($before['uuid'],$mixed['uuid']);
sspa_spro_check(count($mixed_doc['pages'][0]['current']['setups']??array())===2&&$mixed_doc['pages'][0]['delta']['absolute']===null,'mid-window policy changes retain periods without a misleading aggregate delta');
sspa_spro_rule(array($owner));
sspa_spro_check(SSPA_Ajax_Profile::compare($before['uuid'],$after['uuid'])===$doc,'reopening saved windows never relabels them using current SPro settings');
echo 'MEASURED: '.wp_json_encode(array('before'=>$page['previous']['median']??null,'after'=>$page['current']['median']??null,'delta'=>$page['delta']??null))."\n";
