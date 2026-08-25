<?php
// Settings-page workflow analysis. This proves the target picker discovers a newly
// registered public CPT without a hard-coded list, defaults to its most recently modified
// item, exposes both supported WooCommerce order transports, and runs a real no-change REST
// save with mail suppressed while retaining the mail attempt in the profile.

function sspa_workflow_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

if (!class_exists('SSPA_Workflow_Analysis')) {
    echo "FAIL: the workflow analysis controller is not registered\n";
    return;
}

$fixture_dir = WP_PLUGIN_DIR . '/sspa-workflow-fixture';
if (!is_dir($fixture_dir)) {
    mkdir($fixture_dir);
}
file_put_contents($fixture_dir . '/sspa-workflow-fixture.php', <<<'PHP'
<?php
/**
 * Plugin Name: SSPA Workflow Fixture (test fixture)
 * Version: 1.0.0
 */
add_action('init', function () {
    register_post_type('sspa_book', array(
        'label' => 'Workflow books',
        'public' => true,
        'show_ui' => true,
        'show_in_rest' => true,
        'supports' => array('title', 'editor'),
    ));
});

add_action('save_post_sspa_book', function ($post_id) {
    if ((int) get_option('sspa_workflow_fixture_target') !== (int) $post_id) {
        return;
    }
    wp_mail('sspa-workflow@blackhole.invalid', 'Measured workflow save', 'Suppressed by the profiler.');
}, 20);

add_action('wp_mail_succeeded', function () {
    update_option('sspa_workflow_fixture_delivered', 1, false);
});
add_action('phpmailer_init', function () {
    update_option('sspa_workflow_fixture_transport_reached', 1, false);
});
PHP
);
activate_plugin('sspa-workflow-fixture/sspa-workflow-fixture.php');
wp_cache_flush();
sleep(3); // php-fpm opcache revalidation.

wp_set_current_user(1);
do_action('init');

$older_id = wp_insert_post(array(
    'post_type' => 'sspa_book',
    'post_status' => 'publish',
    'post_title' => 'Older workflow book',
    'post_content' => 'unchanged older content',
));
sleep(1);
$latest_id = wp_insert_post(array(
    'post_type' => 'sspa_book',
    'post_status' => 'publish',
    'post_title' => 'Latest workflow book',
    'post_content' => 'unchanged latest content',
));
update_option('sspa_workflow_fixture_target', (int) $latest_id, false);
delete_option('sspa_workflow_fixture_delivered');
delete_option('sspa_workflow_fixture_transport_reached');

$types = SSPA_Workflow_Analysis::object_types();
$type_keys = wp_list_pluck($types, 'key');
sspa_workflow_t(in_array('post', $type_keys, true), 'posts appear in the workflow picker');
sspa_workflow_t(in_array('page', $type_keys, true), 'pages appear in the workflow picker');
sspa_workflow_t(in_array('product', $type_keys, true), 'products appear in the workflow picker');
sspa_workflow_t(in_array('order', $type_keys, true), 'orders appear in the workflow picker');
sspa_workflow_t(in_array('sspa_book', $type_keys, true), 'a public custom post type appears automatically');

$targets = SSPA_Workflow_Analysis::targets('sspa_book');
sspa_workflow_t(!empty($targets) && (int) $targets[0]['id'] === (int) $latest_id, 'the picker defaults to the most recently modified item');

$book_transports = wp_list_pluck(SSPA_Workflow_Analysis::transports('sspa_book', $latest_id), 'key');
sspa_workflow_t(in_array('rest', $book_transports, true), 'a REST-enabled custom post type exposes its editor transport');

$order_targets = SSPA_Workflow_Analysis::targets('order');
$only_parent_orders = !empty($order_targets);
foreach ($order_targets as $order_target) {
    $target_order = wc_get_order((int) $order_target['id']);
    if (!$target_order || is_a($target_order, 'WC_Order_Refund') || $target_order->has_status('trash')) {
        $only_parent_orders = false;
    }
}
sspa_workflow_t($only_parent_orders, 'the order picker excludes refund records and trashed synthetic orders');

