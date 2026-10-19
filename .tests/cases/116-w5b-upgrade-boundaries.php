<?php
function w5b_t($ok,$label) { echo ($ok?'PASS':'FAIL').': '.$label."\n"; }
wp_set_current_user(1);
$expiry=time()+300; $token=WP_Session_Tokens::get_instance(1)->create($expiry);
$cookies=array(LOGGED_IN_COOKIE=>wp_generate_auth_cookie(1,$expiry,'logged_in',$token),AUTH_COOKIE=>wp_generate_auth_cookie(1,$expiry,'auth',$token));
$active=SSPA_Traffic_Collection::active();if($active)SSPA_Traffic_Collection::stop((int)$active['id'],true);
$start=SSPA_Traffic_Collection::start('1h','test');
if(is_wp_error($start)) {echo 'FAIL: '.$start->get_error_message();return;}
$owner=SSPA_Atomic_Claim::acquire(SSPA_Traffic_Helper::LOCK_OPTION,120);
update_option('sspa_behaviour_version','0.43.4');
$r=wp_remote_get(admin_url('admin.php?page=sspa'),array('cookies'=>$cookies,'timeout'=>30));
wp_cache_delete('sspa_upgrade_error','options');wp_cache_delete('alloptions','options');wp_cache_delete('notoptions','options');
w5b_t(200===wp_remote_retrieve_response_code($r),'busy helper lock does not fatal wp-admin');
w5b_t(strpos(wp_remote_retrieve_body($r),'being changed by another request')!==false,'PA screen surfaces busy helper migration failure');
w5b_t(get_option('sspa_behaviour_version')==='0.43.4','failed migration leaves version pending');
$r=wp_remote_get(home_url('/'),array('timeout'=>20));w5b_t(200===wp_remote_retrieve_response_code($r),'visitor succeeds while admin migration is pending');
SSPA_Atomic_Claim::release(SSPA_Traffic_Helper::LOCK_OPTION,$owner);
$r=wp_remote_get(admin_url('admin.php?page=sspa'),array('cookies'=>$cookies,'timeout'=>30));
wp_cache_delete('sspa_upgrade_error','options');wp_cache_delete('alloptions','options');wp_cache_delete('notoptions','options');
w5b_t(200===wp_remote_retrieve_response_code($r)&&get_option('sspa_behaviour_version')===SSPA_VERSION&&!get_option('sspa_upgrade_error'),'next wp-admin request retries successfully and clears the resolved fault');
SSPA_Traffic_Collection::stop((int)$start['collection']['id'],true);
WP_Session_Tokens::get_instance(1)->destroy($token);

$path=SSPA_Helper_Files::dropin_path();
$before=file_get_contents($path);
try {
    file_put_contents($path,$before."\n// obsolete installed helper bytes\n");
    $health=SSPA_Helper_Files::health();
    w5b_t(!empty($health['dropin_stale'])&&!$health['mu']&&'stale'===$health['mu_reason'],'Health flags a stale owned db.php');
} finally { file_put_contents($path,$before); }
$uploads=wp_upload_dir();
$r=wp_remote_get(str_replace($uploads['basedir'],$uploads['baseurl'],SSPA_Traffic_Authority::path()),array('timeout'=>15));
w5b_t(''===wp_remote_retrieve_body($r),'authority data cannot be read over HTTP');

// Reproduce the old disabled authority state using real deactivation state.
wp_set_current_user(1);
SSPA_Install::deactivate();
$raw=file_get_contents(SSPA_Traffic_Authority::path());
$state=substr($raw,strlen(SSPA_Traffic_Authority::GUARD));
$legacy=SSPA_MU_DIR.'/sspa-traffic-authority.json';
file_put_contents($legacy,$state);unlink(SSPA_Traffic_Authority::path());
update_option('sspa_behaviour_version','0.43.4');
SSPA_Install::activate();SSPA_Install::maybe_upgrade();
try {$start=SSPA_Traffic_Collection::start('1h','test');}
catch(Throwable $error){$start=new WP_Error('exception',$error->getMessage());}
echo (is_wp_error($start)?'FAIL: ':'PASS: ').'legacy disabled authority can start Traffic after reactivation'.(is_wp_error($start)?': '.$start->get_error_message():'')."\n";
if(!is_wp_error($start))SSPA_Traffic_Collection::stop((int)$start['collection']['id'],true);
