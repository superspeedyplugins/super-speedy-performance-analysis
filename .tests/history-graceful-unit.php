<?php
// No database/server required. Only WordPress/storage adapters are substituted;
// all selection, inventory, point and fault logic is the production implementation.
define('ABSPATH', __DIR__);
define('ARRAY_A', 'ARRAY_A');
function __($s, $domain = '') { return $s; }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s)); }
function sanitize_text_field($s) { return strip_tags((string) $s); }
function wp_json_encode($v) { return json_encode($v); }
function wp_list_pluck($rows, $key) { return array_column($rows, $key); }
function wp_parse_url($url) { return parse_url($url); }
function is_wp_error($value) { return $value instanceof WP_Error; }
class WP_Error { private $message; public function __construct($code, $message) { $this->message = $message; } public function get_error_message() { return $this->message; } }
class SSPA_Schema { public static function table($name) { return 'sspa_' . $name; } }
class SSPA_Catalogue { public static function build() { return array(); } }
$code_root = getenv('SSPA_HISTORY_TEST_ROOT') ?: dirname(__DIR__);
require $code_root . '/includes/class-sspa-run-controller.php';
require $code_root . '/includes/class-sspa-profile-store.php';
require $code_root . '/includes/community/class-sspa-community-schema.php';
require $code_root . '/includes/class-sspa-history-series.php';
class HistoryFixtureStorage {
    public $runs = array();
    public $profiles = array();
    public $environments = array(1 => array('php' => '8.1'));
    public function prepare($sql, ...$args) { foreach ($args as $arg) { $sql = preg_replace('/%[id]/', (string) $arg, $sql, 1); } return $sql; }
    public function get_row($sql, $format) { preg_match('/WHERE id = (\d+)/', $sql, $m); return strpos($sql, 'sspa_runs') !== false ? ($this->runs[(int) ($m[1] ?? 0)] ?? null) : array('metrics' => json_encode($this->environments[(int) ($m[1] ?? 0)] ?? null)); }
    public function get_results($sql, $format) {
        if (strpos($sql, 'sspa_runs') !== false) { $rows = array_reverse(array_values($this->runs)); return strpos($sql, 'LIMIT 50') !== false ? array_slice($rows, 0, 50) : $rows; }
        preg_match('/run_id = (\d+)/', $sql, $m);
        $rows = $this->profiles[(int) ($m[1] ?? 0)] ?? array();
        return strpos($sql, "plugin_set_hash = ''") !== false ? array_values(array_filter($rows, function ($p) { return $p['plugin_set_hash'] === ''; })) : $rows;
    }
}
$wpdb = new HistoryFixtureStorage();
for ($id = 1; $id <= 60; $id++) {
    $wpdb->runs[$id] = array('id' => $id, 'status' => 'done', 'run_type' => 'spot', 'measurement_version' => 1,
        'plugin_set' => json_encode(array('components' => array(array('type' => 'plugin', 'slug' => 'custom-fixture', 'version' => '1.0')))),
        'site_metrics_id' => 1, 'share_context' => '{}', 'started' => '2026-01-01 00:00:00', 'finished' => '2026-01-01 00:01:00');
    $wpdb->profiles[$id] = array(array('id' => $id, 'page_key' => 'home', 'url' => 'https://example.test/', 'method' => 'GET', 'variant' => 'anon', 'object_cache_mode' => 'normal',
        'plugin_set_hash' => '', 'samples' => json_encode(array(array('wall_ms' => 123, 'code' => 200))), 'page_gen_ms' => 123, 'response_code' => 200, 'blocked_by' => null));
}
$cases = array(
    'unknown plugin version and absent environment' => array(60, 59),
    'old run outside latest fifty' => array(60, 1),
    'same run on both sides' => array(60, 60),
    'legacy measurement format and empty inventory' => array(58, 57),
    'failed and experiment run types' => array(56, 55),
    'disjoint page scenarios' => array(54, 53),
    'error timings and missing values' => array(52, 51),
    'test configuration profiles' => array(50, 49),
);
$wpdb->runs[58]['measurement_version'] = 0;
$wpdb->runs[59]['plugin_set'] = json_encode(array('components' => array(array('type' => 'plugin', 'slug' => 'custom-fixture', 'version' => null))));
$wpdb->runs[59]['site_metrics_id'] = 0;
$wpdb->runs[58]['plugin_set'] = '[]';
$wpdb->runs[57]['plugin_set'] = '{broken';
$wpdb->runs[56]['status'] = 'failed';
$wpdb->runs[55]['run_type'] = 'deep';
$wpdb->profiles[54][0]['page_key'] = 'shop';
$wpdb->profiles[52][0]['samples'] = json_encode(array(array('wall_ms' => 450, 'code' => 500), array('code' => 200), array('wall_ms' => 0, 'code' => 200), array('wall_ms' => 'bad', 'code' => 200)));
$wpdb->profiles[50][] = array_merge($wpdb->profiles[50][0], array('id' => 500, 'plugin_set_hash' => 'experiment-a', 'samples' => json_encode(array(array('wall_ms' => 77, 'code' => 200)))));
foreach (array('wp', 'php', 'mysql', 'db_family', 'object_cache', 'object_cache_category', 'page_cache', 'hpos', 'checkout_type', 'multisite', 'locale', 'environment_type') as $index => $key) {
    $id = 100 + $index;
    $wpdb->runs[$id] = array_merge($wpdb->runs[60], array('id' => $id, 'site_metrics_id' => $id));
    $wpdb->profiles[$id] = array(array_merge($wpdb->profiles[60][0], array('id' => $id)));
    $wpdb->environments[$id] = array_merge($wpdb->environments[1], array($key => 'changed'));
    $cases['changed ' . $key] = array($id, 60);
}
$failures = 0;
foreach ($cases as $label => $ids) {
    try {
        $doc = SSPA_History_Series::build($ids[0], 'request_wall_ms', $ids[1], 'pair');
        if (is_wp_error($doc)) { throw new RuntimeException($doc->get_error_message()); }
        if ($doc['current']['run_ids'] !== array($ids[0]) || $doc['previous']['run_ids'] !== array($ids[1])) { throw new RuntimeException('selected runs were substituted'); }
        if (!$doc['pages']) { throw new RuntimeException('measurements were dropped'); }
        if ($ids[0] === 52) {
            $values = array_column($doc['pages'][0]['current']['points'], 'value');
            $faults = $doc['pages'][0]['current']['faults'];
            if ($values !== array(450.0, 0.0) || count($faults) !== 2) { throw new RuntimeException('numeric error/zero measurements or missing-value behaviour incorrect'); }
        }
        if ($ids[0] === 50 && count($doc['pages']) !== 2) { throw new RuntimeException('experiment configuration discarded or merged into full setup'); }
        echo 'PASS: ' . $label . "\n";
    } catch (Throwable $e) { $failures++; echo 'FAIL: ' . $label . ': ' . $e->getMessage() . "\n"; }
}
exit($failures ? 1 : 0);
