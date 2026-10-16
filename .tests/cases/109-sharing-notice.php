<?php
function w1_notice_t($ok, $label) { echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n"; }
$readme = file_get_contents(SSPA_PLUGIN_DIR . 'readme.txt');
$external = explode('== ', explode('== External services ==', $readme)[1])[0];
w1_notice_t(substr_count($external, 'https://superspeedy.org/privacy-and-terms/') >= 2, 'External services links both privacy policy and terms to the community notice');
w1_notice_t(strpos($external, 'rules feed') === false && strpos($external, 'https://collector.superspeedy.org/') === false, 'External services has no rules-feed or collector-root wording');
wp_set_current_user(1);
$cookies = SSPA_Auth::cookies_for('admin', 1);
$_COOKIE[LOGGED_IN_COOKIE] = $cookies[LOGGED_IN_COOKIE];
$response = wp_remote_post(admin_url('admin-ajax.php'), array('cookies' => $cookies, 'timeout' => 30, 'body' => array('action' => 'sspa_render_tab', 'tabs' => 'share', 'nonce' => wp_create_nonce('sspa_admin'))));
$document = json_decode(wp_remote_retrieve_body($response), true);
$html = $document['data']['tabs']['share'] ?? '';
w1_notice_t(!is_wp_error($response) && !empty($document['success']) && $html !== '', 'existing share tab renders through authenticated admin-ajax');
$share = html_entity_decode(strip_tags($html));
w1_notice_t(strpos($share, 'aggregates and anonymised individual results') !== false, 'Share consent discloses both forms of publication');
w1_notice_t(strpos($share, 'support@superspeedyplugins.com') !== false && strpos($share, 'installation identifier') !== false, 'Share explains deletion by email with the installation identifier');
w1_notice_t(strpos($share, 'indefinitely') !== false && strpos($share, 'Cloudflare R2') !== false, 'Share discloses archive retention and storage');
