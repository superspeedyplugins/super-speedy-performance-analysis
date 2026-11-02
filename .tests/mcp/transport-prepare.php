<?php
// MCP transport check fixture: MCP turned on exactly as Super Speedy > MCP setup does, and a
// fresh Application Password for the administrator written to the gitignored .data folder.
// Cleared on the way IN; nothing is removed at the end.
require_once dirname(__DIR__) . '/lib/native-guard.php';

$options = get_option('superspeedy_options', array());
$options = is_array($options) ? $options : array();
$options['superspeedy_field_mcp_enabled'] = '1';
update_option('superspeedy_options', $options);

$admin = get_users(array('role' => 'administrator', 'number' => 1))[0];
foreach (WP_Application_Passwords::get_user_application_passwords($admin->ID) as $item) {
    if ('sspa-mcp-transport' === $item['name']) {
        WP_Application_Passwords::delete_application_password($admin->ID, $item['uuid']);
    }
}
$created = WP_Application_Passwords::create_new_application_password($admin->ID, array('name' => 'sspa-mcp-transport'));
if (is_wp_error($created)) {
    throw new RuntimeException($created->get_error_message());
}
file_put_contents(getenv('SSPA_MCP_SECRET_FILE'), $admin->user_login . ':' . $created[0]);
chmod(getenv('SSPA_MCP_SECRET_FILE'), 0600);
echo wp_json_encode(array('user' => $admin->user_login, 'mcp_enabled' => true, 'version' => SSPA_VERSION)) . "\n";
