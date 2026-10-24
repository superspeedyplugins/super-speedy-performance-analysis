<?php
// Overview "Top insights": an insight measured on a page links to the URL that was measured and
// to that page's analysis on the Pages tab (plan .docs/2026-10-24-overview-insight-page-links-plan.md).
//
// A real spot analysis of Home and the search page, with an MU fixture that runs a 200 ms query on
// search requests only, produces a real slow_query finding on search-many. The Overview tab is then
// rendered through the real sspa_render_tab AJAX handler as the admin. The fixture and the run are
// retained; the fixture is removed and the previous run cleared on the next entry.

require_once SSPA_PLUGIN_DIR . '.tests/lead-gen/lib.php';
require_once SSPA_PLUGIN_DIR . '.tests/lib/retained-fixtures.php';
global $wpdb;
wp_set_current_user(1);
sspa_retained_reset('144-overview-links');

$fixture = WPMU_PLUGIN_DIR . '/sspa-case144-slow-search.php';
wp_mkdir_p(WPMU_PLUGIN_DIR);
file_put_contents($fixture, <<<'PHP'
<?php
/**
 * Plugin Name: SSPA case 144 fixture - slow search query
 * Description: Test-only. Runs one 200 ms query on front-end search requests, so the analysis
 * records a real slow_query finding on the search page.
 */
add_action('template_redirect', static function () {
    if (is_search()) {
        $GLOBALS['wpdb']->query('SELECT SLEEP(0.2) /* sspa_case144_slow_search */');
    }
});
PHP
);
sleep(3); // php-fpm opcache revalidation

$run_id = SSPA_Run_Controller::start(array('type' => 'spot', 'trigger' => 'manual', 'user_id' => 1, 'page_keys' => array('home', 'search-many')));
if (!lg_t(!is_wp_error($run_id), 'a spot analysis of Home and search starts' . (is_wp_error($run_id) ? ': ' . $run_id->get_error_message() : ''))) { return; }
sspa_retained_save('144-overview-links', array('runs' => array((int) $run_id)));
$deadline = time() + 600;
do {
    SSPA_Run_Controller::process_batch($run_id);
    $status = SSPA_Run_Controller::status($run_id);
} while ($status && in_array($status['status'], array('queued', 'crawling', 'analysing'), true) && time() < $deadline);
if (!lg_t($status && 'done' === $status['status'], 'the analysis finished (' . ($status ? $status['status'] : 'null') . ')')) { return; }

$top = SSPA_Insights::top((int) $run_id, 5);
$search_finding = null;
foreach ($top as $finding) {
    if ('search-many' === $finding['page_key'] && 'slow_query' === $finding['finding_type']) { $search_finding = $finding; }
}
if (!lg_t((bool) $search_finding, 'the run found the fixture slow query on search-many among the top insights')) { return; }
$profile = $wpdb->get_row($wpdb->prepare("SELECT id, url FROM %i WHERE run_id = %d AND page_key = %s AND plugin_set_hash = '' ORDER BY id DESC LIMIT 1", SSPA_Schema::table('profiles'), (int) $run_id, 'search-many'), ARRAY_A);
lg_t($profile && false !== strpos($profile['url'], 's='), 'the measured search URL is stored with its profile (' . ($profile ? $profile['url'] : 'none') . ')');

$tab = lg_tab('overview');
lg_t('' !== $tab, 'the Overview tab renders');
$dom = new DOMDocument();
libxml_use_internal_errors(true);
$dom->loadHTML('<?xml encoding="utf-8"?><div>' . $tab . '</div>');
libxml_clear_errors();
$xpath = new DOMXPath($dom);
$items = $xpath->query('//ol[contains(@class,"sspa-insights")]/li');
lg_t($items->length === count($top), 'one list item per top insight (' . $items->length . ' of ' . count($top) . ')');

foreach ($top as $index => $finding) {
    $item = $items->item($index);
    if (!$item) { continue; }
    $open = $xpath->query('.//a[contains(@class,"sspa-insight-open")]', $item);
    $analysis = $xpath->query('.//a[contains(@class,"sspa-insight-profile")]', $item);
    $expected = $finding['page_key'] ? $wpdb->get_row($wpdb->prepare("SELECT id, url FROM %i WHERE run_id = %d AND page_key = %s AND plugin_set_hash = '' AND method = 'GET' ORDER BY id DESC LIMIT 1", SSPA_Schema::table('profiles'), (int) $run_id, $finding['page_key']), ARRAY_A) : null;
    $label = $finding['finding_type'] . ' on ' . ($finding['page_key'] ?: 'the site');
    if (!$expected) {
        lg_t(0 === $open->length && 0 === $analysis->length, $label . ': a site-wide insight carries no page links');
        continue;
    }
    $open_href = $open->length ? $open->item(0)->getAttribute('href') : '';
    lg_t($open_href === $expected['url'] && '_blank' === ($open->length ? $open->item(0)->getAttribute('target') : ''), $label . ': "Open the page" goes to the measured URL in a new tab (' . $open_href . ')');
    $href = $analysis->length ? html_entity_decode($analysis->item(0)->getAttribute('href')) : '';
    $id = $analysis->length ? (int) $analysis->item(0)->getAttribute('data-profile-id') : 0;
    lg_t($id === (int) $expected['id'] && false !== strpos($href, 'page=sspa') && false !== strpos($href, 'sspa_profile=' . (int) $expected['id']) && '#pages' === substr($href, -6), $label . ': "Page analysis" opens profile ' . (int) $expected['id'] . ' on the Pages tab (' . $href . ')');
}

// The deep link opens a real panel: the profile it names renders through the Pages tab's own handler.
$panel = lg_panel((int) $profile['id']);
lg_t(false !== strpos($panel, 'sspa-adhoc-rerun'), 'the linked page analysis renders, with its Re-run button');
