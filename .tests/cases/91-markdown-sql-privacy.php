<?php
require SSPA_PLUGIN_DIR . '.tests/lib/fleet.php';
wp_set_current_user(1);
wp_mkdir_p(WPMU_PLUGIN_DIR);
file_put_contents(WPMU_PLUGIN_DIR . '/sspa-markdown-sql-fixture.php', <<<'FIXTURE'
<?php
add_action('template_redirect', static function () {
    if (!is_front_page() || !get_option('zz_pa_export_probe_armed', false)) return;
    global $wpdb;
    for ($i = 0; $i < 6; $i++) $wpdb->get_var('SELECT "review-canary@example.invalid"');
});
FIXTURE
);
update_option('zz_pa_export_probe_armed', 1, true);
wp_cache_flush();
$run = (int)sspa_fleet_cli(array('sspa','run','--pages=home','--porcelain'));
sspa_fleet_assert($run > 0, 'normal Home analysis completes with the real SQL fixture');
$findings = SSPA_Report::findings($run);
$dupes = array_filter($findings, static function($finding) {return $finding['type'] === 'dupe_queries';});
sspa_fleet_assert(count($dupes) > 0, 'real captured duplicate queries produce an analysis finding');
$built = SSPA_Markdown_Export::build('run', $run);
if (is_wp_error($built)) throw new RuntimeException($built->get_error_message());
sspa_fleet_assert(strpos($built['markdown'], 'review-canary@example.invalid') === false, 'privacy-safe Markdown removes double-quoted SQL literals');
sspa_fleet_assert(strpos($built['markdown'], 'SELECT ?') !== false, 'Markdown retains the useful query fingerprint');
sspa_fleet_done();
