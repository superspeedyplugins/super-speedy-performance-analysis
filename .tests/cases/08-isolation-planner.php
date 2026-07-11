<?php
// Unit test: the isolation planner against synthetic cost functions - no HTTP involved.
// World: base page cost 150ms; plugin C adds 200ms, plugin E adds 80ms, others free.
// Plugin D fatals if B is excluded while D stays active (dependency).

function sspa_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

function sspa_world_measure($exclude, $costs, $dependencies) {
    // $dependencies: dependant => required. Fatal when required excluded but dependant not.
    foreach ($dependencies as $dependant => $required) {
        if (in_array($required, $exclude, true) && !in_array($dependant, $exclude, true)) {
            return array('fatal' => true, 'gen_ms' => null, 'sql_ms' => null, 'mem_bytes' => null, 'queries' => null, 'samples' => array());
        }
    }
    $gen = 150.0;
    foreach ($costs as $plugin => $cost) {
        if (!in_array($plugin, $exclude, true)) {
            $gen += $cost;
        }
    }
    return array(
        'fatal' => false,
        'gen_ms' => $gen,
        'sql_ms' => $gen * 0.5,
        'mem_bytes' => 20000000,
        'queries' => 100,
        'samples' => array($gen - 2, $gen, $gen + 2), // tight spread -> gate floors at 30ms
    );
}

function sspa_drive_planner($planner, $costs, $dependencies) {
    $guard = 0;
    while (($spec = $planner->next()) !== null && $guard++ < 200) {
        $planner->record(sspa_world_measure($spec['exclude'], $costs, $dependencies));
        // Round-trip state through JSON every step, as the real controller does.
        $planner = SSPA_Isolation_Planner::from_state(json_decode(json_encode($planner->state), true));
    }
    return $planner;
}

$costs = array('C' => 200.0, 'E' => 80.0);
$deps = array('D' => 'B');

// --- Scenario 1: single-out finds the exact cost; innocent plugin reports no impact ---
$planner = SSPA_Isolation_Planner::create(array(
    array('plugin' => 'C', 'page_key' => 'home'),
    array('plugin' => 'A', 'page_key' => 'home'),
), array());
$planner = sspa_drive_planner($planner, $costs, array());

$impacts = array();
foreach ($planner->impacts() as $i) {
    $impacts[$i['plugin']] = $i;
}
sspa_t(isset($impacts['C']) && abs($impacts['C']['delta_ttfb_ms'] - 200) < 15, 'single-out measures C at ~200ms (' . $impacts['C']['delta_ttfb_ms'] . ')');
sspa_t($impacts['C']['confidence'] === 'measured', 'C confidence measured');
sspa_t(isset($impacts['A']) && $impacts['A']['confidence'] === 'none', 'innocent A reports no measurable impact');
sspa_t($impacts['C']['noise_floor_ms'] >= 30, 'noise gate floors at 30ms (' . $impacts['C']['noise_floor_ms'] . ')');

// --- Scenario 2: bisection finds BOTH culprits among 8 plugins ---
$planner = SSPA_Isolation_Planner::create(array(), array(
    array('page_key' => 'shop', 'candidates' => array('A', 'B2', 'C', 'D2', 'E', 'F', 'G', 'H')),
));
$planner = sspa_drive_planner($planner, $costs, array());
$found = array();
foreach ($planner->impacts() as $i) {
    if ($i['confidence'] === 'measured') {
        $found[$i['plugin']] = $i['delta_ttfb_ms'];
    }
}
sspa_t(isset($found['C']) && abs($found['C'] - 200) < 15, 'bisection found C (~200ms: ' . ($found['C'] ?? 'missing') . ')');
sspa_t(isset($found['E']) && abs($found['E'] - 80) < 15, 'bisection found E (~80ms: ' . ($found['E'] ?? 'missing') . ')');
sspa_t(count($found) === 2, 'no false culprits (' . implode(',', array_keys($found)) . ')');
sspa_t($planner->done_count() < 20, 'bisection efficient: ' . $planner->done_count() . ' measurements for 8 candidates');

// --- Scenario 3: fatal dependency recovery - B/D pair must not sink the search ---
$planner = SSPA_Isolation_Planner::create(array(), array(
    array('page_key' => 'shop', 'candidates' => array('A', 'B', 'C', 'D', 'E', 'F')),
));
$planner = sspa_drive_planner($planner, $costs, $deps);
$found = array();
foreach ($planner->impacts() as $i) {
    if ($i['confidence'] === 'measured') {
        $found[$i['plugin']] = $i['delta_ttfb_ms'];
    }
}
sspa_t(isset($found['C']), 'C still found despite fatal-prone B/D pair');
sspa_t(isset($found['E']), 'E still found despite fatal-prone B/D pair');
$unresolved_plugins = array();
foreach ($planner->unresolved() as $u) {
    if (isset($u['plugin'])) {
        $unresolved_plugins[] = $u['plugin'];
    }
}
sspa_t(!isset($found['B']), 'B not falsely blamed');
sspa_t($planner->truncated() === false, 'run completed within budget');

// --- Scenario 4: budget cap truncates gracefully ---
$planner = SSPA_Isolation_Planner::create(array(), array(
    array('page_key' => 'shop', 'candidates' => array('A', 'B2', 'C', 'D2', 'E', 'F', 'G', 'H')),
), 3);
$planner = sspa_drive_planner($planner, $costs, array());
sspa_t($planner->done_count() <= 3, 'budget respected (' . $planner->done_count() . ')');
sspa_t($planner->truncated() === true, 'truncation flagged');

// --- Scenario 5: fatal baseline page aborts its tasks without wedging ---
$planner = SSPA_Isolation_Planner::create(array(array('plugin' => 'C', 'page_key' => 'brokenpage')), array());
$spec = $planner->next();
sspa_t($spec['kind'] === 'baseline', 'baseline measured first');
$planner->record(array('fatal' => true, 'gen_ms' => null, 'sql_ms' => null, 'mem_bytes' => null, 'queries' => null, 'samples' => array()));
sspa_t($planner->next() === null, 'fatal baseline ends work on that page');
sspa_t(count($planner->unresolved()) === 1, 'page fatal recorded as unresolved');
