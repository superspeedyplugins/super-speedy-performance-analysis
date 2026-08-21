<?php
defined('ABSPATH') || exit;

/**
 * Durable immutable payload outbox. Network failures never alter or remove payload bytes.
 */
class SSPA_Community_Outbox {

    /**
     * How long a claimed row stays undue while its attempt is in flight.
     *
     * One attempt can spend 30s reserving, 120s uploading and 120s completing, and the worker
     * holds its lease for 300s. If the process dies mid-attempt nothing runs sent() or failed(),
     * so without a horizon here the row would still carry a next_attempt in the past and the
     * next worker invocation would retry it instantly - walking the whole 15min/1h/6h/24h
     * backoff ladder in minutes of wall clock. A dead attempt must look like a scheduled retry.
     */
    const ATTEMPT_LEASE_SECONDS = 600;

    /** Attempts a 'permanent' failure gets before it is treated as one. */
    const PERMANENT_GRACE = 3;

    /**
     * @param string $consent_scope 'automatic' (the site-wide setting) or 'manual' (the owner
     *                              explicitly chose to share this one analysis). A manual share
     *                              is its own act of consent, so it does not require - and never
     *                              enables - the site-wide setting.
     */
    public static function queue_run($run_id, $consent_scope = 'automatic') {
        global $wpdb;
        $consent_scope = ('manual' === $consent_scope) ? 'manual' : 'automatic';
        if ('manual' !== $consent_scope && !get_option('sspa_share_optin')) {
            return new WP_Error('sspa_not_opted_in', __('Sharing is not enabled.', 'super-speedy-performance-analysis'));
        }
        $run = $wpdb->get_row($wpdb->prepare(
            'SELECT id, run_uuid, run_type FROM %i WHERE id = %d',
            SSPA_Schema::table('runs'),
            (int) $run_id
        ), ARRAY_A);
        if (!$run || !wp_is_uuid($run['run_uuid'], 4)) {
            return new WP_Error('sspa_invalid_run', __('The analysis run cannot be queued for sharing.', 'super-speedy-performance-analysis'));
        }
        $existing = self::for_run_uuid($run['run_uuid']);
        if ($existing) {
            return $existing;
        }

        $submission_uuid = wp_generate_uuid4();
        $created_at = SSPA_Community_Schema::canonical_time();
        $payload = SSPA_Community_Exporter::build((int) $run_id, $submission_uuid, $created_at, $consent_scope);
        if (is_wp_error($payload)) {
            self::remember_local_error($run_id, $payload);
            return $payload;
        }
        $json = SSPA_Community_Schema::encode($payload);
        if (is_wp_error($json)) {
            self::remember_local_error($run_id, $json);
            return $json;
        }
        $uncompressed_bytes = strlen($json);
        if ($uncompressed_bytes > SSPA_Community_Schema::MAX_UNCOMPRESSED_BYTES) {
            $error = new WP_Error('sspa_payload_uncompressed_too_large', __('The community payload exceeds the 256 MiB uncompressed limit.', 'super-speedy-performance-analysis'));
            self::remember_local_error($run_id, $error);
            return $error;
        }
        $gzip = SSPA_Community_Schema::compress($json);
        if (is_wp_error($gzip)) {
            self::remember_local_error($run_id, $gzip);
            return $gzip;
        }
        $compressed_bytes = strlen($gzip);
        if ($compressed_bytes > SSPA_Community_Schema::MAX_COMPRESSED_BYTES) {
            $error = new WP_Error('sspa_payload_compressed_too_large', __('The community payload exceeds the 32 MiB compressed limit.', 'super-speedy-performance-analysis'));
            self::remember_local_error($run_id, $error);
            return $error;
        }
        $now = gmdate('Y-m-d H:i:s');
        $inserted = $wpdb->insert(self::table(), array(
            'submission_uuid' => $submission_uuid,
            'run_id' => (int) $run_id,
            'run_uuid' => $run['run_uuid'],
            'run_type' => $run['run_type'],
            'transport_version' => SSPA_Community_Schema::TRANSPORT_VERSION,
            'payload_schema_major' => SSPA_Community_Schema::PAYLOAD_SCHEMA_MAJOR,
            'payload_schema_minor' => SSPA_Community_Schema::PAYLOAD_SCHEMA_MINOR,
            'anonymisation_version' => SSPA_Community_Schema::ANONYMISATION_VERSION,
            'client_version' => SSPA_VERSION,
            'payload_created_at' => $created_at,
            'payload_gzip' => $gzip,
            'payload_sha256' => hash('sha256', $gzip),
            'compressed_bytes' => $compressed_bytes,
            'uncompressed_bytes' => $uncompressed_bytes,
            'state' => 'pending',
            'phase' => 'pending',
            'consent_scope' => $consent_scope,
            'attempts' => 0,
            'next_attempt' => $now,
            'created' => $now,
        ));
        if (!$inserted) {
            $existing = self::for_run_uuid($run['run_uuid']);
            return $existing ?: new WP_Error('sspa_outbox_insert_failed', __('The community payload could not be stored in the local outbox.', 'super-speedy-performance-analysis'));
        }
        $row = self::get((int) $wpdb->insert_id);
        self::event($row['id'], 'pending', 'pending', 0, 'payload_created');
        if ('manual' === $consent_scope) {
            // Autoloaded flag so the per-request nudge can spot manually shared work without
            // querying the outbox on every page load.
            update_option('sspa_share_manual_pending', 1);
        }
        self::nudge();
        return $row;
    }

