<?php
// S6 and R4 on the checkout panel and its export, from the real checkout run case 19 leaves
// retained on this site (its purge-stack fixture fetches the order's own permalink and a
// phantom /amp/ page inside payment_complete, both measured by the profiler).
require_once SSPA_PLUGIN_DIR . '.tests/lead-gen/lib.php';
deactivate_plugins('scalability-pro/scalability-pro.php', true);
lg_reset_dismissals();
global $wpdb;
$run_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM %i WHERE run_type = 'checkout' AND status IN ('done','failed') ORDER BY id DESC LIMIT 1", SSPA_Schema::table('runs')));
if (!lg_t($run_id > 0, 'a retained real checkout analysis exists (run case 19 first)')) { return; }
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
