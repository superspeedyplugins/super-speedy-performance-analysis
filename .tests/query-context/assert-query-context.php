<?php
// Where each slow query came from (plan .docs/2026-11-24-slowest-query-context.md). Run by
// .tests/query-context/run.sh on its own site, never by run-tests.sh on the shared one: it
// switches the theme to the fixture theme and, for one pass, puts a foreign db.php in place.
//
// The customer report: the home page's slowest query (SELECT SQL_CALC_FOUND_ROWS ..., 10 rows) was
// shown as caller "td_util::get_the_privacy_policy_link (td_util.php:0)" with "Active hook/callback:
// not captured"; it ran in a footer shortcode in footer.php after get_footer. Line 0 is what a run
// without PA's wpdb (a foreign db.php, SAVEQUERIES only) produces.
//
// Two real spot analyses of Home: one under a foreign db.php (SAVEQUERIES, as on that site), one
// under PA's own wpdb. Each asserts the four fixture queries' context in the capture; the second
// also asserts the panel HTML, the page Markdown and the get-page-profile ability over their real
// entry points, and Analyse rows over admin-ajax.
require_once SSPA_PLUGIN_DIR . '.tests/lead-gen/lib.php';
global $wpdb;
wp_set_current_user(1);

if (0 !== strpos(home_url(), 'http://tests-query-context.') && false === getenv('SSPA_QC_ANY_SITE')) {
    echo 'FAIL: this case switches the theme and replaces db.php; run it with .tests/query-context/run.sh on its own site (this is ' . home_url() . ")\n";
    return;
}

// ---------------- fixture state, set on the way in ----------------
switch_theme('sspa-qc-theme');
update_option('show_on_front', 'posts');
update_option('posts_per_page', 10);
$cat = term_exists('qc-news', 'category');
if (!$cat) {
    $cat = wp_insert_term('QC News', 'category', array('slug' => 'qc-news'));
}
$cat_id = (int) (is_array($cat) ? $cat['term_id'] : $cat);
$have = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt USING (term_taxonomy_id) WHERE tt.term_id = %d", $cat_id));
for ($i = $have; $i < 25; $i++) {
    wp_insert_post(array('post_title' => 'QC news ' . ($i + 1), 'post_status' => 'publish', 'post_category' => array($cat_id)));
}
$expected_total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} p JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID JOIN {$wpdb->term_taxonomy} tt USING (term_taxonomy_id) WHERE tt.term_id = %d AND p.post_type = 'post' AND p.post_status = 'publish'", $cat_id));
$widgets = get_option('widget_sspa_qc_widget', array());
if (empty($widgets[2])) {
    update_option('widget_sspa_qc_widget', array(2 => array(), '_multiwidget' => 1));
}
$sidebars = get_option('sidebars_widgets', array());
$sidebars['qc-sidebar'] = array('sspa_qc_widget-2');
update_option('sidebars_widgets', $sidebars);
add_option('sspa_qc_touch', '1');
wp_cache_flush();

// Real line numbers, read from the fixture files the frames must point at.
$line_of = static function ($file, $needle, $nth = 1) {
    foreach (file($file) as $number => $text) {
        if (false !== strpos($text, $needle) && 0 === --$nth) {
            return $number + 1;
        }
    }
    return 0;
};
$fixture_file = WPMU_PLUGIN_DIR . '/sspa-qc-fixture.php';
$theme_dir = get_theme_root() . '/sspa-qc-theme';
$footer_line = $line_of($fixture_file, '$posts = new WP_Query(array(', 1);
$widget_line = $line_of($fixture_file, '$posts = new WP_Query(array(', 2);
$loop_line = $line_of($theme_dir . '/index.php', '$related = new WP_Query(');
$shortcode_line = $line_of($theme_dir . '/footer.php', "do_shortcode('[sspa_qc_footer_posts]')");
lg_t($footer_line && $widget_line && $loop_line && $shortcode_line, "fixture lines located: footer query $footer_line, widget query $widget_line, loop query $loop_line, footer.php do_shortcode $shortcode_line");