    /**
     * Explicit one-off sharing of a single analysis, independent of the site-wide setting.
     */
    public static function share_run($run_id) {
        $run = SSPA_Run_Controller::run_row((int) $run_id);
        if (!$run || !in_array($run['status'], array('done', 'failed'), true)) {
            return new WP_Error('sspa_run_not_shareable', __('Only a completed analysis can be shared.', 'super-speedy-performance-analysis'));
        }
        if ('failed' === $run['status'] && 'checkout' !== $run['run_type']) {
            return new WP_Error('sspa_run_not_shareable', __('That analysis did not complete, so there is nothing coherent to share.', 'super-speedy-performance-analysis'));
        }
        $queued = self::queue_run((int) $run_id, 'manual');
        if (is_wp_error($queued)) {
            return $queued;
        }
        update_option('sspa_share_manual_consent_version', SSPA_Community_Schema::CONSENT_VERSION, false);
        return $queued;
    }

    public static function queue_latest() {
        global $wpdb;
        $run_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM %i
             WHERE status = 'done' OR (run_type = 'checkout' AND status = 'failed')
             ORDER BY id DESC LIMIT 1",
            SSPA_Schema::table('runs')
        ));
        return $run_id ? self::queue_run($run_id) : new WP_Error('sspa_no_run', __('Run an analysis first - there is nothing to share yet.', 'super-speedy-performance-analysis'));
    }

    public static function on_terminal_run($run_id) {
        if (!get_option('sspa_share_optin')) {
            return;
        }
        self::queue_run((int) $run_id);
    }

    /**
     * The single authority on what this site is currently allowed to deliver. With the
     * site-wide setting off, only analyses the owner explicitly chose to share remain due.
     */
    public static function due() {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $consent_clause = get_option('sspa_share_optin') ? '' : " AND consent_scope = 'manual'";
        return $wpdb->get_row($wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $consent_clause is one of two literals chosen above, never input.
            "SELECT * FROM %i
             WHERE state IN ('pending','retry') AND (next_attempt IS NULL OR next_attempt <= %s)
             $consent_clause
             ORDER BY created ASC, id ASC LIMIT 1",
            self::table(),
            $now
        ), ARRAY_A);
    }

    /**
     * Is any explicitly shared analysis still working its way out? Includes rows inside their
     * retry backoff, which is exactly the case "is anything due?" misses.
     */
    public static function has_pending_manual() {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM %i WHERE consent_scope = 'manual' AND state IN ('pending','retry') LIMIT 1",
            self::table()
        ));
    }

    public static function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', self::table(), (int) $id), ARRAY_A);
    }

    public static function for_run_uuid($run_uuid) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE run_uuid = %s', self::table(), $run_uuid), ARRAY_A);
    }

    public static function latest() {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM %i ORDER BY id DESC LIMIT 1', self::table()), ARRAY_A);
    }

    public static function preview($row) {
        if (!$row || empty($row['payload_gzip'])) {
            return new WP_Error('sspa_payload_not_retained', __('The exact local payload is no longer retained.', 'super-speedy-performance-analysis'));
        }
        $json = gzdecode($row['payload_gzip']);
        if (false === $json || strlen($json) !== (int) $row['uncompressed_bytes']) {
            return new WP_Error('sspa_payload_corrupt', __('The stored community payload failed its local integrity check.', 'super-speedy-performance-analysis'));
        }
        if (!hash_equals($row['payload_sha256'], hash('sha256', $row['payload_gzip']))) {
            return new WP_Error('sspa_payload_hash_mismatch', __('The stored community payload hash does not match.', 'super-speedy-performance-analysis'));
        }
        return $json;
    }

    /**
     * Claim one row for delivery. Compare-and-set on (id, attempts, state): due() is a plain
     * read and two WP-Cron spawns can overlap, so the claim itself has to be what decides who
     * sends. A worker that loses the race gets null and must not submit.
     *
     * @return array|null The claimed row, or null when another worker already has it.
     */
    public static function begin_attempt($row) {
        global $wpdb;
        $attempt = (int) $row['attempts'] + 1;
        $table = self::table();
        $claimed = $wpdb->query($wpdb->prepare(
            "UPDATE $table
                SET state = 'retry', phase = 'reserving', attempts = %d,
                    last_attempt = %s, next_attempt = %s,
                    last_http_status = NULL, last_error_code = NULL, last_error_detail = NULL
              WHERE id = %d AND attempts = %d AND state IN ('pending','retry')",
            $attempt,
            gmdate('Y-m-d H:i:s'),
            gmdate('Y-m-d H:i:s', time() + self::ATTEMPT_LEASE_SECONDS),
            (int) $row['id'],
            (int) $row['attempts']
        ));
        if (1 !== (int) $claimed) {
            return null;
        }
        self::event($row['id'], 'retry', 'reserving', $attempt, 'attempt_started');
        return self::get($row['id']);
    }

    public static function phase($id, $phase, $fields = array()) {
        global $wpdb;
        $allowed = array('reserving', 'uploading', 'completing');
        if (!in_array($phase, $allowed, true)) {
            return;
        }
        $data = array('phase' => $phase);
        foreach (array('reservation_uuid', 'upload_expires_at') as $key) {
            if (array_key_exists($key, $fields)) {
                $data[$key] = $fields[$key];
            }
        }
        $wpdb->update(self::table(), $data, array('id' => (int) $id));
        $row = self::get($id);
        self::event($id, $row['state'], $phase, $row['attempts'], 'phase_changed');
    }

    public static function sent($id, $receipt_uuid, $http_status) {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->update(self::table(), array(
            'state' => 'sent',
            'phase' => 'sent',
            'receipt_uuid' => $receipt_uuid,
            'next_attempt' => null,
            'last_http_status' => (int) $http_status,
            'last_error_code' => null,
            'last_error_detail' => null,
            'sent_at' => $now,
        ), array('id' => (int) $id));
        $row = self::get($id);
        self::event($id, 'sent', 'sent', $row['attempts'], 'receipt_verified');
    }

    public static function failed($id, $error, $permanent = false, $http_status = null, $retry_after = null) {
        global $wpdb;
        $row = self::get($id);
        $attempt = $row ? (int) $row['attempts'] : 1;
        $code = is_wp_error($error) ? sanitize_key($error->get_error_code()) : 'unknown_error';
        $detail = is_wp_error($error) ? $error->get_error_message() : __('Submission attempt failed.', 'super-speedy-performance-analysis');
        $delay = $retry_after ? max(60, (int) $retry_after) : self::retry_delay($attempt);
        // Give a "permanent" failure PERMANENT_GRACE attempts before believing it. A wrong
        // collector URL 404s identically to a genuine rejection, and putting every queued item
        // into permanent_failure on attempt one turns a corrected typo into a manual Retry now
        // per row. A truly permanent error simply fails again and lands there anyway.
        $state = ($permanent && $attempt >= self::PERMANENT_GRACE) ? 'permanent_failure' : 'retry';
        $wpdb->update(self::table(), array(
            'state' => $state,
            'phase' => $state,
            'next_attempt' => ('permanent_failure' === $state) ? null : gmdate('Y-m-d H:i:s', time() + $delay),
            'last_http_status' => $http_status ? (int) $http_status : null,
            'last_error_code' => substr($code, 0, 64),
            'last_error_detail' => substr(wp_strip_all_tags($detail), 0, 255),
        ), array('id' => (int) $id));
        self::event($id, $state, $state, $attempt, $code);
        if ('retry' === $state) {
            self::nudge($delay);
        }
    }

    public static function history($limit = 20) {
        global $wpdb;
        $limit = max(1, min(100, (int) $limit));
        return $wpdb->get_results($wpdb->prepare(
            'SELECT o.*, r.run_type, r.started AS run_started
             FROM %i o
             JOIN %i r ON r.id = o.run_id
             ORDER BY o.id DESC LIMIT %d',
            self::table(),
            SSPA_Schema::table('runs'),
            $limit
        ), ARRAY_A);
    }

    public static function counts() {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT state, COUNT(*) c FROM %i GROUP BY state', self::table()), ARRAY_A);
        $counts = array('pending' => 0, 'retry' => 0, 'sent' => 0, 'permanent_failure' => 0, 'cancelled' => 0);
        foreach ($rows as $row) {
            $counts[$row['state']] = (int) $row['c'];
        }
        return $counts;
    }

    public static function queue_status() {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT MIN(created) AS oldest_pending, MIN(next_attempt) AS next_attempt
             FROM %i WHERE state IN ('pending','retry')",
            self::table()
        ), ARRAY_A);
        return array(
            'oldest_pending' => !empty($row['oldest_pending']) ? $row['oldest_pending'] : null,
            'next_attempt' => !empty($row['next_attempt']) ? $row['next_attempt'] : null,
        );
    }

    public static function retry_now($id) {
        global $wpdb;
        $row = self::get($id);
        if ($row && self::is_in_flight($row)) {
            return new WP_Error('sspa_outbox_in_flight', __('That submission is currently being delivered.', 'super-speedy-performance-analysis'));
        }
        if (!$row || !in_array($row['state'], array('retry', 'permanent_failure'), true)) {
            return new WP_Error('sspa_outbox_not_retryable', __('That submission is not waiting for a retry.', 'super-speedy-performance-analysis'));
        }
        $wpdb->update(self::table(), array(
            'state' => 'pending',
            'phase' => 'pending',
            'next_attempt' => gmdate('Y-m-d H:i:s'),
            'last_error_code' => null,
            'last_error_detail' => null,
        ), array('id' => (int) $id));
        self::event($id, 'pending', 'pending', (int) $row['attempts'], 'manual_retry');
        self::nudge();
        return true;
    }

    public static function pause($id) {
        global $wpdb;
        $row = self::get($id);
        if ($row && self::is_in_flight($row)) {
            return new WP_Error('sspa_outbox_in_flight', __('That submission is currently being delivered.', 'super-speedy-performance-analysis'));
        }
        if (!$row || !in_array($row['state'], array('pending', 'retry', 'permanent_failure'), true)) {
            return new WP_Error('sspa_outbox_not_pausable', __('That submission cannot be paused.', 'super-speedy-performance-analysis'));
        }
        $wpdb->update(self::table(), array(
            'state' => 'cancelled',
            'phase' => 'cancelled',
            'next_attempt' => null,
        ), array('id' => (int) $id));
        self::event($id, 'cancelled', 'cancelled', (int) $row['attempts'], 'manual_pause');
        return true;
    }

    public static function resume($id) {
        global $wpdb;
        $row = self::get($id);
        if (!$row || 'cancelled' !== $row['state']) {
            return new WP_Error('sspa_outbox_not_resumable', __('That submission is not paused.', 'super-speedy-performance-analysis'));
        }
        $wpdb->update(self::table(), array(
            'state' => 'pending',
            'phase' => 'pending',
            'next_attempt' => gmdate('Y-m-d H:i:s'),
            'last_error_code' => null,
            'last_error_detail' => null,
        ), array('id' => (int) $id));
        self::event($id, 'pending', 'pending', (int) $row['attempts'], 'manual_resume');
        self::nudge();
        return true;
    }

    public static function nudge($delay = 5) {
        $timestamp = time() + max(1, (int) $delay);
        $next = wp_next_scheduled('sspa_submission_worker_event');
        if (!$next || $next > $timestamp + 60) {
            wp_schedule_single_event($timestamp, 'sspa_submission_worker_event');
        }
    }

    private static function retry_delay($attempt) {
        $schedule = array(900, 3600, 21600, 86400);
        $base = isset($schedule[$attempt - 1]) ? $schedule[$attempt - 1] : DAY_IN_SECONDS;
        return $base + wp_rand(0, max(60, (int) floor($base * 0.1)));
    }

    private static function is_in_flight($row) {
        return in_array($row['phase'], array('reserving', 'uploading', 'completing'), true);
    }

    private static function event($outbox_id, $state, $phase, $attempt, $reason) {
        global $wpdb;
        $wpdb->insert(self::events_table(), array(
            'outbox_id' => (int) $outbox_id,
            'state' => sanitize_key($state),
            'phase' => sanitize_key($phase),
            'attempt' => (int) $attempt,
            'reason_code' => substr(sanitize_key($reason), 0, 64),
            'created' => gmdate('Y-m-d H:i:s'),
        ));
    }

    private static function remember_local_error($run_id, $error) {
        $errors = get_option('sspa_submission_build_errors', array());
        $errors[(int) $run_id] = array(
            'time' => gmdate('Y-m-d H:i:s'),
            'code' => sanitize_key($error->get_error_code()),
            'detail' => substr(wp_strip_all_tags($error->get_error_message()), 0, 255),
        );
        update_option('sspa_submission_build_errors', array_slice($errors, -20, null, true), false);
    }

    private static function table() {
        return SSPA_Schema::table('submission_outbox');
    }

    private static function events_table() {
        return SSPA_Schema::table('submission_events');
    }
}
