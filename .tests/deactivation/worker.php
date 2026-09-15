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
    $source=file_get_contents(SSPA_Traffic_Helper::path());
    if(!preg_match('/(\$sspa_traffic_config = array \([\s\S]*?\n\);)/',$source,$match))throw new RuntimeException('Cannot read the actual generated config');
    eval($match[1]);
    $config=$sspa_traffic_config;
    unset($config['generation']);
    $id=(int)$config['collection_id'];
    delete_option(SSPA_Traffic_Authority::option($id));
    $code="<?php\n// ".SSPA_Traffic_Helper::SIGNATURE."\n".'$sspa_traffic_config = '.var_export($config,true).";\nrequire_once ".var_export(SSPA_PLUGIN_DIR.'traffic-observer/bootstrap.php',true).";\nSSPA_Traffic_Hot_Path::boot(\$sspa_traffic_config);\n";
    file_put_contents(SSPA_Traffic_Helper::path(),$code);
    if(function_exists('opcache_invalidate'))opcache_invalidate(SSPA_Traffic_Helper::path(),true);
    echo $id;
}
