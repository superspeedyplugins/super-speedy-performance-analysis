<?php
// Cache performance against the real Cache Enabler from wordpress.org, on its own retained
// site (tests-cache-enabler): the browser journey presses "Check cache performance", then the
// saved check is asserted. See .tests/cache-performance/assert-enabler.php.
passthru('bash ' . escapeshellarg(SSPA_PLUGIN_DIR . '.tests/cache-performance/run.sh') . ' enabler', $status);
if ($status) { echo "FAIL: Cache performance with a real page cache (three audiences, leak check, C3)\n"; exit(1); }
echo "PASS: Cache performance with a real page cache (three audiences, leak check, C3)\n";