$order_ids = function_exists('wc_get_orders') ? wc_get_orders(array(
    'limit' => 1,
    'return' => 'ids',
    'type' => 'shop_order',
    'status' => array_keys(wc_get_order_statuses()),
)) : array();
if ($order_ids) {
    $order_transports = wp_list_pluck(SSPA_Workflow_Analysis::transports('order', $order_ids[0]), 'key');
    sspa_workflow_t(in_array('classic', $order_transports, true), 'the WooCommerce order picker exposes the classic form handler');
    sspa_workflow_t(in_array('rest', $order_transports, true), 'the WooCommerce order picker exposes the admin REST route');
}

$expiry = time() + 300;
$session_token = wp_generate_password(43, false, false);
$sessions = WP_Session_Tokens::get_instance(1);
$sessions->update($session_token, array(
    'expiration' => $expiry,
    'ip' => '127.0.0.1',
    'ua' => 'SSPA workflow test',
    'login' => time(),
));
$auth_cookie = wp_generate_auth_cookie(1, $expiry, is_ssl() ? 'secure_auth' : 'auth', $session_token);
$logged_cookie = wp_generate_auth_cookie(1, $expiry, 'logged_in', $session_token);
$_COOKIE[LOGGED_IN_COOKIE] = $logged_cookie;
$_COOKIE[is_ssl() ? SECURE_AUTH_COOKIE : AUTH_COOKIE] = $auth_cookie;
$request_cookies = array(
    LOGGED_IN_COOKIE => $logged_cookie,
    (is_ssl() ? SECURE_AUTH_COOKIE : AUTH_COOKIE) => $auth_cookie,
);

$launch = SSPA_Workflow_Analysis::launch('sspa_book', $latest_id, 'rest', true);
if (is_wp_error($launch)) {
    echo 'FAIL: prepare workflow editor launch: ' . $launch->get_error_message() . "\n";
} else {
    sspa_workflow_t(false !== strpos($launch['editor_url'], 'sspa_workflow=1'), 'the controlled editor URL is explicitly workflow-scoped');
    $editor_response = SSPA_Crawler::request($launch['editor_url'], array(
        'sslverify' => false,
        'cookies' => $request_cookies,
    ));
    $editor_html = is_wp_error($editor_response) ? '' : wp_remote_retrieve_body($editor_response);
    sspa_workflow_t(false !== strpos($editor_html, 'sspa-admin-save.js'), 'the controlled editor loads the real save driver');
    sspa_workflow_t(false !== strpos($editor_html, '"active":true') && false !== strpos($editor_html, '"mail_mode":"suppress"'), 'the controlled editor receives the validated suppress-mail workflow');
}

