<?php
// One measured run per page by default (Dave, 24 November 2026; 0.71.0). A real baseline of
// Home, a post and the noise-floor page, driven by the real run controller over loopback, with
// a request-counting MU fixture logging every request the site receives:
//   - default setting: each page profile holds exactly ONE sample, the site received exactly one
//     request per page and none without the profiler's token (no warm-up), the run records
//     mode one, and the run Markdown says "1 measured request per page, cold";
//   - Measurement runs per page = Three runs: three samples per page, one unsigned warm-up plus
//     three signed requests per page (none for the noise floor, which never warmed up), TTFB is
//     the median of the three, the run records mode three and the Markdown says so.
//
// Own site:  SSPA_SCENARIO=tests-one-run .tests/run-tests.sh 167-one-run
// Everything is reset on the way IN. Nothing is torn down.
defined('ABSPATH') || exit;

$GLOBALS['sspa_167_failures'] = 0;
function sspa_167_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
    if (!$ok) { $GLOBALS['sspa_167_failures']++; }
}
function sspa_167_finish($run_id) {
    $deadline = time() + 600;
    do {
        SSPA_Run_Controller::process_batch($run_id);
        $status = SSPA_Run_Controller::status($run_id);
    } while ($status && in_array($status['status'], array('crawling', 'analysing'), true) && time() < $deadline);
    return $status ? $status['status'] : 'missing';
}
/** path?query of a URL, the form the fixture logs. */
function sspa_167_request_uri($url) {
    $parts = wp_parse_url($url);
    $uri = isset($parts['path']) ? $parts['path'] : '/';
    return $uri . (isset($parts['query']) ? '?' . $parts['query'] : '');
}
/** The logged requests for one URI: ['signed' => n, 'unsigned' => n]. */
function sspa_167_counts($log, $uri) {
    $counts = array('signed' => 0, 'unsigned' => 0);
    foreach ($log as $row) {
        if (isset($row['uri']) && $row['uri'] === $uri) {
            $counts[!empty($row['token']) ? 'signed' : 'unsigned']++;
        }
    }
    return $counts;
}
function sspa_167_log($file) {
    $rows = array();
    foreach (file_exists($file) ? (array) file($file, FILE_IGNORE_NEW_LINES) : array() as $line) {
        $row = json_decode($line, true);
        if (is_array($row)) { $rows[] = $row; }
    }
    return $rows;
}
/** A tab exactly as the browser loads it: authenticated admin-ajax over HTTP. */
function sspa_167_tab($tab, $nonce, $cookies) {
    $response = wp_remote_post(admin_url('admin-ajax.php'), array('timeout' => 120, 'cookies' => $cookies, 'body' => array('action' => 'sspa_render_tab', 'nonce' => $nonce, 'tabs' => $tab)));
    $json = is_wp_error($response) ? null : json_decode(wp_remote_retrieve_body($response), true);
    return isset($json['data']['tabs'][$tab]) ? (string) $json['data']['tabs'][$tab] : '';
}
/** Start a real baseline of these pages, clear the log after start()'s loopback preflight, finish it. */
function sspa_167_run($pages, $log_file) {
    $run_id = SSPA_Run_Controller::start(array('type' => 'baseline', 'page_keys' => $pages, 'trigger' => 'test'));
    if (is_wp_error($run_id)) {
        sspa_167_t(false, 'the baseline starts: ' . $run_id->get_error_message());
        return 0;
    }
    file_put_contents($log_file, '');
    $status = sspa_167_finish($run_id);
    sspa_167_t('done' === $status, "run $run_id completes (status $status)");
    return (int) $run_id;
}
function sspa_167_profiles($run_id) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare('SELECT run_id, page_key, url, samples, ttfb_ms FROM %i WHERE run_id = %d ORDER BY id', SSPA_Schema::table('profiles'), $run_id), ARRAY_A);
}

if ('tests-one-run' !== basename(rtrim(ABSPATH, '/'))) {
    echo 'FAIL: this case installs a request-counting MU plugin; run it on its own site: SSPA_SCENARIO=tests-one-run (this is ' . home_url() . ")\n";
    return;
}
wp_set_current_user(1);
$active = SSPA_Run_Controller::active_run_id();
if ($active) { SSPA_Run_Controller::cancel($active); SSPA_Run_Controller::process_batch($active); }

