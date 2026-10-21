<?php
// A small, fast store running WP All Import from wordpress.org with a real completed import.
// Below every speed threshold, so no Scalability Pro, Search or Filters offer may appear; the
// Imports tab shows WP All Import's own run history beside the published figures.
require __DIR__ . '/lib.php';
lg_reset_dismissals();
$run_id = (int) SSPA_Report::latest_done_run_id();
lg_t($run_id > 0, 'a completed real analysis exists (run ' . $run_id . ')');
$pages = array();
foreach (SSPA_Report::pages($run_id) as $page) {
    $pages[$page['page_key']] = $page;
}
echo 'measured: ' . wp_json_encode(array_map(function ($p) { return array($p['generation_ms'], $p['sql_ms']); }, $pages)) . "\n";

// ---- Silent below the thresholds ----
$overview = lg_tab('overview');
$ids = lg_offer_ids($overview);
lg_t(!array_intersect($ids, array('search', 'filters')), 'no Search or Filters offer on a fast store (' . wp_json_encode($ids) . ')');
$spro_triggers = array();
foreach (lg_offers($overview) as $offer) {
    if ('scalability_pro' === $offer['offer']) {
        $spro_triggers[] = $offer['trigger'];
    }
}
lg_t(!array_diff($spro_triggers, array('S4')), 'Scalability Pro appears, if at all, only for the measured autoload finding (' . wp_json_encode($spro_triggers) . ')');
$fast_unattributed = array();
foreach (array('search-zero', 'search-many', 'product-cat', 'shop', 'admin-products', 'admin-orders-search') as $page_key) {
    $profile = lg_profile_id($run_id, $page_key);
    if (!$profile) {
        lg_t(false, $page_key . ' was measured');
        continue;
    }
    $found = lg_offers(lg_panel($profile));
    $products = array_filter($found, function ($o) { return 'fix_quote' !== $o['offer']; });
    lg_t(!$products, $page_key . ' summary offers no product below the speed thresholds' . sprintf(' [gen %.0f ms, SQL %.0f ms]', $pages[$page_key]['generation_ms'], $pages[$page_key]['sql_ms']) . ($found ? ': ' . wp_json_encode(array_column($found, 'trigger')) : ''));
    // R2 (decided by Dave, 21 October 2026) needs a slow page as well as 35% or more of its
    // generation unattributed: a page generated in under 1,000 ms gets no fix quote, however
    // much of it ran unattributed. The share and time are the summary's own measured figures.
    $summary = lg_summary($profile);
    $gen = (float) $summary['generation_ms'];
    $unattributed_pct = $gen > 0 && null !== $summary['unattributed_ms'] ? 100 * $summary['unattributed_ms'] / $gen : 0;
    $quotes = array_filter($found, function ($o) { return 'fix_quote' === $o['offer']; });
    if ($gen < 1000 && $unattributed_pct >= 35) {
        $fast_unattributed[] = $page_key;
    }
    if ($gen < 1000) {
        lg_t(!$quotes, sprintf('%s is a fast page (%.0f ms, %.0f%% unattributed) and offers no fix quote', $page_key, $gen, $unattributed_pct) . ($quotes ? ': ' . wp_json_encode(array_column($quotes, 'trigger')) : ''));
    } else {
        foreach ($quotes as $offer) {
            preg_match('/(\d+)% of this page\'s generation/', html_entity_decode(wp_strip_all_tags($offer['html']), ENT_QUOTES), $share);
            lg_t('R2' === $offer['trigger'] && isset($share[1]) && (int) $share[1] >= 35, $page_key . ' fix quote is R2 with its measured unattributed share (' . ($share[1] ?? '?') . '%)');
        }
    }
}
// The gate must be exercised: on this store ordinary wp-admin screens run mostly unattributed.
lg_t((bool) $fast_unattributed, 'at least one measured page is fast with 35% or more of its generation unattributed (' . wp_json_encode($fast_unattributed) . ')');
// I1: the active importer is named on the Overview, pointing at the Imports tab.
lg_t(in_array('imports', $ids, true), 'the active WP All Import is answered by the Imports offer on the Overview');
lg_t(false !== strpos($overview, 'href="#imports"'), 'the Overview Imports offer links to the Imports tab');

