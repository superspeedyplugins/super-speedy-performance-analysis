<?php
// Offers on a store with a real 120,000-product catalogue and 250,000 HPOS orders, measured by
// a real analysis (no persistent object cache, as on many real stores). Every offer must come
// from that run's measured evidence, through the rendered Overview, page panels and exports.
require __DIR__ . '/lib.php';
lg_reset_dismissals();
$run_id = (int) SSPA_Report::latest_done_run_id();
lg_t($run_id > 0, 'a completed real analysis exists (run ' . $run_id . ')');
$pages = array();
foreach (SSPA_Report::pages($run_id) as $page) {
    $pages[$page['page_key']] = $page;
}
$share = function ($key) use ($pages) {
    return isset($pages[$key]) && $pages[$key]['generation_ms'] > 0 ? $pages[$key]['sql_ms'] / $pages[$key]['generation_ms'] : 0;
};
echo 'measured: ' . wp_json_encode(array_map(function ($p) { return array($p['generation_ms'], $p['sql_ms']); }, $pages)) . "\n";

// ---- Overview: at most one primary and one secondary, attached to the finding ----
$overview = lg_tab('overview');
$offers = lg_offers($overview);
lg_t(2 === count($offers), 'Overview shows exactly two offers, one primary and one secondary (' . wp_json_encode(array_column($offers, 'offer')) . ')');
lg_t(array('primary', 'secondary') === array_column($offers, 'role'), 'the first offer is primary and the second secondary');
lg_t(count(array_unique(array_column($offers, 'offer'))) === count($offers), 'no product is offered twice on one screen');
lg_t(in_array('search', array_column($offers, 'offer'), true), 'the 700 ms leading-wildcard search query offers Super Speedy Search');
lg_t(in_array('scalability_pro', array_column($offers, 'offer'), true), 'the slow catalogue count queries offer Scalability Pro');
lg_t(!in_array('fix_quote', array_column($offers, 'offer'), true), 'the fix quote waits while two product offers fill the screen');
foreach ($offers as $offer) {
    $lead = strpos($offer['html'], 'If you would rather not do this by hand:');
    $link = strpos($offer['html'], 'utm_campaign=');
    lg_t(false !== $lead && false !== $link && $lead < $link, $offer['offer'] . ': "If you would rather not do this by hand:" precedes the offer link');
    lg_t(false !== strpos($offer['html'], 'sspa-offer-dismiss'), $offer['offer'] . ': has a Don\'t show this again control');
}
// Inside the triggering finding: the search offer sits in the search-zero insight, after its free fix.
if (preg_match('#<li class="sspa-insight[^"]*"[^>]*>((?:(?!</li>).)*?search-zero(?:(?!</li>).)*?)</li>#s', $overview, $insight)) {
    $fix = strpos($insight[1], 'sspa-insight-rec');
    $offer_at = strpos($insight[1], 'data-offer="search"');
    lg_t(false !== $fix && false !== $offer_at && $fix < $offer_at, 'the Search offer is inside the search-zero finding, after its free fix');
} else {
    lg_t(false, 'the search-zero finding is among the Overview insights');
}
lg_t('search_overview' === lg_campaign($overview, 'search') && 'scalability_pro_overview' === lg_campaign($overview, 'scalability_pro'), 'Overview links carry their placement campaign');
$bad = lg_identifying_links($overview);
lg_t(!$bad, 'no Overview link carries identifying parameters' . ($bad ? ': ' . implode(' ', $bad) : ''));
foreach (array('index.php', 'plugins.php', 'edit.php?post_type=product') as $screen) {
    $page = wp_remote_get(admin_url($screen), array('cookies' => lg_cookies(), 'timeout' => 120));
    $html = is_wp_error($page) ? '' : wp_remote_retrieve_body($page);
    lg_t('' !== $html && false === strpos($html, 'sspa-offer') && false === strpos($html, 'utm_source=performance-analysis'), 'no offer appears on the ' . $screen . ' admin screen');
}