$run_home = static function () {
    $run_id = SSPA_Run_Controller::start(array('type' => 'spot', 'trigger' => 'manual', 'user_id' => 1, 'page_keys' => array('home')));
    if (is_wp_error($run_id)) {
        lg_t(false, 'a spot analysis of Home starts: ' . $run_id->get_error_message());
        return null;
    }
    $deadline = time() + 300;
    do {
        SSPA_Run_Controller::process_batch($run_id);
        $status = SSPA_Run_Controller::status($run_id);
    } while ($status && in_array($status['status'], array('queued', 'crawling', 'analysing'), true) && time() < $deadline);
    if (!lg_t($status && 'done' === $status['status'], 'the spot analysis of Home finished (run ' . $run_id . ', ' . ($status ? $status['status'] : 'null') . ')')) {
        return null;
    }
    global $wpdb;
    $profile_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM %i WHERE run_id = %d AND page_key = 'home' AND plugin_set_hash = '' ORDER BY id DESC LIMIT 1", SSPA_Schema::table('profiles'), (int) $run_id));
    $row = SSPA_Profile_Panel::profile_row($profile_id);
    return array('run' => (int) $run_id, 'profile' => $profile_id, 'capture' => $row ? SSPA_Profile_Panel::capture($row) : null);
};

$by_marker = static function ($capture) {
    $found = array();
    $markers = array('footer' => 'SLEEP(0.15)', 'sidebar' => 'SLEEP(0.07)', 'main' => 'SLEEP(0.04)', 'loop' => 'SLEEP(0.025)', 'write' => 'SLEEP(0.06)');
    foreach ((array) ($capture['sql']['queries'] ?? array()) as $query) {
        foreach ($markers as $name => $marker) {
            if (!isset($found[$name]) && false !== strpos((string) ($query['sql'] ?? ''), $marker)) {
                $found[$name] = $query;
            }
        }
    }
    return $found;
};

$frame_at = static function ($context, $file_suffix, $line) {
    foreach ((array) ($context['frames'] ?? array()) as $frame) {
        if (substr((string) $frame['file'], -strlen($file_suffix)) === $file_suffix && (int) $frame['line'] === (int) $line) {
            return true;
        }
    }
    return false;
};
$frames_text = static function ($context) {
    return implode(' | ', array_map(static function ($f) { return $f['file'] . ':' . $f['line'] . ' ' . $f['function']; }, (array) ($context['frames'] ?? array())));
};

