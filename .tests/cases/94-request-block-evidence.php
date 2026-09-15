<?php
// Real signed responses prove that challenge text is not evidence of blocking.
defined('ABSPATH') || exit;
wp_set_current_user(1);
$failures = 0;
$check = function ($condition, $message) use (&$failures) {
    echo ($condition ? 'PASS: ' : 'FAIL: ') . $message . "\n";
    if (!$condition) { $failures++; }
};
file_put_contents(WPMU_PLUGIN_DIR . '/000-sspa-block-evidence-fixture.php', <<<'PHP'
<?php
if (empty($_GET['sspa_block_fixture'])) { return; }
add_action('template_redirect', function () {
    if (!isset($GLOBALS['sspa_capture'])) { return; }
    if ('denied' === $_GET['sspa_block_fixture']) { status_header(403); }
    else { status_header(200); }
    if (in_array($_GET['sspa_block_fixture'], array('passed', 'challenge'), true)) { header('CF-Mitigated: challenge'); }
    if ('challenge' === $_GET['sspa_block_fixture']) { header_remove('X-SSPA-Profiled'); }
    header('Content-Type: text/html');
    echo '<html><body>A normal article discussing cf-challenge and checking your browser.</body></html>';
    exit;
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
if (!$status || 'done' !== $status['status']) { throw new RuntimeException('Fixture run did not finish'); }
$crawler = new SSPA_Crawler();
$good = $crawler->send_profiled(add_query_arg('sspa_block_fixture', 'success', home_url('/')));
$bad = $crawler->send_profiled(add_query_arg('sspa_block_fixture', 'denied', home_url('/')));
$passed = $crawler->send_profiled(add_query_arg('sspa_block_fixture', 'passed', home_url('/')));
$challenge = $crawler->send_profiled(add_query_arg('sspa_block_fixture', 'challenge', home_url('/')));
$check(empty($passed['blocked_by']) && !empty($passed['capture']), 'Successful signed profiling proves the request passed despite challenge metadata');
$check(!empty($challenge['blocked_by']) && 'Security challenge response (CF-Mitigated: challenge)' === ($challenge['blocked_reason'] ?? null), 'Unpassed explicit challenge retains the observed header reason');
$check(200 === $good['code'], 'Challenge text fixture really returned HTTP 200');
$check(empty($good['blocked_by']) && empty($good['error']) && !empty($good['capture']['overview']), 'HTTP 200 with challenge words retains its real profiling capture and is not blocked');
$check(403 === $bad['code'] && !empty($bad['blocked_by']), 'Actual HTTP 403 remains unsuccessful');
$check('HTTP 403' === ($bad['blocked_reason'] ?? null), 'The denied sample explains its exact HTTP trigger');
$check(null === SSPA_Security_Detect::classify_detail(200, array(), 'cf-challenge checking your browser', false), 'Body strings alone cannot declare a block');
$check(null !== SSPA_Security_Detect::classify_detail(200, array('cf-mitigated' => 'challenge'), '', false), 'An explicit unpassed challenge header is recognised');
$profile = SSPA_Profile_Store::save($run, array('page_key' => 'block-mixed', 'url' => home_url('/'), 'variant' => 'anon', 'blocked_by' => $bad['blocked_by'], 'samples' => array($bad, $good)));
$legacy = $good;
unset($legacy['blocked_by'], $legacy['blocked_reason'], $legacy['blocked_confidence']);
$legacy_profile = SSPA_Profile_Store::save($run, array('page_key' => 'block-legacy', 'url' => home_url('/'), 'variant' => 'anon', 'blocked_by' => 'unknown security layer', 'samples' => array($legacy)));
$doc = SSPA_History_Series::build($run, 'request_wall_ms');
$points = array();
foreach ($doc['pages'] as $page) { foreach ($page['current']['points'] as $point) { if ((int) $point['profile_id'] === $profile) { $points[$point['sample']] = $point; } } }
$check(isset($points[2]) && null === $points[2]['state'], 'Successful sample does not inherit another sample block');
$check('blocked' === ($points[1]['state'] ?? null) && 'HTTP 403' === ($points[1]['evidence']['blocked_reason'] ?? null), 'History retains the failed sample reason');
$doc = SSPA_History_Series::build($run, 'request_wall_ms');
$legacy_points = array();
foreach ($doc['pages'] as $page) { foreach ($page['current']['points'] as $point) { if ((int) $point['profile_id'] === $legacy_profile) { $legacy_points[] = $point; } } }
$check(count($legacy_points) === 1 && null === $legacy_points[0]['state'], 'Legacy HTTP 200 does not inherit an unexplained page block');
$notes = new ReflectionMethod('SSPA_Profile_Panel', 'notes_html');
foreach (SSPA_History_Series::profile_rows($run) as $row) {
    if ((int) $row['id'] === $profile) {
        $html = $notes->invoke(null, $row, null);
        $check(false !== strpos($html, 'Request 1 was unsuccessful: HTTP 403') && false === strpos($html, 'Request 2 was unsuccessful'), 'Profile panel identifies only the failed attempt and its reason');
    }
    if ((int) $row['id'] === $legacy_profile) {
        $html = $notes->invoke(null, $row, null);
        $check(false !== strpos($html, 'did not record which request or why') && false === strpos($html, 'Blocked by'), 'Old profile flags show missing evidence instead of an unsupported block verdict');
    }
}
if ($failures) { throw new RuntimeException($failures . ' block-evidence failures'); }
