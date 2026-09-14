<?php
// The History comparison export is assembled field by field, never by serialising internal
// rows, so nothing planted in the stored inputs can cross the export boundary. This case
// plants unexpected nested fields through the real storage paths - a profile saved via
// SSPA_Profile_Store with foreign keys inside its samples, and a run whose share_context
// carries foreign keys - then holds every level of the JSON export, the real CLI output and
// the Abilities result to an exact declared key set. A future field that leaks or that is
// added without being declared here fails this case.
require __DIR__ . '/../lib/fleet.php';

function sspa_84_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
    if (!$ok) { $GLOBALS['sspa_84_failures']++; }
}
$GLOBALS['sspa_84_failures'] = 0;
function sspa_84_run() {
    $id = SSPA_Run_Controller::start(array('type' => 'spot', 'page_keys' => array('home'), 'user_id' => 1));
    if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
    $deadline = time() + 180;
    do {
        SSPA_Run_Controller::process_batch($id);
        $status = SSPA_Run_Controller::status($id);
    } while ($status && in_array($status['status'], array('crawling', 'analysing'), true) && time() < $deadline);
    if (!$status || 'done' !== $status['status']) { throw new RuntimeException('Allowlist fixture run did not complete'); }
    return (int) $id;
}
function sspa_84_keys($array) { $k = array_keys((array) $array); sort($k); return $k; }
function sspa_84_same_keys($array, $expected, $label) {
    $expected = (array) $expected; sort($expected);
    sspa_84_t(sspa_84_keys($array) === $expected, $label . ' (' . implode(',', sspa_84_keys($array)) . ')');
}

wp_set_current_user(1);
global $wpdb;
$markers = array('SSPA84_RAW_BODY', 'SSPA84_SQL_LITERAL', 'SSPA84_ACCOUNT_ID', 'SSPA84_NESTED_INTERNAL', 'SSPA84_CONTEXT_LEAK');

