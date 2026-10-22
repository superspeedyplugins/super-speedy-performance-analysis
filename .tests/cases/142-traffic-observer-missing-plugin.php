<?php
// The traffic observer MU plugin must never take the site down when Performance Analysis's folder
// is missing (renamed or deleted over FTP while a collection runs). Runs on its own site, where PA
// is a real folder copied from this checkout as a ZIP install leaves it: see
// .tests/observer-guard/run.sh for why the symlinked suite sites cannot show this.
defined('ABSPATH') || exit;
passthru('bash ' . escapeshellarg(SSPA_PLUGIN_DIR . '.tests/observer-guard/run.sh'), $sspa_142_exit);
if ($sspa_142_exit !== 0) {
    WP_CLI::error('Dedicated traffic observer regression failed');
}
