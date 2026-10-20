<?php
// Cache performance on a site with no page cache and a really slow shop page, on its own
// retained site (tests-cache-nocache): a real analysis, the browser journey, then C3 from
// generation time and the Ajax Prices detection (P1 from real geolocation and a real
// wordpress.org pricing plugin, suppression with Ajax Prices active).
// See .tests/cache-performance/assert-nocache.php.
passthru('bash ' . escapeshellarg(SSPA_PLUGIN_DIR . '.tests/cache-performance/run.sh') . ' nocache', $status);
if ($status) { echo "FAIL: Cache performance without a page cache (Not cached, C3, Ajax Prices detection)\n"; exit(1); }
echo "PASS: Cache performance without a page cache (Not cached, C3, Ajax Prices detection)\n";
