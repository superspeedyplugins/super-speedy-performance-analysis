<?php
// Real signed probe -> installed mailer boundary. This remains red while Decision 2 is stopped.
require_once __DIR__ . '/../lib/native-guard.php';
function w1_mail_t($ok, $label) { echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n"; }
$fixture = WP_PLUGIN_DIR . '/sspa-w1-mail-boundary';
wp_mkdir_p($fixture);
file_put_contents($fixture . '/boundary.php', <<<'FIXTURE'
<?php
/* Plugin Name: W1 synthetic mail boundary */
if (get_option('sspa_w1_replacement_mailer') && !function_exists('wp_mail')) {
    function wp_mail($to, $subject, $message, $headers = '', $attachments = array()) {
        $rows = get_option('sspa_w1_mail_boundary', array());
        $rows[] = array('boundary' => 'replacement-queue', 'to' => (array) $to);
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
});
add_action('save_post', static function($id) {
    if ((int) get_option('sspa_w1_workflow_target') === (int) $id) {
        $post = get_post($id);
        wp_mail(get_the_author_meta('user_email', $post->post_author), 'W1 existing content update', 'Existing author notification');
    }
});
add_action('phpmailer_init', static function($mail) {
    $rows = get_option('sspa_w1_mail_boundary', array());
    $rows[] = array('boundary' => 'core-transport', 'to' => array_column($mail->getAllRecipientAddresses() ? array_merge($mail->getToAddresses(), $mail->getCcAddresses(), $mail->getBccAddresses()) : array(), 0));
    update_option('sspa_w1_mail_boundary', $rows, false);
}, PHP_INT_MAX);
FIXTURE
);
activate_plugin('sspa-w1-mail-boundary/boundary.php');
sleep(3);
foreach (array(false, true) as $replacement) {
    update_option('sspa_w1_replacement_mailer', $replacement, false);
    delete_option('sspa_w1_mail_boundary');
    // The crawler creates the one-use token and ingests real shutdown evidence.
    $result = (new SSPA_Crawler())->profile_job(array('page_key' => 'mail-probe', 'url' => home_url('/?sspa_mail_probe=1'), 'variant' => 'anon', 'flags' => array('mp' => '1', 'mail' => 'd'), 'skip_warmup' => true, 'samples' => 1), 1);
    wp_cache_delete('sspa_w1_mail_boundary', 'options'); wp_cache_delete('notoptions', 'options');
    $rows = get_option('sspa_w1_mail_boundary', array());
    $admin = get_option('admin_email');
    $only_admin = !empty($rows);
    $count = 0;
    foreach ($rows as $row) foreach ($row['to'] as $recipient) {
        $count++;
        $untagged = preg_replace('/\+[^@]*(?=@)/', '', $recipient);
        if ($recipient !== $admin && $untagged !== $admin) $only_admin = false;
    }
    w1_mail_t(!empty($rows) && $count > 0, ($replacement ? 'replacement queue' : 'core SMTP transport') . ' captures the real mail probe recipient');
    w1_mail_t($only_admin, ($replacement ? 'replacement queue' : 'core SMTP transport') . ' receives only administrator addresses');
}
// A no-change Workflows save uses an existing content author, not a synthetic recipient.
wp_set_current_user(1);
$author = username_exists('w1-workflow-author');
if (!$author) $author = wp_insert_user(array('user_login' => 'w1-workflow-author', 'user_pass' => wp_generate_password(32), 'user_email' => 'w1-author@example.invalid', 'role' => 'author'));
$post = wp_insert_post(array('post_title' => 'W1 existing author workflow', 'post_status' => 'publish', 'post_author' => $author));
update_option('sspa_w1_workflow_target', $post, false);
update_option('sspa_w1_replacement_mailer', true, false);
delete_option('sspa_w1_mail_boundary');
$cookies = SSPA_Auth::cookies_for('admin', 1);
$_COOKIE[LOGGED_IN_COOKIE] = $cookies[LOGGED_IN_COOKIE];
$prepared = SSPA_Admin_Save::prepare(rest_url('wp/v2/posts/' . $post), 'POST', array('screen_id' => 'post', 'object_type' => 'post', 'object_id' => $post), array('mail_mode' => 'suppress', 'trigger' => 'workflow', 'workflow_transport' => 'rest'));
if (is_wp_error($prepared)) { w1_mail_t(false, $prepared->get_error_message()); }
else {
    $response = wp_remote_post(rest_url('wp/v2/posts/' . $post), array('cookies' => $cookies, 'timeout' => 30, 'headers' => array('Content-Type' => 'application/json', 'X-WP-Nonce' => wp_create_nonce('wp_rest'), $prepared['header_name'] => $prepared['token']), 'body' => '{}'));
    w1_mail_t(!is_wp_error($response) && 200 === wp_remote_retrieve_response_code($response), 'real no-change workflow saves the existing author content');
    wp_cache_delete('sspa_w1_mail_boundary', 'options'); wp_cache_delete('notoptions', 'options');
    $rows = get_option('sspa_w1_mail_boundary', array());
    w1_mail_t(!empty($rows) && $rows[0]['boundary'] === 'replacement-queue', 'replacement mailer receives the workflow notification despite suppress mode');
    w1_mail_t(!empty($rows) && $rows[0]['to'] === array(get_option('admin_email')), 'existing-author workflow notification goes only to an administrator');
    SSPA_Run_Controller::cancel($prepared['run_id']);
}
update_option('sspa_w1_workflow_target', 0, false);
update_option('sspa_w1_replacement_mailer', false, false);
// Count every actual wp_mail invocation from the complete baseline plus optional writes.
update_option('sspa_w1_count_enabled', true, false);
update_option('sspa_w1_mail_attempts', array(), false);
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
    echo 'MAIL INVENTORY: ' . count($attempts) . ' wp_mail attempts: ' . wp_json_encode($subjects) . "\n";
}
update_option('sspa_w1_count_enabled', false, false);
deactivate_plugins('sspa-w1-mail-boundary/boundary.php', true);
