<?php
// One validated record shape for a plugin change, shared by capture, persistence, the run
// context and History. Each action has its own version requirements; a malformed
// combination is rejected rather than repaired; and the same change reads identically at
// every entry point.
require __DIR__ . '/../lib/fleet.php';

function sspa_86_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
    if (!$ok) { $GLOBALS['sspa_86_failures']++; }
}
$GLOBALS['sspa_86_failures'] = 0;

try {
    sspa_86_t(class_exists('SSPA_Plugin_Change'), 'a shared plugin-change record model exists');

    // Each action's requirements, and the one public shape.
    $accepted = array(
        'installed needs a target version' => array(array('slug' => 'a', 'action' => 'installed', 'to_version' => '1.0'), array('slug' => 'a', 'action' => 'installed', 'from_version' => '', 'to_version' => '1.0')),
        'activated needs a target version' => array(array('slug' => 'a', 'action' => 'activated', 'to_version' => '1.0'), array('slug' => 'a', 'action' => 'activated', 'from_version' => '', 'to_version' => '1.0')),
        'updated needs both versions' => array(array('slug' => 'a', 'action' => 'updated', 'from_version' => '1.0', 'to_version' => '1.1'), array('slug' => 'a', 'action' => 'updated', 'from_version' => '1.0', 'to_version' => '1.1')),
        'deactivated needs a previous version' => array(array('slug' => 'a', 'action' => 'deactivated', 'from_version' => '1.0'), array('slug' => 'a', 'action' => 'deactivated', 'from_version' => '1.0', 'to_version' => '')),
        'removed needs a previous version' => array(array('slug' => 'a', 'action' => 'removed', 'from_version' => '1.0'), array('slug' => 'a', 'action' => 'removed', 'from_version' => '1.0', 'to_version' => '')),
        'versions pass through the shared normaliser' => array(array('slug' => 'Some_Plugin', 'action' => 'updated', 'from_version' => ' 1.0<b>x</b> ', 'to_version' => 'v1.1'), array('slug' => 'some_plugin', 'action' => 'updated', 'from_version' => '1.0x', 'to_version' => 'v1.1')),
    );
    foreach ($accepted as $label => $pair) {
        $record = SSPA_Plugin_Change::from_array($pair[0]);
        sspa_86_t($record instanceof SSPA_Plugin_Change && $record->to_array() === $pair[1], $label . ' (' . wp_json_encode($record ? $record->to_array() : null) . ')');
    }
    $rejected = array(
        'no slug' => array('action' => 'updated', 'from_version' => '1.0', 'to_version' => '1.1'),
        'unknown action' => array('slug' => 'a', 'action' => 'exploded', 'from_version' => '1.0', 'to_version' => '1.1'),
        'installed with a previous version' => array('slug' => 'a', 'action' => 'installed', 'from_version' => '0.9', 'to_version' => '1.0'),
        'activated without a target version' => array('slug' => 'a', 'action' => 'activated'),
        'updated missing its previous version' => array('slug' => 'a', 'action' => 'updated', 'to_version' => '1.1'),
        'updated with an invalid target version' => array('slug' => 'a', 'action' => 'updated', 'from_version' => '1.0', 'to_version' => '2.0 rc1'),
        'deactivated with a target version' => array('slug' => 'a', 'action' => 'deactivated', 'from_version' => '1.0', 'to_version' => '1.0'),
        'removed without a previous version' => array('slug' => 'a', 'action' => 'removed'),
        'not an array' => 'updated',
    );
    foreach ($rejected as $label => $raw) {
        sspa_86_t(null === SSPA_Plugin_Change::from_array($raw), 'rejects ' . $label);
    }

    // The same change reads identically at every entry point, through real capture.
    $plugin = 'sspa-history-update-fixture/sspa-history-update-fixture.php';
    sspa_86_t(file_exists(WP_PLUGIN_DIR . '/' . $plugin), 'the History update fixture plugin exists to record a change against');
    delete_option(SSPA_Change_Set::OPTION);
    sspa_update_option('plugin_update_detection', true);
    SSPA_Change_Set::record($plugin, 'updated', '1.2.0', ' 1.3.0<b>x</b> ');
    $expected = array('slug' => 'sspa-history-update-fixture', 'action' => 'updated', 'from_version' => '1.2.0', 'to_version' => '1.3.0x');
    $pending = SSPA_Change_Set::pending(true);
    $stored = is_array($pending) && !empty($pending['changes']) ? reset($pending['changes']) : array();
    $stored_public = array_intersect_key((array) $stored, $expected);
    sspa_86_t($stored_public === $expected, 'the stored change carries exactly the model vocabulary (' . wp_json_encode($stored_public) . ')');
    sspa_86_t(!empty($stored['events'][0]) && array_intersect_key($stored['events'][0], array('action' => 1, 'from_version' => 1, 'to_version' => 1)) === array('action' => 'updated', 'from_version' => '1.2.0', 'to_version' => '1.3.0x'), 'the stored event carries the same vocabulary');
    $context = SSPA_Change_Set::context($pending);
    sspa_86_t(!empty($context['changes']) && reset($context['changes']) === $expected, 'the run context carries the identical record');
    $sanitised = SSPA_History::sanitise_run_context(array('baseline_run_id' => 1, 'change_set' => $context));
    sspa_86_t(!empty($sanitised['change_set']['changes']) && reset($sanitised['change_set']['changes']) === $expected, 'History reads the identical record back');

    // A malformed record in a stored context is dropped, not rendered as nonsense.
    $bad_context = $context;
    $bad_context['changes'][] = array('slug' => 'ghost', 'action' => 'activated');
    $bad_context['changes'][] = array('slug' => 'phantom', 'action' => 'vanished', 'from_version' => '1.0');
    $filtered = SSPA_History::sanitise_run_context(array('baseline_run_id' => 1, 'change_set' => $bad_context));
    sspa_86_t(1 === count($filtered['change_set']['changes']) && reset($filtered['change_set']['changes']) === $expected, 'malformed stored records are rejected on read (' . count($filtered['change_set']['changes']) . ' kept)');

    // Deactivation and activation through the real hooks carry their one-sided versions.
    delete_option(SSPA_Change_Set::OPTION);
    SSPA_Change_Set::deactivated($plugin);
    $deactivated = SSPA_Change_Set::pending(true);
    $deactivated_change = !empty($deactivated['changes']) ? reset($deactivated['changes']) : array();
    sspa_86_t('deactivated' === ($deactivated_change['action'] ?? '') && '' !== ($deactivated_change['from_version'] ?? '') && '' === ($deactivated_change['to_version'] ?? 'x'), 'a real deactivation records the previous version only (' . wp_json_encode(array_intersect_key((array) $deactivated_change, $expected)) . ')');
    delete_option(SSPA_Change_Set::OPTION);
    SSPA_Change_Set::activated($plugin);
    $activated = SSPA_Change_Set::pending(true);
    $activated_change = !empty($activated['changes']) ? reset($activated['changes']) : array();
    sspa_86_t('activated' === ($activated_change['action'] ?? '') && '' === ($activated_change['from_version'] ?? 'x') && '' !== ($activated_change['to_version'] ?? ''), 'a real activation records the target version only (' . wp_json_encode(array_intersect_key((array) $activated_change, $expected)) . ')');
} catch (Throwable $error) {
    sspa_86_t(false, $error->getMessage());
}

if ($GLOBALS['sspa_86_failures']) { exit(1); }
