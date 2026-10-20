<?php
// Assertions on the check the browser journey just ran against the real Cache Enabler from
// wordpress.org. Nothing here writes evidence; it reads what the production code saved.
//
// What Cache Enabler really does (captured first, 21 October 2026, Cache Enabler 1.8.17):
// its advanced-cache.php answers from a stored page with "X-Cache-Handler:
// cache-enabler-engine" and skips the stored page for wordpress_logged_in_* cookies. Its
// default cookie exclusions do not include WooCommerce's basket cookies, so a visitor with a
// basket is served the stored page too.
require_once __DIR__ . '/../lib/native-guard.php';
function sspa_ce_t($ok, $label) { echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n"; }

$report = SSPA_Cache_Delivery::latest();
sspa_ce_t(is_array($report) && 'sspa/cache-optimisation-analysis@3' === $report['schema'], 'the browser journey saved a version 3 cache check');
if (!is_array($report)) { return; }

$states = function ($audience) use ($report) {
    $out = array();
    foreach ($report['audience_verdicts'][$audience] as $row) { $out[$row['page_key']] = $row; }
    return $out;
};
$anonymous = $states('anonymous');
$basket = $states('basket');
$customer = $states('customer');
sspa_ce_t(array('home', 'shop', 'product_category', 'product') === array_keys($anonymous), 'all four fixed pages were checked');
sspa_ce_t(!array_filter($anonymous, function ($row) { return 'cached' !== $row['cache_state'] || 'cache_enabler' !== $row['layer']; }), 'visitors: every page served by Cache Enabler (' . wp_json_encode(array_column($anonymous, 'verdict')) . ')');
sspa_ce_t(!array_filter($basket, function ($row) { return 'cached' !== $row['cache_state']; }), 'visitors with a basket: Cache Enabler serves the stored page, as its default cookie rules allow (' . wp_json_encode(array_column($basket, 'verdict')) . ')');
sspa_ce_t(!array_filter($customer, function ($row) { return 'reuse_not_observed' !== $row['verdict'] || 'anonymous_cache_layer_not_used_for_this_audience' !== $row['reason']; }), 'logged-in customers: the cache that served visitors did not serve them (' . wp_json_encode(array_column($customer, 'verdict')) . ')');
sspa_ce_t(!array_filter($customer, function ($row) { return !$row['logged_in_markers_seen']; }), 'the customer requests really were logged in (WordPress logged-in body class seen)');
sspa_ce_t('cached' === $report['audiences']['anonymous']['headline'] && 'cached' === $report['audiences']['basket']['headline'] && 'not_cached' === $report['audiences']['customer']['headline'], 'audience headlines: Cached, Cached, Not cached');
$ttfb_sources = array_unique(array_column($report['delivery_path_observations']['server']['requests'], 'ttfb_source'));
sspa_ce_t(array('curl_starttransfer_time') === array_values($ttfb_sources), 'server time to first byte is cURL starttransfer_time on every server request');
sspa_ce_t('no_leak_observed' === $report['leak_check']['status'], 'leak check: the final anonymous request matched the earlier anonymous body (' . $report['leak_check']['status'] . ')');

// Privacy of what was saved.
$encoded = wp_json_encode($report);
$customer_user = get_userdata(SSPA_Auth::test_customer_id());
sspa_ce_t(false === strpos($encoded, '<html') && false === stripos($encoded, '<body'), 'no response HTML is stored');
sspa_ce_t(false === strpos($encoded, 'wordpress_logged_in') && false === strpos($encoded, 'woocommerce_cart_hash') && false === strpos($encoded, 'woocommerce_items_in_cart'), 'no cookie name or value sent by the check is stored');
sspa_ce_t($customer_user && false === strpos($encoded, $customer_user->display_name) && false === strpos($encoded, $customer_user->user_email), 'the test account\'s name and email are not stored');
$problems = SSPA_Traffic_Privacy::validate_export($report);
sspa_ce_t(!$problems, 'the saved check passes the shared forbidden-property validator' . ($problems ? ': ' . implode(', ', $problems) : ''));
sspa_ce_t(!get_option(SSPA_Cache_Delivery::PENDING_OPTION), 'the pending check (which held the basket hash) was removed on completion');

// C3: the measured gap, and the tab says so through the real lazy tab loader.
$evidence = SSPA_Offers::cache_evidence();
sspa_ce_t($evidence['C4'] && $evidence['C3'] && 'audience_gap' === $evidence['C3']['kind'] && $evidence['C3']['customer'] && !$evidence['C3']['basket'], 'C3 fires from the measured customer gap');
wp_set_current_user(1);
$cookies = SSPA_Auth::cookies_for('admin', 1);
// The nonce is bound to the session in the logged-in cookie the request will carry.
$_COOKIE[LOGGED_IN_COOKIE] = $cookies[LOGGED_IN_COOKIE];
$response = wp_remote_post(admin_url('admin-ajax.php'), array('cookies' => $cookies, 'timeout' => 60, 'body' => array('action' => 'sspa_render_tab', 'tabs' => 'cache', 'nonce' => wp_create_nonce('sspa_admin'))));
$document = json_decode(wp_remote_retrieve_body($response), true);
$html = isset($document['data']['tabs']['cache']) ? $document['data']['tabs']['cache'] : '';
sspa_ce_t('' !== $html, 'the Cache performance tab renders through the authenticated lazy tab loader (HTTP ' . wp_remote_retrieve_response_code($response) . ')');
sspa_ce_t(false !== strpos($html, 'class="sspa-cache-evidence"') && false !== strpos($html, 'from the Cache Enabler cache') && false !== strpos($html, 'for a logged-in customer'), 'the service offer leads with the measured gap sentence');
$system_start = strpos($html, 'sspa-cache-system');
$system = false === $system_start ? '' : substr($html, $system_start, strpos($html, 'sspa-cache-education') - $system_start);
sspa_ce_t(substr_count($system, 'Cache Enabler') >= 2 && false !== strpos($system, 'Present, without a plugin header'), 'Your cache system names Cache Enabler as the active plugin and the cache seen in responses, and reports its header-less drop-in as present');
