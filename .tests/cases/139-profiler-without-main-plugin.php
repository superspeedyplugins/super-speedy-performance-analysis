<?php
// Issue #47: a profiled request on which PA's main plugin is not loaded must still be captured.
//
// A Fast Ajax/REST trim or a plugin organiser can remove PA's main plugin from
// option_active_plugins for one request. The MU loader and profiler still arm, because they do
// not depend on it, but up to 0.49.4 the shutdown capture fataled on a main-plugin constant
// (SSPA_CORE_DIR, via SSPA_Mail::warning()) and every sample read capture_missing.
//
// Part 1 installs .tests/fixtures/trim-main-plugin.php as an MU plugin and profiles
// /?trim_pa_probe=1 through a real ad-hoc analysis and the real crawler. Each sample must carry
// a capture whose boot includes show WooCommerce loaded and PA's main plugin absent.
//
// Part 2 makes a third-party alloptions filter throw inside the shutdown capture. The sample must
// name that failure (capture_failed and the exception text) through the profile store and the
// result panel instead of a bare capture_missing, and PHP must still log the real fatal.
//
// The fixture stays installed afterwards as inspectable evidence; it is inert without its probes.

function sspa_139_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

global $wpdb;
wp_set_current_user(1);

$sspa_139_health = SSPA_Helper_Files::health();
sspa_139_t(!empty($sspa_139_health['mu']), 'the profiler MU loader is installed and healthy');

$sspa_139_mu = WPMU_PLUGIN_DIR . '/zz-sspa-test-trim-main-plugin.php';
copy(SSPA_PLUGIN_DIR . '.tests/fixtures/trim-main-plugin.php', $sspa_139_mu);
clearstatcache();
sleep(3); // php-fpm revalidates changed PHP files at most every 2s.

$sspa_139_log = WP_CONTENT_DIR . '/debug.log';
clearstatcache();
$sspa_139_log_start = is_file($sspa_139_log) ? filesize($sspa_139_log) : 0;
$sspa_139_log_since = static function () use ($sspa_139_log, $sspa_139_log_start) {
    clearstatcache();
    if (!is_file($sspa_139_log)) {
        return '';
    }
    $handle = fopen($sspa_139_log, 'r');
    fseek($handle, $sspa_139_log_start);
    $text = stream_get_contents($handle);
    fclose($handle);
    return (string) $text;
};

$sspa_139_active = SSPA_Run_Controller::active_run_id();
if ($sspa_139_active) {
    SSPA_Run_Controller::cancel($sspa_139_active);
}
SSPA_Run_Controller::cleanup();

// ---- Part 1: the user path, an ad-hoc analysis of the trimmed URL ----
$sspa_139_url = home_url('/?trim_pa_probe=1');
$sspa_139_run = SSPA_Run_Controller::start(array('type' => 'adhoc', 'url' => $sspa_139_url, 'user_id' => 1));
sspa_139_t(!is_wp_error($sspa_139_run), 'an ad-hoc analysis of the trimmed URL starts'
    . (is_wp_error($sspa_139_run) ? ': ' . $sspa_139_run->get_error_message() : ''));
$sspa_139_status = null;
if (!is_wp_error($sspa_139_run)) {
    $sspa_139_deadline = time() + 300;
    do {
        SSPA_Run_Controller::process_batch($sspa_139_run);
        $sspa_139_status = SSPA_Run_Controller::status($sspa_139_run);
    } while ($sspa_139_status && in_array($sspa_139_status['status'], array('queued', 'crawling', 'analysing'), true) && time() < $sspa_139_deadline);
}
sspa_139_t($sspa_139_status && 'done' === $sspa_139_status['status'], 'the ad-hoc analysis completes (status '
    . ($sspa_139_status ? $sspa_139_status['status'] : 'none') . ')');

$sspa_139_row = is_wp_error($sspa_139_run) ? null : $wpdb->get_row($wpdb->prepare(
    'SELECT id, page_gen_ms, samples FROM %i WHERE run_id = %d',
    SSPA_Schema::table('profiles'),
    $sspa_139_run
), ARRAY_A);
$sspa_139_samples = $sspa_139_row ? (array) json_decode((string) $sspa_139_row['samples'], true) : array();
$sspa_139_errors = array_values(array_unique(array_filter(array_map(static function ($s) {
    return isset($s['error']) ? (string) $s['error'] : '';
}, $sspa_139_samples))));
sspa_139_t(count($sspa_139_samples) > 0 && !$sspa_139_errors, 'every sample of the trimmed URL has a capture (errors: '
    . ($sspa_139_errors ? implode(', ', $sspa_139_errors) : 'none') . ', ' . count($sspa_139_samples) . ' samples)');
