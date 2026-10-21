<?php
// Offers on a slow 120,000-product store (S1, S3, S5, Q1-Q3, F1, volume, dismissal, suppression, exports).
// Runs on its own retained site; see .tests/lead-gen/run.sh slow and .tests/lead-gen/assert-*.php.
passthru('bash ' . escapeshellarg(SSPA_PLUGIN_DIR . '.tests/lead-gen/run.sh') . ' slow', $status);
if ($status) { echo "FAIL: Offers on a slow 120,000-product store (S1, S3, S5, Q1-Q3, F1, volume, dismissal, suppression, exports)\n"; exit(1); }
echo "PASS: Offers on a slow 120,000-product store (S1, S3, S5, Q1-Q3, F1, volume, dismissal, suppression, exports)\n";
