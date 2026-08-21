<?php
defined('ABSPATH') || exit;

/**
 * Renders findings as plain-English insights. Recommendation copy comes from the rules
 * snapshot; this class only owns the sentence templates.
 */
class SSPA_Insights {

    /**
     * Findings that get their own place on the screen rather than competing for a top-N slot.
     *
     * A site-wide configuration finding loses that competition every time: it is a 'warn', and
     * any real site has several critical per-page query findings above it. On a live store the
     * autoload finding named 374 KB of options loaded on every request and was still invisible,
     * ranked below a slow query on one page.
     *
     * An isolation reaction is here for the opposite reason: it has no measured impact at all,
     * so the within-severity ranking (biggest delta first) puts it last among the warns and it
     * drops off the list - when what it says is that a plugin tried to change the live site.
     */
    const STANDALONE = array('autoload_coverage', 'isolation_reaction', 'cache_safety');

    /**
     * Every finding of one type for a run - one reaction per reacting pair.
     */
    public static function all_of_type($run_id, $finding_type) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM %i WHERE run_id = %d AND finding_type = %s ORDER BY id ASC',
            SSPA_Schema::table('findings'),
            (int) $run_id,
            $finding_type
        ), ARRAY_A);
    }

    /**
     * The single finding of one type for a run, or null. Feeds the dedicated Overview blocks.
     */
    public static function standalone($run_id, $finding_type) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM %i WHERE run_id = %d AND finding_type = %s ORDER BY id DESC LIMIT 1',
            SSPA_Schema::table('findings'),
            (int) $run_id,
            $finding_type
        ), ARRAY_A);
    }

    public static function top($run_id, $limit = 5) {
        global $wpdb;
        $excluded = implode(',', array_fill(0, count(self::STANDALONE), '%s'));
        $rows = $wpdb->get_results($wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $excluded is a generated run of %s placeholders, one per STANDALONE entry.
            "SELECT * FROM %i WHERE run_id = %d
             AND finding_type NOT IN ($excluded)
             ORDER BY FIELD(severity, 'critical', 'warn', 'info'), id ASC",
            array_merge(array(SSPA_Schema::table('findings'), $run_id), self::STANDALONE)
        ), ARRAY_A);

        // Secondary ordering: biggest measured impact first within each severity.
        usort($rows, function ($a, $b) {
            $sev = array('critical' => 0, 'warn' => 1, 'info' => 2);
            if ($sev[$a['severity']] !== $sev[$b['severity']]) {
                return $sev[$a['severity']] <=> $sev[$b['severity']];
            }
            return self::impact($b) <=> self::impact($a);
        });

        return array_slice($rows, 0, $limit);
    }

    private static function impact($finding) {
        $e = json_decode($finding['evidence'], true);
        if (!is_array($e)) {
            return 0;
        }
        return (float) ($e['ms'] ?? 0) + ((float) ($e['sql_ms'] ?? 0)) + ((int) ($e['rows'] ?? 0)) / 10 + ((int) ($e['query_count'] ?? 0));
    }

    /**
     * @return array {headline, detail, rec_title, rec_body, rec_link}
     */
    public static function render($finding) {
        $e = json_decode($finding['evidence'], true);
        $e = is_array($e) ? $e : array();
        $component = $finding['component'];
        $page = $finding['page_key'];
        $headline = '';
        $detail = '';
        $sql = '';

        // Where the work happened inside a library bundled in ANOTHER component, say so.
        // The cost is charged here because this component asked for it, but the code being
        // blamed is not this component's own - see SSPA_Component_Map::attribute().
        $via_note = '';
        if (!empty($e['via'])) {
            $via_note = ' ' . sprintf(
                /* translators: %s: the component the work actually happened in, e.g. "woocommerce" */
                __('(work done in %s, charged here because this is what called it)', 'super-speedy-performance-analysis'),
                $e['via']
            );
        }

        // What EXPLAIN said is wrong with the query plan, when we managed to get one.
        $plan_line = !empty($e['plan_note'])
            /* translators: %s: the MySQL query plan note, e.g. "full table scan on wp_postmeta" */
            ? sprintf(__('Query plan: %s. (Row counts are MySQL\'s estimate, not a measurement.)', 'super-speedy-performance-analysis'), $e['plan_note'])
            : '';

        switch ($finding['finding_type']) {
            case 'slow_query':
                /* translators: 1: component name, 2: milliseconds, 3: page key */
                $headline = sprintf(__('%1$s ran a %2$sms query on %3$s', 'super-speedy-performance-analysis'), $component, number_format((float) $e['ms']), $page) . $via_note;
                $detail = trim((!empty($e['sql']) ? $e['sql'] : '') . ($plan_line ? "\n" . $plan_line : ''));
                break;
            case 'unindexed_query':
                $headline = sprintf(
                    /* translators: 1: component, 2: table, 3: page */
                    __('%1$s runs a query with no usable index on %2$s (%3$s)', 'super-speedy-performance-analysis'),
                    $component,
                    !empty($e['table']) ? $e['table'] : __('a large table', 'super-speedy-performance-analysis'),
                    $page
                ) . $via_note;
                $detail = trim((!empty($e['sql']) ? $e['sql'] : '') . ($plan_line ? "\n" . $plan_line : ''));
                break;
            case 'archive_query_unindexed':
                $headline = sprintf(
                    /* translators: 1: page, 2: the ORDER BY columns */
                    __('The %1$s archive cannot sort by %2$s from an index', 'super-speedy-performance-analysis'),
                    $page,
                    !empty($e['shape']) ? $e['shape'] : __('its chosen order', 'super-speedy-performance-analysis')
                );
                $body = !empty($e['taxonomy'])
                    ? sprintf(
                        /* translators: 1: rows in the archive, 2: taxonomy list */
                        __('This archive holds %1$s posts and filters by %2$s. Filtering by term and sorting by a column of another table cannot use one index, so the database sorts the matched rows every time - which costs more as the catalogue grows, not less.', 'super-speedy-performance-analysis'),
                        number_format((int) $e['rows']),
                        implode(', ', (array) $e['taxonomy'])
                    )
                    : sprintf(
                        /* translators: %s: rows in the archive */
                        __('This archive holds %s posts. The database cannot serve this sort from an index, so it sorts the matched rows every time - which costs more as the catalogue grows, not less.', 'super-speedy-performance-analysis'),
                        number_format((int) $e['rows'])
                    );
                $detail = trim($body . ($plan_line ? "\n" . $plan_line : ''));
                break;
            case 'over_examining_query':
                $headline = sprintf(
                    /* translators: 1: component, 2: rows examined, 3: rows returned, 4: page */
                    __('%1$s ran a query that read %2$s rows to return %3$s (on %4$s)', 'super-speedy-performance-analysis'),
                    $component,
                    number_format((int) $e['examined']),
                    number_format((int) $e['sent']),
                    $page
                ) . $via_note;
                $detail = trim((!empty($e['sql']) ? $e['sql'] : '') . "\n" . sprintf(
                    /* translators: 1: ratio, 2: number of times the query ran */
                    __('That is %1$sx more rows read than returned, across %2$d executions. These are MySQL\'s own counters - what the database actually did, not an estimate.', 'super-speedy-performance-analysis'),
                    number_format((int) $e['ratio']),
                    (int) $e['count']
                ));
                break;
            case 'big_result_set':
                /* translators: 1: component name, 2: number of rows, 3: page key */
                $headline = sprintf(__('%1$s fetched %2$s rows in a single query on %3$s', 'super-speedy-performance-analysis'), $component, number_format((int) $e['rows']), $page) . $via_note;
                $detail = trim((!empty($e['sql']) ? $e['sql'] : '') . ($plan_line ? "\n" . $plan_line : ''));
                break;
            case 'query_loop':
                /* translators: 1: component name, 2: number of queries, 3: page key */
                $headline = sprintf(__('%1$s ran %2$d queries on %3$s', 'super-speedy-performance-analysis'), $component, (int) $e['query_count'], $page);
                /* translators: 1: milliseconds of SQL, 2: number of rows fetched */
                $detail = sprintf(__('%1$sms of SQL, %2$s rows fetched. Query counts like this usually mean queries inside a loop and grow with your content.', 'super-speedy-performance-analysis'), number_format((float) $e['sql_ms'], 1), number_format((int) $e['rows']));
                // Name where they ran. "plugin-b ran 70 queries" is much less actionable than
                // "70 of them inside woocommerce" - that says it is looping over an API.
                if (!empty($e['ran_in']) && is_array($e['ran_in'])) {
                    $parts = array();
                    foreach ($e['ran_in'] as $where => $count) {
                        $parts[] = sprintf(
                            /* translators: 1: query count, 2: component the queries ran inside */
                            __('%1$d inside %2$s', 'super-speedy-performance-analysis'),
                            (int) $count,
                            $where
                        );
                    }
                    $detail .= ' ' . sprintf(
                        /* translators: %s: a list like "70 inside woocommerce" */
                        __('These did not all run in %1$s\'s own code: %2$s. That is the signature of looping over another plugin\'s API instead of asking for everything at once.', 'super-speedy-performance-analysis'),
                        $component,
                        implode(', ', $parts)
                    );
                }
                break;
            case 'dupe_queries':
                /* translators: 1: component name, 2: number of times the query repeated, 3: page key */
                $headline = sprintf(__('%1$s ran the identical query %2$d times on %3$s', 'super-speedy-performance-analysis'), $component, (int) $e['count'], $page);
                $detail = !empty($e['sql']) ? $e['sql'] : '';
                break;
            case 'slow_http':
                /* translators: 1: component name, 2: milliseconds, 3: outbound URL called, 4: page key */
                $headline = sprintf(__('%1$s blocked page render for %2$sms calling %3$s (on %4$s)', 'super-speedy-performance-analysis'), $component, number_format((float) $e['ms']), $e['url'], $page);
                break;
            case 'blocking_mail':
                /* translators: 1: component name, 2: milliseconds, 3: page key */
                $headline = sprintf(__('%1$s spent %2$sms building an email during %3$s', 'super-speedy-performance-analysis'), $component, number_format((float) $e['construct_ms']), $page);
                break;
            case 'cache_blind':
                /* translators: 1: component name, 2: queries with the object cache on, 3: queries with it off, 4: percentage saved */
                $headline = sprintf(__('%1$s ignores your object cache (%2$d queries with it, %3$d without - %4$d%% saved)', 'super-speedy-performance-analysis'), $component, (int) $e['queries_on'], (int) $e['queries_off'], (int) $e['saved_pct']);
                break;
            case 'cache_friendly':
                /* translators: 1: component name, 2: percentage of queries saved */
                $headline = sprintf(__('%1$s uses the object cache well (%2$d%% of its queries saved)', 'super-speedy-performance-analysis'), $component, (int) $e['saved_pct']);
                break;
            case 'autoload_bloat':
                /* translators: %s: total size of autoloaded options, e.g. "1.2 MB" */
                $headline = sprintf(__('Autoloaded options are %s - loaded on every request', 'super-speedy-performance-analysis'), size_format((int) $e['autoload_bytes']));
                break;
            case 'autoload_coverage':
                $headline = sprintf(
                    /* translators: 1: bytes never read, 2: total autoload bytes, 3: pages profiled */
                    __('%1$s of your %2$s of autoloaded options were never read on any of the %3$d pages analysed', 'super-speedy-performance-analysis'),
                    size_format((int) $e['unread_bytes']),
                    size_format((int) $e['autoload_bytes']),
                    (int) $e['pages_covered']
                );
                $sql = self::autoload_sql($e);
                break;
            case 'environment':
                if ('old_php' === $finding['recommendation_key']) {
                    /* translators: %s: the PHP version in use, e.g. "7.4" */
                    $headline = sprintf(__('PHP %s is holding this site back', 'super-speedy-performance-analysis'), $e['php']);
                } else {
                    $headline = __('Large database with no persistent object cache', 'super-speedy-performance-analysis');
                }
                break;
            case 'duplicate_functionality':
                /* translators: 1: number of plugins, 2: plugin category, 3: comma-separated plugin names */
                $headline = sprintf(__('%1$d potentially overlapping %2$s plugins active: %3$s', 'super-speedy-performance-analysis'), count($e['plugins']), $e['category'], implode(', ', $e['plugins']));
                $detail = __('Their module configuration was not inspected. Review enabled capabilities before deciding whether anything genuinely overlaps.', 'super-speedy-performance-analysis');
                break;
            case 'isolation_reaction':
                // The one finding where the site owner needs to be told what was ATTEMPTED,
                // not what it cost: a plugin tried to change the live site because we
                // measured something. All of it was refused, and the pair is grouped from
                // now on, but it is his site and his plugin list, so he gets told plainly.
                $ops = isset($e['ops']) && is_array($e['ops']) ? $e['ops'] : array();
                if (isset($ops['sql'])) {
                    $attempt = __('run a destructive database statement', 'super-speedy-performance-analysis');
                } elseif (isset($ops['activate'])) {
                    $attempt = __('switch a plugin back on', 'super-speedy-performance-analysis');
                } else {
                    $attempt = __('deactivate a plugin', 'super-speedy-performance-analysis');
                }
                $headline = sprintf(
                    /* translators: 1: reacting plugin, 2: what it tried to do, 3: excluded plugin */
                    __('%1$s tried to %2$s while %3$s was excluded for measurement', 'super-speedy-performance-analysis'),
                    $component,
                    $attempt,
                    !empty($e['excluded']) ? $e['excluded'] : __('another plugin', 'super-speedy-performance-analysis')
                );
                $attempts = array_sum(array_map('intval', $ops));
                $detail = sprintf(
                    /* translators: %d: number of attempts */
                    _n(
                        '%d attempt, every one refused: your plugin list, the plugins\' own activation and deactivation routines and your database were all left untouched.',
                        '%d attempts, every one refused: your plugin list, the plugins\' own activation and deactivation routines and your database were all left untouched.',
                        max(1, $attempts),
                        'super-speedy-performance-analysis'
                    ),
                    max(1, $attempts)
                );
                break;
            case 'security_block':
                /* translators: 1: the layer that blocked profiling, 2: number of pages affected */
                $headline = sprintf(__('%1$s blocked profiling of %2$d page(s)', 'super-speedy-performance-analysis'), $e['layer'], count((array) $e['pages']));
                $detail = implode(', ', (array) $e['pages']);
                break;
            case 'cache_safety':
                $shared_cache_status = isset($e['shared_cache_status']) ? $e['shared_cache_status'] : 'not_assessed';
                if ('visitor_specific_content_review_recommended' === $shared_cache_status) {
                    $headline = __('Visitor-specific content may need attention before these pages are shared-cached', 'super-speedy-performance-analysis');
                } elseif ('no_visitor_specific_content_hazards_detected' === $shared_cache_status) {
                    $headline = __('No obvious visitor-specific content hazards were detected', 'super-speedy-performance-analysis');
                } else {
                    $headline = __('Shared-cache safety was not assessed', 'super-speedy-performance-analysis');
                }
                $detail = sprintf(
                    /* translators: 1: pages scanned, 2: review difficulty, 3: hazards, 4: candidate components */
                    __('%1$d shared-cache page type(s) scanned. Estimated review difficulty: %2$s. %3$d hazard class(es) and %4$d component candidate(s) were recorded.', 'super-speedy-performance-analysis'),
                    (int) ($e['pages_scanned'] ?? 0),
                    isset($e['difficulty']) ? $e['difficulty'] : __('unknown', 'super-speedy-performance-analysis'),
                    count((array) ($e['hazards'] ?? array())),
                    count((array) ($e['candidate_components'] ?? array()))
                );
                break;
            default:
                $headline = $finding['finding_type'] . ($component ? ' - ' . $component : '');
        }

        $rec = SSPA_Rules::recommendation($finding['recommendation_key']);
        return array(
            'headline' => $headline,
            'detail' => $detail,
            'sql' => $sql,
            'rec_title' => isset($rec['title']) ? $rec['title'] : '',
            'rec_body' => isset($rec['body']) ? $rec['body'] : '',
            'rec_link' => isset($rec['link']) ? $rec['link'] : '',
        );
    }

    /**
     * Copy-and-paste SQL for the autoload_coverage finding.
     *
     * WordPress 6.6 replaced the 'yes'/'no' autoload values with 'on'/'off', so the statement
     * has to match the site it will be run on. The alloptions cache has to be dropped
     * afterwards or the change does not take effect until it expires on its own.
     */
    private static function autoload_sql($e) {
        global $wpdb;

        $modern = function_exists('wp_autoload_values_to_autoload');
        $off = $modern ? 'off' : 'no';
        $on = $modern ? 'on' : 'yes';
        $lines = array();

        $unread = isset($e['unread']) && is_array($e['unread']) ? $e['unread'] : array();
        if ($unread) {
            $names = array();
            foreach ($unread as $row) {
                $names[] = "'" . esc_sql($row['name']) . "'";
            }
            $lines[] = sprintf(
                /* translators: 1: number of options, 2: human-readable size */
                '-- ' . _n(
                    'Stop autoloading %1$d option never read during this analysis (%2$s)',
                    'Stop autoloading %1$d options never read during this analysis (%2$s)',
                    count($unread),
                    'super-speedy-performance-analysis'
                ),
                count($unread),
                size_format(array_sum(array_column($unread, 'bytes')))
            );
            $lines[] = "UPDATE {$wpdb->options} SET autoload = '{$off}'";
            $lines[] = '  WHERE option_name IN (' . implode(', ', $names) . ');';
            $lines[] = '';
        }

        $missing = isset($e['missing']) && is_array($e['missing']) ? $e['missing'] : array();
        if ($missing) {
            $names = array();
            foreach ($missing as $row) {
                $names[] = "'" . esc_sql($row['name']) . "'";
            }
            $lines[] = sprintf(
                /* translators: 1: number of options, 2: pages profiled */
                '-- ' . _n(
                    'Start autoloading %1$d option read on nearly all of the %2$d pages analysed',
                    'Start autoloading %1$d options read on nearly all of the %2$d pages analysed',
                    count($missing),
                    'super-speedy-performance-analysis'
                ),
                count($missing),
                (int) $e['pages_covered']
            );
            $lines[] = "UPDATE {$wpdb->options} SET autoload = '{$on}'";
            $lines[] = '  WHERE option_name IN (' . implode(', ', $names) . ');';
            $lines[] = '';
        }

        if (!$lines) {
            return '';
        }
        $lines[] = '-- ' . __('Then clear the cached option set:', 'super-speedy-performance-analysis');
        $lines[] = '--   wp cache delete alloptions options';
        return implode("\n", $lines);
    }
}
