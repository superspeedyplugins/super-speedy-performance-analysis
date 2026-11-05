<?php
// Compare includes pages measured with "Analyse this page" (.docs/2026-11-05-compare-custom-pages.md).
// Each is its own one-page 'adhoc' run, so before 0.61.7 it never appeared beside a baseline:
// on the 1M-product benchmark the attribute-filter pages were in no Compare chart.
//
// Real runs: spot A, an adhoc run of a custom URL, spot B, the same custom URL again, then the
// plugin set changes and a second custom URL is measured. Comparing A with B must show the
// custom URL on both sides, labelled by its URL, and never the URL measured under another setup.

defined('ABSPATH') || exit;

$GLOBALS['sspa_159_failures'] = 0;
function sspa_159_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
    if (!$ok) { $GLOBALS['sspa_159_failures']++; }
}

function sspa_159_drive($args) {
    $run_id = SSPA_Run_Controller::start($args + array('user_id' => 1));
    if (is_wp_error($run_id)) { throw new RuntimeException($run_id->get_error_message()); }
    $deadline = time() + 180;
    do {
        SSPA_Run_Controller::process_batch($run_id);
        $status = SSPA_Run_Controller::status($run_id);
    } while ($status && in_array($status['status'], array('queued', 'crawling', 'analysing'), true) && time() < $deadline);
    if (!$status || 'done' !== $status['status']) { throw new RuntimeException('run ' . $run_id . ' ended ' . ($status ? $status['status'] : 'missing')); }
    return (int) $run_id;
}

wp_set_current_user(1);
if (!function_exists('activate_plugin')) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
$active = SSPA_Run_Controller::active_run_id();
if ($active) { SSPA_Run_Controller::cancel($active); SSPA_Run_Controller::process_batch($active); }

// A plugin used only to change the plugin set for the last run; inactive on the way in.
$fixture = 'sspa-compare-pages-fixture/sspa-compare-pages-fixture.php';
wp_mkdir_p(WP_PLUGIN_DIR . '/sspa-compare-pages-fixture');
file_put_contents(WP_PLUGIN_DIR . '/' . $fixture, "<?php\n/**\n * Plugin Name: SSPA Compare Pages Fixture\n * Version: 1.0.0\n */\n");
wp_clean_plugins_cache(true);
deactivate_plugins($fixture, true);

$custom = home_url('/?case159=custom-page');
$other  = home_url('/?case159=other-setup');
try {
    $before = sspa_159_drive(array('type' => 'spot', 'page_keys' => array('home')));
    sspa_159_drive(array('type' => 'adhoc', 'url' => $custom));
    $after = sspa_159_drive(array('type' => 'spot', 'page_keys' => array('home')));
    sspa_159_drive(array('type' => 'adhoc', 'url' => $custom));
    activate_plugin($fixture);
    sspa_159_drive(array('type' => 'adhoc', 'url' => $other));
    deactivate_plugins($fixture, true);

    SSPA_History_Series::$include_adhoc = true;
    $series = SSPA_History_Series::build($after, 'request_wall_ms', $before, 'pair');
    $page = null; $other_seen = false;
    foreach ($series['pages'] as $candidate) {
        if ('/?case159=custom-page' === $candidate['relative_url']) { $page = $candidate; }
        if ('/?case159=other-setup' === $candidate['relative_url']) { $other_seen = true; }
    }
    sspa_159_t(is_array($page), 'the chart includes the page measured with Analyse this page');
    sspa_159_t($page && $page['previous']['point_count'] > 0 && $page['current']['point_count'] > 0, 'it has measurements on both the Before and the After side');
    sspa_159_t($page && '/?case159=custom-page' === $page['label'], 'it is labelled by its URL, not its url-<hash> key' . ($page ? ' (' . $page['label'] . ')' : ''));
    sspa_159_t(!$other_seen, 'a page measured under a different plugin set is not pulled in');
    sspa_159_t((bool) array_filter($series['pages'], function ($p) { return 'home' === $p['page_key']; }), 'the runs\' own pages are still there');

    $comparison = SSPA_History::compare($before, $after);
    $row = null;
    foreach ($comparison['pages'] as $candidate) {
        if ($page && $candidate['key'] === $page['key']) { $row = $candidate; }
    }
    sspa_159_t($row && $row['present']['before'] && $row['present']['after'], 'the comparison table has the page on both sides');
    sspa_159_t($row && !isset($row['url']) && !isset($row['label']), 'the comparison page entry keeps its exact allowlisted keys (no URL added)');
    $html = SSPA_History::render($comparison);
    sspa_159_t(false !== strpos($html, '/?case159=custom-page'), 'the rendered table shows the page by its URL');

    SSPA_History_Series::$include_adhoc = false;
    $plain = SSPA_History_Series::build($after, 'request_wall_ms', $before, 'pair');
    SSPA_History_Series::$include_adhoc = true;
    sspa_159_t(!array_filter($plain['pages'], function ($p) { return 0 === strpos($p['page_key'], 'url-'); }), 'with the checkbox off, only the runs\' own pages are compared');
} catch (Throwable $error) {
    sspa_159_t(false, $error->getMessage());
}

if ($GLOBALS['sspa_159_failures']) { exit(1); }
