<?php
// Assertions on the check the browser journey just ran on a site with no page cache, after a
// real analysis measured its shop page (which really waits 600 ms). Reads what production
// code saved and renders the tab through the real lazy loader.
require_once __DIR__ . '/../lib/native-guard.php';
function sspa_nc_t($ok, $label) { echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n"; }

$report = SSPA_Cache_Delivery::latest();
sspa_nc_t(is_array($report) && 'no_page_cache_observed' === $report['page_cache_status'], 'the check found no page cache (' . (is_array($report) ? $report['page_cache_status'] : 'no report') . ')');
if (!is_array($report)) { return; }
foreach (SSPA_Cache_Delivery::AUDIENCES as $audience) {
    sspa_nc_t('not_cached' === $report['audiences'][$audience]['headline'], $audience . ': Not cached');
}
$shop = null;
foreach ($report['origin_profiles'] as $origin) {
    if ('shop' === $origin['page_key']) { $shop = $origin; }
}
sspa_nc_t($shop && $shop['generation_ms'] >= 600, 'the analysis measured the slow shop page (' . ($shop ? $shop['generation_ms'] : 'none') . ' ms)');
sspa_nc_t($shop && null !== $shop['customer_generation_ms'], 'logged-in customer generation for the shop comes from the same analysis');
sspa_nc_t((int) $report['run_id'] === SSPA_Report::latest_done_run_id(), 'the check names the analysis it was shown with');

$evidence = SSPA_Offers::cache_evidence();
sspa_nc_t($evidence['C4'] && $evidence['C3'] && 'no_page_cache' === $evidence['C3']['kind'] && 'shop' === $evidence['C3']['page_key'], 'C3 fires from no page cache and slow anonymous catalogue generation');

wp_set_current_user(1);
$cookies = SSPA_Auth::cookies_for('admin', 1);
$_COOKIE[LOGGED_IN_COOKIE] = $cookies[LOGGED_IN_COOKIE];
$render = function () use ($cookies) {
    $response = wp_remote_post(admin_url('admin-ajax.php'), array('cookies' => $cookies, 'timeout' => 60, 'body' => array('action' => 'sspa_render_tab', 'tabs' => 'cache', 'nonce' => wp_create_nonce('sspa_admin'))));
    $document = json_decode(wp_remote_retrieve_body($response), true);
    return isset($document['data']['tabs']['cache']) ? $document['data']['tabs']['cache'] : '';
};
$html = $render();
sspa_nc_t(false !== strpos($html, 'No page cache was observed on this site, and the Shop page took'), 'the service offer leads with the no-cache generation sentence');
sspa_nc_t(false === strpos($html, 'data-offer="ajax_prices"'), 'no Ajax Prices offer while prices cannot vary per visitor');

// P1 from real configuration: WooCommerce geolocation, then a real price-affecting plugin
// from wordpress.org. P2 holds because no page cache was observed.
update_option('woocommerce_default_customer_address', 'geolocation');
$html = $render();
sspa_nc_t(false !== strpos($html, 'data-offer="ajax_prices"') && false !== strpos($html, 'WooCommerce geolocates customers') && false !== strpos($html, 'Ajax Prices works with a page cache'), 'geolocation fires P1 and the Ajax Prices offer appears, conditional on adding a page cache');
update_option('woocommerce_default_customer_address', 'base');
$adp = 'advanced-dynamic-pricing-for-woocommerce/advanced-dynamic-pricing-for-woocommerce.php';
$activated = activate_plugin($adp);
sspa_nc_t(!is_wp_error($activated), 'the real Advanced Dynamic Pricing plugin from wordpress.org activates');
$html = $render();
$plugins = SSPA_Cache_System::price_affecting_plugins();
sspa_nc_t(1 === count($plugins) && false !== strpos($html, 'active: ' . esc_html($plugins[0])), 'an active price-affecting plugin fires P1 by name (' . implode(', ', $plugins) . ')');
preg_match_all('#href="(https://www\.superspeedyplugins\.com/[^"]+)"#', $html, $links);
$offer_links = array_values(array_filter(array_map('html_entity_decode', $links[1]), function ($url) { return false !== strpos($url, 'utm_'); }));
sspa_nc_t(array(
    'https://www.superspeedyplugins.com/product/super-speedy-ajax-prices/?utm_source=performance-analysis&utm_medium=plugin&utm_campaign=cache_tab_ajax_prices',
    'https://www.superspeedyplugins.com/product/woocommerce-full-page-caching-implementation/?utm_source=performance-analysis&utm_medium=plugin&utm_campaign=cache_tab_service',
) === array_values(array_unique(array_merge(array_filter($offer_links, function ($u) { return false !== strpos($u, 'ajax-prices'); }), array_filter($offer_links, function ($u) { return false === strpos($u, 'ajax-prices'); })))), 'both offers link with their exact static placement tags');

// With Super Speedy Ajax Prices active the offer is suppressed.
$ssap = 'super-speedy-ajax-prices/super-speedy-ajax-prices.php';
$ssap_activated = activate_plugin($ssap);
sspa_nc_t(!is_wp_error($ssap_activated), 'Super Speedy Ajax Prices activates on this site');
$html = $render();
sspa_nc_t(false === strpos($html, 'data-offer="ajax_prices"') && false !== strpos($html, 'data-offer="cache_service"'), 'with Super Speedy Ajax Prices active its offer is suppressed and the service remains');
// Left active for inspection; run.sh deactivates both on the way in.
