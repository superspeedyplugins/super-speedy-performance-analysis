<?php
/**
 * Plugin Name: SSPA test fixture - slow admin-ajax endpoint
 * Description: Test-only MU plugin for the lead-generation S8 check. The admin-ajax action
 * lead_gen_fixture_slow_endpoint answers after one real 350 ms database wait, so a Traffic
 * collection measures a genuinely slow endpoint. Installed only on the retained
 * tests-lead-gen-imports site.
 */
defined('ABSPATH') || exit;
if (!preg_match('/^tests(-|$)/', basename(rtrim(ABSPATH, '/')))) {
    return;
}
$lead_gen_fixture_slow_endpoint = static function () {
    global $wpdb;
    $wpdb->query('SELECT SLEEP(0.35)');
    wp_send_json_success(array('ok' => true));
};
add_action('wp_ajax_nopriv_lead_gen_fixture_slow_endpoint', $lead_gen_fixture_slow_endpoint);
add_action('wp_ajax_lead_gen_fixture_slow_endpoint', $lead_gen_fixture_slow_endpoint);
