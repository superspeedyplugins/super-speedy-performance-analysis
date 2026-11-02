<?php
// The ad-click and campaign parameter check: cookies each parameter triggers, attributed, and how the cache key treats it (ignored, separate key, bypass). On its own retained site (tests-cache-blockers) with the
// tracker, click-cookie and page-cache fixtures. See .tests/new-visitor-cache/run.sh parameters.
passthru('bash ' . escapeshellarg(SSPA_PLUGIN_DIR . '.tests/new-visitor-cache/run.sh') . ' parameters', $status);
if ($status) { echo "FAIL: ad-click and campaign parameter check\n"; exit(1); }
echo "PASS: ad-click and campaign parameter check\n";