$assert_contexts = static function ($label, $q) use ($frame_at, $frames_text, $footer_line, $widget_line, $loop_line, $shortcode_line, $expected_total) {
    foreach (array('footer', 'sidebar', 'main', 'loop') as $name) {
        lg_t(isset($q[$name]), "$label: the $name query was captured with its full SQL");
    }
    if (!isset($q['footer'], $q['sidebar'], $q['main'], $q['loop'])) {
        return;
    }
    $f = $q['footer']['context'] ?? null;
    lg_t(is_array($f), "$label: the footer query has a context");
    $f = (array) $f;
    lg_t(($f['shortcode']['tag'] ?? null) === 'sspa_qc_footer_posts' && ($f['shortcode']['owner'] ?? null) === 'mu:sspa-qc-fixture', "$label: footer query shortcode [sspa_qc_footer_posts], owned by mu:sspa-qc-fixture (" . wp_json_encode($f['shortcode'] ?? null) . ')');
    lg_t(($f['hook'] ?? null) === 'get_footer' && ($f['hook_path'] ?? null) === 'get_footer > [sspa_qc_footer_posts]', "$label: footer query innermost meaningful hook get_footer, path 'get_footer > [sspa_qc_footer_posts]' (" . ($f['hook'] ?? 'null') . ' / ' . ($f['hook_path'] ?? 'null') . ')');
    lg_t(($f['hook_stack'] ?? null) === array('[sspa_qc_footer_posts]'), "$label: footer query hook stack is only the shortcode: no action was running (" . wp_json_encode($f['hook_stack'] ?? null) . ')');
    lg_t(($f['area'] ?? null) === 'footer' && ($f['template'] ?? null) === 'wp-content/themes/sspa-qc-theme/footer.php', "$label: footer query area footer, template wp-content/themes/sspa-qc-theme/footer.php (" . ($f['area'] ?? 'null') . ', ' . ($f['template'] ?? 'null') . ')');
    $w = (array) ($f['wp_query'] ?? array());
    lg_t(false === ($w['main'] ?? null) && 10 === ($w['posts_per_page'] ?? null) && 'posts_per_page' === ($w['per_page_from'] ?? null) && false === ($w['no_found_rows'] ?? null) && 'posts' === ($w['role'] ?? null), "$label: footer WP_Query facts: not main, posts_per_page 10 set by posts_per_page, found rows asked for (" . wp_json_encode($w) . ')');
    lg_t('category' === ($w['tax_filter']['clauses'][0]['taxonomy'] ?? null) && 'slug' === ($w['tax_filter']['clauses'][0]['field'] ?? null) && 1 === ($w['tax_filter']['clauses'][0]['term_count'] ?? null), "$label: footer tax_query summary names category by slug, one term");
    lg_t(true === ($f['found_rows']['calc_found_rows'] ?? null) && $expected_total === ($f['found_rows']['total'] ?? null), "$label: footer query SQL_CALC_FOUND_ROWS yes, total $expected_total (" . wp_json_encode($f['found_rows'] ?? null) . ')');
    lg_t(array('spro_fixture_flag' => 'set-by-fixture') === ($f['spro'] ?? null), "$label: footer query spro_* query vars read generically (" . wp_json_encode($f['spro'] ?? null) . ')');
    lg_t(count((array) ($f['frames'] ?? array())) === 6 && $frame_at($f, 'wp-content/mu-plugins/sspa-qc-fixture.php', $footer_line) && $frame_at($f, 'wp-content/themes/sspa-qc-theme/footer.php', $shortcode_line), "$label: footer query has 6 frames with real lines, including sspa-qc-fixture.php:$footer_line and footer.php:$shortcode_line (" . $frames_text($f) . ')');

    $s = (array) ($q['sidebar']['context'] ?? array());
    lg_t(($s['area'] ?? null) === 'sidebar' && ($s['template'] ?? null) === 'wp-content/themes/sspa-qc-theme/sidebar.php' && ($s['widget']['name'] ?? null) === 'QC recent posts' && ($s['widget']['sidebar'] ?? null) === 'qc-sidebar', "$label: sidebar query area sidebar, template sidebar.php, widget 'QC recent posts' in qc-sidebar (" . wp_json_encode(array($s['area'] ?? null, $s['template'] ?? null, $s['widget'] ?? null)) . ')');
    lg_t(($s['hook'] ?? null) === 'dynamic_sidebar_before' && null === ($s['shortcode'] ?? null), "$label: sidebar query hook dynamic_sidebar_before, no shortcode (" . ($s['hook'] ?? 'null') . ')');
    $sw = (array) ($s['wp_query'] ?? array());
    lg_t(5 === ($sw['posts_per_page'] ?? null) && 'showposts' === ($sw['per_page_from'] ?? null) && true === ($sw['no_found_rows'] ?? null) && '_sspa_qc_featured' === ($sw['meta_filter']['clauses'][0]['key'] ?? null) && 'NOT EXISTS' === ($sw['meta_filter']['clauses'][0]['compare'] ?? null), "$label: sidebar WP_Query facts: 5 per page set by showposts, no_found_rows, meta_query _sspa_qc_featured NOT EXISTS (" . wp_json_encode($sw) . ')');
    lg_t(false === ($s['found_rows']['calc_found_rows'] ?? null) && array_key_exists('total', (array) ($s['found_rows'] ?? null)) && null === $s['found_rows']['total'], "$label: sidebar query has no SQL_CALC_FOUND_ROWS and no total");
    lg_t($frame_at($s, 'wp-content/mu-plugins/sspa-qc-fixture.php', $widget_line), "$label: sidebar query frames include sspa-qc-fixture.php:$widget_line (" . $frames_text($s) . ')');

    $m = (array) ($q['main']['context'] ?? array());
    $mw = (array) ($m['wp_query'] ?? array());
    lg_t(true === ($mw['main'] ?? null) && 'default' === ($mw['per_page_from'] ?? null) && 10 === ($mw['posts_per_page'] ?? null), "$label: main query is_main_query, posts_per_page 10 from the Reading setting (" . wp_json_encode($mw) . ')');
    lg_t(array_key_exists('area', $m) && null === $m['area'] && array_key_exists('template', $m) && null === $m['template'] && array() === ($m['hook_stack'] ?? null), "$label: main query ran before the theme template, outside any hook (" . wp_json_encode(array($m['area'] ?? null, $m['template'] ?? null, $m['hook_stack'] ?? null)) . ')');

    $l = (array) ($q['loop']['context'] ?? array());
    lg_t(($l['area'] ?? null) === 'content' && ($l['template'] ?? null) === 'wp-content/themes/sspa-qc-theme/index.php' && ($l['hook'] ?? null) === 'loop_start' && ($l['hook_from'] ?? null) === 'area', "$label: loop query area content, template index.php, after loop_start (" . wp_json_encode(array($l['area'] ?? null, $l['template'] ?? null, $l['hook'] ?? null)) . ')');
    lg_t($frame_at($l, 'wp-content/themes/sspa-qc-theme/index.php', $loop_line) && false === ($l['wp_query']['main'] ?? null) && 3 === ($l['wp_query']['posts_per_page'] ?? null), "$label: loop query frames include index.php:$loop_line, a secondary WP_Query of 3 (" . $frames_text($l) . ')');
};

