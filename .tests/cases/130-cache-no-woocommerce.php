<?php
// Cache performance on a plain WordPress site without WooCommerce, on its own retained site
// (tests-cache-nowoo): the browser journey runs the check; the tab and the caching-service
// pitch render. See .tests/cache-performance/assert-nowoo.php.
passthru('bash ' . escapeshellarg(SSPA_PLUGIN_DIR . '.tests/cache-performance/run.sh') . ' nowoo', $status);
if ($status) { echo "FAIL: Cache performance without WooCommerce (tab, check and service pitch)\n"; exit(1); }
echo "PASS: Cache performance without WooCommerce (tab, check and service pitch)\n";
