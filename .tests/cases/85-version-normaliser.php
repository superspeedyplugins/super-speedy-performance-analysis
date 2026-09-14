<?php
// One plugin-version normaliser, used at every History entry point. Change capture, the
// pending change set, the context handed to a run, and History's own run-context sanitiser
// must all agree on what a version is, and the explicit behaviours for empty, invalid,
// pre-release and unusual-but-valid versions are pinned here so they cannot drift again.
require __DIR__ . '/../lib/fleet.php';

function sspa_85_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
    if (!$ok) { $GLOBALS['sspa_85_failures']++; }
}
$GLOBALS['sspa_85_failures'] = 0;

try {
    sspa_85_t(class_exists('SSPA_Version'), 'a shared version normaliser exists');

    // Explicit behaviours: local capture and comparison grammar.
    $local = array(
        '1.2.3' => '1.2.3',
        ' 1.2.3 ' => '1.2.3',
        'v2.0' => 'v2.0',
        '1.2.3-beta.1+build.7' => '1.2.3-beta.1+build.7',
        '2026.09.14' => '2026.09.14',
        '1.3.0-beta.1<b>bold</b>' => '1.3.0-beta.1bold',
        '' => '',
        '   ' => '',
        '2.0 rc1' => '',
        "1.0'; DROP TABLE wp_users; --" => '',
        'SELECT 1' => '',
        '.hidden' => '',
        str_repeat('9', 64) => str_repeat('9', 64),
        str_repeat('9', 65) => '',
    );
    foreach ($local as $input => $expected) {
        $actual = SSPA_Version::normalise($input);
        sspa_85_t($expected === $actual, 'normalise(' . var_export($input, true) . ') = ' . var_export($actual, true));
    }

    // The privacy boundary keeps its shorter cap and answers null, exactly as the exporter,
    // the report and the site snapshot did individually.
    $shared = array(
        '1.2.3' => '1.2.3',
        '' => null,
        '2.0 rc1' => null,
        str_repeat('9', 32) => str_repeat('9', 32),
        str_repeat('9', 33) => null,
    );
    foreach ($shared as $input => $expected) {
        $actual = SSPA_Version::shared($input);
        sspa_85_t($expected === $actual, 'shared(' . var_export($input, true) . ') = ' . var_export($actual, true));
    }

    // Every History entry point agrees with the normaliser on the same messy input.
    $messy = ' 1.3.0-beta.1<b>bold</b> ';
    $expected = SSPA_Version::normalise($messy);
    $plugin = 'sspa-history-update-fixture/sspa-history-update-fixture.php';
    sspa_85_t(file_exists(WP_PLUGIN_DIR . '/' . $plugin), 'the History update fixture plugin exists to record a change against');
    delete_option(SSPA_Change_Set::OPTION);
    sspa_update_option('plugin_update_detection', true);
    SSPA_Change_Set::record($plugin, 'updated', ' 1.2.0<i>x</i> ', $messy);
    $pending = SSPA_Change_Set::pending(true);
    $change = is_array($pending) && !empty($pending['changes']) ? reset($pending['changes']) : array();
    sspa_85_t(isset($change['to_version']) && $expected === $change['to_version'], 'change capture stores the normalised target version (' . var_export($change['to_version'] ?? null, true) . ')');
    sspa_85_t(isset($change['from_version']) && SSPA_Version::normalise(' 1.2.0<i>x</i> ') === $change['from_version'], 'change capture stores the normalised previous version (' . var_export($change['from_version'] ?? null, true) . ')');
    $context = SSPA_Change_Set::context($pending);
    $context_change = !empty($context['changes']) ? reset($context['changes']) : array();
    sspa_85_t(isset($context_change['to_version']) && $expected === $context_change['to_version'], 'the run context carries the same normalised version');
    $sanitised = SSPA_History::sanitise_run_context(array('baseline_run_id' => 1, 'change_set' => array_merge($context, array('changes' => array(array('slug' => 'probe', 'action' => 'updated', 'from_version' => ' 1.2.0<i>x</i> ', 'to_version' => $messy))))));
    sspa_85_t($expected === $sanitised['change_set']['changes'][0]['to_version'], 'History sanitises a raw context version to the same result (' . var_export($sanitised['change_set']['changes'][0]['to_version'], true) . ')');
} catch (Throwable $error) {
    sspa_85_t(false, $error->getMessage());
}

if ($GLOBALS['sspa_85_failures']) { exit(1); }
