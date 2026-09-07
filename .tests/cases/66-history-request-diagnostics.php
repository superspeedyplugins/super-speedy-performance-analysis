<?php
// Real signed HTTP requests, stored by the production profile store and read by History.
defined('ABSPATH') || exit;
wp_set_current_user(1);
$GLOBALS['sspa_66_failures'] = 0;
function sspa_66_check($ok, $label) {
    echo ($ok ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$ok) { $GLOBALS['sspa_66_failures']++; }
}
try {
    $fixture = WPMU_PLUGIN_DIR . '/000-sspa-php-diagnostics-fixture.php';
    wp_mkdir_p(WPMU_PLUGIN_DIR);
    file_put_contents($fixture, <<<'PHP'
<?php
// Dormant outside this case's explicit request parameter; retained for inspection.
if (empty($_GET['pair_php_diag'])) { return; }
$mode = (string) $_GET['pair_php_diag'];
if ('previous' === $mode) {
    set_error_handler(function () { $GLOBALS['sspa_66_previous_calls'] = ($GLOBALS['sspa_66_previous_calls'] ?? 0) + 1; return true; }, E_USER_NOTICE);
}
add_action('template_redirect', function () use ($mode) {
    if (!isset($GLOBALS['sspa_capture'])) { return; }
    $reporting = error_reporting(E_ALL);
    if ('replacement' === $mode) {
        set_error_handler(function () { return true; }, E_USER_WARNING);
    }
    if ('temporary' === $mode) {
        set_error_handler(function () { $GLOBALS['sspa_66_temporary_calls'] = ($GLOBALS['sspa_66_temporary_calls'] ?? 0) + 1; return true; }, E_USER_WARNING);
    }
    $count = 'many' === $mode ? 30 : ('zero' === $mode ? 0 : 1);
    for ($i = 0; $i < $count; $i++) {
        trigger_error('SSPA local diagnostic ' . $mode . ' private-fixture@example.invalid token=PRIVATE-DIAG-66 <script>alert(66)</script> ' . $i, E_USER_WARNING);
    }
    if ('temporary' === $mode) { restore_error_handler(); }
    $default_error = error_get_last();
    @trigger_error('SSPA suppressed diagnostic must not be retained', E_USER_WARNING);
    if ('previous' === $mode) { trigger_error('SSPA previous notice', E_USER_NOTICE); }
    error_reporting($reporting);
    wp_send_json(array('previous_calls' => $GLOBALS['sspa_66_previous_calls'] ?? 0, 'temporary_calls' => $GLOBALS['sspa_66_temporary_calls'] ?? 0, 'default_message' => $default_error['message'] ?? null));
}, 1);
PHP
    );
    $run = SSPA_Run_Controller::start(array('type' => 'spot', 'page_keys' => array('home'), 'user_id' => 1));
    if (is_wp_error($run)) { throw new RuntimeException($run->get_error_message()); }
    $deadline = time() + 180;
    do {
        SSPA_Run_Controller::process_batch($run);
        $status = SSPA_Run_Controller::status($run);
    } while ($status && in_array($status['status'], array('crawling', 'analysing'), true) && time() < $deadline);
    if (!$status || 'done' !== $status['status']) { throw new RuntimeException('Diagnostic fixture run did not complete'); }
    $crawler = new SSPA_Crawler();
    $samples = array();
    foreach (array('one', 'two', 'zero', 'many', 'previous', 'replacement', 'temporary') as $mode) {
        $samples[$mode] = $crawler->send_profiled(add_query_arg('pair_php_diag', $mode, home_url('/')));
        sspa_66_check(!empty($samples[$mode]['capture']), 'real ' . $mode . ' request retains its capture');
    }
    $one = $samples['one']['capture']['php_diagnostics'] ?? array();
    sspa_66_check('observer_delivery' === ($one['coverage'] ?? '') && ($one['count'] ?? 0) >= 1, 'a real page warning is delivered to the PHP observer');
    $events = array_values(array_filter($one['events'] ?? array(), function ($event) { return false !== strpos($event['message'], 'SSPA local diagnostic one'); }));
    sspa_66_check(count($events) === 1 && E_USER_WARNING === $events[0]['type'] && 'mu:000-sspa-php-diagnostics-fixture' === $events[0]['component'], 'warning type and responsible component match the real request');
    sspa_66_check(false !== strpos($samples['one']['json']['default_message'] ?? '', 'SSPA local diagnostic one'), 'the observer preserves PHP default handling and error_get_last');
    sspa_66_check(false === strpos(wp_json_encode($one), 'suppressed diagnostic'), 'suppressed warnings are not presented as reported request faults');
    $many = $samples['many']['capture']['php_diagnostics'] ?? array();
    sspa_66_check(($many['count'] ?? 0) >= 30 && count($many['events'] ?? array()) <= 20 && !empty($many['truncated']), 'request diagnostic retention is bounded and reports truncation');
    $previous = $samples['previous']['capture']['php_diagnostics'] ?? array();
    sspa_66_check(1 === ($samples['previous']['json']['previous_calls'] ?? 0) && 'existing_error_handler' === ($previous['reason'] ?? '') && null === ($previous['count'] ?? null), 'a pre-existing masked handler keeps its exact behaviour and yields unavailable coverage');
    $replacement = $samples['replacement']['capture']['php_diagnostics'] ?? array();
    sspa_66_check('handler_changed' === ($replacement['reason'] ?? ''), 'a later handler replacement is reported as a coverage limitation');
    $temporary = $samples['temporary']['capture']['php_diagnostics'] ?? array();
    sspa_66_check(1 === ($samples['temporary']['json']['temporary_calls'] ?? 0) && 'observer_delivery' === ($temporary['coverage'] ?? '') && false === strpos(wp_json_encode($temporary), 'diagnostic temporary'), 'temporary handlers retain their warnings and observer coverage never claims to include them');
    $profile = SSPA_Profile_Store::save($run, array('page_key' => 'diagnostic-pair', 'url' => home_url('/'), 'variant' => 'anon', 'blocked_by' => null, 'samples' => array($samples['one'], $samples['two'])));
    $legacy = $samples['zero'];
    unset($legacy['capture']['php_diagnostics']);
    SSPA_Profile_Store::save($run, array('page_key' => 'diagnostic-legacy', 'url' => home_url('/'), 'variant' => 'anon', 'blocked_by' => null, 'samples' => array($legacy)));
    SSPA_Profile_Store::save($run, array('page_key' => 'diagnostic-zero', 'url' => home_url('/'), 'variant' => 'anon', 'blocked_by' => null, 'samples' => array($samples['zero'])));
    $document = SSPA_History_Series::build($run);
    if (is_wp_error($document)) { throw new RuntimeException($document->get_error_message()); }
    $pages = array_column($document['pages'], null, 'page_key');
    $all_points = $pages['diagnostic-pair']['current']['points'] ?? array();
    $current_run_points = function ($points) use ($run) {
        return array_values(array_filter($points, function ($point) use ($run) { return (int) $point['run_id'] === (int) $run; }));
    };
    $points = $current_run_points($all_points);
    $profiles_by_id = array();
    foreach (array_unique(array_column($all_points, 'run_id')) as $point_run) {
        foreach (SSPA_History_Series::profile_rows($point_run) as $row) { $profiles_by_id[(int) $row['id']] = (int) $point_run; }
    }
    $identities_match = true;
    foreach ($all_points as $point) {
        $identities_match = $identities_match && ($profiles_by_id[(int) $point['profile_id']] ?? null) === (int) $point['run_id'];
    }
    sspa_66_check(count($all_points) >= 2 && $identities_match, 'all retained diagnostic points stay linked to their own saved run and profile');
    sspa_66_check(count($points) === 2 && $profile === $points[0]['profile_id'] && false !== strpos(wp_json_encode($points[0]['evidence']['php_diagnostics'] ?? array()), 'diagnostic one') && false !== strpos(wp_json_encode($points[1]['evidence']['php_diagnostics'] ?? array()), 'diagnostic two'), 'each plotted request retains its own diagnostics independently of the median capture');
    $legacy_points = $current_run_points($pages['diagnostic-legacy']['current']['points'] ?? array());
    $legacy_diagnostics = $legacy_points[0]['evidence']['php_diagnostics'] ?? array();
    sspa_66_check('unavailable' === ($legacy_diagnostics['coverage'] ?? '') && null === ($legacy_diagnostics['count'] ?? null), 'legacy samples explicitly lack diagnostic coverage instead of claiming zero warnings');
    $zero_points = $current_run_points($pages['diagnostic-zero']['current']['points'] ?? array());
    $zero = $zero_points[0]['evidence']['php_diagnostics'] ?? array();
    sspa_66_check('observer_delivery' === ($zero['coverage'] ?? '') && 0 === ($zero['count'] ?? null), 'a request delivering no diagnostics to the observer records a measured zero');
    $payload = SSPA_Community_Exporter::build($run, null, null, 'manual');
    sspa_66_check(!is_wp_error($payload), 'the real community exporter accepts the measured run');
    $json = wp_json_encode($payload);
    sspa_66_check(false === strpos($json, 'PRIVATE-DIAG-66') && false === strpos($json, 'private-fixture@example.invalid') && false === strpos($json, 'php_diagnostics'), 'local diagnostic messages and fields never enter community exports');
} catch (Throwable $error) {
    sspa_66_check(false, $error->getMessage());
}
if ($GLOBALS['sspa_66_failures']) { exit(1); }
