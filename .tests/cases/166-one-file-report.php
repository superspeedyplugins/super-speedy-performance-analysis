<?php
// One button, one Markdown file (part 1, 0.69.1). A real baseline analysis, then the run export
// fetched exactly as Download Markdown fetches it (authenticated admin-ajax, kind=run), must hold:
// per-page phase evidence for every front-end page with a one-line verdict, render regions capped
// at 2% of generation, component tables capped at 15 plus "Others", the measured-components list
// once, findings sharing a SQL fingerprint printed once, small archive sort notes left out, and
// which PHP serves the site. The Tools tab shows the same PHP facts. A spot run with a foreign
// db.php (no SQL capture) on a CPU-bound page concludes "PHP CPU-bound" instead of "unknown".
//
// Own site, because it adds 400 products, 17 filler plugins and a foreign drop-in step:
//   SSPA_SCENARIO=tests-one-file-report .tests/run-tests.sh 166-one-file-report
// Everything is reset on the way IN. Nothing is torn down.
defined('ABSPATH') || exit;

$GLOBALS['sspa_166_failures'] = 0;
function sspa_166_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
    if (!$ok) { $GLOBALS['sspa_166_failures']++; }
}
function sspa_166_finish($run_id) {
    $deadline = time() + 900;
    do {
        SSPA_Run_Controller::process_batch($run_id);
        $status = SSPA_Run_Controller::status($run_id);
    } while ($status && in_array($status['status'], array('crawling', 'analysing'), true) && time() < $deadline);
    return $status ? $status['status'] : 'missing';
}
/** The run export exactly as the Download Markdown button requests it. */
function sspa_166_export($run_id, $nonce, $cookies) {
    $response = wp_remote_post(admin_url('admin-ajax.php'), array('timeout' => 120, 'cookies' => $cookies, 'body' => array(
        'action' => 'sspa_markdown_export', 'nonce' => $nonce, 'kind' => 'run', 'id' => (int) $run_id,
    )));
    $json = is_wp_error($response) ? null : json_decode(wp_remote_retrieve_body($response), true);
    return !empty($json['success']) && isset($json['data']['markdown']) ? (string) $json['data']['markdown'] : '';
}
/** Rows of the Markdown table under $header, up to the first blank line. */
function sspa_166_rows($text, $header) {
    $at = strpos($text, $header);
    if (false === $at) { return null; }
    $rows = array();
    foreach (array_slice(explode("\n", substr($text, $at)), 2) as $line) {
        if ('' === trim($line) || '|' !== substr($line, 0, 1)) { break; }
        $rows[] = array_map('trim', explode(' | ', trim($line, '| ')));
    }
    return $rows;
}
function sspa_166_ms($cell) {
    return (float) str_replace(',', '', preg_replace('/\s*ms$/', '', $cell));
}

global $wpdb;
if ('tests-one-file-report' !== basename(rtrim(ABSPATH, '/'))) {
    echo 'FAIL: this case adds 400 products, filler plugins and a foreign drop-in; run it on its own site: SSPA_SCENARIO=tests-one-file-report (this is ' . home_url() . ")\n";
    return;
}
wp_set_current_user(1);
$active = SSPA_Run_Controller::active_run_id();
if ($active) { SSPA_Run_Controller::cancel($active); SSPA_Run_Controller::process_batch($active); }

// --- On the way in ---------------------------------------------------------------------------
// The fixture MU plugin: one real 55 ms statement five times on Home, the Shop and a post.
$mu = WPMU_PLUGIN_DIR . '/sspa-one-file-report.php';
wp_mkdir_p(WPMU_PLUGIN_DIR);
copy(SSPA_PLUGIN_DIR . '.tests/fixtures/one-file-report.php', $mu);
// 17 tiny real plugins, so a page has more than 15 measured components.
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$fillers = array();
for ($i = 1; $i <= 17; $i++) {
    $slug = sprintf('sspa-ofr-filler-%02d', $i);
    wp_mkdir_p(WP_PLUGIN_DIR . '/' . $slug);
    file_put_contents(WP_PLUGIN_DIR . "/$slug/$slug.php", "<?php\n/*\nPlugin Name: SSPA case 166 filler $i\n*/\nadd_action('init', static function () { \$h = ''; for (\$n = 0; \$n < 3000; \$n++) { \$h = md5(\$h . \$n); } });\n");
    $fillers[] = "$slug/$slug.php";
}
wp_clean_plugins_cache(false);
$activated = activate_plugins($fillers);
if (is_wp_error($activated)) {
    echo 'FAIL: filler plugins did not activate: ' . $activated->get_error_message() . "\n";
    return;
}
// Exactly 400 published products named SSPA t166, so the shop archive holds a few hundred rows:
// enough for the archive sort note to be raised, under the 1,000 rows that make it a warning.
$existing = $wpdb->get_col($wpdb->prepare("SELECT ID FROM %i WHERE post_type = 'product' AND post_status = 'publish' AND post_title LIKE %s", $wpdb->posts, 'SSPA t166 %'));
if (400 !== count($existing)) {
    foreach ($existing as $id) { wp_delete_post((int) $id, true); }
    for ($i = 1; $i <= 400; $i++) {
        $product = new WC_Product_Simple();
        $product->set_name('SSPA t166 product ' . $i);
        $product->set_regular_price('10');
        $product->set_status('publish');
        $product->save();
    }
}
wp_cache_flush();

