<?php
// Legacy CPT storage has its own native site; the main suite keeps HPOS enabled.
passthru('bash ' . escapeshellarg(SSPA_PLUGIN_DIR . '.tests/legacy-workflows/run.sh'), $exit);
if ($exit) { WP_CLI::error('Legacy CPT workflow browser regression failed'); }
