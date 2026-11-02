<?php
// Loopback-blocked MCP fixture (site tests-mcp-blocked, served at :8082 behind
// blocked-proxy.cjs). Cleared on the way IN and never torn down: no active run, no leftover
// save-profiling object, no stored loopback route, origin or credentials, the profiler ON (this
// test is about reaching the site, not about enabling the profiler, which the journey covers),
// and a fresh Application Password for the administrator written to the gitignored .data folder.
require_once dirname(__DIR__) . '/lib/native-guard.php';

$active = SSPA_Run_Controller::active_run_id();
if ($active) {
    SSPA_Run_Controller::cancel($active);
}
SSPA_Workflow_Analysis::cleanup();
delete_option('sspa_loopback_route');
delete_option('sspa_loopback_auth');
$options = get_option('sspa_options', array());
if (is_array($options) && array_key_exists('loopback_origin', $options)) {
    unset($options['loopback_origin']);
    update_option('sspa_options', $options);
}
$on = SSPA_Run_Controller::set_profiler(true);
if (is_wp_error($on)) {
    throw new RuntimeException($on->get_error_message());
}
wp_cache_flush();

$admin = get_users(array('role' => 'administrator', 'number' => 1))[0];
foreach (WP_Application_Passwords::get_user_application_passwords($admin->ID) as $item) {
    if ('sspa-mcp-blocked' === $item['name']) {
        WP_Application_Passwords::delete_application_password($admin->ID, $item['uuid']);
    }
}
$created = WP_Application_Passwords::create_new_application_password($admin->ID, array('name' => 'sspa-mcp-blocked'));
if (is_wp_error($created)) {
    throw new RuntimeException($created->get_error_message());
}
file_put_contents(getenv('SSPA_MCP_SECRET_FILE'), $admin->user_login . ':' . $created[0]);
chmod(getenv('SSPA_MCP_SECRET_FILE'), 0600);
echo wp_json_encode(array(
    'user' => $admin->user_login,
    'home' => home_url('/'),
    'version' => SSPA_VERSION,
    'profiler' => SSPA_Helper_Files::health()['mu'],
    'block_editor_post' => use_block_editor_for_post_type('post'),
    'block_editor_product' => post_type_exists('product') && use_block_editor_for_post_type('product'),
)) . "\n";
