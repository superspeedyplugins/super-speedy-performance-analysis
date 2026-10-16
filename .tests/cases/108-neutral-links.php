<?php
function w1_links_t($ok, $label) { echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n"; }
// Render the existing Overview with a real retained autoload finding.
// The contextual recommendation appears when Scalability Pro is not already active.
deactivate_plugins('scalability-pro/scalability-pro.php', true);
global $wpdb;
$run = $wpdb->get_row('SELECT * FROM ' . SSPA_Schema::table('runs') . " WHERE status='done' AND run_type IN ('baseline','spot') ORDER BY id DESC LIMIT 1", ARRAY_A);
if (!$run) { w1_links_t(false, 'completed analysis prerequisite'); return; }
$wpdb->insert(SSPA_Schema::table('findings'), array('run_id' => $run['id'], 'finding_type' => 'autoload_coverage', 'severity' => 'warning', 'component' => 'core', 'page_key' => '', 'evidence' => wp_json_encode(array('autoload_bytes' => 2000000, 'unread_bytes' => 1000000, 'pages_covered' => 4, 'unread' => array(), 'missing' => array())), 'recommendation_key' => 'autoload_coverage'));
wp_set_current_user(1);
$cookies = SSPA_Auth::cookies_for('admin', 1);
$_COOKIE[LOGGED_IN_COOKIE] = $cookies[LOGGED_IN_COOKIE];
$response = wp_remote_post(admin_url('admin-ajax.php'), array('cookies' => $cookies, 'timeout' => 30, 'body' => array('action' => 'sspa_render_tab', 'tabs' => 'overview', 'nonce' => wp_create_nonce('sspa_admin'))));
$document = json_decode(wp_remote_retrieve_body($response), true);
$html = $document['data']['tabs']['overview'] ?? '';
w1_links_t(!is_wp_error($response) && !empty($document['success']) && $html !== '', 'existing overview tab renders through authenticated admin-ajax');

w1_links_t(strpos($html, 'https://www.superspeedyplugins.com/product/scalability-pro/') !== false, 'Overview retains the contextual Scalability Pro link');
w1_links_t(strpos($html, 'utm_') === false, 'rendered Overview contains no UTM parameters');
$rec = SSPA_Rules::recommendation('slow_query_found_rows');
w1_links_t(!empty($rec['body']) && stripos(wp_json_encode($rec), 'halve query time') === false, 'bundled recommendation makes no unsupported query-time claim');
