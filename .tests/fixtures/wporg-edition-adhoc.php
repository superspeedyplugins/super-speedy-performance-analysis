<?php
/**
 * Plugin Name: SSPA test fixture - start an administrator ad hoc run
 *
 * Test-only MU plugin for .tests/wporg/edition/run.sh. Starts the real SSPA_Run_Controller::start()
 * from inside a real admin-ajax request, so the administrator measurement rides on the
 * browser-equivalent login cookies that request carries. The wordpress.org edition has no MCP
 * ability that starts a run, and the screen's own buttons choose the variant from the URL, so
 * this is the narrowest way to ask for an admin-variant REST measurement through the real code.
 */
defined('ABSPATH') || exit;

add_action('wp_ajax_sspa_test_admin_adhoc', static function () {
    check_ajax_referer('sspa_admin', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('forbidden', 403);
    }
    $url = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';
    $run_id = SSPA_Run_Controller::start(array('type' => 'adhoc', 'url' => $url, 'variant' => 'admin', 'samples' => 1, 'trigger' => 'ajax-endpoint'));
    if (is_wp_error($run_id)) {
        wp_send_json_error($run_id->get_error_message());
    }
    wp_send_json_success(array('run_id' => (int) $run_id));
});
