<?php
// The Cache performance check makes a second cookie-less request and a returning-visitor request, and says Not cached for new visitors naming the cookie and plugin. On its own retained site (tests-cache-blockers) with the
// tracker, click-cookie and page-cache fixtures. See .tests/new-visitor-cache/run.sh check.
passthru('bash ' . escapeshellarg(SSPA_PLUGIN_DIR . '.tests/new-visitor-cache/run.sh') . ' check', $status);
if ($status) { echo "FAIL: cache check: new visitor again and returning visitor\n"; exit(1); }
echo "PASS: cache check: new visitor again and returning visitor\n";
