<?php
// A plain WordPress site without WooCommerce (Dave, 20 October 2026, Q2: the caching-service
// pitch shows wherever the tab appears). The browser journey has already run the check.
require_once __DIR__ . '/../lib/native-guard.php';
function sspa_nw_t($ok, $label) { echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n"; }

sspa_nw_t(!class_exists('WooCommerce'), 'WooCommerce is not loaded on this site');
$targets = SSPA_Cache_Delivery::targets();
sspa_nw_t(array('home') === array_column($targets, 'page_key'), 'only the home page is a check target without WooCommerce');
$report = SSPA_Cache_Delivery::latest();
sspa_nw_t(is_array($report) && 1 === count($report['audience_verdicts']['anonymous']), 'the browser journey saved a check of the home page');
if (is_array($report)) {
    $basket = $report['audience_verdicts']['basket'][0];
    sspa_nw_t('unknown' === $basket['cache_state'] && 'woocommerce_not_active' === $basket['reason'], 'the basket audience is Unknown because there is no basket to represent');
    sspa_nw_t('not_cached' === $report['audiences']['anonymous']['headline'] && 'not_cached' === $report['audiences']['customer']['headline'], 'visitors and logged-in users are measured: Not cached');
    sspa_nw_t(false === $report['basket_state']['warning'], 'no basket warning without WooCommerce');
}

wp_set_current_user(1);
$cookies = SSPA_Auth::cookies_for('admin', 1);
$_COOKIE[LOGGED_IN_COOKIE] = $cookies[LOGGED_IN_COOKIE];
$response = wp_remote_post(admin_url('admin-ajax.php'), array('cookies' => $cookies, 'timeout' => 60, 'body' => array('action' => 'sspa_render_tab', 'tabs' => 'cache', 'nonce' => wp_create_nonce('sspa_admin'))));
$document = json_decode(wp_remote_retrieve_body($response), true);
$html = isset($document['data']['tabs']['cache']) ? $document['data']['tabs']['cache'] : '';
sspa_nw_t('' !== $html, 'the Cache performance tab renders through the authenticated lazy tab loader');
sspa_nw_t(false !== strpos($html, 'data-offer="cache_service"') && false !== strpos($html, 'View the Cache Everything for Everyone service') && false !== strpos($html, 'utm_campaign=cache_tab_service'), 'the caching-service pitch shows without WooCommerce');
sspa_nw_t(false === strpos($html, 'class="sspa-cache-evidence"'), 'no evidence sentence: the fit gate C4 needs WooCommerce');
sspa_nw_t(false === strpos($html, 'data-offer="ajax_prices"') && false === strpos($html, 'Super Speedy Ajax Prices</th>'), 'no Ajax Prices offer or row without WooCommerce');
sspa_nw_t(false !== strpos($html, esc_html__('WooCommerce is not active, so there is no basket to represent.', 'super-speedy-performance-analysis')), 'the results say why the basket audience is unknown');
