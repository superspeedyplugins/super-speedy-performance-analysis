<?php
// Slow-query evidence (plan .docs/2026-10-24-slow-query-evidence-fixes-plan.md), from the demo's
// 6,175 ms search-many query: advice blamed SQL_CALC_FOUND_ROWS (first-match shape), the CTE got no
// plan, the caller named PA's own wpdb wrapper, the stored SQL had {64-hex} for %, and no URL.
//
// A real spot analysis of the search page. The MU fixture rewrites WordPress's own main search query
// through posts_request into a CTE with an 80 ms subquery, so the query keeps core's
// SQL_CALC_FOUND_ROWS and its prepared LIKE '%term%', and is executed by core's WP_Query like the
// demo's. Fixture and run are retained; the previous run is cleared on entry.

require_once SSPA_PLUGIN_DIR . '.tests/lead-gen/lib.php';
require_once SSPA_PLUGIN_DIR . '.tests/lib/retained-fixtures.php';
global $wpdb;
wp_set_current_user(1);
sspa_retained_reset('146-slow-query');

// Unit: which statements may be EXPLAINed.
lg_t(SSPA_Explain::is_explainable("WITH c AS (SELECT ID FROM {$wpdb->posts}) SELECT ID FROM c"), 'a WITH ... SELECT is explainable');
lg_t(!SSPA_Explain::is_explainable("WITH c AS (SELECT ID FROM {$wpdb->posts}) UPDATE {$wpdb->posts} SET post_title = 'x' WHERE ID IN (SELECT ID FROM c)"), 'a WITH ... UPDATE is not');
lg_t(SSPA_Explain::is_explainable("WITH c AS (SELECT ID FROM {$wpdb->posts} WHERE post_title = 'update delete') SELECT ID FROM c"), 'keywords inside string literals do not refuse a WITH ... SELECT');

$fixture = WPMU_PLUGIN_DIR . '/sspa-case146-cte-search.php';
wp_mkdir_p(WPMU_PLUGIN_DIR);
file_put_contents($fixture, <<<'PHP'
<?php
/**
 * Plugin Name: SSPA case 146 fixture - CTE main search query
 * Description: Test-only. Rewrites the main front-end search query into a CTE whose WHERE waits
 * 80 ms once, keeping core's SQL_CALC_FOUND_ROWS and prepared LIKE '%term%'. Core executes it.
 */
// WordPress caches WP_Query results in the persistent object cache, so after the warm-up the
// profiled samples would never execute the query. The demo's search query was executed each time.
add_action('pre_get_posts', static function ($query) {
    if (!is_admin() && $query->is_main_query() && $query->is_search()) {
        $query->set('cache_results', false);
    }
});
add_filter('posts_request', static function ($sql, $query) {
    if (is_admin() || !$query->is_main_query() || !$query->is_search() || false === stripos($sql, 'WHERE 1=1')) {
        return $sql;
    }
    $sql = preg_replace('/WHERE 1=1/i', 'WHERE 1=1 AND (SELECT SLEEP(0.08) FROM sspa_case146_cte) = 0', $sql, 1);
    return 'WITH sspa_case146_cte AS (SELECT 1 AS one) ' . $sql;
}, 10, 2);
PHP
);
sleep(3); // php-fpm opcache revalidation

$run_id = SSPA_Run_Controller::start(array('type' => 'spot', 'trigger' => 'manual', 'user_id' => 1, 'page_keys' => array('search-many')));
if (!lg_t(!is_wp_error($run_id), 'a spot analysis of the search page starts' . (is_wp_error($run_id) ? ': ' . $run_id->get_error_message() : ''))) { return; }
sspa_retained_save('146-slow-query', array('runs' => array((int) $run_id)));
$deadline = time() + 600;
do {
    SSPA_Run_Controller::process_batch($run_id);
    $status = SSPA_Run_Controller::status($run_id);
} while ($status && in_array($status['status'], array('queued', 'crawling', 'analysing'), true) && time() < $deadline);
if (!lg_t($status && 'done' === $status['status'], 'the analysis finished (' . ($status ? $status['status'] : 'null') . ')')) { return; }

$finding = null;
foreach ($wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE run_id = %d AND finding_type = %s AND page_key = %s', SSPA_Schema::table('findings'), (int) $run_id, 'slow_query', 'search-many'), ARRAY_A) as $row) {
    $e = json_decode($row['evidence'], true);
    if (false !== stripos((string) ($e['sql'] ?? ''), 'sspa_case146_cte')) { $finding = $row; $evidence = $e; }
}
if (!lg_t((bool) $finding, 'the CTE search query was flagged as slow on search-many')) { return; }
$profile = $wpdb->get_row($wpdb->prepare("SELECT url FROM %i WHERE run_id = %d AND page_key = %s AND plugin_set_hash = '' ORDER BY id DESC LIMIT 1", SSPA_Schema::table('profiles'), (int) $run_id, 'search-many'), ARRAY_A);

// 1 and 2: every shape is reported; the count is not blamed over a leading-wildcard LIKE.
$shapes = isset($evidence['shapes']) ? (array) $evidence['shapes'] : array();
lg_t(in_array('like', $shapes, true) && in_array('found_rows', $shapes, true), 'every shape is reported: ' . wp_json_encode($shapes) . ' (shape ' . ($evidence['shape'] ?? '-') . ')');
lg_t('slow_query_like' === $finding['recommendation_key'], 'the advice is about the leading-wildcard LIKE, not SQL_CALC_FOUND_ROWS (' . $finding['recommendation_key'] . ')');
// 3: the CTE is explained.
lg_t(!empty($evidence['plan_steps']), 'the CTE has a stored query plan (' . count((array) ($evidence['plan_steps'] ?? array())) . ' steps)');
// 4: the caller is the real call site, not PA's wrapper.
lg_t(false === strpos((string) $evidence['caller'], 'SSPA_Profiling_WPDB') && false !== strpos((string) $evidence['caller'], 'WP_Query'), 'the caller is the real call site: ' . $evidence['caller'] . ' (component ' . $finding['component'] . ')');
// 5: the stored SQL is runnable.
lg_t(!preg_match('/\{[0-9a-f]{64}\}/', (string) $evidence['sql']) && false !== strpos((string) $evidence['sql'], "LIKE '%Tshirts%'"), 'the stored SQL has % and no placeholder-escape hash: ' . (preg_match("/LIKE '[^']{0,90}'/", (string) $evidence['sql'], $m) ? $m[0] : '-'));
// 6: the measured URL is stored with the finding.
lg_t($profile && isset($evidence['page_url']) && $evidence['page_url'] === $profile['url'], 'the finding stores the measured URL as page_url (' . ($evidence['page_url'] ?? 'none') . ')');