try {
    $before = sspa_84_run();
    $after = sspa_84_run();

    // Plant through the real profile store: a page whose samples carry foreign nested fields.
    $planted_profile = SSPA_Profile_Store::save($after, array(
        'page_key' => 'allowlist-probe',
        'url' => home_url('/?sspa84=1'),
        'variant' => 'anon',
        'blocked_by' => null,
        'samples' => array(array(
            'wall_ms' => 120, 'code' => 200, 'error' => null, 'cached' => false,
            'raw_body' => '<html>SSPA84_RAW_BODY</html>',
            'sql' => "SELECT * FROM wp_users WHERE user_email = 'SSPA84_SQL_LITERAL'",
            'order_id' => 'SSPA84_ACCOUNT_ID',
            'internal' => array('trace' => array('SSPA84_NESTED_INTERNAL')),
        )),
    ));
    sspa_84_t($planted_profile > 0, 'a real profile with foreign nested sample fields is stored');

    // Plant through the run row: share_context is where History reads its run context from.
    $context = array(
        'history_comparison' => array(
            'baseline_run_id' => $before,
            'leak' => 'SSPA84_CONTEXT_LEAK',
            'change_set' => array('id' => wp_generate_uuid4(), 'changes' => array(array('slug' => 'probe', 'action' => 'updated', 'from_version' => '1.0', 'to_version' => '1.1', 'secret' => 'SSPA84_CONTEXT_LEAK'))),
        ),
        'unrelated' => 'SSPA84_CONTEXT_LEAK',
    );
    $wpdb->update(SSPA_Schema::table('runs'), array('share_context' => wp_json_encode($context)), array('id' => $after));

    $comparison = SSPA_History::compare($before, $after);
    sspa_84_t(!is_wp_error($comparison), 'the comparison builds' . (is_wp_error($comparison) ? ' (' . $comparison->get_error_message() . ')' : ''));
    $export = SSPA_History::export($comparison);
    $json = wp_json_encode($export);
    foreach ($markers as $marker) {
        sspa_84_t(false === strpos($json, $marker), 'planted value never reaches the export: ' . $marker);
    }

    // Exact declared field sets, level by level. Adding a field means declaring it here.
    sspa_84_same_keys($export, array('schema', 'generated_at', 'source_id', 'comparison'), 'export top level is exactly the declared set');
    sspa_84_t(SSPA_History::EXPORT_SCHEMA === $export['schema'], 'the export names its versioned schema (' . $export['schema'] . ')');
    sspa_84_same_keys($export['comparison'], array('schema', 'status', 'before', 'after', 'setup_changes_available', 'setup_changes', 'configuration_changes', 'headline', 'new_diagnostics', 'summary', 'pages'), 'comparison level is exactly the declared set');
    foreach (array('before', 'after') as $side) {
        sspa_84_same_keys($export['comparison'][$side], array('id', 'uuid', 'type', 'status', 'measurement_version', 'trigger', 'started', 'finished', 'components', 'component_state', 'history_context'), $side . ' identity is exactly the declared set');
        foreach ($export['comparison'][$side]['components'] as $component) {
            sspa_84_same_keys($component, array('type', 'slug', 'version'), $side . ' component entry is exactly the declared set');
            break;
        }
    }
    $after_context = $export['comparison']['after']['history_context'];
    sspa_84_same_keys($after_context, array('baseline_run_id', 'change_set'), 'run context keeps only its declared keys');
    sspa_84_same_keys($after_context['change_set'], array('id', 'first_detected_at', 'last_detected_at', 'changes'), 'change set keeps only its declared keys');
    sspa_84_same_keys($after_context['change_set']['changes'][0], array('slug', 'action', 'from_version', 'to_version'), 'a change record keeps only its declared keys');
    sspa_84_same_keys($export['comparison']['summary'], array('pages', 'headline_pages', 'output_changes', 'failed_validity_cases', 'failed_declared_cases'), 'summary is exactly the declared set');
    $probe = null;
    foreach ($export['comparison']['pages'] as $page) {
        if ('allowlist-probe' === $page['page_key']) { $probe = $page; }
    }
    sspa_84_t(is_array($probe), 'the planted page is present in the comparison');
    if ($probe) {
        sspa_84_same_keys($probe, array('key', 'page_key', 'variant', 'method', 'object_cache_mode', 'present', 'validity', 'response_code', 'metrics', 'output', 'declared'), 'a page entry is exactly the declared set');
        sspa_84_same_keys($probe['metrics'], array('generation_ms', 'ttfb_ms', 'sql_ms', 'sql_count', 'rows_fetched', 'http_ms', 'php_ms', 'peak_mem_bytes', 'duplicate_queries', 'mail_count'), 'page metrics are exactly the declared set');
        sspa_84_same_keys($probe['metrics']['generation_ms'], array('before', 'after', 'delta', 'percent', 'direction', 'unit'), 'a metric delta is exactly the declared set');
        sspa_84_same_keys($probe['declared'], array('state', 'expected_signature', 'source_run_uuid', 'signature_state', 'response_code'), 'a declared block is exactly the declared set');
    }

    // The real CLI and the Abilities callback hand out the same document.
    $cli = json_decode(sspa_fleet_cli(array('sspa', 'history-compare', (string) $before, (string) $after)), true);
    sspa_84_t(is_array($cli) && sspa_84_keys($cli) === sspa_84_keys($export) && false === strpos(wp_json_encode($cli), 'SSPA84_'), 'the real CLI output has the same top-level set and no planted value');
    $ability = SSPA_Abilities::exec_compare_history(array('before_run_id' => $before, 'after_run_id' => $after));
    sspa_84_t(is_array($ability) && sspa_84_keys($ability) === sspa_84_keys($export) && false === strpos(wp_json_encode($ability), 'SSPA84_'), 'the Abilities result has the same top-level set and no planted value');
} catch (Throwable $error) {
    sspa_84_t(false, $error->getMessage());
}

if ($GLOBALS['sspa_84_failures']) { exit(1); }
