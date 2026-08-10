<?php
// Plant a deliberately bad plugin, run a spot analysis, and assert every planted offence
// becomes a finding that names the culprit. This is the acceptance test for Phase 2.

function sspa_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

global $wpdb;

// --- Plant the bad plugin ---
$bad_dir = WP_PLUGIN_DIR . '/sspa-bad-plugin';
if (!is_dir($bad_dir)) {
    mkdir($bad_dir);
}
$bad_code = <<<'PHP'
<?php
/**
 * Plugin Name: SSPA Bad Plugin (test fixture)
 * Description: Deliberately terrible. Every sin here must be caught by the analysis engine.
 */

// Sleep endpoint so the blocking-HTTP sin has a slow target without leaving the container.
if (isset($_GET['sspa_sleep'])) {
    add_action('init', function () {
        usleep(200000);
        die('slow');
    }, 0);
}

add_action('wp_footer', function () {
    global $wpdb;

    // Sin 1: N+1 - many single-row queries in a loop (unique literals, so not duplicates).
    for ($i = 1; $i <= 60; $i++) {
        $wpdb->get_var("SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_id = " . $i);
    }

    // Sin 2: big result set - fetch 500+ rows in one query.
    $wpdb->get_results("SELECT meta_id, post_id, meta_key FROM {$wpdb->postmeta} LIMIT 600");

    // Sin 3: slow query with a classifiable shape (ORDER BY rand()). Must land DECISIVELY
    // over the critical line (slow_query_ms x 5 = 250ms): the plain 3-way self-join
    // measured ~237ms on Apple Silicon - a knife-edge warn that broke the score assertion.
    // The x2 factor pushes it to ~800ms here.
    // Every alias is bounded. Unbounded, this is O(posts^3): tuned to ~800ms on a fresh
    // container, it reached ~170 million rows once the environment had accumulated 440
    // posts and every profiled request hit the 60s crawler timeout - which presents as
    // "the analysis engine found nothing", not as a slow query.
    $wpdb->get_results("SELECT p1.ID FROM (SELECT ID FROM {$wpdb->posts} LIMIT 120) p1, (SELECT ID FROM {$wpdb->posts} LIMIT 120) p2, (SELECT ID FROM {$wpdb->posts} LIMIT 120) p3 ORDER BY rand() LIMIT 5");

    // Sin 4: byte-identical duplicate queries (missing caching).
    for ($i = 0; $i < 8; $i++) {
        $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'blogname'");
    }

    // Sin 5: blocking HTTP call during page render.
    wp_remote_get(home_url('/?sspa_sleep=1'), array('timeout' => 5, 'sslverify' => false));
});
PHP;
file_put_contents($bad_dir . '/sspa-bad-plugin.php', $bad_code);
$activated = activate_plugin('sspa-bad-plugin/sspa-bad-plugin.php');
wp_cache_flush(); // apache must see the fresh active_plugins despite Redis alloptions caching
sspa_t(!is_wp_error($activated), 'bad plugin planted and activated');
sleep(3); // opcache revalidation window before profiled requests

// --- Run a spot analysis of the home page (wp_footer fires there) ---
$run_id = SSPA_Run_Controller::start(array('type' => 'spot', 'page_keys' => array('home'), 'user_id' => 1));
if (is_wp_error($run_id)) {
    echo 'FAIL: start(): ' . $run_id->get_error_message() . "\n";
    return;
}
$deadline = time() + 180;
do {
    SSPA_Run_Controller::process_batch($run_id);
    $status = SSPA_Run_Controller::status($run_id);
} while ($status && in_array($status['status'], array('crawling', 'analysing'), true) && time() < $deadline);
sspa_t($status && 'done' === $status['status'], 'spot run completed: ' . ($status ? $status['status'] : 'null'));

// --- Assert findings name the culprit ---
$findings = $wpdb->get_results($wpdb->prepare(
    'SELECT * FROM ' . SSPA_Schema::table('findings') . ' WHERE run_id = %d',
    $run_id
), ARRAY_A);
$by_type = array();
foreach ($findings as $f) {
    $by_type[$f['finding_type']][] = $f;
}
$named = function ($type) use ($by_type) {
    foreach (isset($by_type[$type]) ? $by_type[$type] : array() as $f) {
        if ($f['component'] === 'sspa-bad-plugin') {
            return $f;
        }
    }
    return null;
};

$f = $named('query_loop');
sspa_t($f !== null, 'N+1 loop caught (query_loop names sspa-bad-plugin)');
if ($f) {
    $e = json_decode($f['evidence'], true);
    sspa_t($e['query_count'] >= 60, 'query_loop count credible (' . $e['query_count'] . ')');
}

$f = $named('big_result_set');
sspa_t($f !== null, 'big result set caught');
if ($f) {
    $e = json_decode($f['evidence'], true);
    sspa_t($e['rows'] >= 500, 'row count credible (' . $e['rows'] . ')');
}

$f = $named('slow_query');
sspa_t($f !== null, 'slow query caught');
if ($f) {
    $e = json_decode($f['evidence'], true);
    sspa_t($e['shape'] === 'rand', 'shape classified as rand (' . $e['shape'] . ')');
    sspa_t($f['recommendation_key'] === 'slow_query_rand', 'recommendation key mapped');
}

$f = $named('dupe_queries');
sspa_t($f !== null, 'duplicate queries caught');
if ($f) {
    $e = json_decode($f['evidence'], true);
    sspa_t($e['count'] >= 8, 'dupe count credible (' . $e['count'] . ')');
}

$f = $named('slow_http');
sspa_t($f !== null, 'blocking HTTP call caught');
if ($f) {
    sspa_t($f['severity'] === 'critical', 'front-end blocking HTTP is critical');
}

// Insights render without notices and name the plugin in the top 5.
$top = SSPA_Insights::top($run_id, 5);
sspa_t(count($top) >= 3, 'top insights populated (' . count($top) . ')');
$mentions = 0;
foreach ($top as $finding) {
    $r = SSPA_Insights::render($finding);
    if (strpos($r['headline'], 'sspa-bad-plugin') !== false) {
        $mentions++;
    }
    sspa_t($r['headline'] !== '', 'insight renders: ' . $r['headline']);
}
sspa_t($mentions >= 2, "bad plugin named in top insights ($mentions mentions)");

// Score reflects the mess; demographics snapshot attached.
$run = SSPA_Run_Controller::run_row($run_id);
$notes = json_decode($run['notes'], true);
sspa_t(is_array($notes) && $notes['score'] < 80, 'score dented by findings (' . $notes['score'] . ')');
sspa_t(!empty($run['site_metrics_id']), 'demographics snapshot linked to run');
$demo = SSPA_Demographics::latest();
sspa_t($demo && $demo['sector'] === 'e-commerce', 'sector inferred as e-commerce (' . $demo['sector'] . ')');
sspa_t($demo && $demo['metrics']['post_counts']['product'] > 10, 'product count captured');

// --- Clean up ---
deactivate_plugins('sspa-bad-plugin/sspa-bad-plugin.php');
unlink($bad_dir . '/sspa-bad-plugin.php');
rmdir($bad_dir);
sspa_t(!file_exists($bad_dir), 'bad plugin removed');
