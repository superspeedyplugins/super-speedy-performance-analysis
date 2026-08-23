<?php
// Interactive admin-save profiling. This drives WordPress's real classic-editor POST to
// wp-admin/post.php, including its redirect, rather than calling wp_update_post() in the
// test process. A fixture makes the real save slow enough that the captured request must
// contain it, and records whether the transport-only token leaked into normal plugin code.

function sspa_admin_save_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

function sspa_admin_save_evidence($run_id, $type) {
    $payload = SSPA_Community_Exporter::build(
        (int) $run_id,
        wp_generate_uuid4(),
        SSPA_Community_Schema::canonical_time(),
        'manual'
    );
    if (is_wp_error($payload)) {
        return $payload;
    }
    foreach ((array) $payload['evidence'] as $record) {
        if ($type === $record['type']) {
            return $record;
        }
    }
    return null;
}

if (!class_exists('SSPA_Admin_Save')) {
    echo "FAIL: the admin update/save profiler is not registered\n";
    return;
}

global $wpdb;

$fixture_dir = WP_PLUGIN_DIR . '/sspa-admin-save-fixture';
if (!is_dir($fixture_dir)) {
    mkdir($fixture_dir);
}
file_put_contents($fixture_dir . '/sspa-admin-save-fixture.php', <<<'PHP'
<?php
/**
 * Plugin Name: SSPA Admin Save Fixture (test fixture)
 * Version: 1.0.0
 */
add_action('save_post', function ($post_id) {
    if ((int) get_option('sspa_admin_save_fixture_post') !== (int) $post_id) {
        return;
    }
    static $measured = false;
    if ($measured) {
        return;
    }
    $measured = true;
    update_option('sspa_admin_save_fixture_seen', array(
        'post' => isset($_POST['_sspa_profile_token']),
        'request' => isset($_REQUEST['_sspa_profile_token']),
    ), false);
    usleep(750000);
    wp_mail('sspa-save@blackhole.invalid', 'Measured admin save', 'This is intercepted by the test fixture.');
}, 20);

// Let wp_mail execute its normal filter stack, but stop before external transport. The
// profiler's deliver-mode hooks must still see and time the call rather than suppress it.
add_filter('pre_wp_mail', function ($return, $atts) {
    if ('Measured admin save' === $atts['subject']) {
        update_option('sspa_admin_save_fixture_mail', 1, false);
        return true;
    }
    return $return;
}, 20, 2);
PHP
);
activate_plugin('sspa-admin-save-fixture/sspa-admin-save-fixture.php');
wp_cache_flush();
sleep(3); // php-fpm opcache revalidation

$post_id = wp_insert_post(array(
    'post_type' => 'post',
    'post_status' => 'draft',
    'post_title' => 'SSPA measured admin save',
    'post_content' => 'The body is deliberately unchanged by the measured update.',
));
update_option('sspa_admin_save_fixture_post', (int) $post_id, false);
delete_option('sspa_admin_save_fixture_seen');
delete_option('sspa_admin_save_fixture_mail');

wp_set_current_user(1);
$expiry = time() + 300;
$session_token = wp_generate_password(43, false, false);
$sessions = WP_Session_Tokens::get_instance(1);
$sessions->update($session_token, array(
    'expiration' => $expiry,
    'ip' => '127.0.0.1',
    'ua' => 'SSPA admin-save test',
    'login' => time(),
));
$auth_cookie = wp_generate_auth_cookie(1, $expiry, is_ssl() ? 'secure_auth' : 'auth', $session_token);
$logged_cookie = wp_generate_auth_cookie(1, $expiry, 'logged_in', $session_token);
$_COOKIE[LOGGED_IN_COOKIE] = $logged_cookie;
$_COOKIE[is_ssl() ? SECURE_AUTH_COOKIE : AUTH_COOKIE] = $auth_cookie;
$save_nonce = wp_create_nonce('update-post_' . $post_id);
$request_cookies = array(
    LOGGED_IN_COOKIE => $logged_cookie,
    (is_ssl() ? SECURE_AUTH_COOKIE : AUTH_COOKIE) => $auth_cookie,
);

$editor_response = SSPA_Crawler::request(admin_url('post.php?post=' . $post_id . '&action=edit'), array(
    'sslverify' => false,
    'cookies' => $request_cookies,
));
$editor_html = is_wp_error($editor_response) ? '' : wp_remote_retrieve_body($editor_response);
sspa_admin_save_t(false !== strpos($editor_html, 'wp-admin-bar-sspa-admin-save'), 'the existing-object editor shows Analyse update/save');
sspa_admin_save_t(false !== strpos($editor_html, 'sspa-admin-save.js'), 'the editor loads the save transport driver');
sspa_admin_save_t(false !== strpos($editor_html, 'editor_button') && false !== strpos($editor_html, 'Analyse update/save'), 'the block editor receives its own fullscreen-safe Analyse update/save control');

