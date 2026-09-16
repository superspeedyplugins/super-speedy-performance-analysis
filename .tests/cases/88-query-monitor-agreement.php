<?php
// Issue 12. On a site where the real Query Monitor owns the db.php drop-in, the plugin and
// QM are compared on the same pages with explicit tolerances, and the ownership contract is
// proved: by default the plugin rides QM's own query log (or, where QM keeps none, reports a
// count-only measurement honestly); when asked to swap it holds QM's drop-in aside for the
// run and gives it back byte-for-byte; QM keeps working throughout. Runs only where QM
// really owns db.php.
require __DIR__ . '/../lib/fleet.php';

function sspa_88_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
    if (!$ok) { $GLOBALS['sspa_88_failures']++; }
}
$GLOBALS['sspa_88_failures'] = 0;
// Two requests of the same page only compare when both see the same cache state: the first
// admin dashboard load of a session runs update checks that the second does not, and a cold
// object cache doubles a front-page count. Warm the page with one request and measure the
// next, which is also the state the plugin's own request will find.
function sspa_88_probe($url, $cookies = array()) {
    $qm = array();
    $code = 0;
    for ($pass = 0; $pass < 2; $pass++) {
        $id = 'p' . wp_rand(100000, 999999);
        $response = wp_remote_get(add_query_arg(array('sspa_qm_probe' => $id, 'sspa_nc' => wp_rand()), $url), array('timeout' => 30, 'sslverify' => false, 'cookies' => $cookies));
        wp_cache_delete('sspa_qm_probe_' . $id, 'options');
        wp_cache_delete('notoptions', 'options');
        $qm = get_option('sspa_qm_probe_' . $id);
        $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
    }
    return array('code' => $code, 'qm' => is_array($qm) ? $qm : array());
}
function sspa_88_run($page_keys, $extra = array(), $settle_seconds = 0) {
    $id = SSPA_Run_Controller::start(array_merge(array('type' => 'spot', 'page_keys' => $page_keys, 'user_id' => 1), $extra));
    if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
    $during = SSPA_Helper_Files::dropin_status(); // start() is where a swap happens
    if ($settle_seconds) {
        // A CLI-started run cannot invalidate php-fpm's opcache after swapping db.php; give
        // opcache.revalidate_freq a chance so the profiled request runs the swapped file.
        sleep($settle_seconds);
    }
    // Ask the fixture to record QM on these exact signed requests, keyed by token.
    $pair_probe = static function ($args) {
        if (!empty($args['headers'][SSPA_Token::HEADER])) {
            $args['headers']['X-SSPA-QM-Probe'] = '1';
        }
        return $args;
    };
    add_filter('http_request_args', $pair_probe);
    $deadline = time() + 240;
    try {
        do {
            SSPA_Run_Controller::process_batch($id);
            $status = SSPA_Run_Controller::status($id);
        } while ($status && in_array($status['status'], array('crawling', 'analysing'), true) && time() < $deadline);
    } finally {
        remove_filter('http_request_args', $pair_probe);
    }
    if (!$status || 'done' !== $status['status']) { throw new RuntimeException('Query Monitor agreement run did not complete'); }
    return array('id' => (int) $id, 'during' => $during);
}
function sspa_88_profile($run_id, $page_key) {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE run_id = %d AND page_key = %s', SSPA_Schema::table('profiles'), $run_id, $page_key), ARRAY_A);
    $capture = $row ? SSPA_Profile_Panel::capture($row) : null;
    $probe_key = 'sspa_qm_probe_' . ($capture['token'] ?? '');
    wp_cache_delete($probe_key, 'options');
    wp_cache_delete('notoptions', 'options');
    return array('qm' => get_option($probe_key), 'capture' => $capture, 'row' => $row, 'mode' => is_array($capture) ? ($capture['overview']['capture_mode'] ?? null) : null);
}

wp_set_current_user(1);
global $wpdb;