// --- On the way in ---------------------------------------------------------------------------
$mu = WPMU_PLUGIN_DIR . '/0-sspa-request-count.php';
wp_mkdir_p(WPMU_PLUGIN_DIR);
copy(SSPA_PLUGIN_DIR . '.tests/fixtures/request-count.php', $mu);
$log_file = WP_CONTENT_DIR . '/sspa-request-count.log';
file_put_contents($log_file, '');
// The site's own default, as a fresh install has it: no stored measurement setting.
$options = get_option('sspa_options', array());
unset($options['measurement_runs']);
update_option('sspa_options', $options);

$pages = array('baseline', 'home', 'post-single');
$expiry = time() + 3600;
$session = WP_Session_Tokens::get_instance(1)->create($expiry);
$cookies = array(
    LOGGED_IN_COOKIE => wp_generate_auth_cookie(1, $expiry, 'logged_in', $session),
    AUTH_COOKIE => wp_generate_auth_cookie(1, $expiry, 'auth', $session),
);
$_COOKIE[LOGGED_IN_COOKIE] = $cookies[LOGGED_IN_COOKIE];
$nonce = wp_create_nonce('sspa_admin');

// --- Default: one run --------------------------------------------------------------------------
sspa_167_t('one' === sspa_get_option('measurement_runs'), 'the default Measurement runs per page is one run (got ' . var_export(sspa_get_option('measurement_runs'), true) . ')');
$compare_html = sspa_167_tab('compare', $nonce, $cookies);
sspa_167_t(false !== strpos($compare_html, 'Measurement runs per page') && (bool) preg_match('#<option value="one"\s+selected#', $compare_html), 'Compare, Advanced settings shows Measurement runs per page with One run selected');
$overview_html = sspa_167_tab('overview', $nonce, $cookies);
sspa_167_t(false !== strpos($overview_html, esc_html(SSPA_Measurement::run_panel_sentence())) && false !== strpos($overview_html, 'sspa-measurement-note') && false !== strpos($overview_html, 'Each page is measured once, cold'), 'the Run Analysis panel says what one run does and links to the setting');
$run_one = sspa_167_run($pages, $log_file);
$log = sspa_167_log($log_file);
$profiles = $run_one ? sspa_167_profiles($run_one) : array();
sspa_167_t(count($profiles) === count($pages), 'one profile per requested page (' . count($profiles) . ')');
foreach ($profiles as $p) {
    $samples = json_decode((string) $p['samples'], true);
    sspa_167_t(1 === count((array) $samples), $p['page_key'] . ': exactly one measured sample (' . count((array) $samples) . ')');
    $c = sspa_167_counts($log, sspa_167_request_uri($p['url']));
    sspa_167_t(1 === $c['signed'] && 0 === $c['unsigned'], $p['page_key'] . ': the site received one signed request and no warm-up (signed ' . $c['signed'] . ', unsigned ' . $c['unsigned'] . ')');
}
$notes = $run_one ? json_decode((string) SSPA_Run_Controller::run_row($run_one)['notes'], true) : array();
sspa_167_t(isset($notes['measurement']['mode']) && 'one' === $notes['measurement']['mode'] && 0 === (int) $notes['measurement']['warmups'] && 1 === (int) $notes['measurement']['samples'], 'the run records one run, no warm-up');
$md = $run_one ? SSPA_Markdown_Export::build('run', $run_one) : null;
$md_text = is_array($md) && isset($md['markdown']) ? $md['markdown'] : '';
sspa_167_t(false !== strpos($md_text, '1 measured request per page, cold'), 'the run Markdown says 1 measured request per page, cold');
$report = $run_one ? SSPA_Report::build($run_one) : null;
sspa_167_t(is_array($report) && isset($report['run']['measurement']['mode']) && 'one' === $report['run']['measurement']['mode'], 'the agent report names the mode');
foreach ($profiles as $p) {
    if ('home' === $p['page_key']) {
        $line = SSPA_Page_Summary::samples($p);
        sspa_167_t(false !== strpos($line, 'One measured request') && false !== strpos($line, 'cold (no warm-up)'), 'the page summary says one measured request, not a min/median/max of one: ' . $line);
    }
}

