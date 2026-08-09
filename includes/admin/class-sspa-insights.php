<?php
defined('ABSPATH') || exit;

/**
 * Renders findings as plain-English insights. Recommendation copy comes from the rules
 * snapshot; this class only owns the sentence templates.
 */
class SSPA_Insights {

    public static function top($run_id, $limit = 5) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . SSPA_Schema::table('findings') . " WHERE run_id = %d
             ORDER BY FIELD(severity, 'critical', 'warn', 'info'), id ASC",
            $run_id
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
                __('(work done in %s, charged here because this is what called it)', 'super-speedy-performance-analysis'),
                $e['via']
            );
        }

        // What EXPLAIN said is wrong with the query plan, when we managed to get one.
        $plan_line = !empty($e['plan_note'])
            ? sprintf(__('Query plan: %s. (Row counts are MySQL\'s estimate, not a measurement.)', 'super-speedy-performance-analysis'), $e['plan_note'])
            : '';

        switch ($finding['finding_type']) {
            case 'slow_query':
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
                $headline = sprintf(__('%1$s fetched %2$s rows in a single query on %3$s', 'super-speedy-performance-analysis'), $component, number_format((int) $e['rows']), $page) . $via_note;
                $detail = trim((!empty($e['sql']) ? $e['sql'] : '') . ($plan_line ? "\n" . $plan_line : ''));
                break;
            case 'query_loop':
                $headline = sprintf(__('%1$s ran %2$d queries on %3$s', 'super-speedy-performance-analysis'), $component, (int) $e['query_count'], $page);
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
                $headline = sprintf(__('%1$s ran the identical query %2$d times on %3$s', 'super-speedy-performance-analysis'), $component, (int) $e['count'], $page);
                $detail = !empty($e['sql']) ? $e['sql'] : '';
                break;
            case 'slow_http':
                $headline = sprintf(__('%1$s blocked page render for %2$sms calling %3$s (on %4$s)', 'super-speedy-performance-analysis'), $component, number_format((float) $e['ms']), $e['url'], $page);
                break;
            case 'blocking_mail':
                $headline = sprintf(__('%1$s spent %2$sms building an email during %3$s', 'super-speedy-performance-analysis'), $component, number_format((float) $e['construct_ms']), $page);
                break;
            case 'cache_blind':
                $headline = sprintf(__('%1$s ignores your object cache (%2$d queries with it, %3$d without - %4$d%% saved)', 'super-speedy-performance-analysis'), $component, (int) $e['queries_on'], (int) $e['queries_off'], (int) $e['saved_pct']);
                break;
            case 'cache_friendly':
                $headline = sprintf(__('%1$s uses the object cache well (%2$d%% of its queries saved)', 'super-speedy-performance-analysis'), $component, (int) $e['saved_pct']);
                break;
            case 'autoload_bloat':
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
                    $headline = sprintf(__('PHP %s is holding this site back', 'super-speedy-performance-analysis'), $e['php']);
                } else {
                    $headline = __('Large database with no persistent object cache', 'super-speedy-performance-analysis');
                }
                break;
            case 'duplicate_functionality':
                $headline = sprintf(__('%1$d overlapping %2$s plugins active: %3$s', 'super-speedy-performance-analysis'), count($e['plugins']), $e['category'], implode(', ', $e['plugins']));
                break;
            case 'security_block':
                $headline = sprintf(__('%1$s blocked profiling of %2$d page(s)', 'super-speedy-performance-analysis'), $e['layer'], count((array) $e['pages']));
                $detail = implode(', ', (array) $e['pages']);
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
