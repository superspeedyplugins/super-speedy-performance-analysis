<?php
// Scan-free wire-format regression: component-state maps are objects; setting lists stay lists.
if (!class_exists('SSPA_Community_Exporter')) {
    foreach (array('privacy', 'schema', 'state', 'exporter') as $name) {
        require_once dirname(__DIR__, 2) . '/includes/community/class-sspa-community-' . $name . '.php';
    }
}
$publisher = function () {
    return array(array(
        'component' => array('type' => 'plugin', 'slug' => 'sspa-shape-fixture'),
        'summary' => array(),
        'options' => array('enabled' => true, 'columns' => array(), 'modes' => array('warm', 'cold')),
        'state' => array(0 => 'invalid-map-key', 'endpoint' => 'https://private.example.invalid/secret'),
    ));
};
add_filter('sspa_component_state', $publisher, PHP_INT_MAX);
$records = SSPA_Community_State::collect(array('run_type' => 'spot', 'trigger' => 'cli'));
remove_filter('sspa_component_state', $publisher, PHP_INT_MAX);
$method = new ReflectionMethod('SSPA_Community_Exporter', 'add_component_state');
$method->setAccessible(true);
$failed = 0;
$check = function ($ok, $label) use (&$failed) {
    echo ($ok ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$ok) { $failed++; }
};
foreach (array('captured' => $records, 'omitted' => array(array('component' => array('type' => 'plugin', 'slug' => 'sspa-shape-fixture')))) as $case => $state) {
    $evidence = array();
    $run = array('share_context' => wp_json_encode(array('component_state' => $state)));
    $args = array(&$evidence, $run, 1, wp_generate_uuid4(), array());
    $method->invokeArgs(null, $args);
    $json = SSPA_Community_Schema::encode(array('evidence' => $evidence));
    $data = json_decode($json)->evidence[0]->data;
    foreach (array('summary', 'options', 'state') as $map) {
        $check(is_object($data->$map), $case . ' ' . $map . ' encodes as a JSON object');
    }
    if ('captured' === $case) {
        $check(true === $data->options->enabled, 'nonempty maps preserve scalar settings');
        $check(array() === $data->options->columns && array('warm', 'cold') === $data->options->modes, 'empty and nonempty setting lists remain JSON arrays');
        $check(2 === $data->omitted_keys && false === strpos($json, 'private.example.invalid'), 'numeric map key and private value are rejected with omissions counted');
    }
    $check(!is_wp_error(SSPA_Community_Privacy::validate(json_decode($json))), $case . ' encoded object passes unchanged privacy validation');
}
if ($failed) { WP_CLI::error($failed . ' component-state wire assertions failed.'); }