// ---------------- pass 1: a foreign db.php, SAVEQUERIES only ----------------
$dropin = WP_CONTENT_DIR . '/db.php';
$held = WP_CONTENT_DIR . '/sspa-qc-held';
wp_mkdir_p($held);
if (file_exists($dropin) || is_link($dropin)) {
    rename($dropin, $held . '/db.php.ours');
}
file_put_contents($dropin, "<?php\n/*\nPlugin Name: SSPA query-context foreign drop-in\nDescription: Test-only. Core wpdb, as under a foreign db.php that keeps wpdb::query().\n*/\n");
sleep(2); // php-fpm opcache revalidation
$degraded = $run_home();
rename($dropin, $held . '/db.php.foreign');
if (file_exists($held . '/db.php.ours')) {
    rename($held . '/db.php.ours', $dropin);
}
sleep(2);
if ($degraded && lg_t('degraded' === ($degraded['capture']['sql']['mode'] ?? null), 'pass 1 captured SQL through SAVEQUERIES (mode ' . ($degraded['capture']['sql']['mode'] ?? 'null') . ')')) {
    $q = $by_marker($degraded['capture']);
    lg_t(isset($q['footer']) && false !== strpos((string) $q['footer']['caller'], ':0)'), 'pass 1 reproduces the customer caller with line 0: ' . ($q['footer']['caller'] ?? 'none'));
    $assert_contexts('foreign db.php', $q);
    lg_t('query_filter' === ($q['footer']['context']['frames_from'] ?? null), 'pass 1 frames were read by the query filter, not by PA\'s wpdb');
}

