<?php
/** Exploratory overhead, local fixture only. Never enables tracing outside explicit windows. */
if(!is_file(WP_PLUGIN_DIR.'/zz-ajax-owner/fixture.php')){WP_CLI::error('Run fast-ajax fixture preparation first.');}
$active=SSPA_Traffic_Collection::active();if($active)SSPA_Traffic_Collection::stop($active['id'],true);
$results=array();
foreach(array('off','identity','detail') as $mode){
 $window=null;
 if($mode!=='off'){$window=SSPA_Ajax_Profile::start(array('scenario'=>'Overhead fixture','label'=>'Overhead '.$mode,'detail'=>$mode==='detail'));if(is_wp_error($window))WP_CLI::error($window->get_error_message());}
 $times=array();for($i=0;$i<5;$i++){$start=microtime(true);$r=wp_remote_post(admin_url('admin-ajax.php'),array('body'=>array('action'=>'zz_ajax_workflow_fixture'),'timeout'=>30));if(is_wp_error($r)||wp_remote_retrieve_response_code($r)!==200)WP_CLI::error('Fixture request failed.');$times[]=(microtime(true)-$start)*1000;}
 $results[$mode]=SSPA_Measurement_Math::distribution($times);
 if($window){SSPA_Ajax_Profile::stop($window['uuid']);$rows=SSPA_Ajax_Profile::rows($window);$captures=array_map(function($r){return json_decode($r['measurement_json'],true);},$rows);$results[$mode]['retained_bytes']=array_sum(array_map(function($r){return strlen($r['measurement_json']);},$rows));$results[$mode]['peak_memory_bytes']=array_column($captures,'peak_memory_bytes');$results[$mode]['detailed_samples']=count(array_filter($captures,function($c){return !empty($c['activity']);}));}
}
echo wp_json_encode($results,JSON_PRETTY_PRINT)."\n";
