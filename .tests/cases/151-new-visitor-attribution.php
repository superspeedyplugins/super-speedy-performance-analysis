<?php
// A cookie set for a cookie-less visitor is attributed at runtime to the plugin, version, hook and callback, and becomes the new_visitor_cache_blocked finding. On its own retained site (tests-cache-blockers) with the
// tracker, click-cookie and page-cache fixtures. See .tests/new-visitor-cache/run.sh attribution.
passthru('bash ' . escapeshellarg(SSPA_PLUGIN_DIR . '.tests/new-visitor-cache/run.sh') . ' attribution', $status);
if ($status) { echo "FAIL: runtime Set-Cookie attribution and the new-visitor finding\n"; exit(1); }
echo "PASS: runtime Set-Cookie attribution and the new-visitor finding\n";