$before = get_post($latest_id);
$rest_target = rest_url('wp/v2/sspa_book/' . $latest_id);
$prepared = SSPA_Admin_Save::prepare($rest_target, 'POST', array(
    'screen_id' => 'sspa_book',
    'object_type' => 'sspa_book',
    'object_id' => (int) $latest_id,
), array(
    'mail_mode' => 'suppress',
    'trigger' => 'workflow',
    'workflow_transport' => 'rest',
));
if (is_wp_error($prepared)) {
    echo 'FAIL: prepare the no-change workflow save: ' . $prepared->get_error_message() . "\n";
} else {
    $response = SSPA_Crawler::request($rest_target, array(
        'method' => 'POST',
        'sslverify' => false,
        'cookies' => $request_cookies,
        'headers' => array(
            'Content-Type' => 'application/json',
            'X-WP-Nonce' => wp_create_nonce('wp_rest'),
            $prepared['header_name'] => $prepared['token'],
        ),
        'body' => '{}',
    ));
    $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
    sspa_workflow_t(200 === $code, 'the real no-change REST save completed (HTTP ' . $code . ')');

    $deadline = microtime(true) + 5;
    do {
        $finished = SSPA_Admin_Save::finish($prepared['run_id'], $prepared['token_id'], array(
            'code' => $code,
            'duration_ms' => 0,
        ));
        if (is_wp_error($finished) && 'sspa_admin_save_pending' === $finished->get_error_code()) {
            usleep(250000);
        } else {
            break;
        }
    } while (microtime(true) < $deadline);

    if (is_wp_error($finished)) {
        echo 'FAIL: finish the no-change workflow save: ' . $finished->get_error_message() . "\n";
    } else {
        $profile = SSPA_Profile_Panel::profile_row($finished['profile_id']);
        $capture = SSPA_Profile_Panel::capture($profile);
        $after = get_post($latest_id);
        sspa_workflow_t('workflow' === SSPA_Run_Controller::run_row($prepared['run_id'])['trigger_source'], 'the run records that the Workflows tab triggered it');
        sspa_workflow_t('admin-save-sspa_book-rest' === $profile['page_key'], 'the result names the selected editor transport');
        sspa_workflow_t((int) $profile['mail_count'] >= 1 && 'suppress' === $capture['mail']['mode'], 'suppressed mail attempts remain visible in the profile');
        sspa_workflow_t(!get_option('sspa_workflow_fixture_delivered') && !get_option('sspa_workflow_fixture_transport_reached'), 'suppressed workflow mail never reaches a transport');
        sspa_workflow_t($before->post_title === $after->post_title && $before->post_content === $after->post_content, 'the save exercises update hooks without changing content or needing restoration');

        $payload = SSPA_Community_Exporter::build(
            (int) $prepared['run_id'],
            wp_generate_uuid4(),
            SSPA_Community_Schema::canonical_time(),
            'manual'
        );
        $save_evidence = null;
        if (is_array($payload)) {
            foreach ((array) $payload['evidence'] as $record) {
                if ('sspa/admin-save' === $record['type']) {
                    $save_evidence = $record;
                    break;
                }
            }
        }
        sspa_workflow_t(
            is_array($save_evidence)
            && 'admin-save-custom-post-type' === $save_evidence['data']['page_class']
            && 'custom-post-type' === $save_evidence['data']['object_type']
            && 'rest' === $save_evidence['data']['transport']
            && 'no-change' === $save_evidence['data']['save_mode']
            && 'suppress' === $save_evidence['data']['mail_mode']
            && false === strpos(wp_json_encode($save_evidence), 'sspa_book'),
            'the shared workflow identifies a no-change custom-post-type save without exposing its private slug'
        );
    }
}

$settings_response = SSPA_Crawler::request(admin_url('admin.php?page=sspa#workflows'), array(
    'sslverify' => false,
    'cookies' => $request_cookies,
));
$settings_html = is_wp_error($settings_response) ? '' : wp_remote_retrieve_body($settings_response);
sspa_workflow_t(false !== strpos($settings_html, 'data-tab="workflows"'), 'the settings page has a Workflows tab');
$tab_response = SSPA_Crawler::request(admin_url('admin-ajax.php'), array(
    'sslverify' => false,
    'cookies' => $request_cookies,
    'method' => 'POST',
    'body' => array(
        'action' => 'sspa_render_tab',
        'nonce' => wp_create_nonce('sspa_admin'),
        'tabs' => 'workflows',
    ),
));
$tab_payload = is_wp_error($tab_response) ? array() : json_decode(wp_remote_retrieve_body($tab_response), true);
$workflow_html = isset($tab_payload['data']['tabs']['workflows']) ? $tab_payload['data']['tabs']['workflows'] : '';
sspa_workflow_t(false !== strpos($workflow_html, 'sspa-workflow-object-type'), 'opening the lazy Workflows tab renders the target picker');
sspa_workflow_t(false !== strpos($workflow_html, 'sspa-ck-open'), 'the rendered Workflows tab includes checkout analysis');

$sessions->destroy($session_token);
unset($_COOKIE[LOGGED_IN_COOKIE], $_COOKIE[AUTH_COOKIE], $_COOKIE[SECURE_AUTH_COOKIE]);
if (!is_wp_error($prepared) && SSPA_Run_Controller::active_run_id() === (int) $prepared['run_id']) {
    SSPA_Run_Controller::cancel($prepared['run_id']);
}
wp_delete_post($older_id, true);
wp_delete_post($latest_id, true);
delete_option('sspa_workflow_fixture_target');
delete_option('sspa_workflow_fixture_delivered');
delete_option('sspa_workflow_fixture_transport_reached');
deactivate_plugins('sspa-workflow-fixture/sspa-workflow-fixture.php');
@unlink($fixture_dir . '/sspa-workflow-fixture.php');
@rmdir($fixture_dir);
sspa_workflow_t(!is_dir($fixture_dir), 'fixture removed');