sspa_139_t($sspa_139_row && null !== $sspa_139_row['page_gen_ms'], 'the profile records a generation time ('
    . ($sspa_139_row ? var_export($sspa_139_row['page_gen_ms'], true) : 'no profile row') . ')');

// The captures themselves: prove the trim really applied, so the case cannot pass on a request
// that loaded PA after all.
$sspa_139_crawler = new SSPA_Crawler();
$sspa_139_job = $sspa_139_crawler->profile_job(array('page_key' => 'url-trim-probe', 'url' => $sspa_139_url, 'variant' => 'anon'), 1);
$sspa_139_captured = 0;
$sspa_139_trimmed = 0;
$sspa_139_job_errors = array();
foreach ((array) $sspa_139_job['samples'] as $sspa_139_sample) {
    if (!empty($sspa_139_sample['error'])) {
        $sspa_139_job_errors[] = $sspa_139_sample['error'];
    }
    if (empty($sspa_139_sample['capture'])) {
        continue;
    }
    $sspa_139_captured++;
    $sspa_139_includes = isset($sspa_139_sample['capture']['boot']['includes']) ? (array) $sspa_139_sample['capture']['boot']['includes'] : array();
    if (isset($sspa_139_includes['woocommerce']) && !isset($sspa_139_includes['super-speedy-performance-analysis'])) {
        $sspa_139_trimmed++;
    }
}
sspa_139_t(count((array) $sspa_139_job['samples']) > 0 && $sspa_139_captured === count((array) $sspa_139_job['samples']),
    'the crawler stores a capture for every trimmed request (' . $sspa_139_captured . ' of ' . count((array) $sspa_139_job['samples'])
    . ($sspa_139_job_errors ? '; errors ' . implode(', ', array_unique($sspa_139_job_errors)) : '') . ')');
sspa_139_t($sspa_139_captured > 0 && $sspa_139_trimmed === $sspa_139_captured,
    'each capture shows WooCommerce loaded and PA\'s main plugin absent (' . $sspa_139_trimmed . ' of ' . $sspa_139_captured . ')');
$sspa_139_log_part1 = $sspa_139_log_since();
sspa_139_t(false === strpos($sspa_139_log_part1, 'Undefined constant "SSPA_'),
    'no profiled request logged an undefined SSPA_ constant');

// ---- Part 2: a capture lost to a fatal is reported as that fatal ----
$sspa_139_marker = strtolower(wp_generate_password(12, false));
$sspa_139_fail = $sspa_139_crawler->profile_job(array(
    'page_key' => 'url-capture-fail-probe',
    'url' => home_url('/?capture_fail_probe=' . $sspa_139_marker),
    'variant' => 'anon',
), 1);
$sspa_139_fail_samples = (array) $sspa_139_fail['samples'];
$sspa_139_named = array_filter($sspa_139_fail_samples, static function ($s) use ($sspa_139_marker) {
    return isset($s['error'], $s['error_message']) && 'capture_failed' === $s['error']
        && false !== strpos((string) $s['error_message'], 'RuntimeException')
        && false !== strpos((string) $s['error_message'], $sspa_139_marker);
});
sspa_139_t(count($sspa_139_fail_samples) > 0 && count($sspa_139_named) === count($sspa_139_fail_samples),
    'every sample whose capture failed reports capture_failed with the exception (' . count($sspa_139_named) . ' of '
    . count($sspa_139_fail_samples) . '; first: ' . (isset($sspa_139_fail_samples[0]['error']) ? $sspa_139_fail_samples[0]['error'] : 'none')
    . ' / ' . (isset($sspa_139_fail_samples[0]['error_message']) ? $sspa_139_fail_samples[0]['error_message'] : '') . ')');

if (!is_wp_error($sspa_139_run)) {
    $sspa_139_profile = SSPA_Profile_Store::save($sspa_139_run, $sspa_139_fail);
    $sspa_139_frow = SSPA_Profile_Panel::profile_row($sspa_139_profile);
    $sspa_139_fstored = (array) json_decode((string) $sspa_139_frow['samples'], true);
    sspa_139_t(isset($sspa_139_fstored[0]['error_message']) && false !== strpos((string) $sspa_139_fstored[0]['error_message'], 'Fixture alloptions filter failed during shutdown ' . $sspa_139_marker),
        'the profile store retains the capture failure message');
    $sspa_139_html = SSPA_Profile_Panel::render($sspa_139_profile, array('cached' => false));
    // The URL carries the marker too, so match the exception text, which only the failure supplies.
    sspa_139_t(is_string($sspa_139_html) && false !== strpos($sspa_139_html, 'Fixture alloptions filter failed during shutdown ' . $sspa_139_marker),
        'the result panel displays the capture failure');
}
sspa_139_t(false !== strpos($sspa_139_log_since(), 'Fixture alloptions filter failed during shutdown ' . $sspa_139_marker),
    'PHP still logs the real fatal in debug.log');
