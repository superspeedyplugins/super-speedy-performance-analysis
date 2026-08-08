<?php
defined('ABSPATH') || exit;

/**
 * Bounded WP-Cron outbox runner. One item per invocation protects shared hosting.
 */
class SSPA_Community_Worker {

    const LOCK_OPTION = 'sspa_submission_worker_lock';
    const LOCK_SECONDS = 300;

    public static function register() {
        add_action('sspa_submission_worker_event', array(__CLASS__, 'run'));
        add_action('init', array(__CLASS__, 'maybe_nudge'));
    }

    public static function maybe_nudge() {
        if (get_option('sspa_share_optin') && !wp_next_scheduled('sspa_submission_worker_event')) {
            SSPA_Community_Outbox::nudge(60);
        }
    }

    public static function run() {
        if (!get_option('sspa_share_optin') || !self::lock()) {
            return;
        }
        try {
            $row = SSPA_Community_Outbox::due();
            if (!$row) {
                return;
            }
            $row = SSPA_Community_Outbox::begin_attempt($row);
            $result = SSPA_Community_Client::submit($row);
            if (is_wp_error($result)) {
                $data = $result->get_error_data();
                $data = is_array($data) ? $data : array();
                SSPA_Community_Outbox::failed(
                    $row['id'],
                    $result,
                    !empty($data['permanent']),
                    isset($data['http_status']) ? $data['http_status'] : null,
                    isset($data['retry_after']) ? $data['retry_after'] : null
                );
                return;
            }
            SSPA_Community_Outbox::sent($row['id'], $result['receipt_uuid'], $result['http_status']);
            if (SSPA_Community_Outbox::due()) {
                SSPA_Community_Outbox::nudge(15);
            }
        } finally {
            delete_option(self::LOCK_OPTION);
        }
    }

    private static function lock() {
        $now = time();
        if (add_option(self::LOCK_OPTION, $now, '', false)) {
            return true;
        }
        $existing = (int) get_option(self::LOCK_OPTION);
        if ($existing && $existing > $now - self::LOCK_SECONDS) {
            return false;
        }
        delete_option(self::LOCK_OPTION);
        return add_option(self::LOCK_OPTION, $now, '', false);
    }
}
