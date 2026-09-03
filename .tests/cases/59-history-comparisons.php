<?php
// Performance History drives the real option system, update-hook recorder, run controller,
// crawler, report contracts and History renderer. The retained fixture changes the response
// itself; the test does not copy the comparison calculation it claims to verify.

defined('ABSPATH') || exit;

$GLOBALS['sspa_history_failures'] = 0;
function sspa_history_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
    if (!$ok) {
        $GLOBALS['sspa_history_failures']++;
    }
}

function sspa_history_drive_run($args) {
    $run_id = SSPA_Run_Controller::start($args);
    if (is_wp_error($run_id)) {
        return $run_id;
    }
    $deadline = time() + 240;
    do {
        SSPA_Run_Controller::process_batch($run_id);
        $status = SSPA_Run_Controller::status($run_id);
    } while ($status && in_array($status['status'], array('crawling', 'analysing'), true) && time() < $deadline);
    return $status && 'done' === $status['status'] ? (int) $run_id : new WP_Error(
        'sspa_history_run_failed',
        $status ? 'run ended ' . $status['status'] : 'run status disappeared'
    );
}

// Reset this case's own state on entry. The site and completed runs remain afterwards.
SSPA_Change_Set::dismiss();
delete_option(SSPA_History::ASSERTIONS_OPTION);
$active_run = SSPA_Run_Controller::active_run_id();
if ($active_run) {
    SSPA_Run_Controller::cancel($active_run);
    SSPA_Run_Controller::process_batch($active_run);
}

// New defaults merge into an old options row without overwriting any saved setting.
$original_options = get_option('sspa_options', array());
update_option('sspa_options', array('blob_retention_runs' => 9));
sspa_history_t(true === sspa_get_option('plugin_update_detection'), 'an existing options row inherits update detection enabled');
sspa_update_option('remove_data_on_uninstall', true);
$merged = get_option('sspa_options', array());
sspa_history_t(9 === (int) $merged['blob_retention_runs'], 'saving an unrelated setting preserves the existing options row');
sspa_update_option('plugin_update_detection', false);
sspa_update_option('blob_retention_runs', 7);
sspa_history_t(false === sspa_get_option('plugin_update_detection'), 'an explicit disabled value survives unrelated saves');
sspa_update_option('plugin_update_detection', true);

// Drive the real updater hook pair against a real plugin header in this retained site.
$update_fixture_dir = WP_PLUGIN_DIR . '/sspa-history-update-fixture';
wp_mkdir_p($update_fixture_dir);
$update_fixture = $update_fixture_dir . '/sspa-history-update-fixture.php';
file_put_contents($update_fixture, "<?php\n/**\n * Plugin Name: SSPA History Update Fixture\n * Version: 1.0.0\n */\n");
SSPA_Change_Set::before_update(true, array(
    'type' => 'plugin',
    'action' => 'update',
    'plugin' => 'sspa-history-update-fixture/sspa-history-update-fixture.php',
));
file_put_contents($update_fixture, "<?php\n/**\n * Plugin Name: SSPA History Update Fixture\n * Version: 1.1.0\n */\n");
SSPA_Change_Set::after_update(null, array(
    'type' => 'plugin',
    'action' => 'update',
    'plugin' => 'sspa-history-update-fixture/sspa-history-update-fixture.php',
));
$changes = SSPA_Change_Set::pending(true);
$fixture_change = isset($changes['changes']['sspa-history-update-fixture'])
    ? $changes['changes']['sspa-history-update-fixture'] : array();
sspa_history_t('1.0.0' === (isset($fixture_change['from_version']) ? $fixture_change['from_version'] : ''), 'updater pre-install captures the installed before version');
sspa_history_t('1.1.0' === (isset($fixture_change['to_version']) ? $fixture_change['to_version'] : ''), 'upgrader completion captures the installed after version');

