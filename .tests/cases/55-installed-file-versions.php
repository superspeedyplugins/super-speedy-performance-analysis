<?php
// The mu-loader and the db.php drop-in are files this plugin WRITES into the site. They have
// their own lifecycles: most plugin releases change neither, and stamping them with the
// plugin version meant every patch bump rewrote both and, until 0.31.2, made a healthy
// install report itself broken.
//
// They now carry SSPA_MU_VERSION and SSPA_DROPIN_VERSION. The danger with an independent
// version is the obvious one: someone edits the template and forgets to bump it, so sites
// keep an old file that claims to be current.
//
// This test is the gate. Each template's content is PINNED to a hash under its declared
// version. Editing a template without bumping fails on the hash; bumping without pinning
// fails on the missing entry. Either way the person who changed it has to say so.
//
// To make this pass after a deliberate change: bump the constant in defines.php, then add
// the new version => hash pair below (keep the old ones, they are the record).

function sspa_ver_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

$sspa_pins = array(
    'mu/sspa-loader.php' => array(
        'constant' => 'SSPA_MU_VERSION',
        'declared' => SSPA_MU_VERSION,
        'hashes' => array(
            '1.0.0' => '586612818e2b8d891f19774c5cfd7908c999d9b6b46deacc8d1beb912a023fd1',
        ),
    ),
    'dropins/db.php' => array(
        'constant' => 'SSPA_DROPIN_VERSION',
        'declared' => SSPA_DROPIN_VERSION,
        'hashes' => array(
            '1.0.0' => '0455bfa1efa6af7b59c72a5a7a7f80406116bbf1ed37b3f4a55f9a87b6260637',
        ),
    ),
);

foreach ($sspa_pins as $relative => $pin) {
    $path = SSPA_PLUGIN_DIR . $relative;
    if (!is_file($path)) {
        sspa_ver_t(false, "$relative exists");
        continue;
    }
    $actual = hash('sha256', file_get_contents($path));

    if (!isset($pin['hashes'][$pin['declared']])) {
        sspa_ver_t(false, sprintf(
            '%s declares %s %s but no hash is pinned for it - add "%s" => "%s" to this test',
            $relative,
            $pin['constant'],
            $pin['declared'],
            $pin['declared'],
            $actual
        ));
        continue;
    }

    sspa_ver_t(
        $pin['hashes'][$pin['declared']] === $actual,
        sprintf(
            '%s matches the hash pinned for %s %s%s',
            $relative,
            $pin['constant'],
            $pin['declared'],
            $pin['hashes'][$pin['declared']] === $actual
                ? ''
                : ' - the template changed, so bump ' . $pin['constant'] . ' and pin ' . $actual
        )
    );
}

// The versions must be independent of the plugin's, which is the whole point.
sspa_ver_t(SSPA_MU_VERSION !== SSPA_VERSION, 'the mu-loader version is not the plugin version');
sspa_ver_t(SSPA_DROPIN_VERSION !== SSPA_VERSION, 'the drop-in version is not the plugin version');

// And the file actually installed on this site must declare the version we think it does,
// which is what proves generate() substitutes the right placeholder.
SSPA_Helper_Files::ensure_installed();

$installed = WPMU_PLUGIN_DIR . '/sspa-loader.php';
$mu_declared = '';
if (is_file($installed) && preg_match('/^\s*\*\s*Version:\s*(\S+)/m', file_get_contents($installed), $m)) {
    $mu_declared = $m[1];
}
sspa_ver_t(
    SSPA_MU_VERSION === $mu_declared,
    'the installed mu-loader declares SSPA_MU_VERSION (' . SSPA_MU_VERSION . ', found ' . ($mu_declared ?: 'nothing') . ')'
);

$dropin = WP_CONTENT_DIR . '/db.php';
$dropin_declared = '';
if (is_file($dropin) && preg_match('/^\s*\*\s*Version:\s*(\S+)/m', file_get_contents($dropin), $m)) {
    $dropin_declared = $m[1];
}
sspa_ver_t(
    '' === $dropin_declared || SSPA_DROPIN_VERSION === $dropin_declared,
    'the installed drop-in declares SSPA_DROPIN_VERSION (' . SSPA_DROPIN_VERSION . ', found ' . ($dropin_declared ?: 'no drop-in installed') . ')'
);

// A plugin version bump on its own must NOT make the installed files look stale, which is
// the regression that started all of this.
$health = SSPA_Helper_Files::health();
sspa_ver_t(!empty($health['mu']), 'the installed mu-loader is healthy after a plugin version bump');
sspa_ver_t(
    isset($health['mu_reason']) && 'ok' === $health['mu_reason'],
    'health reports a reason, and it is ok'
);