// Establish this case's real vendor prerequisite on the guarded dedicated site.
$qm_dir = WP_PLUGIN_DIR . '/query-monitor';
if (!is_file($qm_dir . '/query-monitor.php')) {
    $sources = glob(dirname(rtrim(ABSPATH, '/')) . '/*/wp-content/plugins/query-monitor/query-monitor.php');
    $source = null;
    foreach ($sources as $candidate) {
        $directory = realpath(dirname($candidate));
        if ($directory && is_file($directory . '/wp-content/db.php')) {
            $source = $directory;
            break;
        }
    }
    if (!$source || !symlink($source, $qm_dir)) {
        throw new RuntimeException('Install the real Query Monitor plugin on this dedicated site before case 88; no usable local vendor source was found');
    }
}
$qm_db_source = realpath($qm_dir . '/wp-content/db.php');
if (!$qm_db_source || !is_file($qm_db_source)) {
    throw new RuntimeException('The installed Query Monitor prerequisite has no real database drop-in');
}
$active = SSPA_Run_Controller::active_run_id();
if ($active) { SSPA_Run_Controller::cancel($active); }
// Preserve our previous generated drop-in as retained evidence, then let QM install its own.
if ('ours' === SSPA_Helper_Files::dropin_status()) {
    if (!rename(SSPA_Helper_Files::dropin_path(), WP_CONTENT_DIR . '/db.php.sspa-case88-' . wp_generate_uuid4())) {
        throw new RuntimeException('Could not retain the preceding PA drop-in');
    }
}
$result = activate_plugin('query-monitor/query-monitor.php');
if (is_wp_error($result)) { throw new RuntimeException($result->get_error_message()); }
// Already-active QM may have lost its drop-in to a preceding owned-helper test.
if ('absent' === SSPA_Helper_Files::dropin_status()) {
    if (!symlink($qm_db_source, SSPA_Helper_Files::dropin_path())) {
        throw new RuntimeException('Could not install the real Query Monitor drop-in');
    }
}
// CLI cannot clear the serving workers' realpath cache after replacing the drop-in.
// Match the existing swap boundary before sending either anonymous probe.
$entry_settle = (int) getenv('SSPA_88_SETTLE') ?: (int) ini_get('realpath_cache_ttl') + 5;
sleep($entry_settle);

// Preconditions: Query Monitor active and owning db.php; fixtures installed and current.
sspa_88_t(SSPA_Helper_Files::qm_plugin_active(), 'the real Query Monitor plugin is active');
sspa_88_t('qm' === SSPA_Helper_Files::dropin_status(), 'Query Monitor owns the db.php drop-in before anything runs (' . SSPA_Helper_Files::dropin_status() . ')');
if ('qm' !== SSPA_Helper_Files::dropin_status()) { exit(1); }
$dropin = SSPA_Helper_Files::dropin_path();
$qm_target = readlink($dropin);
$qm_bytes = md5_file($dropin);
wp_mkdir_p(WPMU_PLUGIN_DIR);
$installed = false;
foreach (array('home-query-fixture.php' => 'sspa-home-query-fixture.php', 'qm-probe.php' => 'sspa-qm-probe.php') as $source => $target) {
    $path = WPMU_PLUGIN_DIR . '/' . $target;
    if (!file_exists($path) || md5_file($path) !== md5_file(__DIR__ . '/../fixtures/' . $source)) {
        copy(__DIR__ . '/../fixtures/' . $source, $path);
        $installed = true;
    }
}
if ($installed) { sleep(3); } // php-fpm opcache revalidation
$active = SSPA_Run_Controller::active_run_id();
if ($active) { SSPA_Run_Controller::cancel($active); }
$admin_cookies = SSPA_Auth::cookies_for('admin', 1);