$expiry = time() + 3600;
$session = WP_Session_Tokens::get_instance(1)->create($expiry);
$cookies = array(
    LOGGED_IN_COOKIE => wp_generate_auth_cookie(1, $expiry, 'logged_in', $session),
    AUTH_COOKIE => wp_generate_auth_cookie(1, $expiry, 'auth', $session),
);
$_COOKIE[LOGGED_IN_COOKIE] = $cookies[LOGGED_IN_COOKIE];
$nonce = wp_create_nonce('sspa_admin');

// --- 1. Missing SQL coverage on a CPU-bound page ----------------------------------------------
// A foreign db.php (as on the 23 November customer stack) leaves SQL uncaptured: capture mode none.
$dropin = WP_CONTENT_DIR . '/db.php';
$held = SSPA_PLUGIN_DIR . '.data/test-entry-dropins/tests-one-file-report';
wp_mkdir_p($held);
if (file_exists($dropin) || is_link($dropin)) { rename($dropin, $held . '/db.php.ours'); }
clearstatcache(true, $dropin);
// It turns core query logging off, as production wp-config.php files often do, so neither the
// shim nor core SAVEQUERIES records a query.
file_put_contents($dropin, "<?php\n/*\nPlugin Name: SSPA case 166 foreign drop-in\n*/\ndefine('SAVEQUERIES', false);\n");
update_option('sspa_166_sql_fixture', 0, true);
$spot = SSPA_Run_Controller::start(array('type' => 'spot', 'page_keys' => array('home'), 'user_id' => 1));
sspa_166_t(!is_wp_error($spot) && 'done' === sspa_166_finish((int) $spot), 'a spot run of Home completes with a foreign db.php');
// Ours back for the baseline (the same step the suite's entry reset takes).
rename($dropin, $held . '/db.php.foreign');
clearstatcache(true, $dropin);
SSPA_Helper_Files::ensure_installed();
if (!is_wp_error($spot)) {
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM %i WHERE run_id = %d AND page_key = 'home'", SSPA_Schema::table('profiles'), (int) $spot), ARRAY_A);
    $capture = $row ? SSPA_Profile_Panel::capture($row) : null;
    $cpu = isset($capture['boot']['cpu']) ? $capture['boot']['cpu'] : array();
    $cpu_bound = !empty($cpu['available']) && $cpu['cpu_ms'] > $cpu['wall_ms'] / 2;
    sspa_166_t(isset($capture['sql']['mode']) && 'none' === $capture['sql']['mode'], 'precondition: Home was measured with no SQL capture (mode ' . (isset($capture['sql']['mode']) ? $capture['sql']['mode'] : 'missing') . ')');
    sspa_166_t($cpu_bound, 'precondition: Home is CPU-bound (CPU ' . (isset($cpu['cpu_ms']) ? $cpu['cpu_ms'] : '?') . ' of ' . (isset($cpu['wall_ms']) ? $cpu['wall_ms'] : '?') . ' ms)');
    $md = sspa_166_export((int) $spot, $nonce, $cookies);
    file_put_contents(SSPA_PLUGIN_DIR . '.data/case-166-spot-run.md', $md);
    sspa_166_t('' !== $md, 'the spot run exports over admin-ajax');
    sspa_166_t(false !== strpos($md, 'PHP CPU-bound'), 'missing SQL coverage on a CPU-bound page concludes "PHP CPU-bound"');
    sspa_166_t((bool) preg_match('/SQL, WordPress HTTP and object cache together took at most [\d,.]+ ms/', $md), 'and bounds SQL + HTTP + object cache by the non-CPU time');
    // The single-page export of the same profile carries the same shared summary.
    $response = wp_remote_post(admin_url('admin-ajax.php'), array('timeout' => 120, 'cookies' => $cookies, 'body' => array(
        'action' => 'sspa_markdown_export', 'nonce' => $nonce, 'kind' => 'page', 'id' => $row ? (int) $row['id'] : 0,
    )));
    $json = is_wp_error($response) ? null : json_decode(wp_remote_retrieve_body($response), true);
    $page_md = isset($json['data']['markdown']) ? (string) $json['data']['markdown'] : '';
    preg_match('/^- Outcome: .*$/m', $page_md, $outcome);
    sspa_166_t(isset($outcome[0]) && false !== strpos($outcome[0], 'PHP CPU-bound') && false === strpos($outcome[0], 'the dominant cost is unknown'), 'the page export of Home says PHP CPU-bound, not unknown (' . (isset($outcome[0]) ? $outcome[0] : 'no outcome line') . ')');
}

