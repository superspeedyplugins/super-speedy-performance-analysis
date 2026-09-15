<?php
require SSPA_PLUGIN_DIR . '.tests/lib/fleet.php';
// Reset on entry. All site data and evidence remain after the case.
sspa_fleet_cli(array('plugin', 'activate', 'super-speedy-performance-analysis'));
$prep = <<<'CODE'
SSPA_Traffic_Collection::deactivate();
if (class_exists('SSPA_Traffic_Authority')) SSPA_Traffic_Authority::activate();
add_filter('sspa_traffic_origin_sample_modulus', static function(){return 1;});
$r=SSPA_Traffic_Collection::start('15m');
if(is_wp_error($r)) throw new RuntimeException($r->get_error_message());
$id=(int)$r['collection']['id'];
$owner=SSPA_Atomic_Claim::acquire(SSPA_Traffic_Collection::COLLECTION_LOCK_PREFIX.$id,300,'deactivation-regression-owner');
update_option('sspa_deactivation_regression_id',$id,false);
update_option('sspa_deactivation_regression_config',file_get_contents(SSPA_Traffic_Helper::path()),false);
echo wp_json_encode(array('id'=>$id,'owner'=>$owner));
CODE;
$started=json_decode(sspa_fleet_cli(array('eval',$prep)),true);
$id=(int)($started['id']??0);
sspa_fleet_assert($id>0 && ($started['owner']??'')==='deactivation-regression-owner','real collection starts and its actual lifecycle lease is held');
sspa_fleet_cli(array('plugin','deactivate','super-speedy-performance-analysis'));
$probe = 'global $wpdb; $id=(int)get_option("sspa_deactivation_regression_id"); echo wp_json_encode(array("active"=>in_array("super-speedy-performance-analysis/super-speedy-performance-analysis.php",get_option("active_plugins",array()),true),"events"=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sspa_traffic_events WHERE collection_id=%d",$id)),"endpoints"=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sspa_traffic_endpoint_observations WHERE collection_id=%d",$id)),"status"=>$wpdb->get_var($wpdb->prepare("SELECT status_code FROM {$wpdb->prefix}sspa_traffic_collections WHERE id=%d",$id)),"lease"=>get_option("sspa_traffic_collection_lock_".$id)));';
$before=json_decode(sspa_fleet_cli(array('eval',$probe)),true);
$response=wp_remote_get(home_url('/?sspa-deactivation-check=1'),array('timeout'=>20));
sspa_fleet_assert(!is_wp_error($response) && 200===wp_remote_retrieve_response_code($response),'real anonymous homepage returns HTTP200 after deactivation');
$after=json_decode(sspa_fleet_cli(array('eval',$probe)),true);
echo 'BEFORE: '.wp_json_encode($before)."\nAFTER: ".wp_json_encode($after)."\n";
sspa_fleet_assert(empty($after['active']),'ordinary plugin is inactive');
sspa_fleet_assert($before['events']===$after['events'] && $before['endpoints']===$after['endpoints'],'no traffic events or endpoint observations grow after deactivation');
sspa_fleet_assert((int)$after['status']===SSPA_Traffic_Codes::COLLECTION_STOPPED,'collection is terminal despite another owner lease');
sspa_fleet_assert(strpos((string)$after['lease'],'deactivation-regression-owner|')===0,'deactivation preserves the existing lifecycle lease owner');
// Reactivate and prove saved old helper contents cannot resume the revoked collection.
sspa_fleet_cli(array('plugin','activate','super-speedy-performance-analysis'));
sspa_fleet_cli(array('eval','file_put_contents(SSPA_Traffic_Helper::path(),get_option("sspa_deactivation_regression_config"));'));
$response=wp_remote_get(home_url('/?sspa-deactivation-check=stale'),array('timeout'=>20));
sspa_fleet_assert(!is_wp_error($response) && 200===wp_remote_retrieve_response_code($response),'saved old observer request remains HTTP200 after reactivation');
$stale=json_decode(sspa_fleet_cli(array('eval',$probe)),true);
sspa_fleet_assert($stale['events']===$after['events'] && $stale['endpoints']===$after['endpoints'],'reactivation does not revive the saved old observer');
$reinstall=sspa_fleet_cli(array('eval','$id=(int)get_option("sspa_deactivation_regression_id");$r=SSPA_Traffic_Helper::install(array("collection_id"=>$id));echo is_wp_error($r)?$r->get_error_code():"installed";'));
sspa_fleet_assert($reinstall==='sspa_traffic_revoked','stale installer is explicitly denied after reactivation');

