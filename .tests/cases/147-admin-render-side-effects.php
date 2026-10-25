<?php
// Ordinary wp-admin screens and the front end, fetched over HTTP as an administrator while a
// plugin change set is pending and the synthetic customer account is in a state WordPress
// refuses to repair (a real user already holds the address PA computes for it). Before
// 0.52.8 the change-set notice built the page catalogue on every admin screen, which edited
// that account and threw an uncaught RuntimeException: every admin page died with a 500.
// Display paths must not write users, and ordinary site mail is never rewritten.
function sspa_147_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
}

global $wpdb;
$log_file = WP_CONTENT_DIR . '/debug.log';
$mu = WPMU_PLUGIN_DIR . '/sspa-render-mail-recorder.php';
copy(SSPA_PLUGIN_DIR . '.tests/fixtures/render-mail-recorder.php', $mu);

// --- State on the way in -------------------------------------------------------------------
sspa_update_option('plugin_update_detection', true);
delete_option(SSPA_Change_Set::OPTION);
foreach (array('sspa-147-holder', 'sspa-147-reader') as $login) {
    $u = get_user_by('login', $login);
    if ($u) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user($u->ID); }
}
// A pending change set, recorded by the real activation hook.
deactivate_plugins('wordpress-importer/wordpress-importer.php');
activate_plugin('wordpress-importer/wordpress-importer.php');
$pending = SSPA_Change_Set::pending();
sspa_147_t(!empty($pending['changes']), 'a plugin change set is pending');

// The marked synthetic account exists, holding an address other than the computed one, and a
// real user holds the computed address - a state a real site reaches when anyone registers
// or edits an account to that address.
$fresh = SSPA_Auth::fresh_test_customer();
$customer_id = is_wp_error($fresh) ? 0 : (int) $fresh;
sspa_147_t($customer_id > 0, 'the synthetic customer exists');
$computed = SSPA_Mail::address('customer');
$wpdb->update($wpdb->users, array('user_email' => 'sspa-147-moved@example.com'), array('ID' => $customer_id));
clean_user_cache($customer_id);
$holder = wp_insert_user(array('user_login' => 'sspa-147-holder', 'user_pass' => wp_generate_password(), 'user_email' => $computed, 'role' => 'subscriber'));
sspa_147_t(!is_wp_error($holder), 'a real user holds the computed address ' . (is_wp_error($holder) ? $holder->get_error_message() : ''));
$users_before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}");

// --- Ordinary admin screens and the front end as an administrator --------------------------
$admin = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
$admin_id = (int) $admin[0];
$cookies = SSPA_Auth::cookies_for('admin', $admin_id);
$log_start = file_exists($log_file) ? filesize($log_file) : 0;
$urls = array(
    admin_url('index.php'),
    admin_url('plugin-install.php'),
    admin_url('plugins.php'),
    admin_url('edit.php'),
    admin_url('users.php'),
    home_url('/'),
);
$notice_seen = false;
foreach ($urls as $url) {
    $response = wp_remote_get($url, array('cookies' => $cookies, 'timeout' => 60, 'redirection' => 0));
    $code = is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_response_code($response);
    $body = is_wp_error($response) ? '' : wp_remote_retrieve_body($response);
    $whole = false !== stripos($body, "</html>") && false === stripos($body, "critical error");
    if (!$whole) { echo "  tail: " . trim(preg_replace("/\s+/", " ", strip_tags(substr($body, -400)))) . "\n"; }
    sspa_147_t(200 === $code && $whole, "$url answers 200 with a whole page (got $code)");
    if (false !== strpos($body, 'plugin change was detected') || false !== strpos($body, 'plugin changes were detected')) {
        $notice_seen = true;
    }
}
sspa_147_t($notice_seen, 'the change-set notice is still shown');
clearstatcache();
$new_log = file_exists($log_file) ? (string) file_get_contents($log_file, false, null, $log_start) : '';
$pa_lines = array_filter(explode("\n", $new_log), static function($line) {
    return preg_match('/PHP (Fatal|Warning|Notice|Deprecated)|Uncaught/', $line) && false !== strpos($line, '/plugins/super-speedy-performance-analysis/');
});
sspa_147_t(!$pa_lines, 'no PHP fatal, warning or notice from PA in debug.log' . ($pa_lines ? ': ' . implode(' | ', array_slice($pa_lines, 0, 3)) : ''));

clean_user_cache($customer_id);
sspa_147_t('sspa-147-moved@example.com' === get_userdata($customer_id)->user_email, 'showing pages did not edit the synthetic account');
sspa_147_t($users_before === (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}"), 'showing pages created or deleted no user');

// --- The analysis that needs the account reports the refusal in its own result ------------
$again = SSPA_Auth::fresh_test_customer();
sspa_147_t(is_wp_error($again) && false !== strpos($again->get_error_message(), 'already used'), 'fresh_test_customer returns the real WordPress error, not an exception (' . (is_wp_error($again) ? $again->get_error_message() : var_export($again, true)) . ')');

// Once the real user moves off the address, the same explicit entry point succeeds. The
// holder stays on the site (renamed address) as evidence; later cases need no clash.
wp_update_user(array('ID' => $holder, 'user_email' => 'sspa-147-holder@example.com'));
$repaired = SSPA_Auth::fresh_test_customer();
sspa_147_t(!is_wp_error($repaired) && $repaired > 0 && $computed === get_userdata($repaired)->user_email, 'with the address free again, the analysis entry point creates the account');

// --- Ordinary site mail is never rewritten ------------------------------------------------
update_option('sspa_test_render_mail', array(), false);
update_option('sspa_test_render_mail_armed', 1, false);
$reader = wp_insert_user(array('user_login' => 'sspa-147-reader', 'user_pass' => wp_generate_password(), 'user_email' => 'sspa-147-reader@example.com', 'role' => 'customer'));
// A WooCommerce customer email sent on an ordinary (unsigned) request.
WC()->mailer()->emails['WC_Email_Customer_New_Account']->trigger($reader);
// Then a password-reset mail requested over HTTP by a logged-out visitor (after the
// in-process send, so this process's cached copy of the log cannot overwrite it).
wp_remote_post(site_url('wp-login.php?action=lostpassword'), array('timeout' => 60, 'redirection' => 0, 'body' => array('user_login' => 'sspa-147-reader', 'redirect_to' => '', 'wp-submit' => 'Get New Password')));
// The HTTP requests wrote the log; read it from the table, not this process's cache.
$mail = (array) maybe_unserialize($wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'sspa_test_render_mail'"));
update_option('sspa_test_render_mail_armed', 0, false);
$tos = array();
foreach ($mail as $m) { $tos = array_merge($tos, $m['to']); }
sspa_147_t(count($mail) >= 2, count($mail) . ' ordinary mails were recorded: ' . implode(' / ', wp_list_pluck($mail, 'subject')));
sspa_147_t($tos && !preg_grep('/\+sspa-perf-/', $tos) && in_array('sspa-147-reader@example.com', $tos, true), 'ordinary mail keeps its real recipient: ' . implode(', ', $tos));