// ---- The Imports tab ----
$admin_page = wp_remote_get(admin_url('admin.php?page=sspa'), array('cookies' => lg_cookies(), 'timeout' => 120));
lg_t(false !== strpos(wp_remote_retrieve_body($admin_page), 'data-tab="imports"'), 'the Imports tab is in the PA navigation');
$tab = lg_tab('imports');
lg_t('' !== $tab, 'the Imports tab renders through authenticated admin-ajax');
lg_t(false !== strpos($tab, 'WP All Import'), 'the tab names the detected importer');

// WP All Import's own History screen is the independent source for the run.
global $wpdb;
$import_id = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i ORDER BY id DESC LIMIT 1', $wpdb->prefix . 'pmxi_imports'));
$history_page = wp_remote_get(admin_url('admin.php?page=pmxi-admin-history&id=' . $import_id), array('cookies' => lg_cookies(), 'timeout' => 120));
$history_text = preg_replace('/\s+/', ' ', wp_strip_all_tags(wp_remote_retrieve_body($history_page)));
preg_match('/(\d+) \w+ created (\d+) updated (\d+) skipped/', $history_text, $summary);
preg_match('/\b(\d{2}):(\d{2}):(\d{2})\b/', $history_text, $run_time);
lg_t($summary && $run_time, 'WP All Import\'s History screen shows the completed run (' . ($summary[0] ?? 'none') . ', ' . ($run_time[0] ?? 'no time') . ')');
if ($summary && $run_time) {
    $records = (int) $summary[1] + (int) $summary[2] + (int) $summary[3];
    $seconds = 3600 * (int) $run_time[1] + 60 * (int) $run_time[2] + (int) $run_time[3];
    $text = preg_replace('/\s+/', ' ', wp_strip_all_tags($tab));
    lg_t(false !== strpos($text, number_format_i18n($records) . ' records'), 'the tab shows the run\'s ' . $records . ' records');
    lg_t(false !== strpos($text, number_format_i18n($records / max(1, $seconds), 1) . ' records per second'), 'the tab shows the measured ' . number_format_i18n($records / max(1, $seconds), 1) . ' records per second');
}
lg_t(false !== strpos($tab, 'benchmarks/super-speedy-imports/results/lite-2-99-21-import-speed/'), 'the published Lite benchmark is quoted with its source link');
lg_t(false !== strpos($tab, 'benchmarks/super-speedy-imports-vs-wp-all-import-pro/'), 'the published WP All Import comparison is quoted with its source link');
lg_t(false !== strpos($tab, 'Measured on 2 and 5 October 2026'), 'the Lite figure carries its published conditions');
lg_t(false !== strpos($tab, 'plugin-install.php?tab=plugin-information&amp;plugin=super-speedy-imports-lite') || false !== strpos($tab, 'plugin-install.php?tab=plugin-information&plugin=super-speedy-imports-lite'), 'the Install Super Speedy Imports Lite (free) button opens WordPress\'s installer');
lg_t(false !== strpos($tab, 'Install Super Speedy Imports Lite (free)'), 'the install button is labelled as the free Lite');
lg_t('imports_imports_tab' === lg_campaign($tab, 'imports'), 'the Super Speedy Imports Pro link carries its placement campaign');
lg_t(in_array('scalability_pro', lg_offer_ids($tab), true), 'with WP All Import as the importer, Scalability Pro is the secondary offer');
lg_t(count(lg_offers($tab)) <= 2, 'the Imports tab holds at most two offers');
lg_t(!preg_match('/would (save|take)|you could save|minutes faster/i', wp_strip_all_tags($tab)), 'the tab makes no per-site projection');
$bad = lg_identifying_links($tab);
lg_t(!$bad, 'no Imports tab link carries identifying parameters' . ($bad ? ': ' . implode(' ', $bad) : ''));
