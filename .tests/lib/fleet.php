<?php
require_once __DIR__ . '/native-guard.php';
// Shared assertions and real subprocess boundary for the feature regressions.
$GLOBALS['sspa_fleet_pass'] = 0;
$GLOBALS['sspa_fleet_fail'] = 0;
function sspa_fleet_assert($condition, $label) {
    $GLOBALS[$condition ? 'sspa_fleet_pass' : 'sspa_fleet_fail']++;
    echo ($condition ? 'PASS: ' : 'FAIL: ') . $label . "\n";
}
function sspa_fleet_done() {
    echo 'Feature regression: ' . $GLOBALS['sspa_fleet_pass'] . ' passed, ' . $GLOBALS['sspa_fleet_fail'] . " failed\n";
}
function sspa_fleet_cli($args, $expected = 0) {
    $command = array_merge(array('wp'), $args);
    $process = proc_open($command, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start real WP subprocess');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($process);
    sspa_fleet_assert($exit === $expected, implode(' ', $args) . ' exits ' . $expected . ($exit !== $expected ? ': ' . $error : ''));
    return trim($output);
}
function sspa_fleet_complete($id) {
    $deadline = time() + 180;
    do {
        SSPA_Run_Controller::process_batch($id);
        $state = SSPA_Run_Controller::status($id);
    } while ($state && in_array($state['status'], array('queued', 'crawling', 'analysing'), true) && time() < $deadline);
    sspa_fleet_assert($state && $state['status'] === 'done', 'real run ' . $id . ' completes with captured evidence');
    return $state;
}
