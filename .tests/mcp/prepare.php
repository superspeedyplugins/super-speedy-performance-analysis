<?php
// MCP journey fixture, cleared on the way IN and never torn down: the zz-slow-shop plugin
// (120 ms on shop and product-cat), no active run, the profiler OFF (the journey turns it on
// through set-profiler), and a fresh Application Password for the administrator. The
// password is written to the gitignored .data folder for the journey and never printed.
require_once dirname(__DIR__) . '/lib/native-guard.php';

$slug = 'zz-slow-shop';
$dir = WP_PLUGIN_DIR . '/' . $slug;
if (!is_dir($dir) && !mkdir($dir)) {
    throw new RuntimeException('cannot create ' . $dir);
}
file_put_contents($dir . '/' . $slug . '.php', <<<'CODE'
<?php
/**
 * Plugin Name: zz-slow-shop (test fixture)
 * Version: 1.0.0
 */
add_action('wp_footer', function () {
    if (function_exists('is_shop') && (is_shop() || is_product_category())) {
        global $wpdb;
        $wpdb->get_var('SELECT SLEEP(0.12)');
    }
});
CODE
);
wp_clean_plugins_cache();
$activated = activate_plugin($slug . '/' . $slug . '.php');
if (is_wp_error($activated)) {
    throw new RuntimeException($activated->get_error_message());
}
$active = SSPA_Run_Controller::active_run_id();
if ($active) {
    SSPA_Run_Controller::cancel($active);
}
$off = SSPA_Run_Controller::set_profiler(false);
if (is_wp_error($off)) {
    throw new RuntimeException($off->get_error_message());
}
delete_option(SSPA_Cache_Delivery::PENDING_OPTION);
wp_cache_flush();

$admin = get_users(array('role' => 'administrator', 'number' => 1))[0];
foreach (WP_Application_Passwords::get_user_application_passwords($admin->ID) as $item) {
    if ('sspa-mcp-journey' === $item['name']) {
        WP_Application_Passwords::delete_application_password($admin->ID, $item['uuid']);
    }
}
$created = WP_Application_Passwords::create_new_application_password($admin->ID, array('name' => 'sspa-mcp-journey'));
if (is_wp_error($created)) {
    throw new RuntimeException($created->get_error_message());
}
// A subscriber with its own Application Password, to prove the capability gates over REST.
$subscriber = get_user_by('login', 'sspa-mcp-subscriber');
$subscriber_id = $subscriber ? $subscriber->ID : wp_insert_user(array('user_login' => 'sspa-mcp-subscriber', 'user_pass' => wp_generate_password(24), 'user_email' => 'mcp-subscriber@example.invalid', 'role' => 'subscriber'));
if (is_wp_error($subscriber_id)) {
    throw new RuntimeException($subscriber_id->get_error_message());
}
foreach (WP_Application_Passwords::get_user_application_passwords($subscriber_id) as $item) {
    WP_Application_Passwords::delete_application_password($subscriber_id, $item['uuid']);
}
$sub = WP_Application_Passwords::create_new_application_password($subscriber_id, array('name' => 'sspa-mcp-journey'));
if (is_wp_error($sub)) {
    throw new RuntimeException($sub->get_error_message());
}
file_put_contents(getenv('SSPA_MCP_SECRET_FILE') . '.subscriber', 'sspa-mcp-subscriber:' . $sub[0]);
chmod(getenv('SSPA_MCP_SECRET_FILE') . '.subscriber', 0600);

$out = getenv('SSPA_MCP_SECRET_FILE');
file_put_contents($out, $admin->user_login . ':' . $created[0]);
chmod($out, 0600);
echo wp_json_encode(array('user' => $admin->user_login, 'cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON, 'profiler' => SSPA_Helper_Files::health()['mu'])) . "\n";
