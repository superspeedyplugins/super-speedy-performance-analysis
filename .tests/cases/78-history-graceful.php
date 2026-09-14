<?php
// Retained-record fixtures use the real schema and profile store, as case 67 does.
// They represent old exports, partial jobs and experiment results, not benchmark claims.
defined('ABSPATH') || exit;
wp_set_current_user(1);
$GLOBALS['sspa_78_failures'] = 0;
function sspa_78_check($ok, $label) {
    echo ($ok ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$ok) { $GLOBALS['sspa_78_failures']++; }
}
function sspa_78_run($overrides = array(), $page_key = 'home', $samples = null, $hash = '') {
    global $wpdb;
    $row = array_merge(array('blog_id' => get_current_blog_id(), 'status' => 'done', 'run_type' => 'spot',
        'run_uuid' => wp_generate_uuid4(), 'trigger_source' => 'test', 'measurement_version' => 1,
        'site_metrics_id' => 0, 'plugin_set' => wp_json_encode(array('components' => array(array('type' => 'plugin', 'slug' => 'unversioned-test', 'version' => null)))),
        'started' => gmdate('Y-m-d H:i:s'), 'finished' => gmdate('Y-m-d H:i:s'),
        'notes' => wp_json_encode(array('fixture' => 'history-graceful', 'kind' => 'retained algorithm fixture'))), $overrides);
    if (false === $wpdb->insert(SSPA_Schema::table('runs'), $row)) { throw new RuntimeException($wpdb->last_error); }
    $id = (int) $wpdb->insert_id;
    $samples = null === $samples ? array(array('wall_ms' => 123, 'code' => 200, 'error' => null, 'cached' => false,
        'capture' => array('overview' => array('gen_ms' => 123), 'sql' => array('total_ms' => 0), 'http' => array('total_ms' => 0)))) : $samples;
    $samples = array_map(function ($sample) { return $sample + array('wall_ms' => null, 'code' => 0, 'error' => null, 'cached' => false); }, $samples);
    $profile_id = SSPA_Profile_Store::save($id, array('page_key' => $page_key, 'url' => home_url('/'), 'variant' => 'anon', 'plugin_set_hash' => $hash, 'blocked_by' => null, 'samples' => $samples));
    if (!$profile_id || $wpdb->last_error) { throw new RuntimeException('Fixture profile failed: ' . $wpdb->last_error); }
    return $id;
}
try {
    global $wpdb;
    $before = sspa_78_run();
    $after = sspa_78_run();
    $cases = array('unversioned plugin / absent environment' => array($before, $after));
    foreach (array(
        'empty legacy inventory' => array('plugin_set' => '[]'),
        'unreadable inventory' => array('plugin_set' => '{invalid'),
        'old measurement version' => array('measurement_version' => 0),
        'different measurement version' => array('measurement_version' => 99),
        'failed partial analysis' => array('status' => 'failed'),
        'cancelled partial analysis' => array('status' => 'cancelled'),
        'experiment run' => array('run_type' => 'deep'),
        'cache experiment' => array('run_type' => 'cache_impact'),
    ) as $label => $row) { $cases[$label] = array($before, sspa_78_run($row)); }
    foreach (array('wp', 'php', 'mysql', 'db_family', 'object_cache', 'object_cache_category', 'page_cache', 'hpos', 'checkout_type', 'multisite', 'locale', 'environment_type') as $key) {
        $environment = SSPA_Demographics::snapshot();
        $metrics = array($key => 'different-retained-value');
        $wpdb->insert(SSPA_Schema::table('site_metrics'), array('metrics' => wp_json_encode($metrics), 'sector' => 'general', 'created' => gmdate('Y-m-d H:i:s')));
        if ($wpdb->last_error) { throw new RuntimeException($wpdb->last_error); }
        $changed_environment_id = (int) $wpdb->insert_id;
        $cases['changed environment ' . $key] = array(sspa_78_run(array('site_metrics_id' => $environment['id'])), sspa_78_run(array('site_metrics_id' => $changed_environment_id)));
    }
    $cases['same run'] = array($after, $after);
    $cases['disjoint pages'] = array($before, sspa_78_run(array(), 'shop'));
    // Sweep jobs persist the MD5 of their JSON configuration payload, not SHA-256.
    $configuration_hash = md5(wp_json_encode(array('exclude' => array('unversioned-test'))));
    $cases['test configuration'] = array($before, sspa_78_run(array('run_type' => 'deep'), 'home', null, $configuration_hash));
    for ($i = 0; $i < 51; $i++) { $latest = sspa_78_run(); }
    $cases['older than fifty runs'] = array($before, $latest);
    foreach ($cases as $label => $ids) {
        $doc = SSPA_History_Series::build($ids[1], 'request_wall_ms', $ids[0], 'pair');
        sspa_78_check(!is_wp_error($doc) && array($ids[0]) === $doc['previous']['run_ids'] && array($ids[1]) === $doc['current']['run_ids'] && count($doc['pages']) > 0, $label . ': chart keeps selected evidence');
        $summary = SSPA_History::compare($ids[0], $ids[1]);
        sspa_78_check(!is_wp_error($summary) && count($summary['pages']) > 0, $label . ': comparison summary remains available');
    }
    sspa_78_check(SSPA_History_Series::is_compatible_run_id($before, array('not-measured')), 'quick comparison does not require page coverage');
    $error = sspa_78_run(array(), 'error-page', array(array('wall_ms' => 450, 'code' => 500), array('wall_ms' => 0, 'code' => 200), array('code' => 200)));
    $doc = SSPA_History_Series::build($error, 'request_wall_ms', $before, 'pair');
    $page = array_values(array_filter($doc['pages'], function ($p) { return $p['page_key'] === 'error-page'; }))[0];
    sspa_78_check(array(450.0, 0.0) === array_column($page['current']['points'], 'value'), 'HTTP error timing and valid zero are retained');
    sspa_78_check('http_error' === $page['current']['points'][0]['state'] && count($page['current']['faults']) === 1, 'error stays labelled and missing sample stays missing');
    ob_start(); include dirname(__DIR__, 2) . '/includes/admin/tabs/history.php'; $html = ob_get_clean();
    sspa_78_check(strpos($html, 'value="' . $before . '"') !== false, 'picker includes old retained run');
} catch (Throwable $error) { sspa_78_check(false, $error->getMessage()); }
if ($GLOBALS['sspa_78_failures']) { exit(1); }