$bulk_fixture_dir = WP_PLUGIN_DIR . '/sspa-history-bulk-fixture';
wp_mkdir_p($bulk_fixture_dir);
$bulk_fixture = $bulk_fixture_dir . '/sspa-history-bulk-fixture.php';
file_put_contents($bulk_fixture, "<?php\n/**\n * Plugin Name: SSPA History Bulk Fixture\n * Version: 2.0.0\n */\n");
SSPA_Change_Set::before_update(true, array(
    'type' => 'plugin',
    'action' => 'update',
    'plugins' => array(
        'sspa-history-update-fixture/sspa-history-update-fixture.php',
        'sspa-history-bulk-fixture/sspa-history-bulk-fixture.php',
    ),
));
file_put_contents($update_fixture, "<?php\n/**\n * Plugin Name: SSPA History Update Fixture\n * Version: 1.2.0\n */\n");
file_put_contents($bulk_fixture, "<?php\n/**\n * Plugin Name: SSPA History Bulk Fixture\n * Version: 2.1.0\n */\n");
SSPA_Change_Set::after_update(null, array(
    'type' => 'plugin',
    'action' => 'update',
    'plugins' => array(
        'sspa-history-update-fixture/sspa-history-update-fixture.php',
        'sspa-history-bulk-fixture/sspa-history-bulk-fixture.php',
    ),
));
$changes = SSPA_Change_Set::pending(true);
sspa_history_t(2 === count($changes['changes']), 'consecutive plugin changes coalesce into one bounded change set');
sspa_history_t(
    '1.0.0' === $changes['changes']['sspa-history-update-fixture']['from_version']
        && '1.2.0' === $changes['changes']['sspa-history-update-fixture']['to_version']
        && 2 === count($changes['changes']['sspa-history-update-fixture']['events']),
    'a repeated update keeps the first before version, latest after version and both events'
);
sspa_history_t(
    '2.0.0' === $changes['changes']['sspa-history-bulk-fixture']['from_version']
        && '2.1.0' === $changes['changes']['sspa-history-bulk-fixture']['to_version'],
    'the documented bulk-updater hook shape captures each plugin version transition'
);
$before_self = $changes;
SSPA_Change_Set::record('super-speedy-performance-analysis/super-speedy-performance-analysis.php', 'updated', '0.35.21', '0.35.22');
sspa_history_t($before_self === SSPA_Change_Set::pending(true), 'the plugin does not prompt for its own update');
$change_set_id = $changes['id'];
sspa_history_t(SSPA_Change_Set::snooze($change_set_id, 120) && null === SSPA_Change_Set::pending(), 'Remind me later hides but retains the pending change set');
sspa_history_t($change_set_id === SSPA_Change_Set::pending(true)['id'], 'the snoozed change set remains available to an explicit quick comparison');
sspa_history_t(
    false === SSPA_Change_Set::dismiss(wp_generate_uuid4()) && $change_set_id === SSPA_Change_Set::pending(true)['id'],
    'a stale or forged dismissal cannot clear the pending change set'
);

// Keep one harmless header-only plugin active across the two runs so the comparison
// proves that exact setup/version changes are visible as well as measurements.
if (!function_exists('activate_plugin')) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
sspa_update_option('plugin_update_detection', false);
$activation = activate_plugin('sspa-history-update-fixture/sspa-history-update-fixture.php');
sspa_update_option('plugin_update_detection', true);
sspa_history_t(!is_wp_error($activation) && is_plugin_active('sspa-history-update-fixture/sspa-history-update-fixture.php'), 'the setup-version fixture is active for both points in time');
$history_state_publisher = function ($records) {
    $records[] = array(
        'component' => array('type' => 'plugin', 'slug' => 'sspa-history-config'),
        'state_schema_version' => 1,
        'disclosure' => array('label' => 'History test state', 'publishes' => array('test profile token')),
        'summary' => array('profile' => get_option('sspa_history_config_variant', 'before')),
        'options' => array(),
        'state' => array(),
    );
    return $records;
};
add_filter('sspa_component_state', $history_state_publisher);
update_option('sspa_history_config_variant', 'before', false);

// The crawler's own fresh cache-buster can be reflected by REST pagination. That
// measurement noise must not manufacture an output change.
$link_hash_a = SSPA_Crawler::body_hash('{"items":[]}', array(
    'content-type' => 'application/json',
    'link' => '<https://example.test/wp-json/wp/v2/posts?sspa_nc=aaaaaaaaaaaa&page=2>; rel="next"',
));
$link_hash_b = SSPA_Crawler::body_hash('{"items":[]}', array(
    'content-type' => 'application/json',
    'link' => '<https://example.test/wp-json/wp/v2/posts?sspa_nc=bbbbbbbbbbbb&page=2>; rel="next"',
));
sspa_history_t($link_hash_a === $link_hash_b, 'the profiler cache-buster is excluded from REST output identity');

