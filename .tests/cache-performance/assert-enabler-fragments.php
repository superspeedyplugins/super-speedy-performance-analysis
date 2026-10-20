<?php
// Second Cache Enabler pass: the same site with WooCommerce's own cart-fragments script on
// every page (as a header mini-basket enqueues it). The basket is still served the cached
// page, but its basket state is refreshed in the browser, so no warning is shown.
require_once __DIR__ . '/../lib/native-guard.php';
function sspa_cf_t($ok, $label) { echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n"; }
$report = SSPA_Cache_Delivery::latest();
sspa_cf_t(is_array($report) && 'cached' === $report['audiences']['basket']['headline'], 'visitors with a basket are still served the cached page');
sspa_cf_t(isset($report['basket_state']) && false === $report['basket_state']['warning'] && in_array('cart_fragments', $report['basket_state']['mechanisms'], true), 'WooCommerce cart fragments are detected as the basket refresh mechanism');
wp_set_current_user(1);
$cookies = SSPA_Auth::cookies_for('admin', 1);
$_COOKIE[LOGGED_IN_COOKIE] = $cookies[LOGGED_IN_COOKIE];
$response = wp_remote_post(admin_url('admin-ajax.php'), array('cookies' => $cookies, 'timeout' => 60, 'body' => array('action' => 'sspa_render_tab', 'tabs' => 'cache', 'nonce' => wp_create_nonce('sspa_admin'))));
$document = json_decode(wp_remote_retrieve_body($response), true);
$html = isset($document['data']['tabs']['cache']) ? $document['data']['tabs']['cache'] : '';
sspa_cf_t('' !== $html && false === strpos($html, 'sspa-cache-basket-warning'), 'no basket warning when the basket state is refreshed');
