<?php
defined('ABSPATH') || exit;

/**
 * Compatibility facade for the Share UI and agent ability.
 *
 * Payload creation and transport are deliberately separate: submit() stores immutable bytes
 * locally and schedules the cron worker. It never performs network I/O in an admin request.
 */
class SSPA_Submitter {

    /**
     * Legacy content/rules hub URL. Community submissions use collector_url() instead.
     */
    public static function hub_url() {
        $url = get_option('sspa_hub_url');
        return $url ? untrailingslashit($url) : 'https://superspeedy.org';
    }

    /**
     * Retained for the independently signed rules feed.
     */
    public static function endpoint($path) {
        return self::hub_url() . '/?rest_route=/ssph/v1/' . ltrim($path, '/');
    }

    public static function collector_url() {
        return SSPA_Community_Identity::collector_url();
    }

    public static function opted_in() {
        return (bool) get_option('sspa_share_optin');
    }

    public static function register() {
        return SSPA_Community_Client::register();
    }

    /**
     * Queue the newest shareable run and nudge asynchronous delivery.
     *
     * @return true|WP_Error
     */
    public static function submit() {
        if (!self::opted_in()) {
            return new WP_Error('sspa_not_opted_in', __('Sharing is not enabled - opt in on the Share tab first.', 'super-speedy-performance-analysis'));
        }
        $row = SSPA_Community_Outbox::queue_latest();
        if (is_wp_error($row)) {
            return $row;
        }
        SSPA_Community_Outbox::nudge();
        return true;
    }

    public static function preview($outbox_id = 0) {
        $row = $outbox_id ? SSPA_Community_Outbox::get((int) $outbox_id) : SSPA_Community_Outbox::latest();
        if (!$row && self::opted_in()) {
            $row = SSPA_Community_Outbox::queue_latest();
        }
        return is_wp_error($row) ? $row : SSPA_Community_Outbox::preview($row);
    }

    public static function history() {
        $history = array();
        foreach (SSPA_Community_Outbox::history(20) as $row) {
            $history[] = array(
                'id' => (int) $row['id'],
                'time' => $row['sent_at'] ?: ($row['last_attempt'] ?: $row['created']),
                'ok' => ('sent' === $row['state']),
                'state' => $row['state'],
                'phase' => $row['phase'],
                'run_type' => $row['run_type'],
                'run_started' => $row['run_started'],
                'message' => self::history_message($row),
                'compressed_bytes' => (int) $row['compressed_bytes'],
                'payload_schema' => (int) $row['payload_schema_major'] . '.' . (int) $row['payload_schema_minor'],
                'payload_sha256' => $row['payload_sha256'],
                'receipt_uuid' => $row['receipt_uuid'],
                'next_attempt' => $row['next_attempt'],
                'last_error_code' => $row['last_error_code'],
                'last_error_detail' => $row['last_error_detail'],
            );
        }
        return $history;
    }

    private static function history_message($row) {
        if ('sent' === $row['state']) {
            return sprintf(__('%1$s archived (%2$s)', 'super-speedy-performance-analysis'), $row['run_type'], size_format((int) $row['compressed_bytes']));
        }
        if ('permanent_failure' === $row['state']) {
            return sprintf(__('%1$s requires attention: %2$s', 'super-speedy-performance-analysis'), $row['run_type'], $row['last_error_code']);
        }
        if ('retry' === $row['state']) {
            return sprintf(__('%1$s queued for retry: %2$s', 'super-speedy-performance-analysis'), $row['run_type'], $row['last_error_code']);
        }
        if ('cancelled' === $row['state']) {
            return sprintf(__('%s paused locally', 'super-speedy-performance-analysis'), $row['run_type']);
        }
        return sprintf(__('%s queued locally', 'super-speedy-performance-analysis'), $row['run_type']);
    }
}
