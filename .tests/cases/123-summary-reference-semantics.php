<?php
require SSPA_PLUGIN_DIR . '.tests/lib/fleet.php';
wp_mkdir_p(WPMU_PLUGIN_DIR);
wp_mkdir_p(WPMU_PLUGIN_DIR . '/sspa-summary-classes');
file_put_contents(WPMU_PLUGIN_DIR . '/sspa-summary-classes/lazy.php', <<<'LAZY'
<?php
if (empty($GLOBALS['sspa_summary_lazy_ready'])) throw new RuntimeException('Class was autoloaded before the earlier callback ran');
class SSPA_Summary_Lazy { public static function handle() { $GLOBALS['sspa_summary_lazy_done'] = true; } }
LAZY
);
file_put_contents(WPMU_PLUGIN_DIR . '/sspa-reference-fixture.php', <<<'FIXTURE'
<?php
// Deliberate autoload fault belongs only to this fixture's request.
if (!isset($_GET['summary_reference'])) return;
class SSPA_Summary_Static { public static function slow() { usleep(20000); } }
add_action('sspa_summary_static', 'SSPA_Summary_Static::slow');
add_action('woocommerce_summary_boot_fixture', 'SSPA_Summary_Static::slow');
add_action('init', function () { if (isset($_GET['summary_reference'])) do_action('woocommerce_summary_boot_fixture'); });
spl_autoload_register(function ($name) { if ($name === 'SSPA_Summary_Lazy') require WPMU_PLUGIN_DIR . '/sspa-summary-classes/lazy.php'; });
add_action('sspa_summary_lazy', function () { $GLOBALS['sspa_summary_lazy_ready'] = true; }, 1);
add_action('sspa_summary_lazy', array('SSPA_Summary_Lazy', 'handle'), 20);
function sspa_summary_reference_callback(&$value) { $value .= '-changed'; }
add_action('sspa_summary_reference', 'sspa_summary_reference_callback');
add_action('template_redirect', function () {
    if (!isset($_GET['summary_reference'])) return;
    $value = 'original';
    do_action_ref_array('sspa_summary_reference', array(&$value));
    do_action('sspa_summary_static');
    do_action('sspa_summary_lazy');
    header('Content-Type: text/plain');
    echo $value . (!empty($GLOBALS['sspa_summary_lazy_done']) ? '-lazy' : '-missing');
    exit;
});
FIXTURE
);
sleep(3);
$url = home_url('/?summary_reference=1');
$normal = SSPA_Crawler::request($url);
sspa_fleet_assert(!is_wp_error($normal) && wp_remote_retrieve_body($normal) === 'original-changed-lazy', 'real reference callback changes caller data without profiling');
$token = SSPA_Token::mint($url, array('v' => 'anon', 'ah' => 'all'));
$started = microtime(true);
$profiled = SSPA_Crawler::request($url, array('headers' => array(SSPA_Token::HEADER => $token['header'])));
sspa_fleet_assert(!is_wp_error($profiled) && wp_remote_retrieve_body($profiled) === 'original-changed-lazy', 'all-hook profiling preserves reference arguments and normal callback/autoload ordering');
if (is_wp_error($profiled)) throw new RuntimeException($profiled->get_error_message());
$headers = array();
foreach (wp_remote_retrieve_headers($profiled) as $key => $value) $headers[strtolower($key)] = $value;
$sample = SSPA_Crawler::evaluate_sample(array('wall_ms' => (microtime(true) - $started) * 1000, 'code' => wp_remote_retrieve_response_code($profiled), 'headers' => $headers, 'body' => wp_remote_retrieve_body($profiled), 'error' => null, 'cookies_present' => false), $token['id'], array('v' => 'anon', 'ah' => 'all'));
file_put_contents(SSPA_PLUGIN_DIR . '.data/summary/reference-semantics-capture.json', wp_json_encode($sample, JSON_PRETTY_PRINT));
$callbacks = isset($sample['capture']['boot']['top_callbacks']) ? $sample['capture']['boot']['top_callbacks'] : array();
$static = array_values(array_filter($callbacks, static function ($entry) { return $entry['label'] === 'SSPA_Summary_Static::slow' && $entry['hook'] === 'sspa_summary_static'; }));
sspa_fleet_assert(count($static) === 1 && $static[0]['ms'] >= 18 && !empty($static[0]['file']) && !empty($static[0]['line']), 'valid static string callback has measured time and a source location');
$render = isset($sample['capture']['boot']['render']['top']) ? $sample['capture']['boot']['render']['top'] : array();
$boot_in_render = array_filter($render, static function ($entry) { return $entry['hook'] === 'woocommerce_summary_boot_fixture'; });
sspa_fleet_assert(!$boot_in_render, 'a WooCommerce-prefixed hook executed during init is not charged to render');
sspa_fleet_done();