$dir=WP_CONTENT_DIR.'/sspa-deactivation-race';
wp_mkdir_p($dir);
// Reset barriers on entry; retain all completed markers/logs until the next run.
foreach(glob($dir.'/*') as $file){if(is_file($file))unlink($file);}
file_put_contents(WPMU_PLUGIN_DIR.'/zz-sspa-deactivation-race.php',file_get_contents(SSPA_PLUGIN_DIR.'.tests/deactivation/race-fixture.php'));
function sspa_deactivation_spawn($command){
    $p=proc_open($command,array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes);
    if(!is_resource($p))throw new RuntimeException('Cannot start concurrency worker');
    fclose($pipes[0]);return array($p,$pipes,$command[0]==='curl');
}
function sspa_deactivation_join($worker){
    list($p,$pipes,$http)=$worker;$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);
    sspa_fleet_assert($exit===0,'concurrency worker exits successfully'.($exit?': '.$err:''));if($http)sspa_fleet_assert(substr(trim($out),-20)==='SSPA_HTTP_STATUS:200','concurrent HTTP request returns HTTP200');return $out;
}
function sspa_deactivation_wait($file){
    $until=microtime(true)+15;
    while(!is_file($file) && microtime(true)<$until){clearstatcache();usleep(10000);}
    if(!is_file($file))throw new RuntimeException('Missing real concurrency barrier: '.basename($file));
}
$worker=SSPA_PLUGIN_DIR.'.tests/deactivation/worker.php';
sspa_fleet_cli(array('eval','add_filter("sspa_traffic_origin_sample_modulus",static function(){return 1;});$r=SSPA_Traffic_Collection::start("15m");if(is_wp_error($r))throw new RuntimeException($r->get_error_message());update_option("sspa_deactivation_regression_id",$r["collection"]["id"],false);echo $r["collection"]["id"];'));
$http=sspa_deactivation_spawn(array('curl','--fail','--write-out',"\nSSPA_HTTP_STATUS:%{http_code}",'--silent','--show-error','--max-time','25',home_url('/?sspa-deactivation-race=flush')));
sspa_deactivation_wait($dir.'/flush.entered');
$deactivation=sspa_deactivation_spawn(array('wp','eval-file',$worker,'deactivate'));
sspa_deactivation_wait($dir.'/deactivate.entered');
usleep(250000);clearstatcache();
sspa_fleet_assert(!is_file($dir.'/deactivate.done'),'deactivation waits while a real observer INSERT holds shared authority');
file_put_contents($dir.'/flush.release','release');
sspa_deactivation_join($http);sspa_deactivation_join($deactivation);
$drained=json_decode(sspa_fleet_cli(array('eval',$probe)),true);
$response=wp_remote_get(home_url('/?sspa-deactivation-check=drained'),array('timeout'=>20));
sspa_fleet_assert(!is_wp_error($response) && 200===wp_remote_retrieve_response_code($response),'drained observer follow-up remains HTTP200');
$drained_after=json_decode(sspa_fleet_cli(array('eval',$probe)),true);
sspa_fleet_assert($drained['events']===$drained_after['events'] && $drained['endpoints']===$drained_after['endpoints'],'after drained deactivation returns, subsequent requests append nothing');
sspa_fleet_assert((int)$drained['events']>0,'the admitted real observer write completed before deactivation returned');

