<?php
// Imports tab states (none, measured WooCommerce import and slow endpoint, Lite, Pro).
// Runs on its own retained site; see .tests/lead-gen/run.sh imports and .tests/lead-gen/assert-*.php.
passthru('bash ' . escapeshellarg(SSPA_PLUGIN_DIR . '.tests/lead-gen/run.sh') . ' imports', $status);
if ($status) { echo "FAIL: Imports tab states (none, measured WooCommerce import and slow endpoint, Lite, Pro)\n"; exit(1); }
echo "PASS: Imports tab states (none, measured WooCommerce import and slow endpoint, Lite, Pro)\n";
