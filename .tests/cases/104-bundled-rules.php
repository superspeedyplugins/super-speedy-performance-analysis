<?php
// Drive activation, the registered janitor hook and a real sharing-disabled analysis.
function w1_rules_check($ok, $label) { echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n"; }
$fixture = SSPA_PLUGIN_DIR . '.tests/fixtures/w1-community-http-boundary.php';
copy($fixture, WPMU_PLUGIN_DIR . '/sspa-w1-community-http-boundary.php');
require_once $fixture;
update_option('sspa_w1_http_watch', true, true);
update_option('sspa_w1_http_requests', array(), false);
sleep(3); // Serving SAPI must see the installed HTTP interceptor.
$requests = static function() {
    wp_cache_delete('sspa_w1_http_requests', 'options');
    wp_cache_delete('notoptions', 'options');
    return get_option('sspa_w1_http_requests', array());
};
try {
update_option('sspa_share_optin', false);
delete_transient('sspa_rules_feed');
delete_transient('sspa_rules_feed_backoff');
SSPA_Install::activate();
w1_rules_check(!$requests(), 'activation makes no superspeedy.org request');
do_action('sspa_cleanup_event');
w1_rules_check(!$requests(), 'cleanup makes no superspeedy.org request with sharing off');
$run = SSPA_Run_Controller::start(array('type' => 'baseline', 'user_id' => 1, 'share' => false));
if (is_wp_error($run)) { w1_rules_check(false, $run->get_error_message()); }
else {
    $started_at = microtime(true);
    $deadline = time() + 120;
    do { SSPA_Run_Controller::process_batch($run); $status = SSPA_Run_Controller::status($run); }
    while ($status && $status['status'] === 'crawling' && time() < $deadline);
    echo 'DIAGNOSTIC: sharing-disabled run ' . $run . ' elapsed ' . round(microtime(true) - $started_at, 3) . " seconds\n";
    if (!$status || $status['status'] !== 'done') {
        echo 'DIAGNOSTIC: sharing-disabled run ' . wp_json_encode($status) . "\n";
        global $wpdb;
        foreach ($wpdb->get_results($wpdb->prepare('SELECT page_key,profile_blob FROM %i WHERE run_id=%d', SSPA_Schema::table('profiles'), $run)) as $profile) {
            $capture = $profile->profile_blob ? json_decode(gzuncompress($profile->profile_blob), true) : array();
            echo 'DIAGNOSTIC: ' . $profile->page_key . ' ' . wp_json_encode(array_filter((array) $capture, static function ($key) { return strpos($key, 'http') !== false; }, ARRAY_FILTER_USE_KEY)) . "\n";
        }
    }
    w1_rules_check($status && $status['status'] === 'done', 'real sharing-disabled analysis completes');
    w1_rules_check(!$requests(), 'analysis makes no superspeedy.org request');
}
set_transient('sspa_rules_feed', array('legacy' => true), 3600);
set_transient('sspa_rules_feed_backoff', 1, 3600);
update_option('sspa_rules_pubkey', 'legacy');
update_option('sspa_hub_url', 'https://superspeedy.org');
// An old plugin version is the upgrade condition, independently of schema changes.
update_option('sspa_behaviour_version', '0.43.4');
SSPA_Install::maybe_upgrade();
w1_rules_check(false === get_transient('sspa_rules_feed') && false === get_transient('sspa_rules_feed_backoff') && false === get_option('sspa_rules_pubkey'), 'upgrade removes obsolete rules-feed state');
w1_rules_check(false === get_option('sspa_hub_url'), 'upgrade removes the unused rules-hub URL option');
$bundled = json_decode(file_get_contents(SSPA_PLUGIN_DIR . 'rules/rules-snapshot.json'), true);
w1_rules_check(SSPA_Rules::categories() === $bundled['categories'], 'rules accessor returns bundled categories');
} finally {
update_option('sspa_w1_http_watch', false, true);
}
