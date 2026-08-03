<?php
// Admin-bar "Analyse this page" ad-hoc runs: URL normalisation, same-site guard, the
// adhoc run type end to end, and its exclusion from the "latest analysis" queries.

function sspa_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

global $wpdb;

// --- job_for(): normalisation + same-site guard ---

$job = SSPA_Adhoc::job_for(home_url('/?page_id=2#section'));
sspa_t(is_array($job) && false === strpos($job['url'], '#'), 'fragment stripped');
$job2 = SSPA_Adhoc::job_for(home_url('/?page_id=2&sspa_nc=deadbeef'));
sspa_t(is_array($job2) && false === strpos($job2['url'], 'sspa_nc'), 'stale cache-buster stripped');
sspa_t(is_array($job) && is_array($job2) && $job['page_key'] === $job2['page_key'], 'normalised URLs share a page key');
sspa_t(is_wp_error(SSPA_Adhoc::job_for('https://evil.example.com/')), 'foreign host rejected');
sspa_t(is_wp_error(SSPA_Adhoc::job_for('')), 'empty URL rejected');
$admin_job = SSPA_Adhoc::job_for(admin_url('index.php'));
sspa_t(is_array($admin_job) && 'admin' === $admin_job['variant'], 'wp-admin URL gets admin variant');
$front_job = SSPA_Adhoc::job_for(home_url('/'));
sspa_t(is_array($front_job) && 'anon' === $front_job['variant'], 'front-end URL gets anon variant');

// --- The run itself ---

$run_id = SSPA_Run_Controller::start(array('type' => 'adhoc', 'url' => home_url('/'), 'trigger' => 'adminbar', 'user_id' => 1));
if (is_wp_error($run_id)) {
    echo 'FAIL: adhoc start(): ' . $run_id->get_error_message() . "\n";
    return;
}
sspa_t(true, "adhoc run $run_id started");

$deadline = time() + 180;
do {
    SSPA_Run_Controller::process_batch($run_id);
    $status = SSPA_Run_Controller::status($run_id);
} while ($status && 'crawling' === $status['status'] && time() < $deadline);
sspa_t($status && 'done' === $status['status'], 'adhoc run completed: ' . ($status ? $status['status'] : 'null'));

$runs_table = SSPA_Schema::table('runs');
$profiles_table = SSPA_Schema::table('profiles');
$run_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $runs_table WHERE id = %d", $run_id), ARRAY_A);
sspa_t($run_row && 'adhoc' === $run_row['run_type'], 'run_type stored as adhoc');

$profile = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM $profiles_table WHERE run_id = %d AND page_key = %s",
    $run_id,
    $front_job['page_key']
), ARRAY_A);
sspa_t(is_array($profile), 'profile stored under URL-derived page key (' . $front_job['page_key'] . ')');
if ($profile) {
    sspa_t($profile['page_gen_ms'] > 0, 'page generation measured (' . $profile['page_gen_ms'] . 'ms)');
    sspa_t((int) $profile['response_code'] === 200, 'responded 200');
    $capture = $profile['profile_blob'] ? json_decode(gzuncompress($profile['profile_blob']), true) : null;
    sspa_t(is_array($capture) && !empty($capture['boot']['segments']), 'boot decomposition present for the popover');
    if (is_array($capture) && !empty($capture['boot']['segments'])) {
        $segs = $capture['boot']['segments'];
        sspa_t(isset($segs['render_and_output']), 'render phase has a row (' . (isset($segs['render_and_output']) ? $segs['render_and_output'] : '?') . 'ms)');
        // The whole point of the segment table: it must account for the whole request,
        // not stop at template_redirect and leave the render time looking unmeasurable.
        $sum = array_sum($segs);
        $gen = (float) $capture['overview']['gen_ms'];
        sspa_t($gen > 0 && abs($sum - $gen) < max(20, $gen * 0.15), "segments sum to gen time ($sum vs $gen)");
    }
}

// A one-page check must never become the "latest analysis" on the Overview/Pages tabs.
$latest = $wpdb->get_var("SELECT id FROM $runs_table WHERE status = 'done' AND run_type IN ('baseline','spot') ORDER BY id DESC LIMIT 1");
sspa_t((int) $latest !== (int) $run_id, 'adhoc run excluded from latest-analysis queries');
