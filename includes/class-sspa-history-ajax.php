<?php
defined('ABSPATH') || exit;

/** Authenticated WordPress AJAX adapter; comparison decisions belong to the domain. */
class SSPA_History_Ajax {
    private static function ajax_guard() {
        check_ajax_referer('sspa_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('forbidden', 403);
        }
    }

    public static function ajax_compare() {
        self::ajax_guard();
        // Resolve once so the chart and report cannot silently describe different runs.
        $mode = isset($_POST['selection_mode']) ? sanitize_key(wp_unslash($_POST['selection_mode'])) : 'pair';
        $series = SSPA_History_Series::build(
            isset($_POST['after_run_id']) ? (int) $_POST['after_run_id'] : 0,
            isset($_POST['metric']) ? sanitize_key(wp_unslash($_POST['metric'])) : 'request_wall_ms',
            isset($_POST['before_run_id']) ? (int) $_POST['before_run_id'] : 0,
            $mode
        );
        if (is_wp_error($series)) {
            wp_send_json_error($series->get_error_message());
        }
        $before_ids = !empty($series['previous']['run_ids']) ? $series['previous']['run_ids'] : array();
        // Period IDs are chronological: the baseline is the last run before the update.
        $before_id = $before_ids ? (int) end($before_ids) : 0;
        $comparison = $before_id ? SSPA_History::compare($before_id, (int) $series['anchor_run_id']) : null;
        if ($before_id && is_wp_error($comparison)) {
            wp_send_json_error($comparison->get_error_message());
        }
        wp_send_json_success(array(
            'html' => $before_id ? SSPA_History::render($comparison) : '<p>' . esc_html__('No saved Before run selected. Available After measurements are shown.', 'super-speedy-performance-analysis') . '</p>',
            'comparison' => $before_id ? $comparison : null,
            'chart_html' => SSPA_History_Chart::render($series),
            'before_run_id' => $before_id,
            'after_run_id' => (int) $series['anchor_run_id'],
        ));
    }

    public static function ajax_setting() {
        self::ajax_guard();
        $enabled = !empty($_POST['plugin_update_detection']);
        sspa_update_option('plugin_update_detection', $enabled);
        if (!$enabled) {
            SSPA_Change_Set::dismiss();
        }
        wp_send_json_success(array('plugin_update_detection' => (bool) sspa_get_option('plugin_update_detection')));
    }

    public static function ajax_assertion() {
        self::ajax_guard();
        $mode = isset($_POST['mode']) ? sanitize_key(wp_unslash($_POST['mode'])) : '';
        $page = isset($_POST['page_identity']) ? sanitize_text_field(wp_unslash($_POST['page_identity'])) : '';
        $before_id = isset($_POST['before_run_id']) ? (int) $_POST['before_run_id'] : 0;
        $after_id = isset($_POST['after_run_id']) ? (int) $_POST['after_run_id'] : 0;
        $result = 'approve' === $mode ? SSPA_History::approve_assertion($after_id, $page) : SSPA_History::clear_assertion($page);
        if (is_wp_error($result) || !$result) {
            wp_send_json_error(is_wp_error($result) ? $result->get_error_message() : __('That expectation could not be changed.', 'super-speedy-performance-analysis'));
        }
        $comparison = SSPA_History::compare($before_id, $after_id);
        if (is_wp_error($comparison)) {
            wp_send_json_error($comparison->get_error_message());
        }
        wp_send_json_success(array('html' => SSPA_History::render($comparison)));
    }

    public static function ajax_export() {
        self::ajax_guard();
        $comparison = SSPA_History::compare(
            isset($_POST['before_run_id']) ? (int) $_POST['before_run_id'] : 0,
            isset($_POST['after_run_id']) ? (int) $_POST['after_run_id'] : 0
        );
        if (is_wp_error($comparison)) {
            wp_send_json_error($comparison->get_error_message());
        }
        $payload = SSPA_History::export($comparison);
        wp_send_json_success(array(
            'payload' => $payload,
            'filename' => sspa_download_filename(sprintf('sspa-history-%d-vs-%d.json', (int) $comparison['before']['id'], (int) $comparison['after']['id'])),
        ));
    }

}
