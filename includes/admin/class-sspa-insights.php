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

        switch ($finding['finding_type']) {
            case 'slow_query':
                $headline = sprintf(__('%1$s ran a %2$sms query on %3$s', 'super-speedy-performance-analysis'), $component, number_format((float) $e['ms']), $page);
                $detail = !empty($e['sql']) ? $e['sql'] : '';
                break;
            case 'big_result_set':
                $headline = sprintf(__('%1$s fetched %2$s rows in a single query on %3$s', 'super-speedy-performance-analysis'), $component, number_format((int) $e['rows']), $page);
                $detail = !empty($e['sql']) ? $e['sql'] : '';
                break;
            case 'query_loop':
                $headline = sprintf(__('%1$s ran %2$d queries on %3$s', 'super-speedy-performance-analysis'), $component, (int) $e['query_count'], $page);
                $detail = sprintf(__('%1$sms of SQL, %2$s rows fetched. Query counts like this usually mean queries inside a loop and grow with your content.', 'super-speedy-performance-analysis'), number_format((float) $e['sql_ms'], 1), number_format((int) $e['rows']));
                break;
            case 'dupe_queries':
                $headline = sprintf(__('%1$s ran the identical query %2$d times on %3$s', 'super-speedy-performance-analysis'), $component, (int) $e['count'], $page);
                $detail = !empty($e['sql']) ? $e['sql'] : '';
                break;
            case 'slow_http':
                $headline = sprintf(__('%1$s blocked page render for %2$sms calling %3$s (on %4$s)', 'super-speedy-performance-analysis'), $component, number_format((float) $e['ms']), $e['url'], $page);
                break;
            case 'autoload_bloat':
                $headline = sprintf(__('Autoloaded options are %s - loaded on every request', 'super-speedy-performance-analysis'), size_format((int) $e['autoload_bytes']));
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
            'rec_title' => isset($rec['title']) ? $rec['title'] : '',
            'rec_body' => isset($rec['body']) ? $rec['body'] : '',
            'rec_link' => isset($rec['link']) ? $rec['link'] : '',
        );
    }
}
