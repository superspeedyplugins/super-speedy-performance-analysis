<?php
// Phase 0/1 contracts: fixtures remain anonymous and the clearer immediate-analysis naming
// does not alter the existing @2 machine schema.

function sspa_tc_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

$fixture_dir = SSPA_PLUGIN_DIR . '.tests/fixtures/';
$fixtures = array(
    'cache-optimisation-analysis-v2.json' => 'sspa/shared-cache-safety-report@2',
    'traffic-performance-analysis-provisional-v1.json' => 'sspa/traffic-performance-analysis@1',
    'traffic-performance-analysis-final-v1.json' => 'sspa/traffic-performance-analysis@1',
    'cloudflare-traffic-evidence-v1.json' => 'sspa/cloudflare-traffic-evidence@1',
);
foreach ($fixtures as $file => $schema) {
    $payload = json_decode((string) file_get_contents($fixture_dir . $file), true);
    sspa_tc_t(is_array($payload) && $schema === $payload['schema'], "$file has its frozen schema");
    sspa_tc_t(!SSPA_Traffic_Privacy::validate_export($payload), "$file passes forbidden-property validation");
}

$planted = array('summary' => array('email' => 'planted@example.test', 'order_id' => 123));
$problems = SSPA_Traffic_Privacy::validate_export($planted);
sspa_tc_t(in_array('summary.email', $problems, true) && in_array('summary.order_id', $problems, true), 'forbidden properties fail closed with their exact paths');

$overview = (string) file_get_contents(SSPA_PLUGIN_DIR . 'includes/admin/tabs/overview.php');
$ajax = (string) file_get_contents(SSPA_PLUGIN_DIR . 'includes/class-sspa-cache-recon.php');
sspa_tc_t(false !== strpos($overview, 'Cache optimisation analysis') && false !== strpos($overview, 'Download cache optimisation analysis'), 'GUI uses the cache optimisation analysis name');
sspa_tc_t(false !== strpos($overview, 'No traffic collector was used'), 'immediate report states that no collector was run');
sspa_tc_t(false !== strpos($ajax, 'sspa-cache-optimisation-analysis-'), 'immediate report uses the new download filename');
sspa_tc_t(false !== strpos($ajax, 'sspa/shared-cache-safety-report@2'), 'existing cache report machine schema remains unchanged');
sspa_tc_t(class_exists('SSPA_Traffic_CLI'), 'traffic WP-CLI controller loads under WP-CLI');
