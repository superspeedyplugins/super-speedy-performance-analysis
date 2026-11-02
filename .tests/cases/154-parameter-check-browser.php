<?php
// The Check ad-click and campaign parameters button on the Cache performance tab, in a real browser. On its own retained site (tests-cache-blockers) with the
// tracker, click-cookie and page-cache fixtures. See .tests/new-visitor-cache/run.sh journey.
passthru('bash ' . escapeshellarg(SSPA_PLUGIN_DIR . '.tests/new-visitor-cache/run.sh') . ' journey', $status);
if ($status) { echo "FAIL: parameter check button browser journey\n"; exit(1); }
echo "PASS: parameter check button browser journey\n";
