<?php
$dir=WP_CONTENT_DIR.'/sspa-deactivation-race';
$mode=$args[0]??'';
if ('deactivate'===$mode) {
    require_once ABSPATH.'wp-admin/includes/plugin.php';
    add_action('deactivate_plugin', static function(){file_put_contents(WP_CONTENT_DIR.'/sspa-deactivation-race/deactivate.entered',(string)microtime(true));});
    deactivate_plugins('super-speedy-performance-analysis/super-speedy-performance-analysis.php');
    file_put_contents($dir.'/deactivate.done',(string)microtime(true));
} elseif ('start'===$mode) {
    add_filter('query',static function($sql) use($dir) {
        if (preg_match('/^INSERT\s+INTO\s+`?[^\s`]*sspa_traffic_collections\b/i',$sql)) {
            file_put_contents($dir.'/start.entered',(string)microtime(true));
            $deadline=microtime(true)+20;
            while(!is_file($dir.'/start.release') && microtime(true)<$deadline){clearstatcache();usleep(10000);}
            if(!is_file($dir.'/start.release'))throw new RuntimeException('Start barrier was not released');
        }
        return $sql;
    });
    $r=SSPA_Traffic_Collection::start('15m');
    if(is_wp_error($r))throw new RuntimeException($r->get_error_message());
    file_put_contents($dir.'/start.done',wp_json_encode($r));
}

if ('legacy'===$mode) {
    $config_path=SSPA_Traffic_Helper::config_path();
    $config=json_decode((string)file_get_contents($config_path),true);
    if(!is_array($config) || empty($config['collection_id']))throw new RuntimeException('Cannot read the actual generated JSON config');
    $id=(int)$config['collection_id'];
    // Exact pre-authority production template from 90407f925ea9c158f9f1bb1af6e9d4a9a2e3adc5.
    // Its observer contract and fields match the current real config apart from generation.
    // Keep the original missing-generation retirement case, not a different contract mismatch.
    unset($config['generation']);
    delete_option(SSPA_Traffic_Authority::option($id));
    $template=file_get_contents(SSPA_PLUGIN_DIR.'.tests/fixtures/legacy-traffic-observer-pre-authority.template');
    $code=str_replace(array('/* %%SSPA_TRAFFIC_CONFIG_ASSIGNMENT%% */','%%SSPA_TRAFFIC_PLUGIN_DIR%%'),array('$sspa_traffic_config = '.var_export($config,true).';',addslashes(trailingslashit(SSPA_PLUGIN_DIR))),$template);
    update_option('sspa_behaviour_version','0.43.4');
    file_put_contents(SSPA_Traffic_Helper::path(),$code);
    if(!unlink($config_path))throw new RuntimeException('Cannot remove fixture JSON config');
    if(function_exists('opcache_invalidate'))opcache_invalidate(SSPA_Traffic_Helper::path(),true);
    echo $id;
}
