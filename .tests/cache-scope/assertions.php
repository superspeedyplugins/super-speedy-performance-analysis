<?php
wp_set_current_user(1);
$failed = 0;
$check = static function($ok, $message) use (&$failed) { echo ($ok?'PASS':'FAIL') . ': ' . $message . "\n"; if (!$ok) $failed++; };
$check(!wp_using_ext_object_cache(), 'cache-scope regression uses the real database transient backend');
$names = array('sspa_w3_scope', 'superspeedy_changes_w3_scope', 'superspeedy_l_w3_scope', 'ssp_w3_scope');
foreach ($names as $name) set_transient($name, 'synthetic-retain', 600);
$check(count(array_filter(array_map('get_transient', $names)))===4, 'all four actual transient families exist before the action');
$cookies = SSPA_Auth::cookies_for('admin', 1);
$_COOKIE[LOGGED_IN_COOKIE] = $cookies[LOGGED_IN_COOKIE];
$url = add_query_arg(array('action'=>'sspa_bar','do'=>'clear_ours','_wpnonce'=>wp_create_nonce('sspa_bar_clear_ours'),'sspa_return'=>admin_url()), admin_url('admin-post.php'));
$response = wp_remote_get($url, array('cookies'=>$cookies,'redirection'=>0,'timeout'=>30));
$check(!is_wp_error($response) && wp_remote_retrieve_response_code($response)===302, 'authenticated admin-bar cache action returns its real redirect');
foreach ($names as $name) { wp_cache_delete('_transient_' . $name, 'options'); wp_cache_delete('_transient_timeout_' . $name, 'options'); }
wp_cache_delete('notoptions', 'options');
wp_cache_delete('alloptions', 'options');
$check(false===get_transient('sspa_w3_scope'), 'cache action deletes the PA-owned transient');
foreach (array_slice($names,1) as $name) $check('synthetic-retain'===get_transient($name), 'cache action preserves foreign transient ' . $name);
$check(is_file(SSPA_CACHE_SCOPE_EVIDENCE . '/query.sql'), 'actual handler SQL is captured at the database boundary');
if (is_file(SSPA_CACHE_SCOPE_EVIDENCE . '/query.sql')) {
    global $wpdb;
    $sql = file_get_contents(SSPA_CACHE_SCOPE_EVIDENCE . '/query.sql');
    $explain = $wpdb->get_results('EXPLAIN ' . $sql, ARRAY_A);
    file_put_contents(SSPA_CACHE_SCOPE_EVIDENCE . '/explain.json', wp_json_encode(array('rows'=>$explain,'error'=>$wpdb->last_error)));
}
if ($failed) exit(1);