// --- 2. A real baseline, exported as Download Markdown exports it -----------------------------
update_option('sspa_166_sql_fixture', 1, true);
$run = SSPA_Run_Controller::start(array('type' => 'baseline', 'user_id' => 1));
$status = is_wp_error($run) ? $run->get_error_message() : sspa_166_finish((int) $run);
sspa_166_t('done' === $status, 'the baseline analysis completes (' . $status . ')');
if ('done' !== $status) {
    echo "FAILED: {$GLOBALS['sspa_166_failures']}\n";
    return;
}
$run = (int) $run;
$md = sspa_166_export($run, $nonce, $cookies);
file_put_contents(SSPA_PLUGIN_DIR . '.data/case-166-baseline-run.md', $md);
sspa_166_t('' !== $md, 'the baseline run exports over admin-ajax (' . strlen($md) . ' bytes, kept in .data/case-166-baseline-run.md)');

// Per-page evidence for every front-end profile with a stored capture.
$profiles = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE run_id = %d ORDER BY id', SSPA_Schema::table('profiles'), $run), ARRAY_A);
$front = 0;
$over_15 = 0;
foreach ($profiles as $row) {
    $capture = SSPA_Profile_Panel::capture($row);
    if ('admin' === $row['variant'] || false !== strpos($row['url'], '/wp-admin/') || empty($capture['boot'])) {
        continue;
    }
    $front++;
    $key = $row['page_key'];
    $heading = '### `' . $key . '` (' . $row['variant'] . ')';
    $at = strpos($md, "\n" . $heading);
    if (false === $at) {
        sspa_166_t(false, "$key: has its own per-page section");
        continue;
    }
    $next = strpos($md, "\n### ", $at + 1);
    $next_h2 = strpos($md, "\n## ", $at + 1);
    $end = min(false === $next ? strlen($md) : $next, false === $next_h2 ? strlen($md) : $next_h2);
    $section = substr($md, $at, $end - $at);
    preg_match('/^### `[^`]+` \([^)]+\): ([\d,.]+) ms generation/m', $section, $m);
    $gen = isset($m[1]) ? (float) str_replace(',', '', $m[1]) : 0;
    $ok = $gen > 0;
    $ok = $ok && (bool) preg_match('/^- Verdict: [\d.]+% PHP CPU \([\d,.]+ of [\d,.]+ ms\), top owner `[^`]+` \([\d,.]+ ms\)(, untimed gap [\d,.]+ ms in `[^`]+`)?\.$/m', $section);
    $ok = $ok && false !== strpos($section, '- Phases: ') && false !== strpos($section, '- Outcome: ');
    $regions = sspa_166_rows($section, '| Template/render boundary |');
    $ok = $ok && null !== $regions;
    foreach ((array) $regions as $cells) {
        $ok = $ok && sspa_166_ms(end($cells)) >= 0.02 * $gen;
    }
    $components = sspa_166_rows($section, '| Component | Plugin includes and callbacks');
    $ok = $ok && null !== $components && count($components) <= 16;
    $measured = count((array) $capture['boot']['components']);
    if ($measured > 15) {
        $over_15++;
        $others = end($components);
        $tail = array_slice(array_values((array) $capture['boot']['components']), 15);
        $ok = $ok && 16 === count($components) && 0 === strpos($others[0], 'Others (' . ($measured - 15) . ' components)')
            && abs(sspa_166_ms($others[1]) - array_sum($tail)) < 0.2;
    }
    sspa_166_t($ok, "$key ($row[variant]): verdict, phases, summary, regions of 2% of " . $gen . ' ms or more (' . count((array) $regions) . ' rows), components top 15 + Others (' . $measured . ' measured)');
}
sspa_166_t($front >= 15, "every front-end page was checked ($front)");
sspa_166_t($over_15 > 0, "the 15-component cap was exercised ($over_15 pages measured more than 15 components)");
sspa_166_t(1 === substr_count($md, '- Measured components: '), 'the measured-components version list appears once per file');

