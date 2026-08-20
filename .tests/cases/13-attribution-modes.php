<?php
// Proves the two attribution modes actually disagree on the case they exist for: a plugin
// calling a WooCommerce function in a loop rather than using one aggregate query.
//
// The fixture deliberately does NOT run its own $wpdb queries (09's bad plugin already
// covers that, and it produces a single-component chain). It calls INTO WooCommerce, so the
// query executes in WooCommerce's code while the waste is the fixture's fault. Code-owner
// mode must file it under woocommerce; caller mode must file it under the fixture.

function sspa_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

global $wpdb;

$fixture_dir = WP_PLUGIN_DIR . '/sspa-caller-fixture';
if (!is_dir($fixture_dir)) {
    mkdir($fixture_dir);
}
// Distinct, non-existent SKUs so every iteration misses any product cache and reaches the
// database from inside WooCommerce's own data store.
$fixture_code = <<<'PHP'
<?php
/**
 * Plugin Name: SSPA Caller Fixture (test fixture)
 */
add_action('wp_footer', function () {
    if (!function_exists('wc_get_product_id_by_sku')) {
        return;
    }
    for ($i = 1; $i <= 70; $i++) {
        wc_get_product_id_by_sku('sspa-no-such-sku-' . $i);
    }
});
PHP;
file_put_contents($fixture_dir . '/sspa-caller-fixture.php', $fixture_code);
activate_plugin('sspa-caller-fixture/sspa-caller-fixture.php');
wp_cache_flush();
sleep(3); // opcache revalidation

sspa_t(in_array('sspa-caller-fixture/sspa-caller-fixture.php', get_option('active_plugins'), true), 'caller fixture active');

$run_id = SSPA_Run_Controller::start(array('type' => 'spot', 'page_keys' => array('home'), 'user_id' => 1));
$deadline = time() + 180;
do {
    SSPA_Run_Controller::process_batch($run_id);
    $status = $wpdb->get_var($wpdb->prepare('SELECT status FROM ' . SSPA_Schema::table('runs') . ' WHERE id = %d', $run_id));
} while ($status !== 'done' && $status !== 'failed' && time() < $deadline);
sspa_t($status === 'done', 'spot run completed: ' . $status);

// --- The chain must actually materialise, or caller mode is inert ---
$chained = 0;
$example = '';
foreach ($wpdb->get_results($wpdb->prepare(
    'SELECT profile_blob FROM ' . SSPA_Schema::table('profiles') . ' WHERE run_id = %d AND profile_blob IS NOT NULL',
    $run_id
)) as $p) {
    $capture = json_decode(gzuncompress($p->profile_blob), true);
    foreach ((array) (isset($capture['sql']['queries']) ? $capture['sql']['queries'] : array()) as $q) {
        if (!empty($q['chain']) && count($q['chain']) > 1) {
            $chained++;
            if ($example === '') {
                $example = implode(' <- ', $q['chain']);
            }
        }
    }
}
sspa_t($chained > 0, "cross-component chains captured ($chained queries) e.g. $example");

// --- The modes must disagree, in the right direction ---
$owner = array();
foreach (SSPA_Attribution::component_rows($run_id, SSPA_Attribution::MODE_CODE_OWNER) as $r) {
    $owner[$r['component']] = (isset($owner[$r['component']]) ? $owner[$r['component']] : 0) + (int) $r['query_count'];
}
$caller = array();
foreach (SSPA_Attribution::component_rows($run_id, SSPA_Attribution::MODE_CALLER) as $r) {
    $caller[$r['component']] = (isset($caller[$r['component']]) ? $caller[$r['component']] : 0) + (int) $r['query_count'];
}

$owner_fixture = isset($owner['sspa-caller-fixture']) ? $owner['sspa-caller-fixture'] : 0;
$caller_fixture = isset($caller['sspa-caller-fixture']) ? $caller['sspa-caller-fixture'] : 0;
$owner_woo = isset($owner['woocommerce']) ? $owner['woocommerce'] : 0;
$caller_woo = isset($caller['woocommerce']) ? $caller['woocommerce'] : 0;

sspa_t($caller_fixture > $owner_fixture, "caller mode charges the looping plugin more ($caller_fixture vs $owner_fixture queries)");
sspa_t($caller_woo < $owner_woo, "caller mode charges WooCommerce less ($caller_woo vs $owner_woo queries)");

// Conservation: the modes move cost between components, they never create or lose it.
sspa_t(array_sum($owner) === array_sum($caller),
    'both modes account for the same total (' . array_sum($owner) . ')');

// --- The N+1 finding must name the plugin, not WooCommerce ---
$loop_rows = $wpdb->get_results($wpdb->prepare(
    'SELECT component, evidence FROM ' . SSPA_Schema::table('findings') . " WHERE run_id = %d AND finding_type = 'query_loop'",
    $run_id
), ARRAY_A);
$loop_components = wp_list_pluck($loop_rows, 'component');
$fixture_loop = null;
foreach ($loop_rows as $loop_row) {
    if ('sspa-caller-fixture' === $loop_row['component']) {
        $fixture_loop = json_decode($loop_row['evidence'], true);
        break;
    }
}
// 70 iterations clears the query_hog_count threshold of 50, so this must actually fire -
// and it must name the fixture, with WooCommerce recorded as where those calls executed.
// Do not reject every separate WooCommerce loop on the page: a standing test site can
// legitimately expose one, and it says nothing about whether THIS fixture was attributed.
sspa_t(in_array('sspa-caller-fixture', $loop_components, true),
    'query_loop finding names the looping plugin (' . implode(', ', $loop_components) . ')');
sspa_t(
    is_array($fixture_loop)
        && !empty($fixture_loop['ran_in']['woocommerce'])
        && (int) $fixture_loop['ran_in']['woocommerce'] >= 70,
    'the fixture finding records its 70 calls as running inside WooCommerce'
);

// --- Clean up ---
deactivate_plugins('sspa-caller-fixture/sspa-caller-fixture.php');
unlink($fixture_dir . '/sspa-caller-fixture.php');
rmdir($fixture_dir);
sspa_t(!file_exists($fixture_dir), 'caller fixture removed');