$product_ids = get_posts(array('post_type' => 'product', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids'));
if ($product_ids) {
    $product_response = SSPA_Crawler::request(admin_url('post.php?post=' . $product_ids[0] . '&action=edit'), array(
        'sslverify' => false,
        'cookies' => $request_cookies,
    ));
    $product_html = is_wp_error($product_response) ? '' : wp_remote_retrieve_body($product_response);
    sspa_admin_save_t(
        false !== strpos($product_html, 'wp-admin-bar-sspa-admin-save') && false !== strpos($product_html, '"object_type":"product"'),
        'the product editor exposes a product-specific save profile'
    );
}

$order_ids = function_exists('wc_get_orders') ? wc_get_orders(array('limit' => 1, 'return' => 'ids')) : array();
if ($order_ids) {
    $order_response = SSPA_Crawler::request(admin_url('admin.php?page=wc-orders&action=edit&id=' . $order_ids[0]), array(
        'sslverify' => false,
        'cookies' => $request_cookies,
    ));
    $order_html = is_wp_error($order_response) ? '' : wp_remote_retrieve_body($order_response);
    sspa_admin_save_t(
        false !== strpos($order_html, 'wp-admin-bar-sspa-admin-save') && false !== strpos($order_html, '"object_type":"order"'),
        'the WooCommerce HPOS order editor exposes an order-specific save profile'
    );
}

$target = admin_url('post.php');
$prepared = SSPA_Admin_Save::prepare($target, 'POST', array(
    'screen_id' => 'post',
    'object_type' => 'post',
    'object_id' => (int) $post_id,
));
$prepared_run_id = is_wp_error($prepared) ? 0 : (int) $prepared['run_id'];
if (is_wp_error($prepared)) {
    echo 'FAIL: prepare the measured save: ' . $prepared->get_error_message() . "\n";
} else {
    $run = SSPA_Run_Controller::run_row($prepared['run_id']);
    sspa_admin_save_t($run && 'admin_save' === $run['run_type'], 'a distinct admin_save run is armed');

    $response = SSPA_Crawler::request($target, array(
        'method' => 'POST',
        'redirection' => 0,
        'sslverify' => false,
        'cookies' => $request_cookies,
        'body' => array(
            '_wpnonce' => $save_nonce,
            '_wp_http_referer' => '/wp-admin/post.php?post=' . $post_id . '&action=edit',
            'user_ID' => 1,
            'action' => 'editpost',
            'post_ID' => $post_id,
            'post_type' => 'post',
            'original_post_status' => 'draft',
            'post_status' => 'draft',
            'post_title' => 'SSPA measured admin save',
            'content' => 'The body is deliberately unchanged by the measured update.',
            'excerpt' => '',
            'comment_status' => 'closed',
            'ping_status' => 'closed',
            'save' => 'Update',
            $prepared['field_name'] => $prepared['token'],
        ),
    ));
    $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
    $canary = is_wp_error($response) ? '' : (string) wp_remote_retrieve_header($response, 'x-sspa-profiled');
    sspa_admin_save_t($code >= 300 && $code < 400, 'the real post.php update completed with its normal redirect (HTTP ' . $code . ')');
    sspa_admin_save_t($canary === $prepared['token_id'], 'the save request itself carried the profiler canary');

    $finish_deadline = microtime(true) + 5;
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
    } while (microtime(true) < $finish_deadline);
    if (is_wp_error($finished)) {
        echo 'FAIL: finish the measured save: ' . $finished->get_error_message() . "\n";
    } else {
        $profile = SSPA_Profile_Panel::profile_row($finished['profile_id']);
        $capture = SSPA_Profile_Panel::capture($profile);
        sspa_admin_save_t('done' === SSPA_Run_Controller::run_row($prepared['run_id'])['status'], 'the admin_save run completed');
        sspa_admin_save_t('POST' === $profile['method'], 'the stored profile identifies a POST action, not a page load');
        sspa_admin_save_t('admin-save-post' === $profile['page_key'], 'the profile is classified as a post save');
        sspa_admin_save_t((float) $profile['page_gen_ms'] >= 650, 'the real slow save callback is inside the profile (' . $profile['page_gen_ms'] . 'ms)');
        sspa_admin_save_t(is_array($capture) && !empty($capture['boot']['segments']), 'the save has full request-phase diagnostics');
        sspa_admin_save_t((int) $profile['mail_count'] >= 1 && (int) get_option('sspa_admin_save_fixture_mail') === 1, 'mail ran normally and was measured');

        $page_evidence = sspa_admin_save_evidence($prepared['run_id'], 'sspa/page-profile');
        $save_evidence = sspa_admin_save_evidence($prepared['run_id'], 'sspa/admin-save');
        sspa_admin_save_t(
            is_array($page_evidence) && 'admin-save-post' === $page_evidence['data']['page_class'],
            'the shared page profile keeps the post-save classification instead of collapsing to custom-admin'
        );
        sspa_admin_save_t(
            is_array($save_evidence)
            && 'post' === $save_evidence['data']['object_type']
            && 'classic' === $save_evidence['data']['transport']
            && 'editor-update' === $save_evidence['data']['save_mode']
            && 'deliver' === $save_evidence['data']['mail_mode']
            && false === $save_evidence['data']['editor_reload_measured'],
            'the shared classic save records its privacy-safe workflow context'
        );
    }

    wp_cache_delete('sspa_admin_save_fixture_seen', 'options');
    wp_cache_delete('notoptions', 'options');
    $seen = get_option('sspa_admin_save_fixture_seen');
    sspa_admin_save_t(is_array($seen) && !$seen['post'] && !$seen['request'], 'the transport token was removed before save_post callbacks ran');
}

// Block editors use the REST API and carry the token as a header. Drive that real update
// too, with the same authenticated browser session, so neither transport is inferred from
// source code alone.
$rest_target = rest_url('wp/v2/posts/' . $post_id);
$rest_prepared = SSPA_Admin_Save::prepare($rest_target, 'POST', array(
    'screen_id' => 'post',
    'object_type' => 'post',
    'object_id' => (int) $post_id,
));
if (is_wp_error($rest_prepared)) {
    echo 'FAIL: prepare the REST save: ' . $rest_prepared->get_error_message() . "\n";
} else {
    $rest_response = SSPA_Crawler::request($rest_target, array(
        'method' => 'POST',
        'sslverify' => false,
        'cookies' => $request_cookies,
        'headers' => array(
            'Content-Type' => 'application/json',
            'X-WP-Nonce' => wp_create_nonce('wp_rest'),
            $rest_prepared['header_name'] => $rest_prepared['token'],
        ),
        'body' => wp_json_encode(array('title' => 'SSPA measured REST admin save')),
    ));
    $rest_code = is_wp_error($rest_response) ? 0 : (int) wp_remote_retrieve_response_code($rest_response);
    $rest_canary = is_wp_error($rest_response) ? '' : (string) wp_remote_retrieve_header($rest_response, 'x-sspa-profiled');
    sspa_admin_save_t(200 === $rest_code, 'the real block-editor REST update completed (HTTP ' . $rest_code . ')');
    sspa_admin_save_t($rest_canary === $rest_prepared['token_id'], 'the REST save carried the header profiler canary');

    $rest_deadline = microtime(true) + 5;
    do {
        $rest_finished = SSPA_Admin_Save::finish($rest_prepared['run_id'], $rest_prepared['token_id'], array(
            'code' => $rest_code,
            'duration_ms' => 0,
        ));
        if (is_wp_error($rest_finished) && 'sspa_admin_save_pending' === $rest_finished->get_error_code()) {
            usleep(250000);
        } else {
            break;
        }
    } while (microtime(true) < $rest_deadline);
    if (is_wp_error($rest_finished)) {
        echo 'FAIL: finish the REST save: ' . $rest_finished->get_error_message() . "\n";
    } else {
        $rest_profile = SSPA_Profile_Panel::profile_row($rest_finished['profile_id']);
        sspa_admin_save_t('POST' === $rest_profile['method'], 'the REST action is stored as a write profile');
        sspa_admin_save_t((float) $rest_profile['page_gen_ms'] >= 650, 'the REST profile contains the same real save cascade (' . $rest_profile['page_gen_ms'] . 'ms)');
        $rest_evidence = sspa_admin_save_evidence($rest_prepared['run_id'], 'sspa/admin-save');
        sspa_admin_save_t(
            is_array($rest_evidence)
            && 'post' === $rest_evidence['data']['object_type']
            && 'rest' === $rest_evidence['data']['transport'],
            'the shared REST save is distinct from the classic form transport'
        );

        $stored_consent = get_option('sspa_share_consent_version', null);
        update_option('sspa_share_consent_version', 3, false);
        $preview = SSPA_Submitter::dry_run_preview();
        if (null === $stored_consent) {
            delete_option('sspa_share_consent_version');
        } else {
            update_option('sspa_share_consent_version', $stored_consent, false);
        }
        sspa_admin_save_t(
            !is_wp_error($preview) && false !== strpos($preview, 'sspa/admin-save'),
            'the pre-consent preview shows the complete current payload rather than the older accepted version'
        );
    }
}

$sessions->destroy($session_token);
unset($_COOKIE[LOGGED_IN_COOKIE], $_COOKIE[AUTH_COOKIE], $_COOKIE[SECURE_AUTH_COOKIE]);
if ($prepared_run_id && SSPA_Run_Controller::active_run_id() === $prepared_run_id) {
    SSPA_Run_Controller::cancel($prepared_run_id);
}
if (!is_wp_error($rest_prepared) && SSPA_Run_Controller::active_run_id() === (int) $rest_prepared['run_id']) {
    SSPA_Run_Controller::cancel($rest_prepared['run_id']);
}

wp_delete_post($post_id, true);
delete_option('sspa_admin_save_fixture_post');
delete_option('sspa_admin_save_fixture_seen');
delete_option('sspa_admin_save_fixture_mail');
deactivate_plugins('sspa-admin-save-fixture/sspa-admin-save-fixture.php');
@unlink($fixture_dir . '/sspa-admin-save-fixture.php');
@rmdir($fixture_dir);
sspa_admin_save_t(!is_dir($fixture_dir), 'fixture removed');