// A real MU fixture produces stable but different REST output and adds a large bounded delay.
$runtime_fixture = WPMU_PLUGIN_DIR . '/sspa-history-comparison-fixture.php';
file_put_contents($runtime_fixture, <<<'PHP'
<?php
add_filter('rest_pre_dispatch', function ($result, $server, $request) {
    if (0 === strpos($request->get_route(), '/wp/v2/posts')
        && (int) get_option('sspa_history_fixture_armed') >= time()
        && 'after' === get_option('sspa_history_fixture_variant')) {
        usleep(250000);
    }
    return $result;
}, 1, 3);
add_filter('rest_post_dispatch', function ($response, $server, $request) {
    if (0 !== strpos($request->get_route(), '/wp/v2/posts')
        || (int) get_option('sspa_history_fixture_armed') < time()) {
        return $response;
    }
    $variant = 'after' === get_option('sspa_history_fixture_variant') ? 'after' : 'before';
    $response = rest_ensure_response($response);
    $response->set_data(array(
        'items' => $response->get_data(),
        'sspa_history_fixture' => $variant,
    ));
    return $response;
}, PHP_INT_MAX, 3);
PHP
);

update_option('sspa_history_fixture_armed', time() + 120, false);
register_shutdown_function(function () use ($history_state_publisher) {
    delete_option('sspa_history_fixture_armed');
    sspa_update_option('plugin_update_detection', false);
    deactivate_plugins('sspa-history-update-fixture/sspa-history-update-fixture.php');
    sspa_update_option('plugin_update_detection', true);
    remove_filter('sspa_component_state', $history_state_publisher);
    delete_option('sspa_history_config_variant');
});
update_option('sspa_history_fixture_variant', 'before', false);
$pending_before_measurement = SSPA_Change_Set::pending(true);
$before_id = sspa_history_drive_run(array('type' => 'spot', 'page_keys' => array('rest-posts'), 'user_id' => 1));
sspa_history_t(!is_wp_error($before_id), 'the real Before point in time completes');
if (is_wp_error($before_id)) {
    echo 'FAIL: before run: ' . $before_id->get_error_message() . "\n";
    return;
}
sspa_history_t($pending_before_measurement === SSPA_Change_Set::pending(true), 'profiled requests do not fabricate plugin changes');

file_put_contents($update_fixture, "<?php\n/**\n * Plugin Name: SSPA History Update Fixture\n * Version: 1.3.0\n */\n");
wp_clean_plugins_cache(true);
SSPA_Change_Set::record('sspa-history-update-fixture/sspa-history-update-fixture.php', 'updated', '1.2.0', '1.3.0');
update_option('sspa_history_fixture_variant', 'after', false);
update_option('sspa_history_config_variant', 'after', false);
$after_id = sspa_history_drive_run(array(
    'type' => 'spot',
    'page_keys' => array('rest-posts'),
    'user_id' => 1,
    'trigger' => 'plugin_change',
    'share_context' => array(
        'history_comparison' => array(
            'baseline_run_id' => $before_id,
            'change_set' => SSPA_Change_Set::context(SSPA_Change_Set::pending(true)),
        ),
    ),
));
sspa_history_t(!is_wp_error($after_id), 'the real After point in time completes');
if (is_wp_error($after_id)) {
    echo 'FAIL: after run: ' . $after_id->get_error_message() . "\n";
    return;
}

$before_row_original = SSPA_Run_Controller::run_row($before_id);
$after_row_original = SSPA_Run_Controller::run_row($after_id);
$after_context = json_decode((string) $after_row_original['share_context'], true);
sspa_history_t(
    (int) $after_context['history_comparison']['baseline_run_id'] === (int) $before_id
        && 'plugin_change' === $after_row_original['trigger_source'],
    'the After run is bound to the exact Before run and plugin-change trigger'
);

