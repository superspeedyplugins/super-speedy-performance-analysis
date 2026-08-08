<?php
defined('ABSPATH') || exit;

/**
 * Resumable bounded conversion of historical analysis runs into immutable outbox items.
 */
class SSPA_Community_Backfill {

    const CURSOR_OPTION = 'sspa_community_backfill_cursor';
    const BATCH_LIMIT = 3;

    public static function inventory() {
        global $wpdb;
        $runs = SSPA_Schema::table('runs');
        $profiles = SSPA_Schema::table('profiles');
        $outbox = SSPA_Schema::table('submission_outbox');
        $rows = $wpdb->get_results(
            "SELECT r.id, r.run_type, r.status, r.started,
                    o.id AS outbox_id,
                    COUNT(p.id) AS profile_count,
                    SUM(CASE WHEN p.profile_blob IS NOT NULL THEN 1 ELSE 0 END) AS detailed_profiles,
                    COALESCE(SUM(LENGTH(p.profile_blob)), 0) AS detail_bytes
             FROM $runs r
             LEFT JOIN $outbox o ON o.run_uuid = r.run_uuid
             LEFT JOIN $profiles p ON p.run_id = r.id
             WHERE r.status = 'done' OR (r.run_type = 'checkout' AND r.status = 'failed')
             GROUP BY r.id, r.run_type, r.status, r.started, o.id
             ORDER BY r.id ASC",
            ARRAY_A
        );
        $inventory = array(
            'eligible' => 0,
            'queued' => 0,
            'remaining' => 0,
            'remaining_after_cursor' => 0,
            'with_detail' => 0,
            'scalar_only' => 0,
            'source_detail_bytes' => 0,
            'by_type' => array(),
            'build_errors' => self::errors(),
            'cursor' => (int) get_option(self::CURSOR_OPTION, 0),
        );
        foreach ($rows as $row) {
            $inventory['eligible']++;
            $type = sanitize_key($row['run_type']) ?: 'unknown';
            if (!isset($inventory['by_type'][$type])) {
                $inventory['by_type'][$type] = array('eligible' => 0, 'queued' => 0, 'remaining' => 0);
            }
            $inventory['by_type'][$type]['eligible']++;
            if ($row['outbox_id']) {
                $inventory['queued']++;
                $inventory['by_type'][$type]['queued']++;
                continue;
            }
            $inventory['remaining']++;
            if ((int) $row['id'] > $inventory['cursor']) {
                $inventory['remaining_after_cursor']++;
            }
            $inventory['by_type'][$type]['remaining']++;
            $inventory['source_detail_bytes'] += (int) $row['detail_bytes'];
            if ((int) $row['detailed_profiles'] > 0) {
                $inventory['with_detail']++;
            } elseif ((int) $row['profile_count'] > 0) {
                $inventory['scalar_only']++;
            }
        }
        ksort($inventory['by_type'], SORT_STRING);
        return $inventory;
    }

    /**
     * Queue at most three historical runs. The cursor is advanced past failures so one
     * malformed old run cannot prevent later evidence from being preserved.
     */
    public static function queue_batch($restart = false) {
        global $wpdb;
        if (!SSPA_Submitter::opted_in()) {
            return new WP_Error('sspa_not_opted_in', __('Sharing is not enabled.', 'super-speedy-performance-analysis'));
        }
        if ($restart) {
            self::restart();
        }
        $cursor = (int) get_option(self::CURSOR_OPTION, 0);
        $runs = SSPA_Schema::table('runs');
        $outbox = SSPA_Schema::table('submission_outbox');
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT r.id
             FROM $runs r
             LEFT JOIN $outbox o ON o.run_uuid = r.run_uuid
             WHERE (r.status = 'done' OR (r.run_type = 'checkout' AND r.status = 'failed'))
               AND o.id IS NULL AND r.id > %d
             ORDER BY r.id ASC LIMIT %d",
            $cursor,
            self::BATCH_LIMIT
        ));
        $queued = 0;
        $failed = array();
        foreach ($ids as $run_id) {
            $run_id = (int) $run_id;
            $result = SSPA_Community_Outbox::queue_run($run_id);
            if (is_wp_error($result)) {
                $failed[] = array(
                    'run_id' => $run_id,
                    'code' => sanitize_key($result->get_error_code()),
                    'detail' => $result->get_error_message(),
                );
            } else {
                $queued++;
            }
            $cursor = $run_id;
        }
        update_option(self::CURSOR_OPTION, $cursor, false);
        if ($queued) {
            SSPA_Community_Outbox::nudge();
        }
        $more = (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1
             FROM $runs r
             LEFT JOIN $outbox o ON o.run_uuid = r.run_uuid
             WHERE (r.status = 'done' OR (r.run_type = 'checkout' AND r.status = 'failed'))
               AND o.id IS NULL AND r.id > %d
             LIMIT 1",
            $cursor
        ));
        return array(
            'attempted' => count($ids),
            'queued' => $queued,
            'failed' => $failed,
            'complete' => !$more,
            'inventory' => self::inventory(),
        );
    }

    public static function restart() {
        update_option(self::CURSOR_OPTION, 0, false);
        delete_option('sspa_submission_build_errors');
    }

    public static function errors() {
        $errors = get_option('sspa_submission_build_errors', array());
        return is_array($errors) ? $errors : array();
    }
}
