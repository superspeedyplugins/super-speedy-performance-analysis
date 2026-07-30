<?php
defined('ABSPATH') || exit;

/**
 * Runs EXPLAIN over captured queries to find the problems timing alone cannot see: a query
 * with no usable index, a filesort, a temp table on disk, a full join.
 *
 * The cheapest of the three SQL-analysis sources and the only one that works everywhere. It
 * needs no PHP extension, no second server, and no extra grant beyond the SELECT the site
 * already has. See .docs/implementation-plan-profilers-and-digests.md section A.
 *
 * WHY IT IS SAFE:
 * - SELECT only. EXPLAIN on a SELECT produces a plan; it does not execute the statement.
 *   EXPLAIN ANALYZE would execute it, and is never used here.
 * - Runs during analysis (an admin or cron request, after profiling), never inside a
 *   profiled request, so it can never contaminate a measurement.
 * - Only queries whose FULL SQL was retained are eligible. Fingerprints have their literals
 *   stripped to "?" and re-binding invented values would produce a plan for a query the site
 *   never ran, so those are skipped rather than guessed at.
 * - Errors are suppressed and skipped: a query against a temporary table, or one referencing
 *   something since dropped, must not break analysis.
 *
 * Row counts here are the OPTIMISER'S ESTIMATE, not a measurement. The UI must say so.
 */
class SSPA_Explain {

    /** Distinct fingerprints explained per run. A guard against pathological captures. */
    const MAX_PER_RUN = 200;

    /**
     * MySQL access types that mean "no usable index for this query".
     * ALL = full table scan, index = full index scan (better, still every row).
     */
    private static $scan_types = array('ALL', 'index');

    public static function is_explainable($sql) {
        $sql = ltrim((string) $sql);
        if ($sql === '' || !preg_match('/^SELECT\b/i', $sql)) {
            return false;
        }
        // Refuse anything that could be more than one statement.
        if (strpos($sql, ';') !== false && !preg_match('/;\s*$/', $sql)) {
            return false;
        }
        // A fingerprint, not real SQL - the literals are gone, so any plan would be fiction.
        if (strpos($sql, '?') !== false) {
            return false;
        }
        return true;
    }

    /**
     * @return array|null {access_type, key, possible_keys, est_rows, filtered, extra, scan}
     *                    Null when the query could not be explained.
     */
    public static function explain($sql) {
        global $wpdb;

        if (!self::is_explainable($sql)) {
            return null;
        }

        $suppress = $wpdb->suppress_errors(true);
        $rows = $wpdb->get_results('EXPLAIN ' . rtrim(trim($sql), ';'), ARRAY_A);
        $wpdb->suppress_errors($suppress);

        if (empty($rows) || !is_array($rows)) {
            return null;
        }

        // A join produces one row per table. Report the worst step: the one examining most
        // rows, since that is the one to fix.
        $worst = null;
        $est_total = 0;
        $extras = array();
        foreach ($rows as $row) {
            $est = isset($row['rows']) ? (int) $row['rows'] : 0;
            $est_total = $est_total ? $est_total * max($est, 1) : max($est, 1);
            if (!empty($row['Extra'])) {
                $extras[] = $row['Extra'];
            }
            if ($worst === null || $est > (int) $worst['rows']) {
                $worst = $row;
            }
        }
        if ($worst === null) {
            return null;
        }

        $extra = implode('; ', array_unique($extras));
        $type = isset($worst['type']) ? (string) $worst['type'] : '';

        return array(
            'access_type' => $type,
            'key' => (isset($worst['key']) && $worst['key'] !== null) ? (string) $worst['key'] : null,
            'possible_keys' => (isset($worst['possible_keys']) && $worst['possible_keys'] !== null) ? (string) $worst['possible_keys'] : null,
            'est_rows' => isset($worst['rows']) ? (int) $worst['rows'] : 0,
            'est_rows_total' => $est_total,
            'filtered' => isset($worst['filtered']) ? (float) $worst['filtered'] : null,
            'extra' => $extra !== '' ? $extra : null,
            'table' => isset($worst['table']) ? (string) $worst['table'] : null,
            'scan' => (in_array($type, self::$scan_types, true) && empty($worst['key'])),
            'filesort' => (stripos($extra, 'Using filesort') !== false),
            'temporary' => (stripos($extra, 'Using temporary') !== false),
        );
    }

    /**
     * A one-line plain-English summary of what the plan says is wrong, or null when the plan
     * looks fine. This is what gets appended to a finding so the user is told WHY, not just
     * that something was slow.
     */
    public static function summarise($plan) {
        if (!is_array($plan)) {
            return null;
        }
        $problems = array();
        if (!empty($plan['scan'])) {
            $problems[] = sprintf(
                /* translators: 1: table name, 2: estimated row count */
                __('no usable index on %1$s - MySQL expects to examine about %2$s rows', 'super-speedy-performance-analysis'),
                $plan['table'] !== null ? $plan['table'] : __('the table', 'super-speedy-performance-analysis'),
                number_format((int) $plan['est_rows'])
            );
        } elseif (empty($plan['key'])) {
            $problems[] = __('no index chosen for this query', 'super-speedy-performance-analysis');
        }
        if (!empty($plan['temporary'])) {
            $problems[] = __('builds a temporary table', 'super-speedy-performance-analysis');
        }
        if (!empty($plan['filesort'])) {
            $problems[] = __('sorts the results in memory or on disk (filesort)', 'super-speedy-performance-analysis');
        }
        return $problems ? implode('; ', $problems) : null;
    }
}
