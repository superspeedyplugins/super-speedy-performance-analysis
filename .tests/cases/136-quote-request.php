<?php
// "Request a fix quote" end to end: PA's real request to the real Super Speedy Contact route on a
// receiver site, previewed Markdown equal to the received Markdown, consent, retries, 400, 413,
// 429, 503 and transport errors, then the browser journey from the admin menu.
// Runs on its own retained sites; see .tests/quote-request/run.sh and assert-quote.php.
passthru('bash ' . escapeshellarg(SSPA_PLUGIN_DIR . '.tests/quote-request/run.sh'), $status);
if ($status) { echo "FAIL: Request a fix quote against the real receiver (case and browser journey)\n"; exit(1); }
echo "PASS: Request a fix quote against the real receiver (case and browser journey)\n";
