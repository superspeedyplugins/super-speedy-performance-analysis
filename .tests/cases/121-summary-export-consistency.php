<?php
require __DIR__ . '/120-actionable-summary.php';
update_option('sspa_summary_variance', 1, true);
file_put_contents(ABSPATH . 'summary-sample-counter', '0');
wp_cache_flush();
try {
    $run = SSPA_Run_Controller::start(array('type' => 'adhoc', 'url' => $url, 'user_id' => 1));
    if (is_wp_error($run)) throw new RuntimeException($run->get_error_message());
    sspa_fleet_complete($run);
    $profile = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE run_id = %d ORDER BY id DESC LIMIT 1', SSPA_Schema::table('profiles'), $run), ARRAY_A);
    $capture = SSPA_Profile_Panel::capture($profile);
    $export = SSPA_Markdown_Export::build('page', (int) $profile['id']);
    if (is_wp_error($export)) throw new RuntimeException($export->get_error_message());
    $md = $export['markdown'];
    $tag = getenv('SSPA_SUMMARY_STAGE') ?: SSPA_VERSION;
    file_put_contents($out . $tag . '-variance.json', wp_json_encode(array('profile' => array_diff_key($profile, array('profile_blob' => true)), 'capture' => $capture), JSON_PRETTY_PRINT));
    file_put_contents($out . $tag . '-variance.md', $md);
    sspa_fleet_assert(strpos($md, 'GET /summary-fixture/') !== false, 'request context names the measured sub-page path');
    $sum = array_sum(array_column($capture['components'], 'query_count'));
    preg_match('/\| Queries \| ([0-9,]+) \|/', $md, $query_match);
    $displayed_count = isset($query_match[1]) ? (int) str_replace(',', '', $query_match[1]) : -1;
    sspa_fleet_assert($sum === $displayed_count, 'detailed attributed queries reconcile with the displayed measured request');
    $http = array_sum(array_column($capture['components'], 'http_ms'));
    preg_match('/\| Outbound HTTP \| ([0-9,.]+) ms \|/', $md, $http_match);
    $displayed_http = isset($http_match[1]) ? (float) str_replace(',', '', $http_match[1]) : -1;
    sspa_fleet_assert($http <= $displayed_http + .2, 'component HTTP never exceeds that request headline HTTP');
    sspa_fleet_assert(!isset($capture['components']['mu:sspa-loader']), 'profiler token-consumption SQL is not a site component');
    sspa_fleet_assert((float) $capture['sql']['count'] >= 12 && (float) $capture['http']['total_ms'] >= 200, 'real median-generation request carries the planted query and HTTP load');
} finally {
    update_option('sspa_summary_variance', 0, true);
    wp_cache_flush();
}
// Genuine pre-fix SQL evidence, projected without private filesystem/boot data.
$legacy = json_decode(file_get_contents(SSPA_PLUGIN_DIR . '.tests/fixtures/legacy-summary-sql.json'), true);
$sample = $legacy['sample'];
$sample['capture'] = $legacy['capture'];
$legacy_id = SSPA_Profile_Store::save($run, array('page_key' => 'legacy-summary-sql', 'url' => $url, 'variant' => 'anon', 'samples' => array($sample), 'blocked_by' => null));
$legacy_row = SSPA_Profile_Panel::profile_row($legacy_id);
$blob_before = hash('sha256', $legacy_row['profile_blob']);
$legacy_export = SSPA_Markdown_Export::build('page', $legacy_id);
$legacy_panel = SSPA_Profile_Panel::render($legacy_id);
sspa_fleet_assert(!is_wp_error($legacy_export) && strpos($legacy_export['markdown'], 'mu:sspa-loader') === false && strpos($legacy_panel, 'mu:sspa-loader') === false, 'retained 0.43.4 report separates profiler SQL from site components');
sspa_fleet_assert(hash('sha256', SSPA_Profile_Panel::profile_row($legacy_id)['profile_blob']) === $blob_before && isset(SSPA_Profile_Panel::export_data($legacy_id)['capture']['components']['mu:sspa-loader']), 'legacy diagnostic capture remains byte-identical with original evidence');
file_put_contents($out . $tag . '-legacy.md', $legacy_export['markdown']);
sspa_fleet_done();
