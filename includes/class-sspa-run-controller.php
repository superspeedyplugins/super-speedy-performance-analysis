<?php
defined('ABSPATH') || exit;

/**
 * Run state machine: queued -> crawling -> done | failed | cancelled. Jobs are processed
 * in time-boxed batches, driven by the admin page's JS (sequential AJAX calls) with a
 * WP-Cron event as backup for headless progress. Crash safety: a held foreign db.php is
 * restored on finish, on failure, and by the stale-hold check on plugins_loaded.
 */
class SSPA_Run_Controller {

    const BATCH_SECONDS = 15;

    public static function register() {
        add_action('sspa_process_batch_event', array(__CLASS__, 'process_batch'));
        add_action('sspa_cleanup_event', array(__CLASS__, 'cleanup'));
        add_action('plugins_loaded', array('SSPA_Helper_Files', 'stale_hold_check'), 20);

        add_action('wp_ajax_sspa_start_run', array(__CLASS__, 'ajax_start_run'));
        add_action('wp_ajax_sspa_process_batch', array(__CLASS__, 'ajax_process_batch'));
        add_action('wp_ajax_sspa_run_status', array(__CLASS__, 'ajax_run_status'));
        add_action('wp_ajax_sspa_cancel_run', array(__CLASS__, 'ajax_cancel_run'));
        add_action('wp_ajax_sspa_page_detail', array(__CLASS__, 'ajax_page_detail'));
        add_action('wp_ajax_sspa_prune_blobs', array(__CLASS__, 'ajax_prune_blobs'));
        if (!wp_next_scheduled('sspa_cleanup_event')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'sspa_cleanup_event');
        }
    }

    // ---------------- run lifecycle ----------------

    /**
     * @param array $args {type, page_keys, swap_dropin, user_id}
     * @return int|WP_Error run id
     */
    public static function start($args = array()) {
        global $wpdb;

        if (self::active_run_id()) {
            return new WP_Error('sspa_run_active', __('An analysis is already running.', 'super-speedy-performance-analysis'));
        }

        SSPA_Helper_Files::ensure_installed();
        $health = SSPA_Helper_Files::health();
        if (!$health['mu']) {
            return new WP_Error('sspa_no_mu', __('The mu-plugin loader could not be installed (wp-content/mu-plugins is not writable).', 'super-speedy-performance-analysis'));
        }

        if (!empty($args['swap_dropin']) && in_array($health['dropin'], array('foreign', 'qm'), true)) {
            SSPA_Helper_Files::hold_foreign_dropin();
        }

        $user_id = !empty($args['user_id']) ? (int) $args['user_id'] : get_current_user_id();
        if (!$user_id) {
            $admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
            $user_id = $admins ? (int) $admins[0] : 0;
        }

        $type = !empty($args['type']) ? $args['type'] : 'baseline';

        if ('deep' === $type) {
            $plan = self::build_deep_plan($args);
            if (is_wp_error($plan)) {
                return $plan;
            }
        } else {
            $jobs = SSPA_Catalogue::build(!empty($args['page_keys']) ? (array) $args['page_keys'] : array());
            if (!$jobs) {
                return new WP_Error('sspa_no_jobs', __('No pages found to profile.', 'super-speedy-performance-analysis'));
            }
        }

        $wpdb->insert(SSPA_Schema::table('runs'), array(
            'blog_id' => get_current_blog_id(),
            'run_type' => $type,
            'trigger_source' => !empty($args['trigger']) ? $args['trigger'] : 'manual',
            'status' => 'crawling',
            'plugin_set' => wp_json_encode(array(
                'plugins' => (array) get_option('active_plugins', array()),
                'user_id' => $user_id,
            )),
            'plugin_set_hash' => md5(wp_json_encode(get_option('active_plugins', array()))),
            'started' => gmdate('Y-m-d H:i:s'),
        ));
        $run_id = (int) $wpdb->insert_id;

        if ('deep' === $type) {
            $plan['user_id'] = $user_id;
            update_option('sspa_deep_' . $run_id, $plan, false);
        } else {
            update_option('sspa_queue_' . $run_id, array('jobs' => $jobs, 'idx' => 0, 'user_id' => $user_id), false);
        }
        wp_schedule_single_event(time() + 5, 'sspa_process_batch_event', array($run_id));

        return $run_id;
    }

    /**
     * Deep runs are adaptive: the isolation planner decides each next measurement. This
     * builds its starting state from the latest completed run's findings.
     *
     * @return array|WP_Error {planner: state, pages: page_key => job, files: slug => file,
     *                         theme: slug|null, source_run_id, hashes: []}
     */
    private static function build_deep_plan($args) {
        global $wpdb;

        $source_run_id = (int) $wpdb->get_var(
            'SELECT id FROM ' . SSPA_Schema::table('runs') . " WHERE status = 'done' AND run_type != 'deep' ORDER BY id DESC LIMIT 1"
        );
        if (!$source_run_id) {
            return new WP_Error('sspa_no_baseline', __('Run a normal analysis first - deep analysis needs its findings to know what to test.', 'super-speedy-performance-analysis'));
        }

        $files = SSPA_Dependency_Map::slug_to_file();

        // Suspects: explicit list, or every plugin named by the source run's findings.
        if (!empty($args['suspects'])) {
            $suspects = array_values(array_intersect((array) $args['suspects'], array_keys($files)));
        } else {
            $components = $wpdb->get_col($wpdb->prepare(
                'SELECT DISTINCT component FROM ' . SSPA_Schema::table('findings') . "
                 WHERE run_id = %d AND component IS NOT NULL
                 AND finding_type IN ('slow_query','big_result_set','query_loop','dupe_queries','slow_http')",
                $source_run_id
            ));
            $suspects = array_values(array_intersect($components, array_keys($files)));
        }

        // Worst page per suspect: where its attributed SQL+HTTP time is biggest.
        $singles = array();
        foreach ($suspects as $slug) {
            $page_key = $wpdb->get_var($wpdb->prepare(
                'SELECT p.page_key FROM ' . SSPA_Schema::table('component_stats') . ' cs
                 JOIN ' . SSPA_Schema::table('profiles') . " p ON p.id = cs.profile_id
                 WHERE cs.run_id = %d AND cs.component = %s AND p.blocked_by IS NULL
                 ORDER BY (cs.sql_ms + cs.http_ms) DESC LIMIT 1",
                $source_run_id,
                $slug
            ));
            if ($page_key) {
                $singles[] = array('plugin' => $slug, 'page_key' => $page_key);
            }
        }

        // Slowest real front-end page: bisection target + theme-isolation page.
        $slowest_page = $wpdb->get_var($wpdb->prepare(
            'SELECT page_key FROM ' . SSPA_Schema::table('profiles') . "
             WHERE run_id = %d AND variant = 'anon' AND page_key != 'baseline'
             AND blocked_by IS NULL AND page_gen_ms IS NOT NULL
             ORDER BY page_gen_ms DESC LIMIT 1",
            $source_run_id
        ));

        $default_theme = self::default_theme();
        if ($default_theme && $slowest_page) {
            $singles[] = array('plugin' => 'theme', 'page_key' => $slowest_page);
        }

        $bisects = array();
        if (!isset($args['bisect']) || $args['bisect']) {
            $candidates = array_diff(SSPA_Dependency_Map::bisect_candidates(), $suspects);
            if ($slowest_page && count($candidates) > 1) {
                $bisects[] = array('page_key' => $slowest_page, 'candidates' => array_values($candidates));
            }
        }

        if (!$singles && !$bisects) {
            return new WP_Error('sspa_nothing_to_test', __('No suspects to isolate - the last analysis produced no plugin findings.', 'super-speedy-performance-analysis'));
        }

        $planner = SSPA_Isolation_Planner::create($singles, $bisects);

        // Resolve the involved pages back to crawlable jobs now, not mid-run.
        $page_keys = array_keys($planner->state['pages']);
        $pages = array();
        foreach (SSPA_Catalogue::build($page_keys) as $job) {
            $pages[$job['page_key']] = $job;
        }

        return array(
            'planner' => $planner->state,
            'pages' => $pages,
            'files' => $files,
            'theme' => $default_theme,
            'source_run_id' => $source_run_id,
            'hashes' => array(),
        );
    }

    /**
     * A stock twenty* theme to swap to for theme isolation; null when the active theme
     * already is one (nothing meaningful to isolate) or none is installed.
     */
    private static function default_theme() {
        $active = get_stylesheet();
        if (strpos($active, 'twenty') === 0) {
            return null;
        }
        $candidates = array();
        foreach (wp_get_themes() as $slug => $theme) {
            if (strpos($slug, 'twenty') === 0) {
                $candidates[] = $slug;
            }
        }
        rsort($candidates);
        return $candidates ? $candidates[0] : null;
    }

    public static function active_run_id() {
        global $wpdb;
        $table = SSPA_Schema::table('runs');
        return (int) $wpdb->get_var("SELECT id FROM $table WHERE status IN ('queued','crawling','analysing') ORDER BY id DESC LIMIT 1");
    }

    public static function run_row($run_id) {
        global $wpdb;
        $table = SSPA_Schema::table('runs');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $run_id), ARRAY_A);
    }

    private static function set_status($run_id, $status, $finished = false) {
        global $wpdb;
        $data = array('status' => $status);
        if ($finished) {
            $data['finished'] = gmdate('Y-m-d H:i:s');
        }
        $wpdb->update(SSPA_Schema::table('runs'), $data, array('id' => $run_id));
    }

    /**
     * Time-boxed batch. Safe to call concurrently (lock) and repeatedly (idempotent).
     */
    public static function process_batch($run_id) {
        $run_id = (int) $run_id;
        $run = self::run_row($run_id);
        if (!$run || 'crawling' !== $run['status']) {
            return;
        }

        // Lock: option add is atomic; stale locks expire after 120s.
        $lock_key = 'sspa_lock_' . $run_id;
        $existing = get_option($lock_key);
        if ($existing && $existing > time() - 120) {
            return;
        }
        update_option($lock_key, time(), false);

        try {
            if ('deep' === $run['run_type']) {
                self::process_deep_batch($run_id);
                return;
            }
            $queue = get_option('sspa_queue_' . $run_id);
            if (!is_array($queue)) {
                self::fail($run_id, 'queue missing');
                return;
            }

            $crawler = new SSPA_Crawler();
            $deadline = microtime(true) + self::BATCH_SECONDS;

            while ($queue['idx'] < count($queue['jobs']) && microtime(true) < $deadline) {
                $job = $queue['jobs'][$queue['idx']];
                $result = $crawler->profile_job($job, $queue['user_id']);
                SSPA_Profile_Store::save($run_id, $result);
                $queue['idx']++;
                update_option('sspa_queue_' . $run_id, $queue, false);

                $run = self::run_row($run_id);
                if (!$run || 'crawling' !== $run['status']) {
                    return; // cancelled mid-batch
                }
            }

            if ($queue['idx'] >= count($queue['jobs'])) {
                self::finish($run_id);
            } else {
                wp_schedule_single_event(time() + 2, 'sspa_process_batch_event', array($run_id));
            }
        } finally {
            delete_option($lock_key);
        }
    }

    private static function process_deep_batch($run_id) {
        global $wpdb;
        $plan = get_option('sspa_deep_' . $run_id);
        if (!is_array($plan)) {
            self::fail($run_id, 'deep plan missing');
            return;
        }

        $planner = SSPA_Isolation_Planner::from_state($plan['planner']);
        $crawler = new SSPA_Crawler();
        $deadline = microtime(true) + self::BATCH_SECONDS;

        while (microtime(true) < $deadline) {
            $spec = $planner->next();
            if ($spec === null) {
                $plan['planner'] = $planner->state;
                update_option('sspa_deep_' . $run_id, $plan, false);
                self::finish_deep($run_id, $plan, $planner);
                return;
            }
            if (!isset($plan['pages'][$spec['page_key']])) {
                // Page no longer resolvable - record as fatal so the planner moves on.
                $planner->record(array('fatal' => true, 'gen_ms' => null, 'sql_ms' => null, 'mem_bytes' => null, 'queries' => null, 'samples' => array()));
            } else {
                $job = $plan['pages'][$spec['page_key']];

                // Materialise the exclusion payload for the mu-loader.
                $exclude_files = array();
                foreach ($spec['exclude'] as $slug) {
                    if (isset($plan['files'][$slug])) {
                        $exclude_files[] = $plan['files'][$slug];
                    }
                }
                if ($exclude_files || $spec['theme_swap']) {
                    $payload = array(
                        'plugins' => $exclude_files,
                        'theme' => $spec['theme_swap'] ? $plan['theme'] : null,
                    );
                    $hash = md5(wp_json_encode($payload));
                    update_option('sspa_isolation_' . $hash, $payload, false);
                    if (!in_array($hash, $plan['hashes'], true)) {
                        $plan['hashes'][] = $hash;
                    }
                    $job['ps'] = $hash;
                }

                $result = $crawler->profile_job($job, $plan['user_id']);
                $profile_id = SSPA_Profile_Store::save($run_id, $result);
                $row = $wpdb->get_row($wpdb->prepare(
                    'SELECT page_gen_ms, sql_ms, peak_mem_bytes, sql_count, response_code FROM ' . SSPA_Schema::table('profiles') . ' WHERE id = %d',
                    $profile_id
                ), ARRAY_A);

                $samples_gen = array();
                $all_failed = true;
                foreach ($result['samples'] as $s) {
                    if (isset($s['capture']['overview']['gen_ms'])) {
                        $samples_gen[] = (float) $s['capture']['overview']['gen_ms'];
                    }
                    if (empty($s['error']) && empty($s['blocked_by'])) {
                        $all_failed = false;
                    }
                }
                $fatal = $all_failed || (int) $row['response_code'] >= 500 || $row['page_gen_ms'] === null;

                $planner->record(array(
                    'fatal' => $fatal,
                    'gen_ms' => (float) $row['page_gen_ms'],
                    'sql_ms' => (float) $row['sql_ms'],
                    'mem_bytes' => (int) $row['peak_mem_bytes'],
                    'queries' => (int) $row['sql_count'],
                    'samples' => $samples_gen,
                ));
            }

            $plan['planner'] = $planner->state;
            update_option('sspa_deep_' . $run_id, $plan, false);

            $run = self::run_row($run_id);
            if (!$run || 'crawling' !== $run['status']) {
                return; // cancelled mid-batch
            }
        }

        wp_schedule_single_event(time() + 2, 'sspa_process_batch_event', array($run_id));
    }

    private static function finish_deep($run_id, $plan, $planner) {
        global $wpdb;
        SSPA_Helper_Files::restore_held_dropin();

        foreach ($planner->impacts() as $impact) {
            $wpdb->insert(SSPA_Schema::table('plugin_impacts'), array(
                'blog_id' => get_current_blog_id(),
                'plugin' => $impact['plugin'],
                'page_key' => $impact['page_key'],
                'method' => $impact['method'],
                'delta_ttfb_ms' => $impact['delta_ttfb_ms'],
                'delta_sql_ms' => $impact['delta_sql_ms'],
                'delta_mem_bytes' => $impact['delta_mem_bytes'],
                'delta_queries' => $impact['delta_queries'],
                'noise_floor_ms' => $impact['noise_floor_ms'],
                'confidence' => $impact['confidence'],
                'baseline_run_id' => $plan['source_run_id'],
                'test_run_id' => $run_id,
                'created' => gmdate('Y-m-d H:i:s'),
            ));
        }

        foreach ($plan['hashes'] as $hash) {
            delete_option('sspa_isolation_' . $hash);
        }
        delete_option('sspa_deep_' . $run_id);

        $wpdb->update(SSPA_Schema::table('runs'), array(
            'status' => 'done',
            'finished' => gmdate('Y-m-d H:i:s'),
            'notes' => wp_json_encode(array(
                'type' => 'deep',
                'impacts' => count($planner->impacts()),
                'measurements' => $planner->done_count(),
                'unresolved' => $planner->unresolved(),
                'truncated' => $planner->truncated(),
            )),
        ), array('id' => $run_id));
    }

    private static function finish($run_id) {
        global $wpdb;
        SSPA_Helper_Files::restore_held_dropin();
        delete_option('sspa_queue_' . $run_id);
        self::set_status($run_id, 'analysing');

        $demographics = SSPA_Demographics::snapshot($run_id);
        $engine = new SSPA_Analysis_Engine();
        $finding_count = $engine->analyse($run_id, $demographics);
        $score = SSPA_Analysis_Engine::score($run_id);

        $wpdb->update(SSPA_Schema::table('runs'), array(
            'status' => 'done',
            'finished' => gmdate('Y-m-d H:i:s'),
            'notes' => wp_json_encode(array('score' => $score, 'findings' => $finding_count)),
        ), array('id' => $run_id));
    }

    private static function cleanup_run_state($run_id) {
        delete_option('sspa_queue_' . $run_id);
        $plan = get_option('sspa_deep_' . $run_id);
        if (is_array($plan) && !empty($plan['hashes'])) {
            foreach ($plan['hashes'] as $hash) {
                delete_option('sspa_isolation_' . $hash);
            }
        }
        delete_option('sspa_deep_' . $run_id);
    }

    private static function fail($run_id, $note) {
        global $wpdb;
        SSPA_Helper_Files::restore_held_dropin();
        self::cleanup_run_state($run_id);
        $wpdb->update(SSPA_Schema::table('runs'), array(
            'status' => 'failed',
            'finished' => gmdate('Y-m-d H:i:s'),
            'notes' => $note,
        ), array('id' => $run_id));
    }

    public static function cancel($run_id) {
        SSPA_Helper_Files::restore_held_dropin();
        self::cleanup_run_state($run_id);
        self::set_status($run_id, 'cancelled', true);
    }

    public static function status($run_id) {
        global $wpdb;
        $run = self::run_row($run_id);
        if (!$run) {
            return null;
        }
        if ('deep' === $run['run_type']) {
            $plan = get_option('sspa_deep_' . $run_id);
            if (is_array($plan)) {
                $planner = SSPA_Isolation_Planner::from_state($plan['planner']);
                $done = $planner->done_count();
                $spec = $plan['planner']['current'];
                return array(
                    'run_id' => (int) $run['id'],
                    'status' => $run['status'],
                    'total' => $done + max(1, $planner->estimate_remaining()),
                    'done' => $done,
                    'current' => is_array($spec) ? $spec['kind'] . ': ' . $spec['page_key'] : null,
                );
            }
        }
        $queue = get_option('sspa_queue_' . $run_id);
        if (is_array($queue)) {
            $total = count($queue['jobs']);
            $idx = $queue['idx'];
        } else {
            // Queue is deleted once the run leaves the crawling state.
            $total = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . SSPA_Schema::table('profiles') . ' WHERE run_id = %d',
                $run_id
            ));
            $idx = $total;
        }
        return array(
            'run_id' => (int) $run['id'],
            'status' => $run['status'],
            'total' => $total,
            'done' => $idx,
            'current' => (is_array($queue) && isset($queue['jobs'][$idx])) ? $queue['jobs'][$idx]['page_key'] : null,
        );
    }

    /**
     * Hourly: orphaned captures, used-token markers, stale runs.
     */
    public static function cleanup() {
        global $wpdb;
        $captures = SSPA_Schema::table('captures');
        $wpdb->query($wpdb->prepare("DELETE FROM $captures WHERE created < %s", gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS)));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
            $wpdb->esc_like('sspa_used_') . '%',
            time() - HOUR_IN_SECONDS
        ));

        $runs = SSPA_Schema::table('runs');
        $stale = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $runs WHERE status IN ('queued','crawling','analysing') AND started < %s",
            gmdate('Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS)
        ));
        foreach ($stale as $run_id) {
            self::fail((int) $run_id, 'stale - timed out');
        }
    }

    // ---------------- AJAX ----------------

    private static function ajax_guard() {
        check_ajax_referer('sspa_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('forbidden', 403);
        }
    }

    public static function ajax_start_run() {
        self::ajax_guard();
        $type = (isset($_POST['type']) && 'deep' === $_POST['type']) ? 'deep' : 'baseline';
        $args = array(
            'type' => $type,
            'swap_dropin' => !empty($_POST['swap_dropin']),
        );
        if ('deep' === $type && !empty($_POST['suspects'])) {
            $args['suspects'] = array_map('sanitize_key', (array) $_POST['suspects']);
            $args['bisect'] = false; // "Measure this plugin" targets one suspect only
        }
        if (isset($_POST['page_keys'])) {
            $args['page_keys'] = array_map('sanitize_text_field', (array) $_POST['page_keys']);
        }
        $run_id = self::start($args);
        if (is_wp_error($run_id)) {
            wp_send_json_error($run_id->get_error_message());
        }
        wp_send_json_success(self::status($run_id));
    }

    public static function ajax_process_batch() {
        self::ajax_guard();
        $run_id = isset($_POST['run_id']) ? (int) $_POST['run_id'] : self::active_run_id();
        if ($run_id) {
            self::process_batch($run_id);
        }
        wp_send_json_success(self::status($run_id));
    }

    public static function ajax_run_status() {
        self::ajax_guard();
        $run_id = isset($_POST['run_id']) ? (int) $_POST['run_id'] : self::active_run_id();
        wp_send_json_success($run_id ? self::status($run_id) : null);
    }

    public static function ajax_cancel_run() {
        self::ajax_guard();
        $run_id = isset($_POST['run_id']) ? (int) $_POST['run_id'] : self::active_run_id();
        if ($run_id) {
            self::cancel($run_id);
        }
        wp_send_json_success();
    }

    public static function ajax_page_detail() {
        global $wpdb;
        self::ajax_guard();
        $profile_id = isset($_POST['profile_id']) ? (int) $_POST['profile_id'] : 0;
        $blob = $wpdb->get_var($wpdb->prepare(
            'SELECT profile_blob FROM ' . SSPA_Schema::table('profiles') . ' WHERE id = %d',
            $profile_id
        ));
        $capture = $blob ? json_decode((string) @gzuncompress($blob), true) : null;
        if (!is_array($capture)) {
            wp_send_json_error(__('No detailed data stored for this page (pruned or capture failed).', 'super-speedy-performance-analysis'));
        }

        $components = array();
        foreach ((array) $capture['components'] as $name => $stats) {
            $components[] = array('component' => $name) + $stats;
        }
        usort($components, function ($a, $b) {
            return $b['sql_ms'] <=> $a['sql_ms'];
        });

        $queries = (array) $capture['sql']['queries'];
        usort($queries, function ($a, $b) {
            return $b['ms'] <=> $a['ms'];
        });
        $queries = array_map(function ($q) {
            return array(
                'sql' => $q['sql'] !== null ? $q['sql'] : $q['fp'],
                'ms' => $q['ms'],
                'rows' => $q['rows'],
                'component' => $q['component'],
                'caller' => $q['caller'],
            );
        }, array_slice($queries, 0, 10));

        wp_send_json_success(array(
            'components' => $components,
            'queries' => $queries,
            'http' => isset($capture['http']['calls']) ? array_map(function ($c) {
                unset($c['frames']);
                return $c;
            }, $capture['http']['calls']) : array(),
            'dupes' => isset($capture['sql']['dupe_details']) ? $capture['sql']['dupe_details'] : array(),
        ));
    }

    public static function ajax_prune_blobs() {
        global $wpdb;
        self::ajax_guard();
        $keep = max(1, (int) sspa_get_option('blob_retention_runs'));
        $runs_table = SSPA_Schema::table('runs');
        $keep_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM $runs_table ORDER BY id DESC LIMIT %d", $keep));
        $keep_ids = array_map('intval', $keep_ids);
        $profiles = SSPA_Schema::table('profiles');
        if ($keep_ids) {
            $in = implode(',', $keep_ids);
            $wpdb->query("UPDATE $profiles SET profile_blob = NULL WHERE run_id NOT IN ($in)");
        }
        $bytes = (int) $wpdb->get_var("SELECT COALESCE(SUM(LENGTH(profile_blob)), 0) FROM $profiles");
        wp_send_json_success(array('bytes' => $bytes, 'human' => size_format($bytes)));
    }
}