$comparison = SSPA_History::compare($before_id, $after_id);
sspa_history_t(!is_wp_error($comparison), 'the two completed points in time compare');
if (is_wp_error($comparison)) {
    echo 'FAIL: comparison: ' . $comparison->get_error_message() . "\n";
    return;
}
$rest_posts = null;
foreach ($comparison['pages'] as $page) {
    if ('rest-posts' === $page['page_key']) {
        $rest_posts = $page;
        break;
    }
}
sspa_history_t(is_array($rest_posts), 'the comparison aligns the real REST-posts evidence');
sspa_history_t(
    $comparison['headline']['after'] > $comparison['headline']['before'] + 150,
    'response time is the headline and reports the fixture slowdown (' . wp_json_encode($comparison['headline']) . ')'
);
sspa_history_t('pass' === $rest_posts['validity']['before'] && 'pass' === $rest_posts['validity']['after'], 'both real responses pass validity checks');
$before_usage = SSPA_Report::page_plugin_usage($before_id);
$after_usage = SSPA_Report::page_plugin_usage($after_id);
$output_debug = array(
    'comparison' => $rest_posts['output'],
    'before_stable' => isset($before_usage['pages'][0]['output_stable']) ? $before_usage['pages'][0]['output_stable'] : null,
    'before_signature' => isset($before_usage['pages'][0]['output_signature']) ? $before_usage['pages'][0]['output_signature'] : null,
    'after_stable' => isset($after_usage['pages'][0]['output_stable']) ? $after_usage['pages'][0]['output_stable'] : null,
    'after_signature' => isset($after_usage['pages'][0]['output_signature']) ? $after_usage['pages'][0]['output_signature'] : null,
);
sspa_history_t(
    'changed' === $rest_posts['output']['state'],
    'the learned stable-output comparison reports the meaningful response change (' . wp_json_encode($output_debug) . ')'
);
$setup_change = null;
foreach ($comparison['setup_changes'] as $change) {
    if ('sspa-history-update-fixture' === $change['slug']) {
        $setup_change = $change;
        break;
    }
}
sspa_history_t(
    true === $comparison['setup_changes_available'] && is_array($setup_change)
        && '1.2.0' === $setup_change['before_version'] && '1.3.0' === $setup_change['after_version'],
    'the comparison shows the exact plugin setup change between the two points in time'
);
$configuration_change = null;
foreach ($comparison['configuration_changes'] as $change) {
    if ('sspa-history-config' === $change['slug']) {
        $configuration_change = $change;
        break;
    }
}
sspa_history_t(
    is_array($configuration_change) && 'changed' === $configuration_change['state'],
    'the comparison identifies a publisher-declared configuration change'
);

// Promote Before first: After must fail the declared expectation. Then approve After.
$approved_before = SSPA_History::approve_assertion($before_id, $rest_posts['key']);
$declared_fail = SSPA_History::compare($before_id, $after_id);
$declared_page = null;
foreach ($declared_fail['pages'] as $page) {
    if ($rest_posts['key'] === $page['key']) {
        $declared_page = $page;
        break;
    }
}
sspa_history_t(true === $approved_before && 'fail' === $declared_page['declared']['state'], 'a promoted learned signature becomes a real failing declared expectation');
$approved_after = SSPA_History::approve_assertion($after_id, $rest_posts['key']);
$declared_pass = SSPA_History::compare($before_id, $after_id);
foreach ($declared_pass['pages'] as $page) {
    if ($rest_posts['key'] === $page['key']) {
        $declared_page = $page;
        break;
    }
}
sspa_history_t(true === $approved_after && 'pass' === $declared_page['declared']['state'], 'approving After updates the declared expectation and it passes');

// Poison the persisted expectation with values that must never reach an export. The real
// comparison loader must validate them rather than trusting a private option row.
$assertion_state = get_option(SSPA_History::ASSERTIONS_OPTION, array());
$assertion_state['expectations'][$rest_posts['key']]['source_run_uuid'] = 'victim@example.com C:\\Users\\victim\\secret.sql';
update_option(SSPA_History::ASSERTIONS_OPTION, $assertion_state, false);
$sharing_before_local_export = SSPA_Submitter::opted_in();
$privacy_comparison = SSPA_History::compare($before_id, $after_id);
$payload = SSPA_History::export($privacy_comparison);
$hostile_context = SSPA_History::sanitise_run_context(array(
    'baseline_run_id' => $before_id,
    'change_set' => array(
        'id' => 'victim@example.com',
        'first_detected_at' => 'victim@example.com',
        'last_detected_at' => 'C:\\Users\\victim\\secret.sql',
        'changes' => array(array('slug' => 'safe-plugin', 'action' => 'updated', 'from_version' => '1.0', 'to_version' => '1.1')),
    ),
));
$hostile_state = SSPA_Community_State::sanitise_stored_records(array(array(
    'component' => array('type' => 'plugin', 'slug' => 'safe-plugin'),
    'state_schema_version' => 1,
    'summary' => array('mode' => 'safe', 'contact' => 'victim@example.com', 'path' => 'C:\\Users\\victim\\secret.sql'),
)));
$json = wp_json_encode(array(
    'payload' => $payload,
    'sanitised_hostile_context' => $hostile_context,
    'sanitised_hostile_state' => $hostile_state,
), JSON_UNESCAPED_SLASHES);
$forbidden = array(
    'victim@example.com', 'C:\\Users\\victim', (string) home_url('/'), (string) ABSPATH,
    (string) get_option('admin_email'), '<html', 'cookie=', '_wpnonce=', 'SELECT * FROM wp_users',
);
foreach ($forbidden as $needle) {
    if ('' !== $needle) {
        sspa_history_t(false === stripos($json, $needle), 'privacy export excludes ' . $needle);
    }
}
sspa_history_t(
    SSPA_History::EXPORT_SCHEMA === $payload['schema'] && wp_is_uuid($payload['source_id'])
        && 'done' === $payload['comparison']['before']['status'] && 'done' === $payload['comparison']['after']['status'],
    'evidence export has a versioned schema, random bundle-local source ID and explicit completion state'
);
sspa_history_t(
    $sharing_before_local_export === SSPA_Submitter::opted_in(),
    'local comparison export leaves community sharing unchanged'
);

