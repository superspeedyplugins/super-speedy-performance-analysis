<?php
defined('ABSPATH') || exit;
// Entry point called by the mu-loader once a token has been verified. Standalone.

if (!function_exists('sspa_profiler_boot')) {
    function sspa_profiler_boot($token_id, $flags) {
        if (isset($GLOBALS['sspa_capture'])) {
            return;
        }
        require_once __DIR__ . '/class-sspa-capture.php';
        $GLOBALS['sspa_capture'] = new SSPA_Capture($token_id, $flags);
        $GLOBALS['sspa_flags'] = $flags; // probe/write handlers gate on these
        $GLOBALS['sspa_capture']->arm();
    }
}
