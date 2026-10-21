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
// The Imports tab and the run export are offer placements too.
$imports_document = json_decode(wp_remote_retrieve_body(wp_remote_post(admin_url('admin-ajax.php'), array('cookies' => $cookies, 'timeout' => 30, 'body' => array('action' => 'sspa_render_tab', 'tabs' => 'imports', 'nonce' => wp_create_nonce('sspa_admin'))))), true);
$imports_html = $imports_document['data']['tabs']['imports'] ?? '';
w1_links_t($imports_html !== '', 'Imports tab renders through authenticated admin-ajax');
$export_document = json_decode(wp_remote_retrieve_body(wp_remote_post(admin_url('admin-ajax.php'), array('cookies' => $cookies, 'timeout' => 60, 'body' => array('action' => 'sspa_markdown_export', 'kind' => 'run', 'id' => (int) $run['id'], 'nonce' => wp_create_nonce('sspa_admin'))))), true);
$export_md = $export_document['data']['markdown'] ?? '';
w1_links_t($export_md !== '', 'run Markdown export builds through authenticated admin-ajax');
$w1_host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
// No identifying parameters: only the static placement tag, never a host name, user, site
// or run value.
$w1_identifying = static function ($link) use ($w1_host) {
    parse_str((string) wp_parse_url($link, PHP_URL_QUERY), $query);
    $ok = !array_diff(array_keys($query), array('utm_source', 'utm_medium', 'utm_campaign'))
        && (!$query || ('performance-analysis' === ($query['utm_source'] ?? '') && 'plugin' === ($query['utm_medium'] ?? '') && preg_match('/^[a-z_]+$/', $query['utm_campaign'] ?? '')))
        && false === stripos($link, $w1_host);
    return !$ok;
};
// The check itself must reject identifying links, or a pass proves nothing.
foreach (array(
    'https://www.superspeedyplugins.com/product/scalability-pro/?utm_source=' . rawurlencode($w1_host) . '&utm_medium=plugin&utm_campaign=scalability_pro_overview',
    'https://www.superspeedyplugins.com/product/scalability-pro/?utm_source=performance-analysis&utm_medium=plugin&utm_campaign=scalability_pro_overview&user=1',
    'https://www.superspeedyplugins.com/product/scalability-pro/?utm_source=performance-analysis&utm_medium=plugin&utm_campaign=run_42',
) as $w1_control) {
    w1_links_t($w1_identifying($w1_control), 'the no-identifying-parameters check rejects ' . $w1_control);
}
preg_match_all('#https?://[^\s"\')<>]*superspeedyplugins\.com[^\s"\')<>]*#', html_entity_decode($html . $cache_html . $imports_html . $export_md), $w1_links);
$w1_bad = array();
$w1_tagged = 0;
foreach (array_unique($w1_links[0]) as $w1_link) {
    if (false !== strpos($w1_link, 'utm_')) {
        $w1_tagged++;
    }
    if ($w1_identifying($w1_link)) {
        $w1_bad[] = $w1_link;
    }
}
w1_links_t($w1_tagged > 0 && !$w1_bad, 'rendered Overview, Cache performance, Imports and export links carry only the static placement tag, no identifying parameters (' . $w1_tagged . ' tagged' . ($w1_bad ? '; ' . implode(' ', $w1_bad) : '') . ')');
$rec = SSPA_Rules::recommendation('slow_query_found_rows');
w1_links_t(!empty($rec['body']) && stripos(wp_json_encode($rec), 'halve query time') === false, 'bundled recommendation makes no unsupported query-time claim');
