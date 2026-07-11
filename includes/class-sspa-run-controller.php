<?php
defined('ABSPATH') || exit;

/**
 * Run state machine: queued -> crawling -> done | failed | cancelled. Jobs are processed
 * in time-boxed batches, driven by the admin page's JS (sequential AJAX calls) with a
 * WP-Cron event as backup for headless progress. Crash safety: a held foreign db.php is
 * restored on finish, on failure, and by the stale-hold check on plugins_loaded.
 */
class SSPA_Run_Controller {

    const BATCH_SECONDS = 15;

    public static function register() {
        add_action('sspa_process_batch_event', array(__CLASS__, 'process_batch'));
        add_action('sspa_cleanup_event', array(__CLASS__, 'cleanup'));
        add_action('plugins_loaded', array('SSPA_Helper_Files', 'stale_hold_check'), 20);

        add_action('wp_ajax_sspa_start_run', array(__CLASS__, 'ajax_start_run'));
        add_action('wp_ajax_sspa_process_batch', array(__CLASS__, 'ajax_process_batch'));
        add_action('wp_ajax_sspa_run_status', array(__CLASS__, 'ajax_run_status'));
        add_action('wp_ajax_sspa_cancel_run', array(__CLASS__, 'ajax_cancel_run'));
        if (!wp_next_scheduled('sspa_cleanup_event')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'sspa_cleanup_event');
        }
    }

    // ---------------- run lifecycle ----------------

    /**
     * @param array $args {type, page_keys, swap_dropin, user_id}
     * @return int|WP_Error run id
     */
    public static function start($args = array()) {
        global $wpdb;

        if (self::active_run_id()) {
            return new WP_Error('sspa_run_active', __('An analysis is already running.', 'super-speedy-performance-analysis'));
        }

        SSPA_Helper_Files::ensure_installed();
        $health = SSPA_Helper_Files::health();
        if (!$health['mu']) {
            return new WP_Error('sspa_no_mu', __('The mu-plugin loader could not be installed (wp-content/mu-plugins is not writable).', 'super-speedy-performance-analysis'));
        }

        if (!empty($args['swap_dropin']) && in_array($health['dropin'], array('foreign', 'qm'), true)) {
            SSPA_Helper_Files::hold_foreign_dropin();
        }

        $user_id = !empty($args['user_id']) ? (int) $args['user_id'] : get_current_user_id();
        if (!$user_id) {
            $admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
            $user_id = $admins ? (int) $admins[0] : 0;
        }

        $jobs = SSPA_Catalogue::build(!empty($args['page_keys']) ? (array) $args['page_keys'] : array());
        if (!$jobs) {
            return new WP_Error('sspa_no_jobs', __('No pages found to profile.', 'super-speedy-performance-analysis'));
        }

        $wpdb->insert(SSPA_Schema::table('runs'), array(
            'blog_id' => get_current_blog_id(),
            'run_type' => !empty($args['type']) ? $args['type'] : 'baseline',
            'trigger_source' => !empty($args['trigger']) ? $args['trigger'] : 'manual',
            'status' => 'crawling',
            'plugin_set' => wp_json_encode(array(
                'plugins' => (array) get_option('active_plugins', array()),
                'user_id' => $user_id,
            )),
            'plugin_set_hash' => md5(wp_json_encode(get_option('active_plugins', array()))),
            'started' => gmdate('Y-m-d H:i:s'),
        ));
        $run_id = (int) $wpdb->insert_id;

        update_option('sspa_queue_' . $run_id, array('jobs' => $jobs, 'idx' => 0, 'user_id' => $user_id), false);
        wp_schedule_single_event(time() + 5, 'sspa_process_batch_event', array($run_id));

        return $run_id;
    }

    public static function active_run_id() {
        global $wpdb;
        $table = SSPA_Schema::table('runs');
        return (int) $wpdb->get_var("SELECT id FROM $table WHERE status IN ('queued','crawling','analysing') ORDER BY id DESC LIMIT 1");
    }

    public static function run_row($run_id) {
        global $wpdb;
        $table = SSPA_Schema::table('runs');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $run_id), ARRAY_A);
    }

    private static function set_status($run_id, $status, $finished = false) {
        global $wpdb;
        $data = array('status' => $status);
        if ($finished) {
            $data['finished'] = gmdate('Y-m-d H:i:s');
        }
        $wpdb->update(SSPA_Schema::table('runs'), $data, array('id' => $run_id));
    }

    /**
     * Time-boxed batch. Safe to call concurrently (lock) and repeatedly (idempotent).
     */
    public static function process_batch($run_id) {
        $run_id = (int) $run_id;
        $run = self::run_row($run_id);
        if (!$run || 'crawling' !== $run['status']) {
            return;
        }

        // Lock: option add is atomic; stale locks expire after 120s.
        $lock_key = 'sspa_lock_' . $run_id;
        $existing = get_option($lock_key);
        if ($existing && $existing > time() - 120) {
            return;
        }
        update_option($lock_key, time(), false);

        try {
            $queue = get_option('sspa_queue_' . $run_id);
            if (!is_array($queue)) {
                self::fail($run_id, 'queue missing');
                return;
            }

            $crawler = new SSPA_Crawler();
            $deadline = microtime(true) + self::BATCH_SECONDS;

            while ($queue['idx'] < count($queue['jobs']) && microtime(true) < $deadline) {
                $job = $queue['jobs'][$queue['idx']];
                $result = $crawler->profile_job($job, $queue['user_id']);
                SSPA_Profile_Store::save($run_id, $result);
                $queue['idx']++;
                update_option('sspa_queue_' . $run_id, $queue, false);

                $run = self::run_row($run_id);
                if (!$run || 'crawling' !== $run['status']) {
                    return; // cancelled mid-batch
                }
            }

            if ($queue['idx'] >= count($queue['jobs'])) {
                self::finish($run_id);
            } else {
                wp_schedule_single_event(time() + 2, 'sspa_process_batch_event', array($run_id));
            }
        } finally {
            delete_option($lock_key);
        }
    }

    private static function finish($run_id) {
        SSPA_Helper_Files::restore_held_dropin();
        delete_option('sspa_queue_' . $run_id);
        self::set_status($run_id, 'done', true);
    }

    private static function fail($run_id, $note) {
        global $wpdb;
        SSPA_Helper_Files::restore_held_dropin();
        delete_option('sspa_queue_' . $run_id);
        $wpdb->update(SSPA_Schema::table('runs'), array(
            'status' => 'failed',
            'finished' => gmdate('Y-m-d H:i:s'),
            'notes' => $note,
        ), array('id' => $run_id));
    }

    public static function cancel($run_id) {
        SSPA_Helper_Files::restore_held_dropin();
        delete_option('sspa_queue_' . $run_id);
        self::set_status($run_id, 'cancelled', true);
    }

    public static function status($run_id) {
        global $wpdb;
        $run = self::run_row($run_id);
        if (!$run) {
            return null;
        }
        $queue = get_option('sspa_queue_' . $run_id);
        if (is_array($queue)) {
            $total = count($queue['jobs']);
            $idx = $queue['idx'];
        } else {
            // Queue is deleted once the run leaves the crawling state.
            $total = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . SSPA_Schema::table('profiles') . ' WHERE run_id = %d',
                $run_id
            ));
            $idx = $total;
        }
        return array(
            'run_id' => (int) $run['id'],
            'status' => $run['status'],
            'total' => $total,
            'done' => $idx,
            'current' => (is_array($queue) && isset($queue['jobs'][$idx])) ? $queue['jobs'][$idx]['page_key'] : null,
        );
    }

    /**
     * Hourly: orphaned captures, used-token markers, stale runs.
     */
    public static function cleanup() {
        global $wpdb;
        $captures = SSPA_Schema::table('captures');
        $wpdb->query($wpdb->prepare("DELETE FROM $captures WHERE created < %s", gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS)));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
            $wpdb->esc_like('sspa_used_') . '%',
            time() - HOUR_IN_SECONDS
        ));

        $runs = SSPA_Schema::table('runs');
        $stale = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $runs WHERE status IN ('queued','crawling','analysing') AND started < %s",
            gmdate('Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS)
        ));
        foreach ($stale as $run_id) {
            self::fail((int) $run_id, 'stale - timed out');
        }
    }

    // ---------------- AJAX ----------------

    private static function ajax_guard() {
        check_ajax_referer('sspa_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('forbidden', 403);
        }
    }

    public static function ajax_start_run() {
        self::ajax_guard();
        $run_id = self::start(array(
            'type' => 'baseline',
            'swap_dropin' => !empty($_POST['swap_dropin']),
        ));
        if (is_wp_error($run_id)) {
            wp_send_json_error($run_id->get_error_message());
        }
        wp_send_json_success(self::status($run_id));
    }

    public static function ajax_process_batch() {
        self::ajax_guard();
        $run_id = isset($_POST['run_id']) ? (int) $_POST['run_id'] : self::active_run_id();
        if ($run_id) {
            self::process_batch($run_id);
        }
        wp_send_json_success(self::status($run_id));
    }

    public static function ajax_run_status() {
        self::ajax_guard();
        $run_id = isset($_POST['run_id']) ? (int) $_POST['run_id'] : self::active_run_id();
        wp_send_json_success($run_id ? self::status($run_id) : null);
    }

    public static function ajax_cancel_run() {
        self::ajax_guard();
        $run_id = isset($_POST['run_id']) ? (int) $_POST['run_id'] : self::active_run_id();
        if ($run_id) {
            self::cancel($run_id);
        }
        wp_send_json_success();
    }
}
