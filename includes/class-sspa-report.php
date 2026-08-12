<?php
defined('ABSPATH') || exit;

/**
 * The agent-facing report: one stable JSON structure consumed by WP-CLI, the Abilities
 * API and (indirectly) any LLM interpreting results. Schema documented in
 * docs/agent-api.md - keep them in sync and bump SCHEMA on breaking changes.
 */
class SSPA_Report {

    const SCHEMA = 1;

    public static function latest_done_run_id() {
        global $wpdb;
        return (int) $wpdb->get_var(
            'SELECT id FROM ' . SSPA_Schema::table('runs') . " WHERE status = 'done' AND run_type IN ('baseline','spot') ORDER BY id DESC LIMIT 1"
        );
    }

    /**
     * @return array|WP_Error
     */
    public static function build($run_id = 0) {
        global $wpdb;
        $run_id = $run_id ? (int) $run_id : self::latest_done_run_id();
        if (!$run_id) {
            return new WP_Error('sspa_no_run', __('No completed analysis run found. Run an analysis first.', 'super-speedy-performance-analysis'));
        }
        $run = SSPA_Run_Controller::run_row($run_id);
        if (!$run) {
            return new WP_Error('sspa_no_run', __('Run not found.', 'super-speedy-performance-analysis'));
        }
        $notes = json_decode((string) $run['notes'], true);
        $demo = SSPA_Demographics::latest();
        $m = $demo ? $demo['metrics'] : array();

        $findings = self::findings($run_id);
        $severity_counts = array('critical' => 0, 'warn' => 0, 'info' => 0);
        foreach ($findings as $f) {
            if (isset($severity_counts[$f['severity']])) {
                $severity_counts[$f['severity']]++;
            }
        }

        $pages = array();
        foreach ($wpdb->get_results($wpdb->prepare(
            'SELECT page_key, variant, page_gen_ms, ttfb_ms, sql_ms, sql_count, rows_returned_total,
                    http_ms, php_ms, peak_mem_bytes, dupe_query_count, mail_count, response_code, blocked_by
             FROM ' . SSPA_Schema::table('profiles') . ' WHERE run_id = %d ORDER BY page_gen_ms DESC',
            $run_id
        ), ARRAY_A) as $p) {
            $pages[] = array(
                'page_key' => $p['page_key'],
                'variant' => $p['variant'],
                'generation_ms' => $p['page_gen_ms'] !== null ? round((float) $p['page_gen_ms'], 1) : null,
                'ttfb_ms' => $p['ttfb_ms'] !== null ? round((float) $p['ttfb_ms'], 1) : null,
                'sql_ms' => $p['sql_ms'] !== null ? round((float) $p['sql_ms'], 1) : null,
                'sql_count' => $p['sql_count'] !== null ? (int) $p['sql_count'] : null,
                'rows_fetched' => $p['rows_returned_total'] !== null ? (int) $p['rows_returned_total'] : null,
                'http_ms' => $p['http_ms'] !== null ? round((float) $p['http_ms'], 1) : null,
                'php_ms' => $p['php_ms'] !== null ? round((float) $p['php_ms'], 1) : null,
                'peak_mem_bytes' => $p['peak_mem_bytes'] !== null ? (int) $p['peak_mem_bytes'] : null,
                'duplicate_queries' => $p['dupe_query_count'] !== null ? (int) $p['dupe_query_count'] : null,
                'mail_count' => $p['mail_count'] !== null ? (int) $p['mail_count'] : null,
                'response_code' => $p['response_code'] !== null ? (int) $p['response_code'] : null,
                'blocked_by' => $p['blocked_by'],
            );
        }

        return array(
            'schema' => self::SCHEMA,
            'generated_at' => gmdate('c'),
            'run' => array(
                'id' => (int) $run['id'],
                'type' => $run['run_type'],
                'status' => $run['status'],
                'started' => $run['started'],
                'finished' => $run['finished'],
            ),
            'score' => is_array($notes) && isset($notes['score']) ? (int) $notes['score'] : null,
            'site' => array(
                'sector' => $demo ? $demo['sector'] : null,
                'wp' => isset($m['wp']) ? (string) $m['wp'] : null,
                'php' => isset($m['php']) ? (string) $m['php'] : null,
                'object_cache' => !empty($m['object_cache']),
                'active_plugins' => isset($m['active_plugins']) ? $m['active_plugins'] : array(),
                'theme' => isset($m['theme']) ? $m['theme'] : null,
            ),
            'summary' => array(
                'pages_profiled' => count($pages),
                'findings' => $severity_counts,
            ),
            'insights' => array_slice($findings, 0, 10),
            'findings' => $findings,
            'pages' => $pages,
            'impacts' => self::impacts(),
        );
    }

    /**
     * Findings rendered for consumption: stable keys, human headline, explicit
     * recommendation object. Ordered most severe / highest impact first.
     */
    public static function findings($run_id) {
        $rows = SSPA_Insights::top($run_id, 1000);
        $out = array();
        foreach ($rows as $row) {
            $rendered = SSPA_Insights::render($row);
            $evidence = json_decode((string) $row['evidence'], true);
            $out[] = array(
                'type' => $row['finding_type'],
                'severity' => $row['severity'],
                'component' => $row['component'],
                'page_key' => $row['page_key'],
                'confidence' => $row['confidence'],
                'headline' => $rendered['headline'],
                'detail' => $rendered['detail'],
                'evidence' => is_array($evidence) ? $evidence : array(),
                'recommendation' => array(
                    'key' => $row['recommendation_key'],
                    'title' => $rendered['rec_title'],
                    'body' => $rendered['rec_body'],
                    'link' => $rendered['rec_link'],
                ),
            );
        }
        return $out;
    }

    public static function impacts() {
        global $wpdb;
        $out = array();
        foreach ($wpdb->get_results(
            'SELECT plugin, page_key, method, object_cache_mode, delta_ttfb_ms, delta_sql_ms, delta_http_ms, delta_mem_bytes,
                    delta_queries, noise_floor_ms, confidence, created
             FROM ' . SSPA_Schema::table('plugin_impacts') . ' ORDER BY id DESC LIMIT 1000',
            ARRAY_A
        ) as $i) {
            $out[] = array(
                'plugin' => $i['plugin'],
                'page_key' => $i['page_key'],
                'method' => $i['method'],
                'object_cache_mode' => $i['object_cache_mode'],
                // Positive = the plugin adds this much to the page; NEGATIVE = it SAVES
                // this much (the page got slower with the plugin excluded).
                'delta_generation_ms' => $i['delta_ttfb_ms'] !== null ? round((float) $i['delta_ttfb_ms'], 1) : null,
                'delta_sql_ms' => $i['delta_sql_ms'] !== null ? round((float) $i['delta_sql_ms'], 1) : null,
                'delta_http_ms' => $i['delta_http_ms'] !== null ? round((float) $i['delta_http_ms'], 1) : null,
                'delta_mem_bytes' => $i['delta_mem_bytes'] !== null ? (int) $i['delta_mem_bytes'] : null,
                'delta_queries' => $i['delta_queries'] !== null ? (int) $i['delta_queries'] : null,
                'noise_floor_ms' => $i['noise_floor_ms'] !== null ? round((float) $i['noise_floor_ms'], 1) : null,
                'confidence' => $i['confidence'],
                'measured_at' => $i['created'],
            );
        }
        return $out;
    }
}