// Findings sharing a SQL fingerprint print it once.
$findings = SSPA_Report::findings($run);
$marker = array_filter($findings, function ($f) { return false !== strpos(isset($f['evidence']['fp']) ? $f['evidence']['fp'] : (isset($f['evidence']['sql']) ? $f['evidence']['sql'] : ''), 'sspa_ofr_marker'); });
sspa_166_t(count($marker) >= 2, 'precondition: the fixture statement raised ' . count($marker) . ' findings (' . implode(', ', wp_list_pluck($marker, 'type')) . ')');
$fp_lines = preg_match_all('/^- Query fingerprint: `[^\n]*sspa_ofr_marker/m', $md);
sspa_166_t(1 === $fp_lines, "the shared fingerprint is printed once ($fp_lines)");
sspa_166_t(count($marker) - 1 === preg_match_all('/^- Also reported for this query: /m', $md), 'every other finding on it is listed under that one fingerprint');

// Small archive sort notes are left out.
$small = array_filter($findings, function ($f) { return 'archive_query_unindexed' === $f['type'] && (int) $f['evidence']['rows'] < 1000 && (float) $f['evidence']['ms'] < 50; });
sspa_166_t(count($small) > 0, 'precondition: the run raised ' . count($small) . ' archive sort note(s) under 1,000 rows and 50 ms');
sspa_166_t(false === strpos($md, 'cannot sort by'), 'none of them is in the export');
sspa_166_t((bool) preg_match('/^- Omitted: ' . count($small) . ' archive sort note\(s\) under 1,000 rows and 50 ms/m', $md), 'the export says how many were left out');

// Which PHP serves the site.
sspa_166_t(false !== strpos($md, "\n## PHP that serves your site\n"), 'the export has a "PHP that serves your site" section');
preg_match('/^- Server API \(SAPI\): `([^`]+)`/m', $md, $sapi);
preg_match('/^- Loaded php\.ini: `([^`]+)`/m', $md, $ini);
sspa_166_t(!empty($sapi[1]) && 'cli' !== $sapi[1], 'it names the web SAPI, not the CLI (' . (isset($sapi[1]) ? $sapi[1] : 'missing') . ')');
sspa_166_t(!empty($ini[1]) && (bool) preg_match('/^- PHP binary: `[^`]+`/m', $md) && (bool) preg_match('/^- Additional ini files: /m', $md), 'it names the PHP binary, the loaded php.ini and the additional ini files');
$home = $wpdb->get_row($wpdb->prepare("SELECT * FROM %i WHERE run_id = %d AND page_key = 'home'", SSPA_Schema::table('profiles'), $run), ARRAY_A);
$home_capture = SSPA_Profile_Panel::capture($home);
$excimer_ran = !empty($home_capture['profile']);
$missing = 0;
foreach (array('excimer', 'xhprof', 'tideways_xhprof', 'spx') as $ext) {
    $loaded = (bool) preg_match('/^- ' . $ext . ': loaded$/m', $md);
    $absent = !empty($sapi[1]) && !empty($ini[1]) && false !== strpos($md, "\n- $ext: not loaded in the PHP that serves your site ($sapi[1], $ini[1])");
    sspa_166_t($loaded xor $absent, "$ext: " . ($loaded ? 'loaded' : ($absent ? 'not loaded, worded with the SAPI and php.ini' : 'missing or contradictory')));
    $missing += $absent ? 1 : 0;
    if ('excimer' === $ext) {
        sspa_166_t($loaded === $excimer_ran, 'excimer agrees with the sampler in the web request (sampler ' . ($excimer_ran ? 'ran' : 'did not run') . ')');
    }
}
sspa_166_t($missing > 0, "the not-loaded wording was exercised ($missing extensions)");

// The Tools tab shows the same facts.
$response = wp_remote_post(admin_url('admin-ajax.php'), array('timeout' => 120, 'cookies' => $cookies, 'body' => array('action' => 'sspa_render_tab', 'nonce' => $nonce, 'tabs' => 'tools')));
$json = is_wp_error($response) ? null : json_decode(wp_remote_retrieve_body($response), true);
$tools = isset($json['data']['tabs']['tools']) ? html_entity_decode($json['data']['tabs']['tools'], ENT_QUOTES) : '';
sspa_166_t('' !== $tools, 'the Tools tab loads over admin-ajax');
sspa_166_t(false !== strpos($tools, 'PHP binary') && false !== strpos($tools, 'Loaded php.ini') && false !== strpos($tools, 'Additional ini files'), 'its "This server" table names the PHP binary, php.ini and additional ini files');
sspa_166_t(!empty($sapi[1]) && false !== strpos($tools, 'not loaded in the PHP that serves your site (' . $sapi[1] . ', '), 'and words a missing profiling extension as not loaded in the PHP that serves the site');

echo $GLOBALS['sspa_166_failures'] ? "FAILED: {$GLOBALS['sspa_166_failures']}\n" : "All passed\n";
