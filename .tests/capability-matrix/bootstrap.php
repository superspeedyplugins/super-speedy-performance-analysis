<?php
$config = "<?php\n";
foreach (array('DB_NAME'=>'sspa_matrix','DB_USER'=>getenv('SSPA_MATRIX_USER'),'DB_PASSWORD'=>getenv('SSPA_MATRIX_PASSWORD'),'DB_HOST'=>getenv('SSPA_MATRIX_DB'),'DB_CHARSET'=>'utf8mb4','DB_COLLATE'=>'') as $key=>$value) {
    $config .= 'define('.var_export($key,true).', '.var_export($value,true).");\n";
}
$config .= "\$table_prefix = 'wp_';\ndefine('WP_ENVIRONMENT_TYPE', 'local');\ndefine('WP_DEBUG', true);\ndefine('WP_DEBUG_DISPLAY', false);\ndefine('ABSPATH', __DIR__.'/');\nrequire_once ABSPATH.'wp-settings.php';\n";
file_put_contents('/var/www/html/wp-config.php',$config);
mkdir('/var/www/html/wp-content/mu-plugins',0777,true);
file_put_contents('/var/www/html/wp-content/mu-plugins/matrix-no-delivery.php', "<?php\nadd_filter('pre_http_request',function(){return new WP_Error('matrix_offline','External HTTP disabled in capability matrix');});\nadd_filter('pre_wp_mail', '__return_true');\n");
