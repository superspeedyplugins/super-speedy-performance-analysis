<?php
defined('ABSPATH') || exit;

/**
 * Reads MySQL's own normalised query statistics from
 * performance_schema.events_statements_summary_by_digest.
 *
 * This is the only source that gives ROWS EXAMINED - how many rows the server actually read
 * to answer a query, as opposed to how many it returned. A query returning 10 rows after
 * examining 500,000 is the classic killer, and nothing else we have can see it: EXPLAIN gives
 * the optimiser's guess, and our own capture only sees the rows that came back.
 *
 * It also has no dependence on retained SQL, so unlike EXPLAIN (see SSPA_Explain) it is not
 * limited to the ~20 queries per page whose full text we keep.
 *
 * HOW IT WORKS: the digest table is CUMULATIVE and SERVER-WIDE. Absolute totals are useless
 * to us - they include every other site on the box and every request since MySQL started. So
 * we snapshot before the run, snapshot after, and diff. Even then the delta includes other
 * traffic, which is why nothing is reported unless it matches a query THIS run captured.
 *
 * Needs SELECT on performance_schema, which a WordPress database user does not have by
 * default. The Tools tab generates the exact GRANT. See SSPA_Tools::performance_schema().
 */
class SSPA_Digests {

    /** SUM_TIMER_WAIT and friends are in picoseconds. */
    const PICOSECONDS_PER_MS = 1000000000;

    const OPTION_PREFIX = 'sspa_ps_before_';

    public static function readable() {
        $ps = SSPA_Tools::performance_schema();
        return !empty($ps['readable']);
    }

    /**
     * Current digest counters for THIS database, keyed by our own fingerprint of the
     * digest text so they can be joined to what we captured.
     *
     * MySQL's DIGEST_TEXT already parameterises literals to "?", and sspa_sql_fingerprint()
     * does the same, so running ours over theirs converges the two shapes onto one key.
     *
     * @return array|null Null when unreadable.
     */
    public static function snapshot() {
        global $wpdb;

        if (!self::readable()) {
            return null;
        }
        require_once SSPA_PLUGIN_DIR . 'profiler/fingerprint.php';

        $suppress = $wpdb->suppress_errors(true);
        $rows = $wpdb->get_results(
            'SELECT DIGEST, DIGEST_TEXT, COUNT_STAR, SUM_TIMER_WAIT, SUM_ROWS_EXAMINED,
                    SUM_ROWS_SENT, SUM_NO_INDEX_USED, SUM_CREATED_TMP_DISK_TABLES,
                    SUM_SELECT_FULL_JOIN, SUM_SORT_MERGE_PASSES
             FROM performance_schema.events_statements_summary_by_digest
             WHERE SCHEMA_NAME = DATABASE()',
            ARRAY_A
        );
        $wpdb->suppress_errors($suppress);

        if (!is_array($rows)) {
            return null;
        }

        $snapshot = array('_overflow' => false, 'digests' => array());
        foreach ($rows as $row) {
            // A NULL DIGEST is the overflow bucket: the digest table filled up and rows have
            // been collapsed into it. Reporting numbers from a truncated table would be
            // quietly wrong, so we detect it and say so instead.
            if ($row['DIGEST'] === null || $row['DIGEST_TEXT'] === null) {
                $snapshot['_overflow'] = true;
                continue;
            }
            $key = md5(sspa_sql_fingerprint(self::normalise($row['DIGEST_TEXT'])));
            $snapshot['digests'][$key] = array(
                'text' => $row['DIGEST_TEXT'],
                'calls' => (int) $row['COUNT_STAR'],
                'timer' => (float) $row['SUM_TIMER_WAIT'],
                'examined' => (int) $row['SUM_ROWS_EXAMINED'],
                'sent' => (int) $row['SUM_ROWS_SENT'],
                'no_index' => (int) $row['SUM_NO_INDEX_USED'],
                'tmp_disk' => (int) $row['SUM_CREATED_TMP_DISK_TABLES'],
                'full_join' => (int) $row['SUM_SELECT_FULL_JOIN'],
                'sort_merge' => (int) $row['SUM_SORT_MERGE_PASSES'],
            );
        }
        return $snapshot;
    }

    /**
     * MySQL writes digests with its own spacing and backtick habits. Flatten both sides the
     * same way before fingerprinting so the join key matches.
     */
    public static function normalise($sql) {
        $sql = str_replace('`', '', (string) $sql);
        $sql = preg_replace('/\s+/', ' ', $sql);
        return trim($sql);
    }

    /** What changed between two snapshots. Only counters that moved. */
    public static function diff($before, $after) {
        if (!is_array($after) || empty($after['digests'])) {
            return array();
        }
        $before_digests = (is_array($before) && !empty($before['digests'])) ? $before['digests'] : array();

        $delta = array();
        foreach ($after['digests'] as $key => $now) {
            $was = isset($before_digests[$key]) ? $before_digests[$key] : null;
            $calls = $now['calls'] - ($was ? $was['calls'] : 0);
            if ($calls <= 0) {
                continue;
            }
            $delta[$key] = array(
                'text' => $now['text'],
                'calls' => $calls,
                'ms' => round(($now['timer'] - ($was ? $was['timer'] : 0)) / self::PICOSECONDS_PER_MS, 3),
                'examined' => $now['examined'] - ($was ? $was['examined'] : 0),
                'sent' => $now['sent'] - ($was ? $was['sent'] : 0),
                'no_index' => $now['no_index'] - ($was ? $was['no_index'] : 0),
                'tmp_disk' => $now['tmp_disk'] - ($was ? $was['tmp_disk'] : 0),
                'full_join' => $now['full_join'] - ($was ? $was['full_join'] : 0),
                'sort_merge' => $now['sort_merge'] - ($was ? $was['sort_merge'] : 0),
            );
        }
        return $delta;
    }

    /** Stash the pre-run counters. Autoload off: this is transient bulk. */
    public static function begin($run_id) {
        $snapshot = self::snapshot();
        if ($snapshot === null) {
            return false;
        }
        update_option(self::OPTION_PREFIX . (int) $run_id, $snapshot, false);
        return true;
    }

    /**
     * The delta for a run, keyed by fingerprint hash so the analysis engine can join it to
     * the queries it captured. Consumes (and clears) the stored pre-run snapshot.
     */
    public static function collect($run_id) {
        $key = self::OPTION_PREFIX . (int) $run_id;
        $before = get_option($key);
        delete_option($key);

        if (!is_array($before)) {
            return array();
        }
        return self::diff($before, self::snapshot());
    }

    public static function discard($run_id) {
        delete_option(self::OPTION_PREFIX . (int) $run_id);
    }
}
