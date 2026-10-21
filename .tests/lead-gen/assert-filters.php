<?php
// F1 and F3: with a real product filter plugin from wordpress.org active (HUSKY), a fresh
// analysis of the same 120,000-product catalogue routes the slow archive query to Super Speedy
// Filters instead of Scalability Pro, and names the active filter plugin factually.
require __DIR__ . '/lib.php';
lg_reset_dismissals();
lg_t(is_plugin_active('woocommerce-products-filter/index.php'), 'HUSKY from wordpress.org is active');
$run_id = (int) SSPA_Report::latest_done_run_id();
$panel = lg_panel(lg_profile_id($run_id, 'product-cat'));
$found = lg_offers($panel);
lg_t(1 === count($found) && 'filters' === $found[0]['offer'] && 'F1' === $found[0]['trigger'], 'the product-cat summary offers Super Speedy Filters (F1); got ' . wp_json_encode(array_map(function ($o) { return $o['offer'] . ':' . $o['trigger']; }, $found)));
lg_t($found && false !== strpos($found[0]['html'], 'HUSKY'), 'the Filters offer names the active filter plugin');
lg_t($found && false === stripos(wp_strip_all_tags($found[0]['html']), 'best'), 'the Filters offer makes no "best" claim');
$overview = lg_tab('overview');
lg_t(in_array('filters', lg_offer_ids($overview), true), 'the Overview offers Super Speedy Filters for the archive (' . wp_json_encode(lg_offer_ids($overview)) . ')');
lg_t(count(lg_offers($overview)) <= 2, 'the Overview still holds at most two offers');
lg_t('filters_overview' === lg_campaign($overview, 'filters'), 'the Filters link carries its placement campaign');
