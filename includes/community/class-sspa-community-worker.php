<?php
defined('ABSPATH') || exit;

/**
 * Bounded WP-Cron outbox runner. One item per invocation protects shared hosting.
 */
class SSPA_Community_Worker {

    const LOCK_OPTION = 'sspa_submission_worker_lock';
    const LOCK_SECONDS = 600;

    public static function register() {
        add_action('sspa_submission_worker_event', array(__CLASS__, 'run'));
        add_action('init', array(__CLASS__, 'maybe_nudge'));
    }

    public static function maybe_nudge() {
        if (!wp_next_scheduled('sspa_submission_worker_event')
            && (get_option('sspa_share_optin') || get_option('sspa_share_manual_pending'))) {
            SSPA_Community_Outbox::nudge(60);
        }
    }

    public static function run() {
        // Consent is enforced by SSPA_Community_Outbox::due(), which returns nothing but
        // explicitly shared analyses while the site-wide setting is off.
        $owner = self::lock();
        if (!$owner) {
            return;
        }
        try {
            $row = SSPA_Community_Outbox::due();
            if (!$row) {
                // "Nothing due right now" is not "nothing left to do": a manually shared item
                // inside its retry backoff is neither. Clearing the flag there left maybe_nudge()
                // unable to re-arm on a site with sharing off, so the item depended on a single
                // scheduled event and was stranded if that event was lost.
                if (!SSPA_Community_Outbox::has_pending_manual()) {
                    delete_option('sspa_share_manual_pending');
                }
                return;
            }
            $row = SSPA_Community_Outbox::begin_attempt($row);
            if (!$row) {
                // Another worker claimed it between the read and the claim. Its attempt is the
                // one that counts; sending as well would duplicate the payload upload.
                return;
            }
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
            SSPA_Atomic_Claim::release(self::LOCK_OPTION, $owner);
        }
    }

    private static function lock() {
        return SSPA_Atomic_Claim::acquire(self::LOCK_OPTION, self::LOCK_SECONDS);
    }
}