// A request that booted before revocation but has not entered its write boundary is denied.
sspa_fleet_cli(array('plugin','activate','super-speedy-performance-analysis'));
sspa_fleet_cli(array('eval','add_filter("sspa_traffic_origin_sample_modulus",static function(){return 1;});$r=SSPA_Traffic_Collection::start("15m");if(is_wp_error($r))throw new RuntimeException($r->get_error_message());update_option("sspa_deactivation_regression_id",$r["collection"]["id"],false);'));
$buffered=sspa_deactivation_spawn(array('curl','--fail','--write-out',"\nSSPA_HTTP_STATUS:%{http_code}",'--silent','--show-error','--max-time','25',home_url('/?sspa-deactivation-race=buffered')));
sspa_deactivation_wait($dir.'/buffered.entered');
sspa_fleet_cli(array('plugin','deactivate','super-speedy-performance-analysis'));
$buffer_before=json_decode(sspa_fleet_cli(array('eval',$probe)),true);
file_put_contents($dir.'/buffered.release','release');
sspa_deactivation_join($buffered);
$buffer_after=json_decode(sspa_fleet_cli(array('eval',$probe)),true);
sspa_fleet_assert($buffer_before['events']===$buffer_after['events'] && $buffer_before['endpoints']===$buffer_after['endpoints'],'already-booted request cannot flush buffered events or endpoints after revocation');

// Pause a real start before its row INSERT while it owns exclusive publication authority.
sspa_fleet_cli(array('plugin','activate','super-speedy-performance-analysis'));
foreach(array('deactivate.entered','deactivate.done') as $name){unlink($dir.'/'.$name);}
$start=sspa_deactivation_spawn(array('wp','eval-file',$worker,'start'));
sspa_deactivation_wait($dir.'/start.entered');
$deactivation=sspa_deactivation_spawn(array('wp','eval-file',$worker,'deactivate'));
sspa_deactivation_wait($dir.'/deactivate.entered');usleep(250000);clearstatcache();
sspa_fleet_assert(!is_file($dir.'/deactivate.done'),'deactivation waits for an in-flight start before its collection row exists');
file_put_contents($dir.'/start.release','release');
sspa_deactivation_join($start);sspa_deactivation_join($deactivation);
$started_race=json_decode(file_get_contents($dir.'/start.done'),true);
$started_id=(int)$started_race['collection']['id'];
$terminal=sspa_fleet_cli(array('eval','global $wpdb;echo $wpdb->get_var($wpdb->prepare("SELECT status_code FROM {$wpdb->prefix}sspa_traffic_collections WHERE id=%d",'.$started_id.'));'));
sspa_fleet_assert((int)$terminal===SSPA_Traffic_Codes::COLLECTION_STOPPED,'the in-flight start is terminalized after publication; no planned-to-running resurrection');
// Leave the dedicated site active for inspection and prove a new explicit collection can start.
sspa_fleet_cli(array('plugin','activate','super-speedy-performance-analysis'));
$new=sspa_fleet_cli(array('eval','$r=SSPA_Traffic_Collection::start("15m");if(is_wp_error($r))throw new RuntimeException($r->get_error_message());echo $r["collection"]["id"];'));
sspa_fleet_assert((int)$new>$started_id,'a new explicit collection succeeds after reactivation');
// Replay the previous generated-MU format with a real current collection configuration.
$legacy=sspa_fleet_cli(array('eval-file',$worker,'legacy'));
$legacy_id=(int)$legacy;
sspa_fleet_assert($legacy_id===(int)$new,'legacy fixture uses the actual last generated collection config');
$response=wp_remote_get(home_url('/?sspa-deactivation-check=legacy'),array('timeout'=>20));
sspa_fleet_assert(!is_wp_error($response) && 200===wp_remote_retrieve_response_code($response),'legacy observer upgrade request returns HTTP200');
$legacy_state=json_decode(sspa_fleet_cli(array('eval','global $wpdb;echo wp_json_encode($wpdb->get_row($wpdb->prepare("SELECT status_code,stop_reason_code FROM {$wpdb->prefix}sspa_traffic_collections WHERE id=%d",'.$legacy_id.'),ARRAY_A));')),true);
sspa_fleet_assert((int)$legacy_state['status_code']===SSPA_Traffic_Codes::COLLECTION_INCOMPLETE && (int)$legacy_state['stop_reason_code']===SSPA_Traffic_Codes::STOP_PLUGIN_UPDATE,'legacy observer upgrade explicitly terminalizes retained collection with plugin-update reason');
sspa_fleet_done();

if ($GLOBALS['sspa_fleet_fail']) { exit(1); }
