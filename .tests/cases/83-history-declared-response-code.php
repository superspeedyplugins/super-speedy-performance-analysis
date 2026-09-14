<?php
// A declared expectation records the approved run's HTTP response code alongside its output
// signature. History must evaluate that code for every applicable measured case, report a
// code mismatch separately from an output-signature change and from missing evidence, and
// hand the same result to the admin table, the export, the CLI and Abilities. Real spot runs
// of Home; the After's failure comes from the retained browser fixture answering 503 to
// captured requests.
require __DIR__ . '/../lib/fleet.php';

function sspa_83_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
    if (!$ok) { $GLOBALS['sspa_83_failures']++; }
}
$GLOBALS['sspa_83_failures'] = 0;
function sspa_83_run($page_keys) {
    $id = SSPA_Run_Controller::start(array('type' => 'spot', 'page_keys' => $page_keys, 'user_id' => 1));
    if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
    $deadline = time() + 180;
    do {
        SSPA_Run_Controller::process_batch($id);
        $status = SSPA_Run_Controller::status($id);
    } while ($status && in_array($status['status'], array('crawling', 'analysing'), true) && time() < $deadline);
    if (!$status || 'done' !== $status['status']) { throw new RuntimeException('History fixture run did not complete'); }
    return (int) $id;
}
function sspa_83_page($comparison, $page_key) {
    foreach ((is_wp_error($comparison) ? array() : $comparison['pages']) as $page) {
        if ($page_key === $page['page_key']) { return $page; }
    }
    return array();
}

wp_set_current_user(1);
// Clear the previous run's state on the way in: no forced error, no approved Home expectation.
update_option('sspa_fleet_http_error', '0', true);
update_option('sspa_fleet_enabled', true);
wp_cache_flush();
$home_identity = SSPA_History_Series::page_identity(array('page_key' => 'home', 'method' => 'GET', 'variant' => 'anon', 'object_cache_mode' => 'normal'));
SSPA_History::clear_assertion($home_identity);

try {
    $before = sspa_83_run(array('home'));
    $approved = SSPA_History::approve_assertion($before, $home_identity);
    sspa_83_t(true === $approved, 'the real Before run approves a Home expectation' . (is_wp_error($approved) ? ' (' . $approved->get_error_message() . ')' : ''));
    $control = SSPA_History::compare($before, $before);
    $control_home = sspa_83_page($control, 'home');
    sspa_83_t('pass' === ($control_home['declared']['state'] ?? ''), 'the approved run compared with itself passes its declared expectation');
    sspa_83_t(200 === (int) ($control_home['declared']['response_code']['expected'] ?? 0) && 'pass' === ($control_home['declared']['response_code']['state'] ?? ''), 'the declared expectation carries the approved response code and evaluates it (' . wp_json_encode($control_home['declared']['response_code'] ?? null) . ')');

    // After A: the captured Home now answers 503. Both the code and the body differ.
    update_option('sspa_fleet_http_error', '1', true);
    wp_cache_flush();
    $after_error = sspa_83_run(array('home'));
    update_option('sspa_fleet_http_error', '0', true);
    wp_cache_flush();
    $mismatch = SSPA_History::compare($before, $after_error);
    $home = sspa_83_page($mismatch, 'home');
    sspa_83_t(503 === (int) ($home['response_code']['after'] ?? 0), 'the After really answered 503 (' . var_export($home['response_code']['after'] ?? null, true) . ')');
    sspa_83_t('fail' === ($home['declared']['response_code']['state'] ?? '') && 200 === (int) ($home['declared']['response_code']['expected'] ?? 0) && 503 === (int) ($home['declared']['response_code']['actual'] ?? 0), 'a response-code mismatch is reported as its own declared failure (' . wp_json_encode($home['declared']['response_code'] ?? null) . ')');
    sspa_83_t('fail' === ($home['declared']['state'] ?? ''), 'the declared expectation fails overall');
    sspa_83_t(isset($home['output']['state']) && isset($home['declared']['signature_state']), 'the output-signature verdict is reported separately from the response-code verdict (' . ($home['output']['state'] ?? '?') . ' / ' . ($home['declared']['signature_state'] ?? '?') . ')');
    sspa_83_t((int) $mismatch['summary']['failed_declared_cases'] >= 1 && 'attention' === $mismatch['status'], 'the summary counts the failed expectation and demands attention');

    // After B: Home was not measured at all. Missing evidence is unknown, never a failure.
    $after_missing = sspa_83_run(array('shop'));
    $missing = SSPA_History::compare($before, $after_missing);
    $missing_home = sspa_83_page($missing, 'home');
    sspa_83_t('unknown' === ($missing_home['declared']['response_code']['state'] ?? '') && 'unknown' === ($missing_home['declared']['state'] ?? ''), 'missing After evidence is unknown, distinct from a mismatch (' . ($missing_home['declared']['state'] ?? '?') . ')');
    sspa_83_t(0 === (int) $missing['summary']['failed_declared_cases'], 'missing evidence is not counted as a failed expectation');

    // Every surface hands out the same document: export wraps it, the CLI prints the export.
    $export = SSPA_History::export($mismatch);
    $export_home = sspa_83_page($export['comparison'], 'home');
    sspa_83_t(($export_home['declared'] ?? null) === $home['declared'], 'the private export carries the identical declared result');
    $cli = json_decode(sspa_fleet_cli(array('sspa', 'history-compare', (string) $before, (string) $after_error)), true);
    $cli_home = is_array($cli) ? sspa_83_page($cli['comparison'], 'home') : array();
    sspa_83_t(($cli_home['declared']['response_code'] ?? null) === $home['declared']['response_code'], 'the real CLI reports the same response-code verdict');
} catch (Throwable $error) {
    sspa_83_t(false, $error->getMessage());
}

if ($GLOBALS['sspa_83_failures']) { exit(1); }