// ---- Page summaries: one offer each, from that page's own evidence ----
$panel_expect = array(
    'search-zero' => array('search', array('Q1', 'Q2')),
    'search-many' => array('search', array('Q1', 'Q2')),
    'product-cat' => array('scalability_pro', array('S1', 'S1_found_rows', 'S3')),
    'admin-products' => array('scalability_pro', array('S5')),
    'admin-orders-search' => array('search', array('Q3')),
);
foreach ($panel_expect as $page_key => $expect) {
    $profile = lg_profile_id($run_id, $page_key);
    $panel = lg_panel($profile);
    $found = lg_offers($panel);
    lg_t(1 === count($found) && $expect[0] === $found[0]['offer'] && in_array($found[0]['trigger'], $expect[1], true),
        $page_key . ' summary offers ' . $expect[0] . ' (' . implode('/', $expect[1]) . ') from its own evidence; got ' . wp_json_encode(array_map(function ($o) { return $o['offer'] . ':' . $o['trigger']; }, $found))
        . sprintf(' [gen %.0f ms, SQL %.0f ms, %.0f%%]', $pages[$page_key]['generation_ms'] ?? 0, $pages[$page_key]['sql_ms'] ?? 0, 100 * $share($page_key)));
    if ($found) {
        $summary_at = strpos($panel, 'sspa-page-summary');
        lg_t(false !== $summary_at && strpos($panel, 'data-offer=') > $summary_at, $page_key . ' offer sits in the page summary next action');
    }
}
// The same panel outside the PA screens (front-end admin bar popover) carries no offer.
lg_t(!lg_offers(lg_panel(lg_profile_id($run_id, 'search-zero'), 0)), 'the profile popover outside the PA screen shows no offer');
// Home is fast: no offer in its summary.
lg_t(!lg_offers(lg_panel(lg_profile_id($run_id, 'home'))), 'the fast Home page summary offers nothing (' . round($pages['home']['generation_ms'] ?? 0) . ' ms)');

// ---- Exports carry the offer only when the same trigger fired for that export ----
$run_md = lg_markdown('run', $run_id);
// The export is a screen too: two offers in all. When the run's own cache evidence fires,
// the caching-service pitch takes the second place.
preg_match_all('#utm_campaign=([a-z_]+)#', $run_md, $export_campaigns);
$export_offers = array();
foreach (array_unique($export_campaigns[1]) as $campaign) {
    $export_offers[] = 'cache_export_service' === $campaign ? 'cache_service' : preg_replace('/_export$/', '', $campaign);
}
$cache_pitch = in_array('cache_service', $export_offers, true);
lg_t(in_array('search', $export_offers, true), 'the run export carries the primary Search offer (' . wp_json_encode($export_offers) . ')');
lg_t(count($export_offers) <= 2, 'the run export carries at most two offers in all');
lg_t($cache_pitch ? 2 === count($export_offers) : in_array('scalability_pro', $export_offers, true), $cache_pitch ? 'with the run\'s cache evidence firing, the caching pitch takes the second place' : 'without cache evidence, Scalability Pro takes the second place');
$home_md = lg_markdown('page', lg_profile_id($run_id, 'home'));
lg_t('' !== $home_md && false === strpos($home_md, 'utm_campaign=search') && false === strpos($home_md, 'utm_campaign=scalability_pro'), 'the Home page export carries no product offer');
$search_md = lg_markdown('page', lg_profile_id($run_id, 'search-zero'));
lg_t(false !== strpos($search_md, 'utm_campaign=search_export'), 'the search-zero page export carries the Search offer');
$bad = lg_identifying_links($run_md . $search_md);
lg_t(!$bad, 'no export link carries identifying parameters' . ($bad ? ': ' . implode(' ', $bad) : ''));

// ---- Dismissal hides the offer in the UI and the export ----
$dismissed = lg_ajax('sspa_dismiss_offer', array('offer' => 'search'));
lg_t(!empty($dismissed['success']), 'Don\'t show this again records the dismissal');
$overview = lg_tab('overview');
lg_t(!in_array('search', lg_offer_ids($overview), true), 'the dismissed Search offer is gone from the Overview');
lg_t(count(lg_offers($overview)) <= 2 && in_array('scalability_pro', lg_offer_ids($overview), true), 'Scalability Pro stays and the screen still holds at most two offers');
lg_t(!lg_offers(lg_panel(lg_profile_id($run_id, 'search-zero'))) || !in_array('search', lg_offer_ids(lg_panel(lg_profile_id($run_id, 'search-zero'))), true), 'the dismissed offer is gone from the search page summary');
lg_t(false === strpos(lg_markdown('run', $run_id), 'utm_campaign=search_export'), 'the dismissed offer is gone from the run export');
lg_reset_dismissals();

// ---- Suppression: never offer a product the site runs ----
$spro = 'scalability-pro/scalability-pro.php';
$spro_was_active = is_plugin_active($spro);
if (!file_exists(WP_PLUGIN_DIR . '/' . $spro)) {
    lg_t(false, 'Scalability Pro is installed on the test site for the suppression check');
} else {
    activate_plugin($spro);
    $overview = lg_tab('overview');
    lg_t(!in_array('scalability_pro', lg_offer_ids($overview), true), 'with Scalability Pro active, no Scalability Pro offer appears (' . wp_json_encode(lg_offer_ids($overview)) . ')');
    lg_t(false === strpos(lg_markdown('run', $run_id), 'utm_campaign=scalability_pro'), 'with Scalability Pro active, the export offers no Scalability Pro either');
    if (!$spro_was_active) {
        deactivate_plugins($spro, true);
    }
    lg_t(in_array('scalability_pro', lg_offer_ids(lg_tab('overview')), true), 'with Scalability Pro inactive again, its offer returns');
}
