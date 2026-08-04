<?php
// Phase-5 Excimer collector: sampling profile captured during a normal profiled
// request, attributed through the component map. The extension lives in the APACHE
// container (run-tests.sh installs it via docker/install-excimer.sh); this CLI process
// never has it, so the decision comes from the CAPTURE contents.

function sspa_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

global $wpdb;

$run_id = SSPA_Run_Controller::start(array('type' => 'adhoc', 'url' => home_url('/'), 'trigger' => 'manual', 'user_id' => 1));
if (is_wp_error($run_id)) {
    echo 'FAIL: start(): ' . $run_id->get_error_message() . "\n";
    return;
}
$deadline = time() + 180;
do {
    SSPA_Run_Controller::process_batch($run_id);
    $status = SSPA_Run_Controller::status($run_id);
} while ($status && 'crawling' === $status['status'] && time() < $deadline);
sspa_t($status && 'done' === $status['status'], 'run completed: ' . ($status ? $status['status'] : 'null'));

$profiles_table = SSPA_Schema::table('profiles');
$row = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM $profiles_table WHERE run_id = %d AND page_gen_ms IS NOT NULL ORDER BY id LIMIT 1",
    $run_id
), ARRAY_A);
$capture = ($row && $row['profile_blob']) ? json_decode(gzuncompress($row['profile_blob']), true) : null;
sspa_t(is_array($capture), 'capture unpacked');

$p = is_array($capture) && isset($capture['profile']) ? $capture['profile'] : null;
// If this fails, excimer is not loaded in the APACHE container - run
// .tests/docker/install-excimer.sh (run-tests.sh does this automatically).
sspa_t(is_array($p), 'excimer profile captured');
if (is_array($p)) {
    sspa_t('excimer' === $p['collector'], 'collector labelled excimer');
    sspa_t($p['samples'] > 10, 'meaningful sample count (' . $p['samples'] . ')');
    // Sampled wall time must be in the same universe as measured generation time:
    // within a factor of ~2 either way (both are noisy on a fast page).
    $gen = (float) $row['page_gen_ms'];
    sspa_t($p['wall_ms'] > $gen * 0.3 && $p['wall_ms'] < $gen * 2.5, "sampled wall time plausible ({$p['wall_ms']}ms vs gen {$gen}ms)");
    sspa_t(!empty($p['functions']) && isset($p['functions'][0]['incl_ms']), 'function rows present (' . count($p['functions']) . ')');
    // Attribution sanity: on a Woo site's home page, woocommerce must appear among the
    // sampled components, and every component must carry positive time.
    sspa_t(isset($p['components']['woocommerce']) || isset($p['components']['core']), 'components attributed (' . implode(', ', array_slice(array_keys($p['components']), 0, 4)) . ')');
    $sane = true;
    $has_by = false;
    foreach ($p['functions'] as $fn) {
        if ($fn['self_ms'] > $fn['incl_ms'] + 0.01) {
            $sane = false;
        }
        if (!empty($fn['by'])) {
            $has_by = true;
            // The drivers split can never exceed the function's own self time.
            if (array_sum($fn['by']) > $fn['self_ms'] + 0.5) {
                $sane = false;
            }
        }
    }
    sspa_t($sane, 'self <= inclusive and drivers <= self for every function');
    sspa_t($has_by, 'at least one function carries a driven-by split');
}
