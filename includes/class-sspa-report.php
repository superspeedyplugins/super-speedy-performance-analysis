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
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM %i WHERE status = 'done' AND run_type IN ('baseline','spot') ORDER BY id DESC LIMIT 1",
            SSPA_Schema::table('runs')
        ));
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

        $cache_safety = null;
        $cache_row = SSPA_Insights::standalone($run_id, 'cache_safety');
        if ($cache_row) {
            $cache_evidence = json_decode((string) $cache_row['evidence'], true);
            $cache_rendered = SSPA_Insights::render($cache_row);
            $cache_safety = array(
                'headline' => $cache_rendered['headline'],
                'detail' => $cache_rendered['detail'],
                'assessment' => is_array($cache_evidence) ? $cache_evidence : array(),
            );
        }

        $pages = array();
        foreach ($wpdb->get_results($wpdb->prepare(
            'SELECT page_key, variant, page_gen_ms, ttfb_ms, sql_ms, sql_count, rows_returned_total,
                    http_ms, php_ms, peak_mem_bytes, dupe_query_count, mail_count, response_code, blocked_by
             FROM %i WHERE run_id = %d ORDER BY page_gen_ms DESC',
            SSPA_Schema::table('profiles'),
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
            'cache_safety' => $cache_safety,
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
            if (class_exists('SSPA_Checkout_Flow')) {
                $row['recommendation_key'] = SSPA_Checkout_Flow::contextual_recommendation_key(
                    $row['page_key'],
                    $row['recommendation_key']
                );
            }
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

    /**
     * The archive query profile: which composite indexes this site's archives would use, and
     * which columns must be materialised first. Consumed by Super Speedy Archives to configure
     * its mirror table without a human filling the settings in by hand.
     *
     * Additive to the report schema, so SCHEMA does not move. Documented in docs/agent-api.md.
     *
     * @return array|WP_Error
     */
    public static function archive_profile($run_id = 0) {
        $run_id = $run_id ? (int) $run_id : self::latest_done_run_id();
        if (!$run_id) {
            return new WP_Error('sspa_no_run', __('No completed analysis run found. Run an analysis first.', 'super-speedy-performance-analysis'));
        }
        return SSPA_Archive_Profile::build($run_id);
    }

    /** The shared-cache safety report embedded in a normal analysis run. */
    public static function cache_safety($run_id = 0) {
        $run_id = $run_id ? (int) $run_id : self::latest_done_run_id();
        if (!$run_id) {
            return new WP_Error('sspa_no_run', __('No completed analysis run found. Run an analysis first.', 'super-speedy-performance-analysis'));
        }
        $row = SSPA_Insights::standalone($run_id, 'cache_safety');
        if (!$row) {
            return new WP_Error('sspa_no_cache_recon', __('That run predates the shared-cache safety scan or contained no eligible pages.', 'super-speedy-performance-analysis'));
        }
        $evidence = json_decode((string) $row['evidence'], true);
        $rendered = SSPA_Insights::render($row);
        return array(
            'run_id' => $run_id,
            'headline' => $rendered['headline'],
            'detail' => $rendered['detail'],
            'assessment' => is_array($evidence) ? $evidence : array(),
        );
    }

    /**
     * The page-plugin-usage contract: for each profiled page, what every active plugin
     * actually DID there, with a per-plugin unload-safety classification. This is the
     * stable cross-plugin surface behind Scalability Pro's Unload Plugins tab - SPRO
     * must consume THIS (or the matching ability/CLI), never the tables or blobs.
     *
     * Evidence per plugin per page:
     *   attribution  (every run)   queries/SQL/HTTP ms, include cost, timed hook-callback
     *                              cost, enqueued asset count - from component_stats.
     *                              NULL include/hook/assets = the run predates evidence
     *                              capture; report unknown, never zero.
     *   measurement  (deep runs)   the latest exclusion delta for (plugin, page), with
     *                              confidence, output_identical and the version measured.
     *
     * Additive to the report schema; versioned independently.
     *
     * @return array|WP_Error
     */
    const PAGE_PLUGIN_USAGE_SCHEMA = 1;

    public static function page_plugin_usage($run_id = 0) {
        global $wpdb;
        $run_id = $run_id ? (int) $run_id : self::latest_done_run_id();
        if (!$run_id) {
            return new WP_Error('sspa_no_run', __('No completed analysis run found. Run an analysis first.', 'super-speedy-performance-analysis'));
        }
        $run = SSPA_Run_Controller::run_row($run_id);
        if (!$run) {
            return new WP_Error('sspa_no_run', __('Run not found.', 'super-speedy-performance-analysis'));
        }

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all_plugins = get_plugins();
        $slug_to_file = SSPA_Dependency_Map::slug_to_file();
        $groups = SSPA_Dependency_Map::must_exclude_together();

        // Latest measured impact per plugin x page, normal cache mode - one indexed pass.
        $impacts = array(); // "plugin|page_key" => row
        foreach ($wpdb->get_results($wpdb->prepare(
            "SELECT plugin, plugin_version, page_key, delta_ttfb_ms, noise_floor_ms, output_identical, confidence, created
             FROM %i WHERE object_cache_mode = 'normal' AND method = 'single_out' ORDER BY id DESC LIMIT 2000",
            SSPA_Schema::table('plugin_impacts')
        ), ARRAY_A) as $row) {
            $key = $row['plugin'] . '|' . $row['page_key'];
            if (!isset($impacts[$key])) {
                $impacts[$key] = $row;
            }
        }

        // Full-set profiles of this run (baseline plugin set, normal cache mode), plus
        // their component attribution.
        $profiles = $wpdb->get_results($wpdb->prepare(
            "SELECT id, page_key, url, variant, method, page_gen_ms, created
             FROM %i WHERE run_id = %d AND plugin_set_hash = '' AND object_cache_mode = 'normal' AND method = 'GET'
             ORDER BY id ASC",
            SSPA_Schema::table('profiles'),
            $run_id
        ), ARRAY_A);

        $stats_by_profile = array();
        if ($profiles) {
            $ids = implode(',', array_map('intval', wp_list_pluck($profiles, 'id')));
            foreach ($wpdb->get_results($wpdb->prepare(
                "SELECT profile_id, component, query_count, sql_ms, http_ms, mail_ms, include_ms, hook_ms, assets_count
                 FROM %i WHERE profile_id IN ($ids)",
                SSPA_Schema::table('component_stats')
            ), ARRAY_A) as $s) {
                $stats_by_profile[(int) $s['profile_id']][$s['component']] = $s;
            }
        }

        $reasons = array();
        $has_evidence_columns = false;
        $has_impacts = (bool) $impacts;
        $classifications = array(); // slug => verdict, classified once
        $pages = array();

        foreach ($profiles as $p) {
            $stats = isset($stats_by_profile[(int) $p['id']]) ? $stats_by_profile[(int) $p['id']] : array();
            $plugins = array();
            foreach ($slug_to_file as $slug => $file) {
                if ('super-speedy-performance-analysis' === $slug) {
                    continue;
                }
                if (!isset($classifications[$slug])) {
                    $classifications[$slug] = SSPA_Unload_Safety::classify($slug);
                }
                $s = isset($stats[$slug]) ? $stats[$slug] : null;
                if ($s && null !== $s['include_ms']) {
                    $has_evidence_columns = true;
                }
                $impact = isset($impacts[$slug . '|' . $p['page_key']]) ? $impacts[$slug . '|' . $p['page_key']] : null;
                $plugins[] = array(
                    'plugin' => $slug,
                    'file' => $file,
                    'version' => isset($all_plugins[$file]['Version']) ? $all_plugins[$file]['Version'] : null,
                    'classification' => $classifications[$slug]['classification'],
                    'classification_reasons' => $classifications[$slug]['reasons'],
                    'group' => isset($groups[$slug]) ? $groups[$slug] : array(),
                    'evidence' => array(
                        // attributed=false means "no instrument saw this plugin do anything";
                        // with include_ms NULL as well, the run predates evidence capture and
                        // the truthful reading is UNKNOWN, not idle.
                        'attributed' => null !== $s,
                        'query_count' => $s ? (int) $s['query_count'] : 0,
                        'sql_ms' => $s ? round((float) $s['sql_ms'], 1) : 0.0,
                        'http_ms' => $s ? round((float) $s['http_ms'], 1) : 0.0,
                        'mail_ms' => $s ? round((float) $s['mail_ms'], 1) : 0.0,
                        'include_ms' => ($s && null !== $s['include_ms']) ? round((float) $s['include_ms'], 2) : null,
                        'hook_ms' => ($s && null !== $s['hook_ms']) ? round((float) $s['hook_ms'], 2) : null,
                        'assets_count' => ($s && null !== $s['assets_count']) ? (int) $s['assets_count'] : null,
                    ),
                    'impact' => $impact ? array(
                        'delta_generation_ms' => null !== $impact['delta_ttfb_ms'] ? round((float) $impact['delta_ttfb_ms'], 1) : null,
                        'noise_floor_ms' => null !== $impact['noise_floor_ms'] ? round((float) $impact['noise_floor_ms'], 1) : null,
                        'confidence' => $impact['confidence'],
                        'output_identical' => null !== $impact['output_identical'] ? (bool) $impact['output_identical'] : null,
                        'measured_version' => $impact['plugin_version'],
                        'measured_at' => $impact['created'],
                    ) : null,
                );
            }
            $pages[] = array(
                'page_key' => $p['page_key'],
                'url' => $p['url'],
                'variant' => $p['variant'],
                // Median server generation time from this run's full-set profile, so a
                // consumer can prioritise pages by how slow they actually are.
                'generation_ms' => null !== $p['page_gen_ms'] ? round((float) $p['page_gen_ms'], 1) : null,
                'profiled_at' => $p['created'],
                'plugins' => $plugins,
            );
        }

        if (!$pages) {
            $reasons[] = 'no_full_set_profiles_in_run';
        }
        if (!$has_evidence_columns && $pages) {
            $reasons[] = 'run_predates_evidence_capture';
        }
        if (!$has_impacts) {
            $reasons[] = 'no_deep_run_yet';
        }

        return array(
            'schema' => self::PAGE_PLUGIN_USAGE_SCHEMA,
            'generated_at' => gmdate('c'),
            'run' => array(
                'id' => (int) $run['id'],
                'type' => $run['run_type'],
                'started' => $run['started'],
                'finished' => $run['finished'],
            ),
            'complete' => !$reasons,
            'incomplete_reasons' => $reasons,
            'pages' => $pages,
        );
    }

    public static function impacts() {
        global $wpdb;
        $out = array();
        foreach ($wpdb->get_results($wpdb->prepare(
            'SELECT plugin, page_key, method, object_cache_mode, delta_ttfb_ms, delta_sql_ms, delta_http_ms, delta_mem_bytes,
                    delta_queries, noise_floor_ms, confidence, created
             FROM %i ORDER BY id DESC LIMIT 1000',
            SSPA_Schema::table('plugin_impacts')
        ), ARRAY_A) as $i) {
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
