<?php
// Invoke the real vendor API against a separate native site and the installed official package.
passthru('bash ' . escapeshellarg(SSPA_PLUGIN_DIR . '.tests/siteground-cache/run.sh'), $status);
if ($status) { echo "FAIL: SiteGround real file-cache browser journey\n"; exit(1); }
echo "PASS: SiteGround real file-cache browser journey\n";
