<?php
if (!defined('SSPA_CACHE_SCOPE_EVIDENCE')) return;
if (($_GET['action'] ?? '') === 'sspa_bar' && ($_GET['do'] ?? '') === 'clear_ours' && !defined('SAVEQUERIES')) define('SAVEQUERIES', true);
add_filter('query', static function($sql) {
    if (preg_match('/^\s*DELETE\s+FROM/i', $sql) && strpos($sql, "REGEXP '^_(site_)?transient") !== false) {
        file_put_contents(SSPA_CACHE_SCOPE_EVIDENCE . '/query.sql', $sql);
    }
    return $sql;
});
register_shutdown_function(static function() {
    global $wpdb;
    foreach ((array) ($wpdb->queries ?? array()) as $query) {
        if (preg_match('/^\s*DELETE\s+FROM/i', $query[0]) && strpos($query[0], "REGEXP '^_(site_)?transient") !== false) {
            file_put_contents(SSPA_CACHE_SCOPE_EVIDENCE . '/query-timing.json', json_encode(array('sql'=>$query[0],'seconds'=>$query[1])));
        }
    }
});
