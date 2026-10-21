<?php
// S6 and R4 on the checkout panel and its export, from a real checkout analysis this case runs
// itself through the public run controller (the same entry point as case 19 and the admin-bar
// button). Its fixture plugin fetches the order's own permalink inside payment_complete, the
// HPOS purge signature the profiler measures. It must not depend on case 19: run-tests.sh puts
// "134-" before "19-", so a fresh suite site has no checkout run of case 19's yet.
require_once SSPA_PLUGIN_DIR . '.tests/lead-gen/lib.php';
require_once SSPA_PLUGIN_DIR . '.tests/lib/retained-fixtures.php';
deactivate_plugins('scalability-pro/scalability-pro.php', true);
lg_reset_dismissals();
global $wpdb;
// Clear only this case's previous checkout run on the way in; the new one is retained.
sspa_retained_reset('134-checkout');
// Start from the block cart and checkout pages, as case 19 does: an earlier case or browser journey
// can leave a classic or deliberately unsupported checkout page (case 80, checkout-flow.e2e.cjs),
// and an unsupported page makes the analysis fail before it buys anything.
foreach (array('woocommerce_cart_page_id' => '<!-- wp:woocommerce/cart /-->', 'woocommerce_checkout_page_id' => '<!-- wp:woocommerce/checkout /-->') as $option => $content) {
    $page = (int) get_option($option);
    if ($page) { wp_update_post(array('ID' => $page, 'post_content' => $content)); clean_post_cache($page); }
}
wp_cache_flush();
$fixture_slug = 'sspa-case134-purge';
$fixture_dir = WP_PLUGIN_DIR . '/' . $fixture_slug;
if (!is_dir($fixture_dir)) { mkdir($fixture_dir); }
file_put_contents($fixture_dir . '/' . $fixture_slug . '.php', <<<'PHP'
<?php
/**
 * Plugin Name: SSPA Case 134 Purge Stack (test fixture)
 * Version: 1.0.0
 */
// A purge stack that fetches the order's own permalink while the customer waits.
add_action('woocommerce_payment_complete', function ($order_id) {
    wp_remote_get(home_url('/?p=' . (int) $order_id), array('timeout' => 10));
}, 10, 1);
PHP
);
activate_plugin($fixture_slug . '/' . $fixture_slug . '.php');
wp_cache_flush(); // activating from CLI with Redis on can leave the web server reading a stale alloptions
sleep(3); // opcache revalidate_freq
$run_id = SSPA_Run_Controller::start(array('type' => 'checkout', 'user_id' => 1, 'allow_integrations' => true, 'allow_webhooks' => true));
if (!lg_t(!is_wp_error($run_id) && (int) $run_id > 0, 'this case starts its own real checkout analysis' . (is_wp_error($run_id) ? ': ' . $run_id->get_error_message() : ''))) { return; }
$run_id = (int) $run_id;
sspa_retained_save('134-checkout', array('runs' => array($run_id)));
$deadline = time() + 300;
do {
    SSPA_Run_Controller::process_batch($run_id);
    $status = SSPA_Run_Controller::status($run_id);
} while ($status && in_array($status['status'], array('crawling', 'analysing'), true) && time() < $deadline);
$run = SSPA_Run_Controller::run_row($run_id);
if (!lg_t($run && 'done' === $run['status'], 'the checkout analysis finished (status: ' . ($run ? $run['status'] : 'null') . ')')) { return; }
$types = $wpdb->get_col($wpdb->prepare('SELECT finding_type FROM %i WHERE run_id = %d', SSPA_Schema::table('findings'), $run_id));
lg_t(in_array('checkout_purge_order_pages', $types, true), 'the checkout run measured the order-permalink purge');
$result = lg_ajax('sspa_checkout_result', array('run_id' => $run_id, 'pa_screen' => 1));
$html = (string) ($result['data']['offers_html'] ?? '');
$offers = lg_offers($html);
$by = array();
foreach ($offers as $offer) { $by[$offer['offer']] = $offer; }
lg_t(isset($by['scalability_pro']) && 'S6' === $by['scalability_pro']['trigger'], 'the purge finding offers Scalability Pro\'s checkout purge shield (S6); got ' . wp_json_encode(array_map(function ($o) { return $o['offer'] . ':' . $o['trigger']; }, $offers)));
lg_t(isset($by['scalability_pro']) && false !== strpos($by['scalability_pro']['html'], '30 outbound HTTP requests'), 'the S6 offer quotes the published purge-shield measurement');
lg_t(count($offers) <= 2, 'the checkout panel shows at most two offers');
lg_t(isset($by['scalability_pro']) && 'scalability_pro_checkout' === lg_campaign($html, 'scalability_pro'), 'the checkout link carries its placement campaign');
$body = SSPA_Rules::recommendation('checkout_purge_order_pages');
lg_t(false === strpos($body['body'], 'Scalability Pro'), 'the finding\'s own free fix no longer names the product; the offer line does');
$outside = lg_ajax('sspa_checkout_result', array('run_id' => $run_id, 'pa_screen' => 0));
lg_t(!empty($outside['success']) && '' === (string) ($outside['data']['offers_html'] ?? ''), 'the checkout popover outside the PA screen carries no offer');
$md = lg_markdown('checkout', $run_id);
lg_t(false !== strpos($md, 'utm_campaign=scalability_pro_export') && false !== strpos($md, 'If you would rather not do this by hand:'), 'the checkout export carries the S6 offer');
$bad = lg_identifying_links($html . $md);
lg_t(!$bad, 'no checkout offer link carries identifying parameters' . ($bad ? ': ' . implode(' ', $bad) : ''));
activate_plugin('scalability-pro/scalability-pro.php');
$suppressed = lg_ajax('sspa_checkout_result', array('run_id' => $run_id, 'pa_screen' => 1));
lg_t(!in_array('scalability_pro', lg_offer_ids((string) ($suppressed['data']['offers_html'] ?? '')), true), 'with Scalability Pro active the purge-shield offer is suppressed');
deactivate_plugins('scalability-pro/scalability-pro.php', true);
