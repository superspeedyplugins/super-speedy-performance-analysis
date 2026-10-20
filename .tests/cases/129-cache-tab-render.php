<?php
// The Cache performance tab through the real lazy tab loader on the suite site: the service
// pitch is always there; its evidence sentences appear exactly when C4 holds and C1, C2 or
// C3 fires; every quotation carries its source link; Overview keeps a short summary that
// opens the tab; the run Markdown export carries the pitch only when the evidence fires.

function sspa_ct_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
}

wp_set_current_user(1);
$cookies = SSPA_Auth::cookies_for('admin', 1);
$_COOKIE[LOGGED_IN_COOKIE] = $cookies[LOGGED_IN_COOKIE];
$response = wp_remote_post(admin_url('admin-ajax.php'), array('cookies' => $cookies, 'timeout' => 60, 'body' => array('action' => 'sspa_render_tab', 'tabs' => 'cache,overview', 'nonce' => wp_create_nonce('sspa_admin'))));
$document = json_decode(wp_remote_retrieve_body($response), true);
$html = isset($document['data']['tabs']['cache']) ? $document['data']['tabs']['cache'] : '';
$overview = isset($document['data']['tabs']['overview']) ? $document['data']['tabs']['overview'] : '';
sspa_ct_t('' !== $html && '' !== $overview, 'Cache performance and Overview render through authenticated admin-ajax');

ob_start();
SSPA_Admin_Page::show();
$shell = ob_get_clean();
preg_match_all('/data-tab="([a-z]+)">/', $shell, $tabs);
sspa_ct_t(array('overview', 'cache') === array_slice($tabs[1], 0, 2) && false !== strpos($shell, '>Cache performance</a>'), 'the "Cache performance" tab sits directly after Overview');

sspa_ct_t(false !== strpos($html, 'data-offer="cache_service"') && false !== strpos($html, 'View the Cache Everything for Everyone service'), 'the caching-service pitch is present');
$evidence = SSPA_Offers::cache_evidence();
$has_evidence = false !== strpos($html, 'class="sspa-cache-evidence"');
sspa_ct_t($has_evidence === (bool) $evidence['fires'], 'evidence sentences appear exactly when C4 holds and C1, C2 or C3 fires (fires: ' . var_export($evidence['fires'], true) . ')');
foreach ($evidence['fires'] ? $evidence['sentences'] : array() as $sentence) {
    sspa_ct_t(false !== strpos($html, esc_html($sentence)), 'evidence sentence rendered: ' . substr($sentence, 0, 60));
}
sspa_ct_t(false === strpos($html, '€') && false === stripos($html, 'founding') && false === stripos($html, 'in stock'), 'no price or slot count is shown');

// Every quotation names its source and the date it was checked.
preg_match_all('#<blockquote class="sspa-cache-quote">.*?</blockquote>#s', $html, $quotes);
$quoted = SSPA_Rules::cache_education();
sspa_ct_t(count($quotes[0]) >= count($quoted) + 3, 'education block, supported systems, service quote and figure are rendered as quotations (' . count($quotes[0]) . ')');
sspa_ct_t(!array_filter($quotes[0], function ($quote) {
    return !preg_match('#<cite><a href="https://www\.superspeedyplugins\.com/[^"]+"[^>]*>[^<]+</a> \(checked [^)]+\)</cite>#', $quote);
}), 'every quotation links its published source and its check date');
foreach ($quoted as $item) {
    if (false === strpos($html, esc_html($item['text']))) {
        sspa_ct_t(false, 'quotation rendered verbatim: ' . substr($item['text'], 0, 50));
    }
}
sspa_ct_t(true, 'every bundled quotation is rendered verbatim');

$traffic = SSPA_Offers::traffic_private_state();
sspa_ct_t($traffic ? false !== strpos($html, 'catalogue page views from visitors with a basket or logged-in customers reached WordPress') : false !== strpos($html, 'data-tab="traffic"'), $traffic ? 'the completed Traffic collection is summarised for basket and logged-in visitors' : 'without a completed Traffic collection the tab links to Traffic');
sspa_ct_t(false !== strpos($overview, 'sspa-cache-overview') && false !== strpos($overview, 'data-tab="cache"'), 'Overview keeps a short cache summary that opens the tab');
sspa_ct_t(false === strpos($overview, 'Potential hazards') && false === strpos($overview, 'sspa-cache-safety-download') && false === strpos($overview, 'woocommerce-full-page-caching-implementation'), 'Overview no longer carries the long cache block or the service link');

$run_id = SSPA_Report::latest_done_run_id();
if ($run_id) {
    $markdown = SSPA_Markdown_Export::build('run', $run_id);
    $export_evidence = SSPA_Offers::cache_evidence($run_id, is_wp_error(SSPA_Cache_Delivery::report($run_id)) ? null : SSPA_Cache_Delivery::report($run_id));
    $has_pitch = !is_wp_error($markdown) && false !== strpos($markdown['markdown'], 'utm_campaign=cache_export_service');
    sspa_ct_t($has_pitch === (bool) $export_evidence['fires'], 'the run export carries the service pitch exactly when the evidence fires for that run (fires: ' . var_export($export_evidence['fires'], true) . ')');
}