// ---------------- pass 2: PA's own wpdb ----------------
$full = $run_home();
if (!$full || !lg_t('full' === ($full['capture']['sql']['mode'] ?? null), 'pass 2 captured SQL through PA\'s wpdb (mode ' . ($full['capture']['sql']['mode'] ?? 'null') . ')')) {
    return;
}
lg_t(6 === ($full['capture']['schema'] ?? null) && 1 === ($full['capture']['sql']['context']['schema'] ?? null), 'capture schema 6 with sql.context schema 1 (' . wp_json_encode($full['capture']['sql']['context'] ?? null) . ')');
$q = $by_marker($full['capture']);
$assert_contexts('PA wpdb', $q);
lg_t('profiling_wpdb' === ($q['footer']['context']['frames_from'] ?? null), 'pass 2 frames came from PA\'s wpdb backtrace');
file_put_contents(getenv('SSPA_FLEET_OUTPUT') . '/footer-query.json', wp_json_encode($q['footer'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

// The panel, over the real admin-ajax request the Pages tab sends.
$panel = lg_panel($full['profile']);
file_put_contents(getenv('SSPA_FLEET_OUTPUT') . '/panel.html', $panel);
$footer_event = (string) ($q['footer']['event_id'] ?? '');
$row = '';
if (preg_match('#<tr class="sspa-adhoc-qrow"[^>]*data-event="' . preg_quote($footer_event, '#') . '".*?</tr>#s', $panel, $m)) {
    $row = $m[0];
}
lg_t('' !== $row, 'the panel has a Slowest queries row for the footer query ' . $footer_event);
foreach (array('Shortcode', '[sspa_qc_footer_posts]', 'mu:sspa-qc-fixture', 'get_footer &gt; [sspa_qc_footer_posts]', 'footer', 'wp-content/themes/sspa-qc-theme/footer.php', 'SQL_CALC_FOUND_ROWS', 'spro_fixture_flag', 'sspa-qc-fixture.php:' . $footer_line, 'footer.php:' . $shortcode_line, 'Analyse rows') as $needle) {
    lg_t(false !== strpos($row, $needle), 'the footer query row shows ' . $needle);
}
$write_event = (string) ($q['write']['event_id'] ?? '');
$write_row = preg_match('#<tr class="sspa-adhoc-qrow"[^>]*data-event="' . preg_quote($write_event, '#') . '".*?</tr>#s', $panel, $m) ? $m[0] : '';
lg_t('' !== $write_event && '' !== $write_row && false === strpos($write_row, 'sspa-adhoc-analyse'), 'the retained UPDATE has a row and no Analyse rows button');

// The page Markdown, over the real export request.
$markdown = lg_markdown('page', $full['profile']);
file_put_contents(getenv('SSPA_FLEET_OUTPUT') . '/page.md', $markdown);
lg_t(false !== strpos($markdown, '<!-- sspa/llm-report@3 -->'), 'the page Markdown is llm-report@3');
foreach (array('### Where the slowest queries came from', 'Shortcode: `[sspa_qc_footer_posts]` (`mu:sspa-qc-fixture`)', 'Hook path: `get_footer > [sspa_qc_footer_posts]`', 'Template: `wp-content/themes/sspa-qc-theme/footer.php` (footer)', 'SQL_CALC_FOUND_ROWS: yes, total ' . $expected_total, '`spro_fixture_flag`', 'sspa-qc-fixture.php:' . $footer_line . ' WP_Query::__construct', 'Widget: QC recent posts (qc-sidebar)', 'set by `showposts`') as $needle) {
    lg_t(false !== strpos($markdown, $needle), 'the page Markdown carries ' . $needle);
}
lg_t(false === strpos($markdown, 'qc-news'), 'the page Markdown names no tax_query term value');
// The two lines the customer report showed as "not captured" now come from the query's own context.
lg_t(false === strpos($markdown, '- Source location: not captured') && false === strpos($markdown, '- Active hook/callback: not captured') && preg_match('/- Source location: `wp-(content|includes)\/[^`\[]+\.php:[1-9]\d* [^`]+`/', $markdown), 'the query-plan evidence names the source location and hook (' . (preg_match('/- Source location: .*\n- Active hook\/callback: .*/', $markdown, $mm) ? str_replace("\n", ' / ', $mm[0]) : 'none') . ')');
$run_markdown = lg_markdown('run', $full['run']);
file_put_contents(getenv('SSPA_FLEET_OUTPUT') . '/run.md', $run_markdown);
lg_t(false !== strpos($run_markdown, 'Hook path: `get_footer > [sspa_qc_footer_posts]`') && false !== strpos($run_markdown, '<!-- sspa/llm-report@3 -->'), 'the run Markdown carries the same per-query context');

// The ability an agent calls.
$ability = function_exists('wp_get_ability') ? wp_get_ability('sspa/get-page-profile') : null;
if (lg_t(is_object($ability), 'the get-page-profile ability is registered')) {
    $out = $ability->execute(array('profile_id' => $full['profile']));
    lg_t(!is_wp_error($out) && 'sspa/page-diagnostic-export@2' === ($out['schema'] ?? null), 'get-page-profile answers sspa/page-diagnostic-export@2');
    $found = null;
    foreach ((array) ($out['capture']['sql']['queries'] ?? array()) as $query) {
        if (($query['event_id'] ?? '') === $footer_event) { $found = $query; }
    }
    $keys = array('hook_stack', 'hook', 'shortcode', 'template', 'area', 'wp_query', 'found_rows', 'spro', 'frames');
    lg_t(is_array($found['context'] ?? null) && !array_diff($keys, array_keys($found['context'])), 'the ability gives the footer query every machine-readable field: ' . implode(', ', $keys));
    lg_t(($found['context']['frames'][0]['line'] ?? 0) > 0 && isset($found['context']['frames'][0]['file'], $found['context']['frames'][0]['function']), 'ability frames are {file, line, function} with real lines');
}

// Analyse rows, over the admin-ajax request the button sends.
$version = strtolower((string) $wpdb->db_server_info());
$analyse = lg_ajax('sspa_analyse_rows', array('profile_id' => $full['profile'], 'event_id' => $footer_event));
file_put_contents(getenv('SSPA_FLEET_OUTPUT') . '/analyse-rows.json', wp_json_encode($analyse, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
$method = false !== strpos($version, 'mariadb') ? 'ANALYZE FORMAT=JSON' : 'EXPLAIN ANALYZE';
lg_t(!empty($analyse['success']) && $method === ($analyse['data']['method'] ?? null), "Analyse rows runs $method on $version (" . wp_json_encode($analyse['data']['method'] ?? $analyse['data'] ?? null) . ')');
$tables = (array) ($analyse['data']['tables'] ?? array());
$posts_rows = null;
foreach ($tables as $table) {
    if (false !== strpos((string) ($table['table'] ?? ''), $wpdb->posts) || 'wp_posts' === ($table['table'] ?? '')) { $posts_rows = $table; }
}
lg_t(is_array($posts_rows) && isset($posts_rows['rows']) && $posts_rows['rows'] >= 1, 'Analyse rows reports the actual rows read per table (' . wp_json_encode($tables) . ')');
lg_t(isset($analyse['data']['total_ms']) && $analyse['data']['total_ms'] >= 150, 'Analyse rows reports the re-run\'s own time, which includes the fixture SLEEP(0.15) (' . ($analyse['data']['total_ms'] ?? 'none') . ' ms)');
$refused = lg_ajax('sspa_analyse_rows', array('profile_id' => $full['profile'], 'event_id' => $write_event));
lg_t(empty($refused['success']) && false !== stripos((string) ($refused['data'] ?? ''), 'only a SELECT'), 'Analyse rows refuses the retained UPDATE: ' . wp_json_encode($refused['data'] ?? null));
$touch_after = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'sspa_qc_touch'));
lg_t('1' === $touch_after, 'the refused UPDATE did not run (sspa_qc_touch is still 1)');
$missing = lg_ajax('sspa_analyse_rows', array('profile_id' => $full['profile'], 'event_id' => 'q-999999'));
lg_t(empty($missing['success']), 'Analyse rows refuses an event the capture does not hold');
$forged = lg_ajax('sspa_analyse_rows', array('profile_id' => $full['profile'], 'event_id' => $footer_event, 'nonce' => 'forged'));
lg_t(empty($forged['success']), 'Analyse rows refuses a forged nonce');

file_put_contents(getenv('SSPA_FLEET_OUTPUT') . '/fixture.json', wp_json_encode(array('profile' => $full['profile'], 'run' => $full['run'], 'degraded_profile' => $degraded ? $degraded['profile'] : null, 'footer_event' => $footer_event, 'write_event' => $write_event, 'footer_line' => $footer_line, 'shortcode_line' => $shortcode_line)));
