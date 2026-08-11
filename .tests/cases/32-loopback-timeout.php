<?php
// The measurement timeout is a setting, and it genuinely reaches the crawler.
//
// The old hardcoded 60 seconds abandoned every sample on a site whose pages take minutes -
// the exact site whose owner most needs the measurement, and who has already raised their
// server timeouts far enough to load the page in a browser. Observed on a real site on 11th
// August 2026: timeouts raised to 600s, page loads, "Analyse this page" records nothing.
//
// Proven with a page that really is slow, both ways round: with the timeout BELOW the page
// time the sample records nothing, with it ABOVE the page time the same page measures. A
// test that only read the option back would pass with the crawler still hardcoded.

function sspa_lt_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

global $wpdb;

// --- The sanitiser: shared by the crawler and the save handler ---

sspa_lt_t(60 === SSPA_Crawler::sanitise_loopback_timeout(null), 'absent value falls back to 60');
sspa_lt_t(60 === SSPA_Crawler::sanitise_loopback_timeout('nonsense'), 'non-numeric falls back to 60');
sspa_lt_t(10 === SSPA_Crawler::sanitise_loopback_timeout(3), 'floor is 10');
sspa_lt_t(900 === SSPA_Crawler::sanitise_loopback_timeout(5000), 'ceiling is 900');
sspa_lt_t(600 === SSPA_Crawler::sanitise_loopback_timeout('600'), 'a sane value passes through');
sspa_lt_t(60 === SSPA_Crawler::loopback_timeout(), 'the default in use is 60');

// --- A genuinely slow page ---

$sspa_slow_dir = WP_PLUGIN_DIR . '/sspa-slow-fixture';
if (!is_dir($sspa_slow_dir)) {
    mkdir($sspa_slow_dir);
}
file_put_contents($sspa_slow_dir . '/sspa-slow-fixture.php', <<<'PHP'
<?php
/**
 * Plugin Name: SSPA Slow Fixture (test fixture)
 * Version: 1.0.0
 */
add_action('template_redirect', function () {
    if (isset($_GET['slow_probe'])) {
        sleep(12);
    }
});
PHP
);
activate_plugin('sspa-slow-fixture/sspa-slow-fixture.php');
wp_cache_flush();
sleep(3); // opcache

// NOT sspa_-prefixed: the catalogue matcher strips sspa_* query keys, which would file
// this under 'home' and measure the catalogue URL - without the sleep.
$sspa_slow_url = home_url('/?slow_probe=1');

function sspa_lt_adhoc($url) {
    SSPA_Run_Controller::cleanup(); // a prior stale run must not block the start
    $run_id = SSPA_Run_Controller::start(array('type' => 'adhoc', 'url' => $url, 'user_id' => 1));
    if (is_wp_error($run_id)) {
        return $run_id;
    }
    $deadline = time() + 240;
    do {
        SSPA_Run_Controller::process_batch($run_id);
        $status = SSPA_Run_Controller::status($run_id);
    } while ($status && in_array($status['status'], array('crawling', 'analysing'), true) && time() < $deadline);
    return $run_id;
}

// Timeout below the page time: every sample is abandoned, and the row says so with nulls.
// 10 is the floor, so the fixture sleeps 12.
sspa_update_option('loopback_timeout', 10);
sspa_lt_t(10 === SSPA_Crawler::loopback_timeout(), 'timeout set to 10s (below the 12s page)');
$sspa_run_short = sspa_lt_adhoc($sspa_slow_url);
if (is_wp_error($sspa_run_short)) {
    echo 'FAIL: short-timeout run: ' . $sspa_run_short->get_error_message() . "\n";
} else {
    $sspa_row = $wpdb->get_row($wpdb->prepare(
        'SELECT page_gen_ms, samples FROM ' . SSPA_Schema::table('profiles') . ' WHERE run_id = %d',
        $sspa_run_short
    ), ARRAY_A);
    sspa_lt_t(is_array($sspa_row) && null === $sspa_row['page_gen_ms'], 'below the page time, nothing is measured');
    $sspa_errors = 0;
    foreach ((array) json_decode((string) $sspa_row['samples'], true) as $sspa_s) {
        if (!empty($sspa_s['error'])) {
            $sspa_errors++;
        }
    }
    sspa_lt_t($sspa_errors > 0, 'the samples record the abandonment (' . $sspa_errors . ' errored)');
}

// Timeout above the page time: the same page measures, sleep included.
sspa_update_option('loopback_timeout', 30);
$sspa_run_long = sspa_lt_adhoc($sspa_slow_url);
if (is_wp_error($sspa_run_long)) {
    echo 'FAIL: long-timeout run: ' . $sspa_run_long->get_error_message() . "\n";
} else {
    $sspa_gen = $wpdb->get_var($wpdb->prepare(
        'SELECT page_gen_ms FROM ' . SSPA_Schema::table('profiles') . ' WHERE run_id = %d',
        $sspa_run_long
    ));
    sspa_lt_t(
        null !== $sspa_gen && (float) $sspa_gen >= 11500,
        'above the page time, the same page measures (' . var_export($sspa_gen, true) . 'ms)'
    );
}

// --- Cleanup ---
sspa_update_option('loopback_timeout', 60);
deactivate_plugins('sspa-slow-fixture/sspa-slow-fixture.php', true);
@unlink($sspa_slow_dir . '/sspa-slow-fixture.php');
@rmdir($sspa_slow_dir);
sspa_lt_t(!is_dir($sspa_slow_dir) && 60 === SSPA_Crawler::loopback_timeout(), 'fixture removed, default restored');
