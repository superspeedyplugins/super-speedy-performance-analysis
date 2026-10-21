<?php
defined('ABSPATH') || exit;

/**
 * What the Imports tab measures on this site. Read-only: the importer's own run history and
 * the import requests a Traffic collection recorded. No projection is ever derived from these
 * numbers; the published benchmarks are shown beside them, never combined with them.
 */
class SSPA_Imports {

    const WP_ALL_IMPORT_SLUGS = array('wp-all-import', 'wp-all-import-pro');

    /** Whether one of this site's own tables exists. */
    private static function table_exists($table) {
        global $wpdb;
        return $table === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
    }

    public static function wp_all_import_active() {
        return (bool) array_intersect(self::WP_ALL_IMPORT_SLUGS, SSPA_Cache_System::active_slugs());
    }

    /**
     * The latest finished run of each WP All Import import, newest first, from WP All Import's
     * own tables (columns verified against WP All Import 4.1.4 from wordpress.org).
     *
     * pmxi_history holds one row per run: its start (`date`) and elapsed seconds (`time_run`,
     * a text column). The import record holds the created, updated and skipped counts of its
     * latest run, so only each import's newest history row is paired with them. Imports that
     * are processing or executing right now are left out.
     *
     * @return array[] {import_id, name, date, seconds, records, created, updated, skipped, records_per_second}
     */
    public static function wp_all_import_runs($limit = 5) {
        global $wpdb;
        if (!self::wp_all_import_active()) {
            return array();
        }
        $imports = $wpdb->prefix . 'pmxi_imports';
        $history = $wpdb->prefix . 'pmxi_history';
        if (!self::table_exists($imports) || !self::table_exists($history)) {
            return array();
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT h.import_id, h.date, h.time_run, i.friendly_name, i.name, i.created, i.updated, i.skipped
             FROM %i h
             INNER JOIN (SELECT import_id, MAX(id) AS id FROM %i GROUP BY import_id) latest ON latest.id = h.id
             INNER JOIN %i i ON i.id = h.import_id
             WHERE i.processing = 0 AND i.executing = 0
             ORDER BY h.id DESC
             LIMIT %d',
            $history,
            $history,
            $imports,
            (int) $limit
        ), ARRAY_A);
        $runs = array();
        foreach ((array) $rows as $row) {
            if (!is_numeric($row['time_run']) || (int) $row['time_run'] <= 0) {
                continue; // a run WP All Import has not finished timing
            }
            $records = (int) $row['created'] + (int) $row['updated'] + (int) $row['skipped'];
            $seconds = (int) $row['time_run'];
            $runs[] = array(
                'import_id' => (int) $row['import_id'],
                'name' => '' !== (string) $row['friendly_name'] ? (string) $row['friendly_name'] : (string) $row['name'],
                'date' => (string) $row['date'],
                'seconds' => $seconds,
                'records' => $records,
                'created' => (int) $row['created'],
                'updated' => (int) $row['updated'],
                'skipped' => (int) $row['skipped'],
                'records_per_second' => $records / $seconds,
            );
        }
        return $runs;
    }

    /** "2m 25s" style elapsed time. */
    public static function duration($seconds) {
        $seconds = (int) $seconds;
        $parts = array();
        if ($seconds >= 3600) {
            /* translators: %d: hours */
            $parts[] = sprintf(__('%dh', 'super-speedy-performance-analysis'), intdiv($seconds, 3600));
        }
        if ($seconds >= 60) {
            /* translators: %d: minutes */
            $parts[] = sprintf(__('%dm', 'super-speedy-performance-analysis'), intdiv($seconds % 3600, 60));
        }
        /* translators: %d: seconds */
        $parts[] = sprintf(__('%ds', 'super-speedy-performance-analysis'), $seconds % 60);
        return implode(' ', $parts);
    }
}
