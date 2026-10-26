<?php
// Real signed requests, actual SMTP and replacement queues, and actual editor saves.
require_once __DIR__ . '/../lib/native-guard.php';
function w1_mail_t($ok, $label) { echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n"; }
function w1_mail_option($key) {
    wp_cache_delete($key, 'options'); wp_cache_delete('notoptions', 'options');
    return get_option($key, array());
}
function w1_mail_admin_only($rows) {
    $admin = get_option('admin_email'); $count = 0;
    foreach ($rows as $row) foreach ($row['to'] as $recipient) {
        $count++;
        if (preg_replace('/\+[^@]*(?=@)/', '', $recipient) !== preg_replace('/\+[^@]*(?=@)/', '', $admin)) return false;
    }
    return $count > 0;
}
$fixture = WP_PLUGIN_DIR . '/sspa-w1-mail-boundary';
wp_mkdir_p($fixture);
file_put_contents($fixture . '/boundary.php', <<<'FIXTURE'
<?php
/* Plugin Name: W1 synthetic mail boundary
 * Version: 1.0.0
 */
if (get_option('sspa_w1_replacement_mailer') && !function_exists('wp_mail')) {
    function wp_mail($to, $subject, $message, $headers = '', $attachments = array()) {
        $atts = compact('to', 'subject', 'message', 'headers', 'attachments');
        if ('filtered' === get_option('sspa_w1_replacement_mailer')) $atts = apply_filters('wp_mail', $atts);
        $recipients = (array) $atts['to'];
        foreach ((array) $atts['headers'] as $header) {
            foreach (preg_split('/\r?\n/', $header) as $line) if (preg_match('/^(?:cc|bcc):\s*(.*)/i', $line, $m)) $recipients = array_merge($recipients, array_map('trim', explode(',', $m[1])));
        }
        $rows = get_option('sspa_w1_mail_boundary', array());
        $rows[] = array('boundary' => 'replacement-queue', 'to' => $recipients);
        update_option('sspa_w1_mail_boundary', $rows, false);
        return true;
    }
}
add_filter('wp_mail', static function($atts) {
    if (get_option('sspa_w1_count_enabled')) {
        $rows = get_option('sspa_w1_mail_attempts', array());
        $rows[] = array('to' => $atts['to'], 'subject' => $atts['subject']);
        update_option('sspa_w1_mail_attempts', $rows, false);
    }
    return $atts;
}, PHP_INT_MAX);
add_action('init', static function() { register_post_type('w1_book', array('public' => true, 'show_ui' => true, 'show_in_rest' => true, 'supports' => array('title', 'editor'))); });
add_action('save_post', static function($id) {
    if ((int) get_option('sspa_w1_workflow_target') === (int) $id) {
        wp_mail('external-author@example.invalid', 'W1 workflow update', 'Save notification', array('Cc: external-cc@example.invalid', 'Bcc: external-bcc@example.invalid'));
    }
});
add_action('woocommerce_update_order', static function($id) {
    if ((int) get_option('sspa_w1_workflow_target') === (int) $id) wp_mail('external-order@example.invalid', 'W1 workflow order', 'Order notification', "Cc: external-cc@example.invalid\r\nBcc: external-bcc@example.invalid");
});
add_action('phpmailer_init', static function($mail) {
    $rows = get_option('sspa_w1_mail_boundary', array());
    $rows[] = array('boundary' => 'core-transport', 'to' => array_column(array_merge($mail->getToAddresses(), $mail->getCcAddresses(), $mail->getBccAddresses()), 0));
    update_option('sspa_w1_mail_boundary', $rows, false);
}, PHP_INT_MAX);
FIXTURE
);
activate_plugin('sspa-w1-mail-boundary/boundary.php');
update_option('sspa_w1_workflow_target', 0, false);
$smtp_file = SSPA_PLUGIN_DIR . '.data/local-services/' . basename(rtrim(ABSPATH, '/')) . '/mail.jsonl';
$smtp_offset = is_file($smtp_file) ? filesize($smtp_file) : 0;
sleep(3);
wp_set_current_user(1);
register_post_type('w1_book', array('public' => true, 'show_ui' => true, 'show_in_rest' => true, 'supports' => array('title', 'editor')));
$cookies = SSPA_Auth::cookies_for('admin', 1);
$_COOKIE[LOGGED_IN_COOKIE] = $cookies[LOGGED_IN_COOKIE];
$active = SSPA_Run_Controller::active_run_id(); if ($active) SSPA_Run_Controller::cancel($active);
$customer = SSPA_Auth::fresh_test_customer();
w1_mail_t(w1_mail_admin_only(array(array('to' => array(get_userdata($customer)->user_email)))), 'synthetic customer account uses administrator plus addressing');
$stock_product = new WC_Product_Simple();
$stock_product->set_name('W1 untouched catalogue stock');
$stock_product->set_status('publish');
$stock_product->set_regular_price('10');
$stock_product->set_manage_stock(true);
$stock_product->set_stock_quantity(17);
$stock_id = $stock_product->save();
foreach (array(false, 'filtered', 'unfiltered') as $replacement) {
    update_option('sspa_w1_replacement_mailer', $replacement, false);
    delete_option('sspa_w1_mail_boundary');
    $result = (new SSPA_Crawler())->profile_job(array('page_key' => 'mail-probe', 'url' => home_url('/?sspa_mail_probe=1'), 'variant' => 'anon', 'flags' => array('mp' => '1'), 'skip_warmup' => true, 'samples' => 1), 1);
    $rows = w1_mail_option('sspa_w1_mail_boundary');
    w1_mail_t(w1_mail_admin_only($rows), ($replacement ?: 'core SMTP') . ' probe sends for real to an administrator');
    $capture = $result['samples'][0]['capture'] ?? array();
    if ($replacement) w1_mail_t(strpos($capture['mail']['warning'] ?? '', 'W1 synthetic mail boundary') !== false, 'replacement owner warning is retained in the actual capture');
    else w1_mail_t(($capture['mail']['total_construct_ms'] ?? 0) > 0, 'core mail send time is measured');

    foreach (array('post', 'page', 'product', 'order', 'w1_book') as $type) {
        // SSPA_Auth::cookies_for() lasts 300 seconds; this case can run longer than that on a busy
        // machine, after which the editor answers 302 and REST saves 403. Fresh cookies per object.
        $cookies = SSPA_Auth::cookies_for('admin', 1);
        $_COOKIE[LOGGED_IN_COOKIE] = $cookies[LOGGED_IN_COOKIE];
        // Old launch accepted real IDs. Supplying one must now create a different owned object.
        $real = $type === 'order' ? wc_create_order() : wp_insert_post(array('post_type' => $type, 'post_status' => 'draft', 'post_title' => 'W1 real untouched content', 'post_author' => 1));
        $real_id = is_object($real) ? $real->get_id() : $real;
        $before = wp_json_encode($type === 'order' ? wc_get_order($real_id)->get_data() : get_post($real_id, ARRAY_A));
        $transport = $type === 'product' ? 'classic' : 'rest';
        $launch = SSPA_Workflow_Analysis::launch($type, $real_id, $transport);
        $id = is_wp_error($launch) ? 0 : ($launch['object_id'] ?? 0);
        w1_mail_t($id && $id !== $real_id, "$type workflow creates its own object instead of selecting real content");
        if (!$id || $id === $real_id) continue;
        if ($replacement) {
            $editor = wp_remote_get($launch['editor_url'], array('cookies' => $cookies, 'timeout' => 30));
            w1_mail_t(strpos(wp_remote_retrieve_body($editor), 'Administrator recipient rewriting cannot be confirmed') !== false && strpos(wp_remote_retrieve_body($editor), 'W1 synthetic mail boundary') !== false, 'owner warning is shown in the actual editor before the run starts');
        }
        w1_mail_t(is_wp_error(SSPA_Admin_Save::prepare(rest_url('wp/v2/posts/' . $real_id), 'POST', array('object_type' => $type, 'object_id' => $real_id), array('trigger' => 'workflow'))), 'workflow prepare refuses a real existing target');
        $product_id = $type === 'order' ? wc_get_order($id)->get_meta('_sspa_temp_product_id') : 0;
        if ($type === 'order') {
            $owned_product = wc_get_product($product_id);
            w1_mail_t($owned_product && $owned_product->managing_stock() && (float) $owned_product->get_price() === 0.0 && w1_mail_admin_only(array(array('to' => array(wc_get_order($id)->get_billing_email())))), 'workflow order owns a zero-price separately stocked product and admin billing');
        } else w1_mail_t((int) get_post($id)->post_author === 1 && get_post_meta($id, '_sspa_temp', true), "$type object is marked and owned by the administrator");
        update_option('sspa_w1_workflow_target', $id, false);
        delete_option('sspa_w1_mail_boundary');
        $url = $type === 'order' ? rest_url('wc/v3/orders/' . $id) : ($transport === 'rest' ? rest_url('wp/v2/' . ($type === 'post' ? 'posts' : ($type === 'page' ? 'pages' : $type)) . '/' . $id) : admin_url('post.php'));
        $prepared = SSPA_Admin_Save::prepare($url, 'POST', array('screen_id' => $type, 'object_type' => $type, 'object_id' => $id), array('trigger' => 'workflow', 'workflow_transport' => $transport));
        w1_mail_t(!is_wp_error($prepared), "$type workflow proceeds with " . ($replacement ?: 'core wp_mail'));
        if (is_wp_error($prepared)) continue;
        $response = wp_remote_post($url, array('cookies' => $cookies, 'timeout' => 30, 'redirection' => 0,
            'headers' => array('X-WP-Nonce' => wp_create_nonce('wp_rest'), $prepared['header_name'] => $prepared['token'], 'Content-Type' => $transport === 'rest' ? 'application/json' : 'application/x-www-form-urlencoded'),
            'body' => $transport === 'rest' ? '{}' : array('action' => 'editpost', 'post_ID' => $id, 'post_type' => $type, 'post_status' => 'draft', 'post_title' => get_the_title($id), '_wpnonce' => wp_create_nonce('update-post_' . $id))));
        $code = wp_remote_retrieve_response_code($response);
        w1_mail_t(!is_wp_error($response) && in_array($code, array(200, 302), true), "$type actual $transport save succeeds (HTTP $code)" . (is_wp_error($response) ? ": " . $response->get_error_message() : ""));
        $rows = w1_mail_option('sspa_w1_mail_boundary');
        if ($replacement !== 'unfiltered') w1_mail_t(w1_mail_admin_only($rows), "$type transport receives only administrator to/cc/bcc");
        $finished = SSPA_Admin_Save::finish($prepared['run_id'], $prepared['token_id'], array('code' => $code));
        w1_mail_t(!is_wp_error($finished), "$type workflow result is stored" . (is_wp_error($finished) ? ": " . $finished->get_error_message() : ""));
        if (!is_wp_error($finished) && $replacement) {
            $profile = SSPA_Profile_Panel::profile_row($finished['profile_id']);
            $stored = SSPA_Profile_Panel::capture($profile);
            w1_mail_t(strpos($stored['mail']['warning'] ?? '', 'W1 synthetic mail boundary') !== false, 'replacement warning persists in the saved workflow result');
            $html = SSPA_Profile_Panel::render($finished['profile_id']);
            w1_mail_t(!is_wp_error($html) && strpos($html, 'W1 synthetic mail boundary') !== false && strpos($html, 'Administrator recipient rewriting cannot be confirmed') !== false, 'saved result displays the replacement warning');
        }
        $after = wp_json_encode($type === 'order' ? wc_get_order($real_id)->get_data() : get_post($real_id, ARRAY_A));
        w1_mail_t($before === $after, "$type real existing object is untouched");
        w1_mail_t(wc_get_product($stock_id)->get_stock_quantity() === 17, "$type workflow leaves real catalogue stock untouched");
        clean_post_cache($id);
        w1_mail_t($type === 'order' ? !wc_get_order($id) && !wc_get_product($product_id) : !get_post($id), "$type workflow removes its owned objects at run end");
        if (is_wp_error($finished)) SSPA_Run_Controller::cancel($prepared['run_id']);
        update_option('sspa_w1_workflow_target', 0, false);
    }
}
update_option('sspa_w1_replacement_mailer', false, false);
w1_mail_t(\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(), 'HPOS remains enabled');
$leftover = SSPA_Workflow_Analysis::launch('post', 0, 'rest');
$next = SSPA_Workflow_Analysis::launch('page', 0, 'rest');
w1_mail_t(!is_wp_error($leftover) && !is_wp_error($next) && !get_post($leftover['object_id']), 'next workflow launch removes an abandoned owned object');
if (!is_wp_error($next)) {
    $prepared = SSPA_Admin_Save::prepare(rest_url('wp/v2/pages/' . $next['object_id']), 'POST', array('object_type' => 'page', 'object_id' => $next['object_id']), array('trigger' => 'workflow', 'workflow_transport' => 'rest'));
    if (!is_wp_error($prepared)) SSPA_Run_Controller::cancel($prepared['run_id']);
    w1_mail_t(!is_wp_error($prepared) && !get_post($next['object_id']), 'cancellation removes the owned workflow object');
}
// Count every actual wp_mail invocation from the complete baseline plus optional writes.
update_option('sspa_w1_count_enabled', true, false);
update_option('sspa_w1_mail_attempts', array(), false);
delete_option('sspa_w1_mail_boundary');
$run = SSPA_Run_Controller::start(array('type' => 'baseline', 'include_writes' => true, 'user_id' => 1, 'share' => false));
if (is_wp_error($run)) { w1_mail_t(false, $run->get_error_message()); }
else {
    $deadline = time() + 600;
    do { SSPA_Run_Controller::process_batch($run); $status = SSPA_Run_Controller::status($run); }
    while ($status && $status['status'] === 'crawling' && time() < $deadline);
    w1_mail_t($status && $status['status'] === 'done', 'full baseline with writes completes for the mail inventory');
    if (!$status || $status['status'] !== 'done') SSPA_Run_Controller::cancel($run);
    wp_cache_delete('sspa_w1_mail_attempts', 'options'); wp_cache_delete('notoptions', 'options');
    $attempts = get_option('sspa_w1_mail_attempts', array());
    $subjects = array_count_values(wp_list_pluck($attempts, 'subject'));
    w1_mail_t(w1_mail_admin_only(w1_mail_option('sspa_w1_mail_boundary')), 'every full-analysis transport recipient is an administrator');
    w1_mail_t(wc_get_product($stock_id)->get_stock_quantity() === 17, 'real catalogue stock is unchanged by workflows and full write analysis');
    echo 'MAIL INVENTORY: ' . count($attempts) . ' wp_mail attempts: ' . wp_json_encode($subjects) . "\n";
}
update_option('sspa_w1_count_enabled', false, false);
deactivate_plugins('sspa-w1-mail-boundary/boundary.php', true);
$smtp = is_file($smtp_file) ? substr(file_get_contents($smtp_file), $smtp_offset) : '';
$envelopes = array();
foreach (explode("\n", trim($smtp)) as $line) {
    $message = json_decode($line, true);
    foreach (($message['envelope'] ?? array()) as $command) if (preg_match('/^RCPT TO:\s*<([^>]+)>/i', $command, $match)) $envelopes[] = $match[1];
}
w1_mail_t(w1_mail_admin_only(array(array('to' => $envelopes))), 'actual SMTP sink envelopes contain only administrator recipients');
echo 'SMTP EVIDENCE: ' . count($envelopes) . ' accepted recipients captured at the real SMTP boundary' . "\n";
