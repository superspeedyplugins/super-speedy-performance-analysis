<?php
// This case needs a genuinely non-WooCommerce site, independent of the main suite.
$command = 'bash ' . escapeshellarg(SSPA_PLUGIN_DIR . '.tests/review-correctness/run.sh');
passthru($command, $status);
if ($status !== 0) {
    WP_CLI::error('Dedicated review correctness regression failed.');
}
