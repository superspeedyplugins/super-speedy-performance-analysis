<?php
defined('ABSPATH') || exit;

/** Administrator controls for the experimental traffic collector. */
class SSPA_Traffic_Ajax {

    public static function register() {
        add_action('wp_ajax_sspa_traffic_start', array(__CLASS__, 'start'));
        add_action('wp_ajax_sspa_traffic_status', array(__CLASS__, 'status'));
        add_action('wp_ajax_sspa_traffic_stop', array(__CLASS__, 'stop'));
        add_action('wp_ajax_sspa_traffic_observations', array(__CLASS__, 'observations'));
        add_action('wp_ajax_sspa_traffic_delete', array(__CLASS__, 'delete'));
    }

    private static function guard() {
        check_ajax_referer('sspa_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You are not allowed to manage traffic collection.', 'super-speedy-performance-analysis'), 403);
        }
    }

    public static function start() {
        self::guard();
        if (empty($_POST['confirmed'])) {
            wp_send_json_error(__('Confirm that you have read the privacy and resource limits before starting.', 'super-speedy-performance-analysis'));
        }
        $duration = isset($_POST['duration']) ? sanitize_key(wp_unslash($_POST['duration'])) : '24h';
        $result = SSPA_Traffic_Collection::start($duration, 'admin');
        self::send($result);
    }

    public static function status() {
        self::guard();
        $collection_id = isset($_POST['collection_id']) ? (int) $_POST['collection_id'] : 0;
        self::send(SSPA_Traffic_Collection::status($collection_id));
    }

    public static function stop() {
        self::guard();
        $collection_id = isset($_POST['collection_id']) ? (int) $_POST['collection_id'] : 0;
        $emergency = !empty($_POST['emergency']);
        self::send(SSPA_Traffic_Collection::stop($collection_id, $emergency));
    }

    public static function observations() {
        self::guard();
        $collection_id = isset($_POST['collection_id']) ? (int) $_POST['collection_id'] : 0;
        $payload = SSPA_Traffic_Collection::observations($collection_id);
        if (is_wp_error($payload)) {
            wp_send_json_error($payload->get_error_message());
        }
        wp_send_json_success(array(
            'filename' => 'sspa-experimental-traffic-observations-' . (int) $payload['collection']['id'] . '-' . gmdate('Ymd-His') . '.json',
            'payload' => $payload,
        ));
    }

    public static function delete() {
        self::guard();
        $collection_id = isset($_POST['collection_id']) ? (int) $_POST['collection_id'] : 0;
        self::send(SSPA_Traffic_Collection::delete($collection_id));
    }

    private static function send($result) {
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        wp_send_json_success($result);
    }
}