$cli_json = WP_CLI::runcommand(
    'sspa history-compare ' . (int) $before_id . ' ' . (int) $after_id,
    array('return' => true, 'launch' => true, 'exit_error' => false)
);
$cli_payload = json_decode($cli_json, true);
sspa_history_t(
    is_array($cli_payload) && SSPA_History::EXPORT_SCHEMA === $cli_payload['schema']
        && $privacy_comparison['headline'] === $cli_payload['comparison']['headline'],
    'WP-CLI exposes the same versioned comparison contract for local CI runners'
);

if (function_exists('wp_get_ability')) {
    $previous_user_id = get_current_user_id();
    wp_set_current_user(1);
    $ability = wp_get_ability('super-speedy-performance/compare-history');
    $ability_payload = $ability ? $ability->execute(array(
        'before_run_id' => (int) $before_id,
        'after_run_id' => (int) $after_id,
    )) : null;
    wp_set_current_user($previous_user_id);
    sspa_history_t(
        is_array($ability_payload) && SSPA_History::EXPORT_SCHEMA === $ability_payload['schema']
            && $privacy_comparison['headline'] === $ability_payload['comparison']['headline'],
        'the readonly Abilities/MCP adapter exposes the same comparison contract'
    );
}

$html = SSPA_History::render($privacy_comparison);
sspa_history_t(
    false !== strpos($html, 'Response time') && false !== stripos($html, 'setup change')
        && false !== stripos($html, 'configuration change')
        && false !== strpos($html, 'Preview privacy-safe evidence'),
    'History renderer exposes the response headline, exact setup changes and preview-first export'
);
ob_start();
include SSPA_PLUGIN_DIR . 'includes/admin/tabs/history.php';
$history_html = ob_get_clean();
sspa_history_t(false !== strpos($history_html, 'sspa-plugin-update-detection'), 'History exposes the default-enabled update comparison setting');
sspa_history_t(false !== strpos($history_html, 'sspa-history-before') && false !== strpos($history_html, 'sspa-history-after'), 'History exposes explicit Before and After selectors');

sspa_history_t($before_row_original === SSPA_Run_Controller::run_row($before_id), 'comparison does not mutate the completed Before run');
sspa_history_t($after_row_original === SSPA_Run_Controller::run_row($after_id), 'comparison does not mutate the completed After run');
sspa_history_t(is_wp_error(SSPA_History::compare($before_id, $before_id)), 'the comparison fails closed for an invalid run pair');

// Restore unrelated settings and disarm fault injection. The completed runs and latest
// After state remain available for inspection on the retained feature site.
update_option('sspa_options', $original_options);
sspa_update_option('plugin_update_detection', true);
SSPA_Change_Set::consume($change_set_id);
delete_option('sspa_history_fixture_armed');
sspa_update_option('plugin_update_detection', false);
deactivate_plugins('sspa-history-update-fixture/sspa-history-update-fixture.php');
sspa_update_option('plugin_update_detection', true);
remove_filter('sspa_component_state', $history_state_publisher);
delete_option('sspa_history_config_variant');

if ($GLOBALS['sspa_history_failures']) {
    echo 'FAIL: ' . $GLOBALS['sspa_history_failures'] . " Performance History assertion(s) failed\n";
} else {
    echo "PASS: all Performance History behaviours hold\n";
}
