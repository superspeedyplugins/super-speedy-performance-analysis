<?php
// Measurements use a generous finite deadline, not WordPress's five-second default.
//
// This reproduces the customer symptom with the real crawler: the legacy timeout option is
// deliberately set to 10 seconds, then a real page takes 12 seconds. Query Monitor can profile
// that page because the server allows it to finish; the 120-second profiling deadline must too.
//
// The second half forces a real WordPress HTTP transport failure through the crawler and profile
// store, then renders the normal page panel. The transport's own explanation must survive all
// three layers and be visible instead of an empty result with question marks.

function sspa_lt_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

global $wpdb;

$sspa_original_options = get_option('sspa_options', array());
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

// This is an old option deliberately left by an earlier build. It must have no effect.
sspa_update_option('loopback_timeout', 10);

// NOT sspa_-prefixed: the catalogue matcher strips sspa_* query keys, which would file
// this under 'home' and measure the catalogue URL without the sleep.
$sspa_slow_url = home_url('/?slow_probe=1');

function sspa_lt_adhoc($url) {
    SSPA_Run_Controller::cleanup();
    $run_id = SSPA_Run_Controller::start(array('type' => 'adhoc', 'url' => $url, 'user_id' => 1));
    if (is_wp_error($run_id)) {
        return $run_id;
    }
    $deadline = time() + 300;
    do {
        SSPA_Run_Controller::process_batch($run_id);
        $status = SSPA_Run_Controller::status($run_id);
    } while ($status && in_array($status['status'], array('crawling', 'analysing'), true) && time() < $deadline);
    return $run_id;
}

$sspa_run = sspa_lt_adhoc($sspa_slow_url);
if (is_wp_error($sspa_run)) {
    echo 'FAIL: bounded slow run started: ' . $sspa_run->get_error_message() . "\n";
} else {
    $sspa_row = $wpdb->get_row($wpdb->prepare(
        'SELECT id, page_gen_ms, samples FROM ' . SSPA_Schema::table('profiles') . ' WHERE run_id = %d',
        $sspa_run
    ), ARRAY_A);
    sspa_lt_t(
        is_array($sspa_row) && null !== $sspa_row['page_gen_ms'] && (float) $sspa_row['page_gen_ms'] >= 11500,
        'the 12-second page is measured despite the old 10-second setting ('
            . (isset($sspa_row['page_gen_ms']) ? $sspa_row['page_gen_ms'] : 'null') . 'ms)'
    );
    $sspa_errors = array_filter((array) json_decode((string) $sspa_row['samples'], true), function ($sample) {
        return !empty($sample['error']);
    });
    sspa_lt_t(0 === count($sspa_errors), 'all slow-page samples finish inside the 120-second deadline');
}

// A real crawler -> profile store -> result panel transport failure. The HTTP request is
// pre-empted at WordPress's supported HTTP boundary so the failure is deterministic, while
// every product layer handling and displaying it remains the production path.
$sspa_transport_message = 'Fixture transport refused the profiling connection.';
$sspa_transport_filter = function ($response, $args, $url) use ($sspa_transport_message) {
    if (false !== strpos($url, 'transport_error_probe=1')) {
        return new WP_Error('http_request_failed', $sspa_transport_message);
    }
    return $response;
};
add_filter('pre_http_request', $sspa_transport_filter, 10, 3);
$sspa_crawler = new SSPA_Crawler();
$sspa_failed = $sspa_crawler->profile_job(array(
    'page_key' => 'url-transport-error',
    'url' => home_url('/?transport_error_probe=1'),
    'variant' => 'anon',
), 1);
remove_filter('pre_http_request', $sspa_transport_filter, 10);

$sspa_failed_profile = SSPA_Profile_Store::save($sspa_run, $sspa_failed);
$sspa_failed_row = SSPA_Profile_Panel::profile_row($sspa_failed_profile);
$sspa_failed_samples = json_decode((string) $sspa_failed_row['samples'], true);
sspa_lt_t(
    isset($sspa_failed_samples[0]['error_message'])
        && $sspa_transport_message === $sspa_failed_samples[0]['error_message'],
    'the profile retains the transport error message'
);
$sspa_failed_html = SSPA_Profile_Panel::render($sspa_failed_profile, array('cached' => false));
sspa_lt_t(
    is_string($sspa_failed_html) && false !== strpos($sspa_failed_html, $sspa_transport_message),
    'the result panel displays the transport error message'
);
sspa_lt_t(
    is_string($sspa_failed_html) && false === strpos($sspa_failed_html, '>0ms</span><span class="sspa-adhoc-stat-label">HTTP'),
    'a missing capture does not claim zero milliseconds of HTTP work'
);

// Restore the pre-test option because it is a live configuration switch. The deliberately
// named slow fixture stays installed as inspectable evidence and is inert without slow_probe.
update_option('sspa_options', $sspa_original_options, false);
