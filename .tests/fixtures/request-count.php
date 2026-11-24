<?php
/**
 * Test fixture (case 167): logs every request this site receives, one JSON line each, with
 * its path (cache-busting argument removed) and whether it carried the profiler's signed
 * X-SSPA-Token header. Installed as wp-content/mu-plugins/0-sspa-request-count.php so it
 * loads before the profiler's own loader. Only on the case's own site; never in a build.
 */
defined('ABSPATH') || exit;
if (!defined('WP_CLI') && isset($_SERVER['REQUEST_URI'])) {
    $sspa_rc_uri = preg_replace('/([?&])sspa_nc=[a-f0-9]*(&|$)/', '$1', (string) $_SERVER['REQUEST_URI']);
    $sspa_rc_uri = rtrim($sspa_rc_uri, '?&');
    $sspa_rc_line = json_encode(array(
        'uri' => $sspa_rc_uri,
        'method' => isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '',
        'token' => !empty($_SERVER['HTTP_X_SSPA_TOKEN']),
    )) . "\n";
    file_put_contents(WP_CONTENT_DIR . '/sspa-request-count.log', $sspa_rc_line, FILE_APPEND | LOCK_EX);
}