sspa_167_t(null !== SSPA_Measurement::seconds_per_request(), 'the completed run recorded seconds per request for the Plugin Impact estimate');

// --- Three runs, chosen as the select saves it --------------------------------------------------
$saved = wp_remote_post(admin_url('admin-ajax.php'), array('timeout' => 60, 'cookies' => $cookies, 'body' => array('action' => 'sspa_history_setting', 'nonce' => $nonce, 'measurement_runs' => 'three')));
$saved = is_wp_error($saved) ? null : json_decode(wp_remote_retrieve_body($saved), true);
wp_cache_delete('sspa_options', 'options'); wp_cache_delete('alloptions', 'options');
sspa_167_t(!empty($saved['success']) && 'three' === sspa_get_option('measurement_runs'), 'the Compare select saves Three runs over admin-ajax');
sspa_167_t(!empty($saved['data']['plugin_update_detection']), 'saving the measurement setting leaves the quick comparison switch as it was');
$run_three = sspa_167_run($pages, $log_file);
$log = sspa_167_log($log_file);
$profiles = $run_three ? sspa_167_profiles($run_three) : array();
sspa_167_t(count($profiles) === count($pages), 'three runs: one profile per requested page (' . count($profiles) . ')');
foreach ($profiles as $p) {
    $samples = (array) json_decode((string) $p['samples'], true);
    sspa_167_t(3 === count($samples), $p['page_key'] . ': three measured samples (' . count($samples) . ')');
    $c = sspa_167_counts($log, sspa_167_request_uri($p['url']));
    $warmups = 'baseline' === $p['page_key'] ? 0 : 1;
    sspa_167_t(3 === $c['signed'] && $warmups === $c['unsigned'], $p['page_key'] . ": $warmups warm-up and three signed requests (signed " . $c['signed'] . ', unsigned ' . $c['unsigned'] . ')');
    $walls = array();
    foreach ($samples as $s) { if (empty($s['error']) && empty($s['blocked_by'])) { $walls[] = (float) $s['wall_ms']; } }
    sort($walls);
    sspa_167_t(3 === count($walls) && abs((float) $p['ttfb_ms'] - $walls[1]) < 0.05, $p['page_key'] . ': TTFB is the median of the three (' . $p['ttfb_ms'] . ' vs ' . (isset($walls[1]) ? $walls[1] : '?') . ')');
}
$notes = $run_three ? json_decode((string) SSPA_Run_Controller::run_row($run_three)['notes'], true) : array();
sspa_167_t(isset($notes['measurement']['mode']) && 'three' === $notes['measurement']['mode'] && 1 === (int) $notes['measurement']['warmups'] && 3 === (int) $notes['measurement']['samples'], 'the run records three runs after a warm-up');
$md = $run_three ? SSPA_Markdown_Export::build('run', $run_three) : null;
$md_text = is_array($md) && isset($md['markdown']) ? $md['markdown'] : '';
sspa_167_t(false !== strpos($md_text, 'median of 3 measured requests after a warm-up'), 'the run Markdown says median of 3 after a warm-up');

// --- Compare names both modes ------------------------------------------------------------------
if ($run_one && $run_three) {
    $comparison = SSPA_History::compare($run_one, $run_three);
    sspa_167_t(is_array($comparison) && 'one' === ($comparison['before']['measurement']['mode'] ?? '') && 'three' === ($comparison['after']['measurement']['mode'] ?? ''), 'the comparison identity carries each side\'s mode');
    $home_output = null;
    foreach (is_array($comparison) ? (array) $comparison['pages'] : array() as $page) {
        if ('home' === $page['page_key']) { $home_output = $page['output']['state']; }
    }
    sspa_167_t('unavailable' === $home_output, 'output change detection against a one-request run is unavailable, not claimed (got ' . var_export($home_output, true) . ')');
    $html = is_array($comparison) ? SSPA_History::render($comparison) : '';
    sspa_167_t(false !== strpos($html, '1 measured request per page') && false !== strpos($html, 'median of 3'), 'the comparison view says which mode each run used');
}

// Left as found by the next run: the setting stays at three runs, the fixture stays installed,
// and the log holds the three-run baseline's requests for inspection.
echo $GLOBALS['sspa_167_failures'] ? '' : "PASS: case 167 complete\n";
