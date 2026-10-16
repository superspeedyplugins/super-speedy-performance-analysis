<?php
// Decision 1 supersedes feed retry/backoff: even the first cleanup fetch is forbidden.
// Preserve this historical regression slot and strengthen it at the registered cron entry.
function sspa_feed_t($ok, $label) { echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n"; }
$calls = array();
$intercept = static function($pre, $args, $url) use (&$calls) {
    if (preg_match('~(^|\.)superspeedy\.org$~', (string) wp_parse_url($url, PHP_URL_HOST))) {
        $calls[] = $url;
        return new WP_Error('sspa_test_no_feed', 'Rules network access is forbidden');
    }
    return $pre;
};
add_filter('pre_http_request', $intercept, PHP_INT_MAX, 3);
update_option('sspa_share_optin', false);
delete_transient('sspa_rules_feed'); delete_transient('sspa_rules_feed_backoff');
try {
    do_action('sspa_cleanup_event');
    sspa_feed_t(count($calls) === 0, 'first cleanup does not fetch any community rules');
    do_action('sspa_cleanup_event');
    sspa_feed_t(count($calls) === 0, 'repeated cleanup needs neither a fetch nor a backoff');
    // An obsolete cached overlay cannot change the installed rules.
    set_transient('sspa_rules_feed', array('version' => 999, 'rules' => array('categories' => array('injected' => 'untrusted'))), 3600);
    SSPA_Rules::flush();
    $snapshot = json_decode(file_get_contents(SSPA_PLUGIN_DIR . 'rules/rules-snapshot.json'), true);
    sspa_feed_t(SSPA_Rules::categories() === $snapshot['categories'], 'legacy cached feed cannot override bundled categories');
} finally {
    remove_filter('pre_http_request', $intercept, PHP_INT_MAX);
    delete_transient('sspa_rules_feed');
}
