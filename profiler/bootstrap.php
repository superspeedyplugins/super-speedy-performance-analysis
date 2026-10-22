<?php
defined('ABSPATH') || exit;
// Entry point called by the mu-loader once a token has been verified. Standalone.

function sspa_profiler_boot($token_id, $flags) {
    if (isset($GLOBALS['sspa_capture'])) {
        return;
    }
    // The profiler runs on requests where the main plugin may be trimmed away, so it loads the
    // shared location constants itself (issue #47).
    require_once dirname(__DIR__) . '/includes/locations.php';
    require_once __DIR__ . '/class-sspa-capture.php';
    $GLOBALS['sspa_capture'] = new SSPA_Capture($token_id, $flags);
    $GLOBALS['sspa_flags'] = $flags; // probe/write handlers gate on these
    $GLOBALS['sspa_capture']->arm();
}