try {
    // A. An anonymous page. QM keeps no per-query log for a viewer who cannot see QM, and the
    //    plugin, riding QM's drop-in, has no per-query data either: both sides can only count.
    //    That is reported as a count-only agreement, never as a timing pass.
    $anon = sspa_88_probe(home_url('/'));
    $aq = $anon['qm'];
    sspa_88_t(200 === $anon['code'] && 'QM_DB' === ($aq['wpdb_class'] ?? '') && 'qm-symlink' === ($aq['dropin'] ?? ''), 'anonymous Home was served through QM\'s database class and drop-in');
    sspa_88_t(0 === (int) ($aq['queries_logged'] ?? -1) && (int) ($aq['total_qs'] ?? 0) >= 4, 'QM keeps no per-query log for an anonymous viewer but still counts (' . ($aq['total_qs'] ?? '?') . ' queries, ' . ($aq['queries_logged'] ?? '?') . ' logged)');
    $home_run = sspa_88_run(array('home'));
    $home = sspa_88_profile($home_run['id'], 'home');
    sspa_88_t('qm' === $home_run['during'] && 'qm' === SSPA_Helper_Files::dropin_status(), 'a default run never touches QM\'s drop-in (' . $home_run['during'] . ' during, ' . SSPA_Helper_Files::dropin_status() . ' after)');
    // Riding QM's drop-in the plugin can only report what QM's class logged for that request:
    // nothing ("none", count from num_queries, no time), a plain SAVEQUERIES log
    // ("degraded") or QM's annotated log ("qm"). It must never claim a full capture, and a
    // capture with no log must carry no SQL time.
    $home_capture = SSPA_Profile_Panel::capture($home['row']);
    sspa_88_t(in_array($home['mode'], array('none', 'degraded', 'qm'), true) && ('none' !== $home['mode'] || null === ($home_capture['sql']['total_ms'] ?? null)), 'the plugin reports honestly what QM\'s class let it see: capture mode ' . var_export($home['mode'], true) . ', logged SQL time ' . var_export($home_capture['sql']['total_ms'] ?? null, true));
    echo 'SEPARATE REQUEST: Home QM ' . $aq['total_qs'] . ', PA ' . $home['row']['sql_count'] . "\n";
    $aq = $home['qm'];
    sspa_88_t(is_array($aq), 'QM evidence is paired with the retained Home capture token');
    if (!is_array($aq)) { throw new RuntimeException('Missing same-request Home QM evidence'); }
    $delta_anon = (int) $home['row']['sql_count'] - (int) $aq['total_qs'];
    sspa_88_t(abs($delta_anon) <= 6, 'anonymous query counts agree within the plugin\'s instrumentation allowance of 6 (plugin ' . $home['row']['sql_count'] . ', QM ' . $aq['total_qs'] . ', delta ' . $delta_anon . ')');

    // B. A page QM logs in full: the admin dashboard seen by an administrator. The plugin
    //    measures it as the admin variant and reads QM's own log, so count and time both
    //    come from the same queries.
    $admin_probe = sspa_88_probe(admin_url('index.php'), $admin_cookies);
    $bq = $admin_probe['qm'];
    sspa_88_t(200 === $admin_probe['code'] && 1 === (int) ($bq['viewer'] ?? 0) && (int) ($bq['rows'] ?? 0) > 0 && (float) ($bq['time_ms'] ?? 0) > 0, 'QM logged the admin dashboard in full for the administrator (' . ($bq['rows'] ?? '?') . ' rows, ' . ($bq['time_ms'] ?? '?') . 'ms)');
    $dash_run = sspa_88_run(array('admin-dashboard'));
    $dash = sspa_88_profile($dash_run['id'], 'admin-dashboard');
    sspa_88_t('qm' === $dash['mode'], 'the plugin captured the dashboard from QM\'s own query log: capture mode "qm" (' . var_export($dash['mode'], true) . ')');
    // The fixture reads QM after PA persists its capture. Keep the existing tolerance
    // for that instrumentation, but compare the same signed request, not another page hit.
    echo 'SEPARATE REQUEST: dashboard QM ' . $bq['total_qs'] . ', PA ' . $dash['row']['sql_count'] . "\n";
    $bq = $dash['qm'];
    sspa_88_t(is_array($bq), 'QM evidence is paired with the retained dashboard capture token');
    if (!is_array($bq)) { throw new RuntimeException('Missing same-request dashboard QM evidence'); }
    $delta_dash = (int) $dash['row']['sql_count'] - (int) $bq['total_qs'];
    $count_ratio = (int) $bq['total_qs'] > 0 ? (int) $dash['row']['sql_count'] / (int) $bq['total_qs'] : null;
    sspa_88_t(null !== $count_ratio && $count_ratio >= 0.75 && $count_ratio <= 1.25, 'dashboard query counts agree within 25% (plugin ' . $dash['row']['sql_count'] . ', QM ' . $bq['total_qs'] . ', delta ' . $delta_dash . ', ratio ' . (null === $count_ratio ? '?' : round($count_ratio, 2)) . ')');
    $ratio = ((float) ($bq['time_ms'] ?? 0) > 0 && null !== $dash['row']['sql_ms']) ? (float) $dash['row']['sql_ms'] / (float) $bq['time_ms'] : null;
    sspa_88_t(null !== $ratio && $ratio >= 0.25 && $ratio <= 4.0, 'dashboard query time agrees within a factor of four (plugin ' . var_export($dash['row']['sql_ms'], true) . 'ms, QM ' . $bq['time_ms'] . 'ms, ratio ' . (null === $ratio ? '?' : round($ratio, 2)) . ')');
    sspa_88_t('qm' === $dash_run['during'] && 'qm' === SSPA_Helper_Files::dropin_status(), 'riding QM\'s log never displaced its drop-in');

    // C. When asked to swap, the plugin holds QM's drop-in aside for exactly the run, captures
    //    through its own shim, and puts QM's back byte-for-byte.
    // QM's drop-in is a symlink. After the swap, running php-fpm workers keep resolving
    // db.php to QM's file through PHP's realpath cache for up to realpath_cache_ttl (120s by
    // default); opcache invalidation cannot reach that. SSPA_88_SETTLE overrides the wait.
    $swap_settle = (int) getenv('SSPA_88_SETTLE') ?: (int) ini_get('realpath_cache_ttl') + 5;
    $swap_run = sspa_88_run(array('home'), array('swap_dropin' => true), $swap_settle);
    $swap = sspa_88_profile($swap_run['id'], 'home');
    sspa_88_t('ours' === $swap_run['during'], 'with swap_dropin the plugin held QM\'s drop-in aside for the run (' . $swap_run['during'] . ' during)');
    sspa_88_t('full' === $swap['mode'] && null !== $swap['row']['sql_ms'] && (int) $swap['row']['sql_count'] >= 4, 'the swapped run captured in full through the plugin\'s own shim (' . var_export($swap['mode'], true) . ', ' . $swap['row']['sql_count'] . ' queries, ' . var_export($swap['row']['sql_ms'], true) . 'ms)');
    sspa_88_t('qm' === SSPA_Helper_Files::dropin_status() && is_link($dropin) && readlink($dropin) === $qm_target && md5_file($dropin) === $qm_bytes, 'QM\'s drop-in is back: the same symlink to the same file');
    sspa_88_t(false === get_option('sspa_dropin_hold', false) && !file_exists(WP_CONTENT_DIR . '/' . SSPA_Helper_Files::HOLD_FILE), 'no hold file or hold record remains');
    $after = sspa_88_probe(admin_url('index.php'), $admin_cookies);
    sspa_88_t(200 === $after['code'] && (int) ($after['qm']['rows'] ?? 0) > 0 && 'qm-symlink' === ($after['qm']['dropin'] ?? ''), 'QM still logs queries through its own drop-in after the swapped run (' . ($after['qm']['rows'] ?? '?') . ' rows)');
    sspa_88_t(SSPA_Helper_Files::qm_plugin_active(), 'Query Monitor is still active');
    echo 'RETAINED: runs ' . $home_run['id'] . ' (anon, count-only), ' . $dash_run['id'] . ' (admin, qm mode), ' . $swap_run['id'] . ' (swap, full mode); QM probes in options sspa_qm_probe_*' . "\n";
} catch (Throwable $error) {
    sspa_88_t(false, $error->getMessage());
}

if ($GLOBALS['sspa_88_failures']) { exit(1); }
