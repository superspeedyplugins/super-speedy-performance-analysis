<?php
// Offers stay silent below thresholds; Imports tab with real WP All Import history.
// Runs on its own retained site; see .tests/lead-gen/run.sh wpai and .tests/lead-gen/assert-*.php.
passthru('bash ' . escapeshellarg(SSPA_PLUGIN_DIR . '.tests/lead-gen/run.sh') . ' wpai', $status);
if ($status) { echo "FAIL: Offers stay silent below thresholds; Imports tab with real WP All Import history\n"; exit(1); }
echo "PASS: Offers stay silent below thresholds; Imports tab with real WP All Import history\n";
