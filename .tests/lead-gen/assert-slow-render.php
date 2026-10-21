<?php
// R2's positive case: a genuinely slow page (about 1,200 ms of real PHP work directly in its
// template, from the lead-gen-slow-render fixture) whose generation is mostly unattributed
// still gets the fixed-price fix quote, in its page summary and its page export.
require __DIR__ . '/lib.php';
lg_reset_dismissals();
$page = get_page_by_path('lead-gen-slow-render');
$page_id = $page ? $page->ID : wp_insert_post(array('post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'lead-gen-slow-render', 'post_title' => 'Slow render fixture', 'post_content' => ''));
$run_id = SSPA_Run_Controller::start(array('type' => 'adhoc', 'url' => get_permalink($page_id), 'user_id' => 1));
if (is_wp_error($run_id)) {
    lg_t(false, 'the analysis of the slow page started: ' . $run_id->get_error_message());
    exit(1);
}
$deadline = time() + 300;
do {
    SSPA_Run_Controller::process_batch($run_id);
    $status = SSPA_Run_Controller::status($run_id);
} while ($status && in_array($status['status'], array('queued', 'crawling', 'analysing'), true) && time() < $deadline);
lg_t($status && 'done' === $status['status'], 'a real analysis of the slow page completed (run ' . $run_id . ', ' . ($status ? $status['status'] : 'missing') . ')');
global $wpdb;
$profile = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE run_id = %d ORDER BY id DESC LIMIT 1', SSPA_Schema::table('profiles'), $run_id));
lg_t($profile > 0, 'the slow page was measured (profile ' . $profile . ')');
$summary = lg_summary($profile);
$gen = (float) $summary['generation_ms'];
$pct = $gen > 0 && null !== $summary['unattributed_ms'] ? 100 * $summary['unattributed_ms'] / $gen : 0;
echo sprintf("measured: generation %.1f ms, unattributed %.1f ms (%.1f%%)\n", $gen, (float) $summary['unattributed_ms'], $pct);
lg_t($gen >= 1000 && $pct >= 35, sprintf('the page is slow with most of its generation unattributed (%.0f ms, %.0f%%)', $gen, $pct));
$found = lg_offers(lg_panel($profile));
lg_t(1 === count($found) && 'fix_quote' === $found[0]['offer'] && 'R2' === $found[0]['trigger'], 'the page summary offers the fix quote for R2; got ' . wp_json_encode(array_map(function ($o) { return $o['offer'] . ':' . $o['trigger']; }, $found)));
if ($found) {
    preg_match('/(\d+)% of this page\'s generation/', html_entity_decode(wp_strip_all_tags($found[0]['html']), ENT_QUOTES), $share);
    lg_t(isset($share[1]) && (int) $share[1] === (int) round($pct), 'the fix quote states the measured unattributed share (' . ($share[1] ?? '?') . '%)');
    lg_t('fix_quote_summary' === lg_campaign(lg_panel($profile), 'fix_quote'), 'the summary fix-quote link carries its placement campaign');
}
lg_t(false !== strpos(lg_markdown('page', $profile), 'utm_campaign=fix_quote_export'), 'the slow page\'s export carries the fix quote');
