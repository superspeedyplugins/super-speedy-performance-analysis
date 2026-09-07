<?php
// Retained scalar fixtures exercise the real comparison algorithm, not benchmark claims.
defined('ABSPATH') || exit;
wp_set_current_user(1);
$failures = 0;
function sspa_67_run($pages) {
    global $wpdb;
    $environment = SSPA_Demographics::snapshot();
    $row = array(
        'blog_id' => get_current_blog_id(), 'status' => 'done', 'run_type' => 'spot',
        'run_uuid' => wp_generate_uuid4(), 'trigger_source' => 'test',
        'measurement_version' => SSPA_Community_Schema::MEASUREMENT_VERSION,
        'site_metrics_id' => $environment['id'],
        'plugin_set' => wp_json_encode(array('components' => SSPA_Community_Exporter::component_inventory_snapshot(), 'user_id' => 1)),
        'started' => gmdate('Y-m-d H:i:s'), 'finished' => gmdate('Y-m-d H:i:s'),
        'notes' => wp_json_encode(array('fixture' => 'sspa-67-headline', 'kind' => 'algorithm scalar fixture, not performance measurement')),
    );
    if (false === $wpdb->insert(SSPA_Schema::table('runs'), $row)) { throw new RuntimeException($wpdb->last_error); }
    $id = (int) $wpdb->insert_id;
    foreach ($pages as $key => $sample) {
        $ms = $sample[0];
        $profile = SSPA_Profile_Store::save($id, array(
            'page_key' => $key, 'url' => home_url('/'), 'variant' => 'anon', 'blocked_by' => null,
            'samples' => array(array('wall_ms' => $ms, 'code' => $sample[1], 'error' => null, 'cached' => false,
                'capture' => array('overview' => array('gen_ms' => $ms), 'sql' => array('total_ms' => 0), 'http' => array('total_ms' => 0)))),
        ));
        if (!$profile || $wpdb->last_error) { throw new RuntimeException('Profile save failed: ' . $wpdb->last_error); }
    }
    return $id;
}
try {
    $cases = array(
        'unmatched slow page cannot create an improvement' => array(array('home' => array(100, 200), 'shop' => array(900, 200)), array('home' => array(100, 200)), 100, 100),
        'failed page cannot change the successful matched-page headline' => array(array('home' => array(100, 200), 'shop' => array(900, 200)), array('home' => array(120, 200), 'shop' => array(10, 500)), 100, 120),
        'no successful matched pages yields unknown on both sides' => array(array('home' => array(100, 200)), array('home' => array(10, 500)), null, null),
        'valid zero response time remains a comparable measurement' => array(array('home' => array(0, 200)), array('home' => array(10, 200)), 0, 10),
    );
    foreach ($cases as $label => $case) {
        $comparison = SSPA_History::compare(sspa_67_run($case[0]), sspa_67_run($case[1]));
        if (is_wp_error($comparison)) { throw new RuntimeException($comparison->get_error_message()); }
        $headline = $comparison['headline'];
        $ok = (null === $case[2] ? null === $headline['before'] : (float) $case[2] === (float) $headline['before'])
            && (null === $case[3] ? null === $headline['after'] : (float) $case[3] === (float) $headline['after']);
        echo ($ok ? 'PASS: ' : 'FAIL: ') . $label . ' ' . wp_json_encode($headline) . "\n";
        if (!$ok) { $failures++; }
        $expected_pages = count(array_unique(array_merge(array_keys($case[0]), array_keys($case[1]))));
        $ok = $expected_pages === count($comparison['pages']);
        echo ($ok ? 'PASS: ' : 'FAIL: ') . "unmatched and failed pages remain in the detailed evidence\n";
        if (!$ok) { $failures++; }
    }
} catch (Throwable $error) {
    echo 'FAIL: ' . $error->getMessage() . "\n";
    $failures++;
}
if ($failures) { exit(1); }
