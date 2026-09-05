<?php
// A full baseline may cover more pages than the offered post-update quick scan.
// Reproduced with 28 baseline profiles and three quick profiles: History offered
// the baseline, then excluded it and selected an older run instead.
defined('ABSPATH') || exit;
wp_set_current_user(1);

function sspa_63_run($args) {
    $id = SSPA_Run_Controller::start($args + array('user_id' => 1));
    if (is_wp_error($id)) {
        throw new RuntimeException($id->get_error_message());
    }
    $deadline = time() + 240;
    do {
        SSPA_Run_Controller::process_batch($id);
        $status = SSPA_Run_Controller::status($id);
    } while (in_array($status['status'], array('crawling', 'analysing'), true) && time() < $deadline);
    if ('done' !== $status['status']) {
        throw new RuntimeException('Run ended: ' . $status['status']);
    }
    return (int) $id;
}

function sspa_63_check($ok, $label) {
    echo ($ok ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$ok) {
        $GLOBALS['sspa_63_failures']++;
    }
}

$GLOBALS['sspa_63_failures'] = 0;
try {
    if (!function_exists('activate_plugin')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $fixture_dir = WP_PLUGIN_DIR . '/sspa-quick-baseline-fixture';
    $fixture = $fixture_dir . '/sspa-quick-baseline-fixture.php';
    wp_mkdir_p($fixture_dir);
    $revision = (string) round(microtime(true) * 1000);
    file_put_contents($fixture, "<?php\n/** Plugin Name: SSPA Quick Baseline Fixture\n * Version: 1.0.$revision\n */\n");
    wp_clean_plugins_cache(true);
    $activation = activate_plugin('sspa-quick-baseline-fixture/sspa-quick-baseline-fixture.php');
    if (is_wp_error($activation)) {
        throw new RuntimeException($activation->get_error_message());
    }
    $before = sspa_63_run(array('type' => 'baseline'));
    $keys = SSPA_History_Series::quick_comparison_page_keys();
    sspa_63_check(count(SSPA_History_Series::profile_rows($before)) > count($keys), 'the real full baseline covers more pages than the quick scan');
    sspa_63_check(SSPA_History_Series::is_compatible_run_id($before, $keys), 'the administrator can select this full baseline for the quick comparison');
    file_put_contents($fixture, "<?php\n/** Plugin Name: SSPA Quick Baseline Fixture\n * Version: 2.0.$revision\n */\n");
    wp_clean_plugins_cache(true);
    $after = sspa_63_run(array(
        'type' => 'spot', 'page_keys' => $keys, 'trigger' => 'plugin_change',
        'share_context' => array('history_comparison' => array('baseline_run_id' => $before)),
    ));
    $document = SSPA_History_Series::build($after);
    sspa_63_check(!is_wp_error($document) && $document['previous'] && in_array($before, $document['previous']['run_ids'], true), 'the chart retains the exact offered full baseline after the quick scan');
    if (!is_wp_error($document)) {
        $home = array_values(array_filter($document['pages'], function ($page) { return 'home|GET|anon|normal' === $page['key']; }));
        sspa_63_check($home && $home[0]['previous']['point_count'] > 0 && $home[0]['current']['point_count'] > 0, 'the same Home scenario has real before and after points');
        $missing = array_values(array_filter($document['pages'], function ($page) { return 'admin-dashboard|GET|admin|normal' === $page['key']; }));
        sspa_63_check($missing && null === $missing[0]['current']['median'] && null === $missing[0]['delta']['absolute'] && $missing[0]['current']['fault_count'] > 0, 'pages outside the quick scan are missing evidence, never zero-time improvements');
    }
    // Retain both runs and the version-updated fixture for inspection.
} catch (Throwable $error) {
    sspa_63_check(false, $error->getMessage());
}
if ($GLOBALS['sspa_63_failures']) {
    exit(1);
}
