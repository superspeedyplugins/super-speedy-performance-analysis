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
            '1.0.1' => '7361c5c13a4a8dfd7c90ad5188321b8334cac0f5876a02403e2afc1eefedc7f1',
            '1.0.2' => '18afca7c76f7559799b9d8dc346bbd5f8fc5a7fbbfeac6efb1eeeab8eccc4ccd',
            '1.0.3' => '2c96790978d81ab988de9c8cd466e9a3b0cba3e3ec9adbda7d35d8ddd776710e',
            '1.0.4' => '87efea4f722d351fda9568b0f7555403101d4a7cf41a33bf5c97b130f50d27d1',
            '1.0.5' => '996e661aa4afa19fcce911a7719ea39fc06f4a33848621e148bd5e0796ed748c',
            '1.0.6' => '8f22c2c15b935e76adfbc70d93d8a75036c0fc4ec9efeddc0bb2313c6d99caf9',
        ),
    ),
    'dropins/db.php' => array(
        'constant' => 'SSPA_DROPIN_VERSION',
        'declared' => SSPA_DROPIN_VERSION,
        'hashes' => array(
            '1.0.0' => '0455bfa1efa6af7b59c72a5a7a7f80406116bbf1ed37b3f4a55f9a87b6260637',
            '1.0.1' => '484d8f49aeb8e7c06e99b34204f72a01549f89773a2bd77a8db858f85424abd5',
            '1.0.2' => '744639af3c47afdfbe4a6f2689985d6d8a94b57e9e337cbbaf11a6b26a4e6779',
            '1.0.3' => '12f440a0c6d4ce4b1a32c5d054476ae29daeacaa0c708d893434339cfc92d6c7',
            '1.0.4' => 'ec20a09bd12acbae9a8aace04e748737196d89dc9e7f20636ab341d210c18a41',
            '1.0.5' => '4628fdcdfeeb3e2120435bcb19d216713015ae07eb41ed20e4ae674adec7191e',
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
