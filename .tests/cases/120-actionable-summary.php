<?php
require SSPA_PLUGIN_DIR . '.tests/lib/fleet.php';
wp_set_current_user(1);
// The fixture's page template is a classic theme template (get_header/get_footer). A block
// fleet theme has no header.php or footer.php, so core falls back to theme-compat and emits a
// deprecation that this case rightly reports as a PHP diagnostic. Measure on a classic theme
// for this process only and restore the entry theme when it ends, as case 83 does. Cases
// 121, 122 and 124 require this file, so the restore waits for process shutdown.
$summary_entry_theme = get_stylesheet();
// locate_template() falls back to wp-includes/theme-compat, so check the theme directories.
$summary_theme_has = static function ($file) { return is_file(get_stylesheet_directory() . '/' . $file) || is_file(get_template_directory() . '/' . $file); };
if (!$summary_theme_has('header.php') || !$summary_theme_has('footer.php')) {
    if (!wp_get_theme('twentytwentyone')->exists()) {
        $library = dirname(rtrim(ABSPATH, '/'), 2) . '/.themes/twentytwentyone';
        if (!is_file($library . '/header.php')) {
            throw new RuntimeException('Case 120 needs a classic theme; the parallel-dev theme library has no Twenty Twenty-One at ' . $library);
        }
        exec('cp -R ' . escapeshellarg($library) . ' ' . escapeshellarg(get_theme_root() . '/twentytwentyone'), $copy_output, $copy_exit);
        if (0 !== $copy_exit) throw new RuntimeException('Could not install Twenty Twenty-One from the parallel-dev theme library');
        wp_clean_themes_cache();
    }
    switch_theme('twentytwentyone');
    register_shutdown_function(static function () use ($summary_entry_theme) { switch_theme($summary_entry_theme); });
}
sspa_fleet_assert($summary_theme_has('header.php') && $summary_theme_has('footer.php'), 'classic fixture theme provides header.php and footer.php (measured: ' . get_stylesheet() . ', entry: ' . $summary_entry_theme . ')');
// Match the original five-plugin fixture on entry, retaining every other source.
$fixture_plugins_before = get_option('active_plugins', array());
$fixture_plugins = array('woocommerce/woocommerce.php', 'redis-cache/redis-cache.php', 'super-speedy-performance-analysis/super-speedy-performance-analysis.php', 'wordpress-importer/wordpress-importer.php', 'sspa-render-fixture/plugin.php');
foreach ($fixture_plugins_before as $file) {
    if (!in_array($file, $fixture_plugins, true)) { deactivate_plugins($file, true); echo 'ENTRY RESET: deactivated optional fixture ' . $file . "\n"; }
}
$fixture = SSPA_PLUGIN_DIR . '.tests/fixtures/actionable-summary/';
$dest = WP_PLUGIN_DIR . '/sspa-render-fixture';
wp_mkdir_p($dest);
foreach (array('plugin.php', 'page.php', 'summary-slow.php', 'summary-child.php', 'variance.php') as $file) {
    copy($fixture . $file, $dest . '/' . $file);
}
copy($fixture . 'endpoint.php', ABSPATH . 'summary-slow-endpoint.php');
activate_plugin('sspa-render-fixture/plugin.php');
$page = get_page_by_path('summary-fixture');
if (!$page) {
    $id = wp_insert_post(array('post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'summary-fixture', 'post_title' => 'Summary fixture', 'post_content' => '[sspa_slow]'));
} else {
    $id = $page->ID;
}
update_option('sspa_summary_variance', 0, true);
update_option('sspa_summary_first_cost', 0, true);
file_put_contents(ABSPATH . 'summary-sample-counter', '0');
wp_cache_flush();
sleep(3);
$url = get_permalink($id);
$run = SSPA_Run_Controller::start(array('type' => 'adhoc', 'url' => $url, 'user_id' => 1, 'all_hooks' => true));
if (is_wp_error($run)) throw new RuntimeException($run->get_error_message());
sspa_fleet_complete($run);
global $wpdb;
$profile = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE run_id = %d ORDER BY id DESC LIMIT 1', SSPA_Schema::table('profiles'), $run), ARRAY_A);
if (!$profile) throw new RuntimeException('No captured fixture profile');
$capture = SSPA_Profile_Panel::capture($profile);
$built = SSPA_Markdown_Export::build('page', (int) $profile['id']);
if (is_wp_error($built)) throw new RuntimeException($built->get_error_message());
$html = SSPA_Profile_Panel::render((int) $profile['id']);
$out = SSPA_PLUGIN_DIR . '.data/summary/';
wp_mkdir_p($out);
$tag = getenv('SSPA_SUMMARY_STAGE') ?: SSPA_VERSION;
file_put_contents($out . $tag . '-environment.json', wp_json_encode(array('plugins_before' => $fixture_plugins_before, 'measured_plugins' => get_option('active_plugins'), 'theme' => get_stylesheet(), 'entry_theme' => $summary_entry_theme, 'wp' => get_bloginfo('version'), 'php' => PHP_VERSION), JSON_PRETTY_PRINT));
file_put_contents($out . $tag . '.json', wp_json_encode(array('profile' => array_diff_key($profile, array('profile_blob' => true)), 'capture' => $capture), JSON_PRETTY_PRINT));
file_put_contents($out . $tag . '.md', $built['markdown']);
file_put_contents($out . $tag . '.html', $html);
sspa_fleet_assert($profile['page_gen_ms'] > 750 && $profile['sql_ms'] < $profile['page_gen_ms'] * .1 && $profile['http_ms'] < $profile['page_gen_ms'] * .1, 'fixture reproduces high generation with low SQL and WP HTTP');
sspa_fleet_assert(stripos($built['markdown'], 'PHP or waiting during page render') !== false, 'verdict names dominant render-phase PHP in Markdown');
sspa_fleet_assert(strpos($built['markdown'], 'Unattributed:') !== false, 'Markdown always reports unattributed coverage');
sspa_fleet_assert(strpos($html, 'Unattributed:') !== false, 'panel first screen reports unattributed coverage');
sspa_fleet_assert(strpos($built['markdown'], 'sspa_slow') !== false && strpos($built['markdown'], 'page.php') !== false, 'summary names slow shortcode and direct template context');
$boot = $capture['boot'];
sspa_fleet_assert(empty($capture['profile']), 'all-hook fixture is measured without Excimer sampling');
sspa_fleet_assert(!empty($boot['cpu']['available']) && $boot['cpu']['cpu_ms'] >= 90 && $boot['cpu']['non_cpu_ms'] >= 650, 'CPU and waiting instruments distinguish planted computation from waits');
sspa_fleet_assert(!empty($boot['largest_gap']) && strpos($boot['largest_gap']['template'], 'page.php') !== false && $boot['largest_gap']['ms'] >= 200, 'largest untimed gap locates direct template CPU and sleep');
$shortcodes = array_values(array_filter($boot['render']['top'], static function ($c) { return $c['hook'] === 'shortcode' && $c['label'] === '[sspa_slow]'; }));
sspa_fleet_assert(count($shortcodes) === 1 && $shortcodes[0]['ms'] >= 115 && $shortcodes[0]['ms'] < 190 && !empty($shortcodes[0]['line']), 'one shortcode self-time row names its real callback file and line');
$custom = array_values(array_filter($boot['render']['top'], static function ($c) { return $c['hook'] === 'sspa_fixture_render'; }));
sspa_fleet_assert(count($custom) === 1 && $custom[0]['ms'] >= 105 && $custom[0]['ms'] < 180, 'all-hook timing names the planted custom render callback');
sspa_fleet_assert(strpos($built['markdown'], 'Samples: min') !== false && strpos($built['markdown'], 'Phase breakdown and PHP attribution') !== false, 'export carries sample range, phase breakdown and PHP attribution');
$files = isset($boot['template_files']) ? $boot['template_files'] : array();
$part = array_values(array_filter($files, static function ($entry) { return basename($entry['file']) === 'summary-slow.php'; }));
sspa_fleet_assert(count($part) === 1 && $part[0]['ms'] >= 155 && $part[0]['ms'] < 220 && $boot['render']['untimed_ms'] < 500, 'bounded template-file timing names the 160 ms direct part and reduces unexplained render time');
$nested = (new SSPA_Crawler())->profile_job(array('page_key' => 'summary-nested', 'url' => add_query_arg('summary_nested', '1', $url), 'variant' => 'anon', 'flags' => array('ah' => 'all', 'xp' => '0'), 'samples' => 1, 'skip_warmup' => true), 1);
$nested_capture = isset($nested['samples'][0]['capture']) ? $nested['samples'][0]['capture'] : array();
file_put_contents($out . $tag . '-nested.json', wp_json_encode($nested_capture, JSON_PRETTY_PRINT));
$parent = array_values(array_filter(isset($nested_capture['boot']['template_files']) ? $nested_capture['boot']['template_files'] : array(), static function ($entry) { return basename($entry['file']) === 'summary-slow.php'; }));
$child = array_values(array_filter(isset($nested_capture['boot']['template_files']) ? $nested_capture['boot']['template_files'] : array(), static function ($entry) { return basename($entry['file']) === 'summary-child.php'; }));
sspa_fleet_assert(count($parent) === 1 && $parent[0]['ms'] >= 155 && $parent[0]['ms'] < 185 && count($child) === 1 && $child[0]['ms'] >= 35 && $child[0]['ms'] < 65, 'template residuals exclude the real 30 ms callback and 40 ms child load');
sspa_fleet_assert(empty($capture['php_diagnostics']['count']), 'fixture profiling has no PHP diagnostics');
sspa_fleet_done();
