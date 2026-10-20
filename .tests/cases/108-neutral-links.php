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
// Dave, 20 October 2026 (Q9): offer links carry a static placement tag and nothing that
// identifies the site or the user.
$response = wp_remote_post(admin_url('admin-ajax.php'), array('cookies' => $cookies, 'timeout' => 30, 'body' => array('action' => 'sspa_render_tab', 'tabs' => 'cache', 'nonce' => wp_create_nonce('sspa_admin'))));
$cache_document = json_decode(wp_remote_retrieve_body($response), true);
$cache_html = $cache_document['data']['tabs']['cache'] ?? '';
w1_links_t($cache_html !== '', 'Cache performance tab renders through authenticated admin-ajax');
preg_match_all('#href="(https?://[^"]*superspeedyplugins\.com[^"]*)"#', $html . $cache_html, $w1_links);
$w1_host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
$w1_identifying = array();
$w1_tagged = 0;
foreach (array_map('html_entity_decode', $w1_links[1]) as $w1_link) {
    parse_str((string) wp_parse_url($w1_link, PHP_URL_QUERY), $w1_query);
    if ($w1_query) {
        $w1_tagged++;
    }
    $w1_ok = !array_diff(array_keys($w1_query), array('utm_source', 'utm_medium', 'utm_campaign'))
        && (!$w1_query || ('performance-analysis' === ($w1_query['utm_source'] ?? '') && 'plugin' === ($w1_query['utm_medium'] ?? '') && preg_match('/^[a-z_]+$/', $w1_query['utm_campaign'] ?? '')))
        && false === stripos($w1_link, $w1_host);
    if (!$w1_ok) {
        $w1_identifying[] = $w1_link;
    }
}
w1_links_t($w1_tagged > 0 && !$w1_identifying, 'rendered Overview and Cache performance links carry only the static placement tag, no identifying parameters (' . $w1_tagged . ' tagged' . ($w1_identifying ? '; ' . implode(' ', $w1_identifying) : '') . ')');
$rec = SSPA_Rules::recommendation('slow_query_found_rows');
w1_links_t(!empty($rec['body']) && stripos(wp_json_encode($rec), 'halve query time') === false, 'bundled recommendation makes no unsupported query-time claim');
