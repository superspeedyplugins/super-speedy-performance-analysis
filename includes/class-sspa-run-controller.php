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
        add_action('wp_ajax_sspa_plugin_detail', array(__CLASS__, 'ajax_plugin_detail'));
        add_action('wp_ajax_sspa_prune_blobs', array(__CLASS__, 'ajax_prune_blobs'));
        add_action('wp_ajax_sspa_replace_stale_dropin', array(__CLASS__, 'ajax_replace_stale_dropin'));
        add_action('wp_ajax_sspa_share_optin', array(__CLASS__, 'ajax_share_optin'));
        add_action('wp_ajax_sspa_payload_preview', array(__CLASS__, 'ajax_payload_preview'));
        add_action('wp_ajax_sspa_submit_now', array(__CLASS__, 'ajax_submit_now'));
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

        // Baseline the MySQL digest counters before any profiling traffic. They are
        // cumulative and server-wide, so only the delta across this run means anything.
        // Silently a no-op when the database user cannot read performance_schema.
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
            $jobs = self::build_sweep_jobs($args, $sweep);
            if (is_wp_error($jobs)) {
                return $jobs;
            }
        } elseif ('cache_impact' === $type) {
            $jobs = self::build_cache_impact_jobs($args, $oc_mode);
            if (is_wp_error($jobs)) {
                return $jobs;
            }
        } elseif ('adhoc' === $type) {
            // Admin-bar "Analyse this page": one URL, stored under a URL-derived page
            // key. Runs of this type are excluded from the Overview/Pages "latest
            // analysis" queries so a one-page check never replaces a full run.
            $job = SSPA_Adhoc::job_for(!empty($args['url']) ? $args['url'] : '');
            if (is_wp_error($job)) {
                return $job;
            }
            $jobs = array($job);
        } else {
            $jobs = SSPA_Catalogue::build(!empty($args['page_keys']) ? (array) $args['page_keys'] : array());
            if (!$jobs) {
                return new WP_Error('sspa_no_jobs', __('No pages found to profile.', 'super-speedy-performance-analysis'));
            }
            if (!empty($args['include_writes'])) {
                $jobs = array_merge($jobs, self::write_jobs());
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

        // Baseline MySQL's digest counters before any profiling traffic. They are cumulative
        // and server-wide, so only the delta across this run means anything. A silent no-op
        // when the database user cannot read performance_schema, which is the common case.
        SSPA_Digests::begin($run_id);

        $queue = array(
            'jobs' => $jobs,
            'idx' => 0,
            'user_id' => $user_id,
            'started_at' => time(),
            'last_progress' => time(),
        );
        if ('cache_impact' === $type) {
            $queue['oc_mode'] = $oc_mode;
        }
        if ('deep' === $type) {
            $queue['sweep'] = $sweep;
        }
        update_option('sspa_queue_' . $run_id, $queue, false);
        wp_schedule_single_event(time() + 5, 'sspa_process_batch_event', array($run_id));

        return $run_id;
    }

    const SWEEP_REBASELINE_EVERY = 5;
    const SWEEP_SCREEN_PAGES = 2;    // top attributed pages per plugin in the screen
    const SWEEP_SCREEN_SAMPLES = 2;  // samples per screening cell (confirm uses 3)

    /**
     * Deep analysis is a TWO-PHASE sweep:
     *
     * Phase 1 (screen): every eligible plugin measured ONCE per page in one cache mode
     * ('normal' - the cache in its natural state) on a handful of pages - its top
     * SWEEP_SCREEN_PAGES pages by attributed SQL+HTTP time plus the site's slowest page.
     * Cheap: a few cells per plugin, SWEEP_SCREEN_SAMPLES samples each.
     *
     * Phase 2 (confirm, appended automatically at the phase boundary): only plugins
     * that showed a measurable impact in the screen get the full treatment - every
     * remaining page in normal mode, plus cache-disabled and cache-priming measurements
     * on their screened pages when the object cache can be toggled per-request.
     * Innocent plugins never cost more than their screening cells.
     *
     * Pages are swept page-major with a fresh baseline at each block start and every
     * SWEEP_REBASELINE_EVERY plugin cells so server drift cannot masquerade as cost.
     *
     * @param array $args {suspects?: [slugs] to restrict the sweep}
     * @param array $sweep Set by reference; stored in the queue for the phase-2
     *                     extension, finish and cleanup.
     * @return array|WP_Error ordered phase-1 job list
     */
    private static function build_sweep_jobs($args, &$sweep) {
        global $wpdb;

        $source_run_id = (int) $wpdb->get_var(
            'SELECT id FROM ' . SSPA_Schema::table('runs') . " WHERE status = 'done' AND run_type IN ('baseline','spot') ORDER BY id DESC LIMIT 1"
        );
        if (!$source_run_id) {
            return new WP_Error('sspa_no_baseline', __('Run a normal analysis first - deep analysis sweeps the pages it profiled.', 'super-speedy-performance-analysis'));
        }

        $files = SSPA_Dependency_Map::slug_to_file();
        $candidates = SSPA_Dependency_Map::isolation_candidates();
        if (!empty($args['suspects'])) {
            $requested = (array) $args['suspects'];
            $candidates = array_values(array_intersect($requested, $candidates));
            if (!$candidates) {
                return new WP_Error('sspa_not_eligible', __('That plugin cannot be safely excluded (it is a dependency of another active plugin, or on the fragile list).', 'super-speedy-performance-analysis'));
            }
        }
        if (!$candidates) {
            return new WP_Error('sspa_nothing_to_test', __('No plugins are eligible for isolation testing.', 'super-speedy-performance-analysis'));
        }

        // Pages: everything real the source run successfully profiled, re-resolved to
        // crawlable jobs now. Write/mail probes cannot be re-crawled and are skipped.
        $page_keys = $wpdb->get_col($wpdb->prepare(
            'SELECT DISTINCT page_key FROM ' . SSPA_Schema::table('profiles') . "
             WHERE run_id = %d AND blocked_by IS NULL AND page_gen_ms IS NOT NULL
             AND page_key NOT IN ('baseline', 'mail-probe') AND page_key NOT LIKE 'write-%%'",
            $source_run_id
        ));
        $page_jobs_list = $page_keys ? SSPA_Catalogue::build($page_keys) : array();
        if (!$page_jobs_list) {
            return new WP_Error('sspa_no_jobs', __('No profiled pages from the last analysis could be resolved - run a fresh analysis first.', 'super-speedy-performance-analysis'));
        }
        $page_jobs = array();
        foreach ($page_jobs_list as $job) {
            $page_jobs[$job['page_key']] = $job;
        }

        // Cache modes need the per-request object-cache toggle, i.e. OUR db.php shim.
        $has_oc = wp_using_ext_object_cache() || file_exists(WP_CONTENT_DIR . '/object-cache.php');
        $oc_capable = $has_oc && 'ours' === SSPA_Helper_Files::dropin_status();

        // Slowest resolvable page: the screening page every plugin gets tested on.
        $slowest = $wpdb->get_var($wpdb->prepare(
            'SELECT page_key FROM ' . SSPA_Schema::table('profiles') . "
             WHERE run_id = %d AND blocked_by IS NULL AND page_gen_ms IS NOT NULL
             AND page_key NOT IN ('baseline', 'mail-probe') AND page_key NOT LIKE 'write-%%'
             ORDER BY page_gen_ms DESC LIMIT 1",
            $source_run_id
        ));
        if (!isset($page_jobs[$slowest])) {
            $slowest = key($page_jobs);
        }

        // Attributed cost per plugin per page from the source run - picks each plugin's
        // screening pages.
        $attr = array(); // slug => page_key => cost
        foreach ($wpdb->get_results($wpdb->prepare(
            'SELECT cs.component, p.page_key, (cs.sql_ms + cs.http_ms) cost
             FROM ' . SSPA_Schema::table('component_stats') . ' cs
             JOIN ' . SSPA_Schema::table('profiles') . ' p ON p.id = cs.profile_id
             WHERE cs.run_id = %d',
            $source_run_id
        ), ARRAY_A) as $r) {
            if (isset($page_jobs[$r['page_key']])) {
                $attr[$r['component']][$r['page_key']] = (isset($attr[$r['component']][$r['page_key']]) ? $attr[$r['component']][$r['page_key']] : 0) + (float) $r['cost'];
            }
        }

        // One isolation payload per candidate (+ optional theme swap), written up front;
        // deleted at finish/cancel/fail and by the stale-run janitor.
        $hashes = array(); // hash => slug ('theme' for the theme swap)
        foreach ($candidates as $slug) {
            if (!isset($files[$slug])) {
                continue;
            }
            $payload = array('plugins' => array($files[$slug]), 'theme' => null);
            $hash = md5(wp_json_encode($payload));
            update_option('sspa_isolation_' . $hash, $payload, false);
            $hashes[$hash] = $slug;
        }
        $theme = empty($args['suspects']) ? self::default_theme() : null;
        if ($theme) {
            $payload = array('plugins' => array(), 'theme' => $theme);
            $theme_hash = md5(wp_json_encode($payload));
            update_option('sspa_isolation_' . $theme_hash, $payload, false);
            $hashes[$theme_hash] = 'theme';
        }

        // Screening pages per plugin. A targeted run (Measure button / --suspects) skips
        // the screen and covers every page for its suspects directly.
        $targeted = !empty($args['suspects']);
        $anon_pages = array_keys(array_filter($page_jobs, function ($j) {
            return 'anon' === $j['variant'];
        }));
        $phase1_pages = array(); // slug => [page_keys]
        foreach ($hashes as $slug) {
            if ('theme' === $slug) {
                $pages = array();
                if (in_array($slowest, $anon_pages, true)) {
                    $pages[] = $slowest;
                }
                if (isset($page_jobs['home'])) {
                    $pages[] = 'home';
                }
                if (!$pages && $anon_pages) {
                    $pages[] = $anon_pages[0];
                }
                $phase1_pages[$slug] = array_values(array_unique($pages));
                continue;
            }
            if ($targeted) {
                $phase1_pages[$slug] = array_keys($page_jobs);
                continue;
            }
            $pages = array();
            if (!empty($attr[$slug])) {
                arsort($attr[$slug]);
                $pages = array_slice(array_keys($attr[$slug]), 0, self::SWEEP_SCREEN_PAGES);
            } elseif (isset($page_jobs['home'])) {
                // No attributed queries anywhere: could still hook expensively - screen
                // it on home + the slowest page.
                $pages[] = 'home';
            }
            $pages[] = $slowest;
            $phase1_pages[$slug] = array_values(array_unique($pages));
        }

        $slug_to_hash = array_flip($hashes);
        $plan = array(); // page_key => cells
        foreach ($phase1_pages as $slug => $pages) {
            foreach ($pages as $page_key) {
                $plan[$page_key][] = array(
                    'slug' => $slug,
                    'hash' => $slug_to_hash[$slug],
                    'modes' => array('normal'),
                    'samples' => $targeted ? 0 : self::SWEEP_SCREEN_SAMPLES,
                );
            }
        }
        $jobs = self::sweep_block_jobs($page_jobs, $plan);

        $sweep = array(
            'source_run_id' => $source_run_id,
            'hashes' => $hashes,
            'oc_capable' => $oc_capable,
            'phase' => 1,
            'targeted' => $targeted,
            'plugins' => count($hashes),
            'pages' => count($page_jobs),
            'page_jobs' => $page_jobs,
            'phase1_pages' => $phase1_pages,
        );
        return $jobs;
    }

    /**
     * Page-major job list from a plan of cells: a baseline (per mode in use) opens each
     * page block and re-runs every SWEEP_REBASELINE_EVERY plugin cells.
     *
     * @param array $page_jobs page_key => catalogue job
     * @param array $plan page_key => [ {slug, hash, modes: [], samples: int|0}, ... ]
     */
    private static function sweep_block_jobs($page_jobs, $plan) {
        $jobs = array();
        foreach ($plan as $page_key => $cells) {
            if (!$cells || !isset($page_jobs[$page_key])) {
                continue;
            }
            $base = $page_jobs[$page_key];
            $block_modes = array();
            foreach ($cells as $cell) {
                foreach ($cell['modes'] as $mode) {
                    $block_modes[$mode] = true;
                }
            }
            $block_modes = array_keys($block_modes);

            $emit_baseline = function () use (&$jobs, $base, $block_modes) {
                foreach ($block_modes as $mode) {
                    $jobs[] = self::sweep_job($base, $mode, null, null, 0);
                }
            };

            $emit_baseline();
            $since = 0;
            foreach ($cells as $cell) {
                if ($since >= self::SWEEP_REBASELINE_EVERY) {
                    $emit_baseline();
                    $since = 0;
                }
                foreach ($cell['modes'] as $mode) {
                    $jobs[] = self::sweep_job($base, $mode, $cell['hash'], $cell['slug'], $cell['samples']);
                }
                $since++;
            }
        }
        return $jobs;
    }

    /**
     * One sweep job. Modes: normal (cache as-is, warmed), disabled (object cache
     * bypassed per-request), prime (first cache-enabled request: no warm-up, 1 sample).
     */
    private static function sweep_job($base, $mode, $hash, $slug, $samples) {
        $job = $base;
        if ($hash) {
            $job['ps'] = $hash;
            $job['plugin'] = $slug;
        }
        $job['oc_label'] = $mode;
        if ('disabled' === $mode) {
            $job['oc_off'] = true;
        }
        if ('prime' === $mode) {
            $job['skip_warmup'] = true;
            $job['samples'] = 1;
        } elseif ($samples > 0) {
            $job['samples'] = $samples;
        }
        return $job;
    }

    /**
     * Phase boundary: plugins with a measurable screening impact graduate to the full
     * treatment - every remaining page in normal mode plus, when the object cache can
     * be toggled per-request, disabled and priming measurements on their screened
     * pages. Appends the jobs and returns true; false = nothing to confirm, finish.
     */
    private static function sweep_extend_phase2($run_id) {
        $queue = get_option('sspa_queue_' . $run_id);
        if (!is_array($queue) || empty($queue['sweep']) || 1 !== (int) $queue['sweep']['phase']) {
            return false;
        }
        $sweep = $queue['sweep'];

        $impacted = array();
        foreach (self::sweep_deltas($run_id, $sweep['hashes']) as $d) {
            if (!empty($d['measured'])) {
                $impacted[$d['slug']] = true;
            }
        }
        $impacted = array_keys($impacted);

        $queue['sweep']['phase'] = 2;
        $queue['sweep']['phase2_plugins'] = count($impacted);
        if (!$impacted) {
            update_option('sspa_queue_' . $run_id, $queue, false);
            return false;
        }

        $slug_to_hash = array_flip($sweep['hashes']);
        $plan = array();
        foreach ($impacted as $slug) {
            $screened = isset($sweep['phase1_pages'][$slug]) ? (array) $sweep['phase1_pages'][$slug] : array();
            foreach ($sweep['page_jobs'] as $page_key => $j) {
                if ('theme' === $slug && 'anon' !== $j['variant']) {
                    continue;
                }
                if (!in_array($page_key, $screened, true)) {
                    $modes = array('normal');
                } elseif (!empty($sweep['oc_capable'])) {
                    $modes = array('disabled', 'prime');
                } else {
                    continue; // already screened, no cache modes available
                }
                $plan[$page_key][] = array(
                    'slug' => $slug,
                    'hash' => $slug_to_hash[$slug],
                    'modes' => $modes,
                    'samples' => 0,
                );
            }
        }
        $jobs = self::sweep_block_jobs($sweep['page_jobs'], $plan);
        if (!$jobs) {
            update_option('sspa_queue_' . $run_id, $queue, false);
            return false;
        }

        $queue['jobs'] = array_merge($queue['jobs'], $jobs);
        $queue['last_progress'] = time();
        update_option('sspa_queue_' . $run_id, $queue, false);
        return true;
    }

    /**
     * Cache-impact runs: each target page profiled twice, cache on vs cache off. Normal
     * jobs come first so the site-wide fallback (renaming object-cache.php) only kicks in
     * for the second half of the queue.
     *
     * @return array|WP_Error jobs; $oc_mode set by reference to 'flag' or 'sitewide'.
     */
    private static function build_cache_impact_jobs($args, &$oc_mode) {
        global $wpdb;

        $has_dropin = file_exists(WP_CONTENT_DIR . '/object-cache.php');
        if (!wp_using_ext_object_cache() && !$has_dropin) {
            return new WP_Error('sspa_no_object_cache', __('No persistent object cache detected - there is nothing to toggle. Install Redis/Memcached first.', 'super-speedy-performance-analysis'));
        }

        if ('ours' === SSPA_Helper_Files::dropin_status()) {
            $oc_mode = 'flag'; // per-request disable via the shim: zero live impact
        } elseif (!empty($args['oc_sitewide'])) {
            $oc_mode = 'sitewide';
        } else {
            return new WP_Error('sspa_oc_needs_confirm', __('Per-request cache toggling needs the SSPA db.php shim. Without it the only option is briefly renaming object-cache.php SITE-WIDE - re-run with that option explicitly confirmed, at a low-traffic time.', 'super-speedy-performance-analysis'));
        }

        if (!empty($args['page_keys'])) {
            $page_keys = (array) $args['page_keys'];
        } else {
            $source_run_id = (int) $wpdb->get_var(
                'SELECT id FROM ' . SSPA_Schema::table('runs') . " WHERE status = 'done' AND run_type IN ('baseline','spot') ORDER BY id DESC LIMIT 1"
            );
            $page_keys = $source_run_id ? $wpdb->get_col($wpdb->prepare(
                'SELECT page_key FROM ' . SSPA_Schema::table('profiles') . "
                 WHERE run_id = %d AND variant = 'anon' AND page_key NOT IN ('baseline','mail-probe')
                 AND blocked_by IS NULL AND page_gen_ms IS NOT NULL
                 ORDER BY page_gen_ms DESC LIMIT 3",
                $source_run_id
            )) : array();
            if (!$page_keys) {
                $page_keys = array('home');
            }
        }

        $jobs = array();
        $base_jobs = SSPA_Catalogue::build($page_keys);
        foreach ($base_jobs as $job) {
            $jobs[] = $job; // cache on
        }
        foreach ($base_jobs as $job) {
            $job['oc_off'] = true; // cache off (second half - see sitewide note above)
            $jobs[] = $job;
        }
        return $jobs ? $jobs : new WP_Error('sspa_no_jobs', __('No pages found to profile.', 'super-speedy-performance-analysis'));
    }

    /**
     * Opt-in write profiles: the save/transition cascades measured against temporary
     * duplicates the batch loop creates and deletes around each job. Never in run 1
     * unless the user ticks the box.
     */
    private static function write_jobs() {
        $jobs = array();
        $write_url = home_url('/?sspa_write_probe=1');
        $jobs[] = array('page_key' => 'write-save-post', 'url' => $write_url, 'variant' => 'anon', 'write' => 'save_post', 'post_type' => 'post');
        if (class_exists('WooCommerce')) {
            $jobs[] = array('page_key' => 'write-save-product', 'url' => $write_url, 'variant' => 'anon', 'write' => 'save_product', 'post_type' => 'product');
            $jobs[] = array('page_key' => 'write-order-processing', 'url' => $write_url, 'variant' => 'anon', 'write' => 'order_processing');
        }
        return $jobs;
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
            $queue = get_option('sspa_queue_' . $run_id);
            if (!is_array($queue)) {
                self::fail($run_id, 'queue missing');
                return;
            }

            $crawler = new SSPA_Crawler();
            $deadline = microtime(true) + self::BATCH_SECONDS;

            while ($queue['idx'] < count($queue['jobs']) && microtime(true) < $deadline) {
                $job = $queue['jobs'][$queue['idx']];

                // Site-wide cache toggle: engages when the queue reaches its cache-off half.
                if (!empty($job['oc_off']) && isset($queue['oc_mode']) && 'sitewide' === $queue['oc_mode'] && !get_option('sspa_oc_hold')) {
                    SSPA_Helper_Files::hold_object_cache();
                }

                // Write profiles run against a temp duplicate created just for this job.
                $temp_id = 0;
                $temp_is_order = false;
                if (!empty($job['write'])) {
                    $temp_is_order = ('order_processing' === $job['write']);
                    $temp_id = $temp_is_order
                        ? SSPA_Probes::create_temp_order()
                        : SSPA_Probes::create_temp_copy($job['post_type']);
                    if (!$temp_id) {
                        $queue['idx']++; // nothing to duplicate on this site - skip quietly
                        update_option('sspa_queue_' . $run_id, $queue, false);
                        continue;
                    }
                    $job['flags'] = array('wp' => $job['write'], 'tid' => (string) $temp_id, 'mail' => 'c');
                }

                try {
                    $result = $crawler->profile_job($job, $queue['user_id']);
                    SSPA_Profile_Store::save($run_id, $result);
                } finally {
                    if ($temp_id) {
                        SSPA_Probes::delete_temp($temp_id, $temp_is_order);
                    }
                }
                $queue['idx']++;
                $queue['last_progress'] = time();
                update_option('sspa_queue_' . $run_id, $queue, false);

                $run = self::run_row($run_id);
                if (!$run || 'crawling' !== $run['status']) {
                    return; // cancelled mid-batch
                }
            }

            if ($queue['idx'] >= count($queue['jobs'])) {
                if ('cache_impact' === $run['run_type']) {
                    self::finish_cache($run_id);
                } elseif ('deep' === $run['run_type']) {
                    // Phase boundary: impacted plugins graduate to the full treatment.
                    if (self::sweep_extend_phase2($run_id)) {
                        wp_schedule_single_event(time() + 2, 'sspa_process_batch_event', array($run_id));
                    } else {
                        self::finish_sweep($run_id);
                    }
                } else {
                    self::finish($run_id);
                }
            } else {
                wp_schedule_single_event(time() + 2, 'sspa_process_batch_event', array($run_id));
            }
        } finally {
            delete_option($lock_key);
        }
    }

    /**
     * Sweep completion: every stored profile with a plugin-set hash is a cell; its delta
     * against the most recent preceding baseline for the same page + cache mode becomes
     * a plugin_impacts row. Sign convention: baseline - excluded, so positive = the
     * plugin adds time, negative = it saves time.
     */
    private static function finish_sweep($run_id) {
        global $wpdb;
        SSPA_Helper_Files::restore_held_dropin();

        $queue = get_option('sspa_queue_' . $run_id);
        $sweep = (is_array($queue) && isset($queue['sweep'])) ? $queue['sweep'] : array();
        $hashes = isset($sweep['hashes']) ? $sweep['hashes'] : array();

        $deltas = self::sweep_deltas($run_id, $hashes);
        $measured = 0;
        $unresolved = 0;
        $fatal_cells = array();
        $now = gmdate('Y-m-d H:i:s');

        foreach ($deltas as $d) {
            if (!empty($d['unresolved'])) {
                $unresolved++;
                if (!empty($d['fatal'])) {
                    $fatal_cells[$d['slug'] . '|' . $d['page_key']] = array(
                        'plugin' => $d['slug'],
                        'page_key' => $d['page_key'],
                    );
                }
                continue;
            }
            if (!empty($d['measured'])) {
                $measured++;
            }
            $wpdb->insert(SSPA_Schema::table('plugin_impacts'), array(
                'blog_id' => get_current_blog_id(),
                'plugin' => ('theme' === $d['slug']) ? get_stylesheet() : $d['slug'],
                'page_key' => $d['page_key'],
                'method' => 'single_out',
                'object_cache_mode' => $d['mode'],
                'delta_ttfb_ms' => round($d['delta_gen'], 1),
                'delta_sql_ms' => round($d['delta_sql'], 1),
                'delta_http_ms' => round($d['delta_http'], 1),
                'delta_mem_bytes' => (int) $d['delta_mem'],
                'delta_queries' => (int) $d['delta_queries'],
                'noise_floor_ms' => round($d['gate'], 1),
                'confidence' => !empty($d['measured']) ? 'measured' : 'none',
                'baseline_run_id' => isset($sweep['source_run_id']) ? (int) $sweep['source_run_id'] : null,
                'test_run_id' => $run_id,
                'created' => $now,
            ));
        }

        foreach (array_keys($hashes) as $hash) {
            delete_option('sspa_isolation_' . $hash);
        }
        delete_option('sspa_queue_' . $run_id);

        $wpdb->update(SSPA_Schema::table('runs'), array(
            'status' => 'done',
            'finished' => $now,
            'notes' => wp_json_encode(array(
                'type' => 'deep',
                'impacts' => $measured,
                'measurements' => count($deltas),
                'cells' => count($deltas) - $unresolved,
                'unresolved' => $unresolved,
                'plugins' => isset($sweep['plugins']) ? (int) $sweep['plugins'] : null,
                'pages' => isset($sweep['pages']) ? (int) $sweep['pages'] : null,
                'phase2_plugins' => isset($sweep['phase2_plugins']) ? (int) $sweep['phase2_plugins'] : 0,
                'modes' => !empty($sweep['oc_capable']) ? array('normal', 'disabled', 'prime') : array('normal'),
                'fatal_cells' => array_values($fatal_cells),
            )),
        ), array('id' => $run_id));
    }

    /**
     * Pair every cell profile (plugin excluded) with the most recent preceding baseline
     * for the same page + cache mode and return the deltas. Shared by the phase-2
     * graduation check and finish_sweep. Sign: baseline - excluded (positive = the
     * plugin adds time, negative = it saves time).
     *
     * @return array[] {slug, page_key, mode, gate, measured, unresolved,
     *                  delta_gen, delta_sql, delta_http, delta_mem, delta_queries}
     */
    private static function sweep_deltas($run_id, $hashes) {
        global $wpdb;
        $profiles = $wpdb->get_results($wpdb->prepare(
            'SELECT id, page_key, plugin_set_hash, object_cache_mode, page_gen_ms, sql_ms, http_ms,
                    peak_mem_bytes, sql_count, samples, response_code, blocked_by
             FROM ' . SSPA_Schema::table('profiles') . ' WHERE run_id = %d ORDER BY id ASC',
            $run_id
        ), ARRAY_A);

        $baselines = array(); // "page|mode" => ['p' => row, 'gate' => float]
        $deltas = array();

        foreach ($profiles as $p) {
            $ok = null === $p['blocked_by'] && null !== $p['page_gen_ms'] && (int) $p['response_code'] < 500;
            $key = $p['page_key'] . '|' . $p['object_cache_mode'];

            if ('' === (string) $p['plugin_set_hash']) {
                if ($ok) {
                    $baselines[$key] = array('p' => $p, 'gate' => self::noise_gate($p['samples']));
                }
                continue;
            }
            if (!isset($hashes[$p['plugin_set_hash']])) {
                continue; // not one of ours (should not happen)
            }
            $slug = $hashes[$p['plugin_set_hash']];
            if (!$ok || !isset($baselines[$key])) {
                $deltas[] = array(
                    'slug' => $slug,
                    'page_key' => $p['page_key'],
                    'mode' => $p['object_cache_mode'],
                    'unresolved' => true,
                    // The page fatals with this plugin excluded but was fine at baseline:
                    // a hard data/runtime dependency discovered by experiment. Reported,
                    // not measured.
                    'fatal' => (int) $p['response_code'] >= 500 && isset($baselines[$key]),
                );
                continue;
            }

            $b = $baselines[$key]['p'];
            $gate = $baselines[$key]['gate'];
            $delta_gen = (float) $b['page_gen_ms'] - (float) $p['page_gen_ms'];
            $deltas[] = array(
                'slug' => $slug,
                'page_key' => $p['page_key'],
                'mode' => $p['object_cache_mode'],
                'gate' => $gate,
                'measured' => abs($delta_gen) > $gate,
                'unresolved' => false,
                'delta_gen' => $delta_gen,
                'delta_sql' => (float) $b['sql_ms'] - (float) $p['sql_ms'],
                'delta_http' => (float) $b['http_ms'] - (float) $p['http_ms'],
                'delta_mem' => (int) $b['peak_mem_bytes'] - (int) $p['peak_mem_bytes'],
                'delta_queries' => (int) $b['sql_count'] - (int) $p['sql_count'],
            );
        }
        return $deltas;
    }

    /**
     * Noise gate from a profile row's stored sample summaries: max(3 x stddev of the
     * sampled generation times, 30ms). Deltas inside the gate are never "measured".
     */
    private static function noise_gate($samples_json) {
        $values = array();
        $samples = json_decode((string) $samples_json, true);
        foreach ((array) $samples as $s) {
            if (isset($s['gen_ms']) && is_numeric($s['gen_ms'])) {
                $values[] = (float) $s['gen_ms'];
            }
        }
        $n = count($values);
        if ($n < 2) {
            return 30.0;
        }
        $mean = array_sum($values) / $n;
        $sum = 0.0;
        foreach ($values as $v) {
            $sum += ($v - $mean) ** 2;
        }
        return max(30.0, 3 * sqrt($sum / $n));
    }

    /**
     * Cache-impact analysis: per component, query counts with the object cache on vs off.
     * Identical counts = cache-blind (the component ignores the object cache entirely).
     */
    private static function finish_cache($run_id) {
        global $wpdb;
        SSPA_Helper_Files::restore_object_cache();
        delete_option('sspa_queue_' . $run_id);

        // Verify the off half really ran without a persistent cache (from the captures).
        $verified = true;
        $off_profiles = $wpdb->get_results($wpdb->prepare(
            'SELECT profile_blob FROM ' . SSPA_Schema::table('profiles') . " WHERE run_id = %d AND object_cache_mode = 'disabled'",
            $run_id
        ), ARRAY_A);
        foreach ($off_profiles as $p) {
            $capture = $p['profile_blob'] ? json_decode((string) @gzuncompress($p['profile_blob']), true) : null;
            if (is_array($capture) && !empty($capture['cache']['persistent'])) {
                $verified = false;
            }
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT p.object_cache_mode, cs.component, cs.component_type, SUM(cs.query_count) qc, SUM(cs.sql_ms) sql_ms
             FROM ' . SSPA_Schema::table('component_stats') . ' cs
             JOIN ' . SSPA_Schema::table('profiles') . ' p ON p.id = cs.profile_id
             WHERE cs.run_id = %d GROUP BY p.object_cache_mode, cs.component, cs.component_type',
            $run_id
        ), ARRAY_A);

        $components = array();
        foreach ($rows as $r) {
            $key = $r['component'];
            if (!isset($components[$key])) {
                $components[$key] = array('type' => $r['component_type'], 'on' => 0, 'off' => 0, 'sql_ms_on' => 0, 'sql_ms_off' => 0);
            }
            $mode = ('disabled' === $r['object_cache_mode']) ? 'off' : 'on';
            $components[$key][$mode] += (int) $r['qc'];
            $components[$key]['sql_ms_' . $mode] += (float) $r['sql_ms'];
        }

        $now = gmdate('Y-m-d H:i:s');
        foreach ($components as $component => $c) {
            if (in_array($component, array('super-speedy-performance-analysis', 'mu:sspa-loader', 'core'), true)) {
                continue;
            }
            if ($c['off'] < 20 || !$verified) {
                continue;
            }
            // Sampling jitter can make "on" exceed "off"; a negative saving is noise.
            $saved_pct = max(0, (int) round(100 * ($c['off'] - $c['on']) / $c['off']));
            $components[$component]['saved_pct'] = $saved_pct;
            if ($saved_pct < 15) {
                $wpdb->insert(SSPA_Schema::table('findings'), array(
                    'run_id' => $run_id,
                    'severity' => 'warn',
                    'finding_type' => 'cache_blind',
                    'component' => $component,
                    'page_key' => null,
                    'evidence' => wp_json_encode(array('queries_on' => $c['on'], 'queries_off' => $c['off'], 'saved_pct' => $saved_pct)),
                    'recommendation_key' => 'cache_blind',
                    'confidence' => 'measured',
                    'created' => $now,
                ));
            } elseif ($saved_pct >= 50) {
                $wpdb->insert(SSPA_Schema::table('findings'), array(
                    'run_id' => $run_id,
                    'severity' => 'info',
                    'finding_type' => 'cache_friendly',
                    'component' => $component,
                    'page_key' => null,
                    'evidence' => wp_json_encode(array('queries_on' => $c['on'], 'queries_off' => $c['off'], 'saved_pct' => $saved_pct)),
                    'recommendation_key' => 'cache_friendly',
                    'confidence' => 'measured',
                    'created' => $now,
                ));
            }
        }

        $wpdb->update(SSPA_Schema::table('runs'), array(
            'status' => $verified ? 'done' : 'failed',
            'finished' => $now,
            'notes' => wp_json_encode(array('type' => 'cache_impact', 'verified' => $verified, 'components' => $components)),
        ), array('id' => $run_id));
    }

    private static function finish($run_id) {
        global $wpdb;
        SSPA_Helper_Files::restore_held_dropin();
        delete_option('sspa_queue_' . $run_id);
        self::set_status($run_id, 'analysing');

        $demographics = SSPA_Demographics::snapshot($run_id);
        $engine = new SSPA_Analysis_Engine();
        $engine->set_digests(SSPA_Digests::collect($run_id));
        $finding_count = $engine->analyse($run_id, $demographics);
        $score = SSPA_Analysis_Engine::score($run_id);

        // Pages where every sample came back without our canary (a page cache or CDN
        // answered before PHP ran) have NULL metrics and silently distort the score if
        // they pass unmentioned. Count them into the run so the UI can be honest.
        $profiles = SSPA_Schema::table('profiles');
        $unmeasured = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $profiles
             WHERE run_id = %d AND page_gen_ms IS NULL AND blocked_by IS NULL
               AND page_key <> 'baseline'",
            $run_id
        ));

        $notes = array('score' => $score, 'findings' => $finding_count);
        if ($unmeasured > 0) {
            $notes['unmeasured_pages'] = $unmeasured;
        }
        $wpdb->update(SSPA_Schema::table('runs'), array(
            'status' => 'done',
            'finished' => gmdate('Y-m-d H:i:s'),
            'notes' => wp_json_encode($notes),
        ), array('id' => $run_id));
    }

    private static function cleanup_run_state($run_id) {
        $queue = get_option('sspa_queue_' . $run_id);
        if (is_array($queue) && !empty($queue['sweep']['hashes'])) {
            foreach (array_keys($queue['sweep']['hashes']) as $hash) {
                delete_option('sspa_isolation_' . $hash);
            }
        }
        delete_option('sspa_queue_' . $run_id);
        delete_option('sspa_deep_' . $run_id); // legacy pre-0.8 deep plans
    }

    private static function fail($run_id, $note) {
        global $wpdb;
        SSPA_Helper_Files::restore_held_dropin();
        SSPA_Helper_Files::restore_object_cache();
        self::cleanup_run_state($run_id);
        $wpdb->update(SSPA_Schema::table('runs'), array(
            'status' => 'failed',
            'finished' => gmdate('Y-m-d H:i:s'),
            'notes' => $note,
        ), array('id' => $run_id));
    }

    public static function cancel($run_id) {
        SSPA_Helper_Files::restore_held_dropin();
        SSPA_Helper_Files::restore_object_cache();
        self::cleanup_run_state($run_id);
        self::set_status($run_id, 'cancelled', true);
    }

    public static function status($run_id) {
        global $wpdb;
        $run = self::run_row($run_id);
        if (!$run) {
            return null;
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

        $current = null;
        $current_plugin = null;
        if (is_array($queue) && isset($queue['jobs'][$idx])) {
            $job = $queue['jobs'][$idx];
            $current = $job['page_key'];
            if (!empty($job['plugin'])) {
                $current_plugin = ('theme' === $job['plugin']) ? get_stylesheet() . ' (theme)' : $job['plugin'];
                $current = $current_plugin . ' on ' . $job['page_key'];
            }
            if (!empty($job['oc_label']) && 'normal' !== $job['oc_label']) {
                $oc_labels = array(
                    'disabled' => __('object cache off', 'super-speedy-performance-analysis'),
                    'prime' => __('priming cache', 'super-speedy-performance-analysis'),
                    'warm' => __('warm cache', 'super-speedy-performance-analysis'),
                );
                $current .= ' (' . $oc_labels[$job['oc_label']] . ')';
            }
        }

        $elapsed = (is_array($queue) && !empty($queue['started_at'])) ? max(0, time() - (int) $queue['started_at']) : null;
        $eta = ($elapsed && $idx > 0 && $idx < $total) ? (int) round(($elapsed / $idx) * ($total - $idx)) : null;

        return array(
            'run_id' => (int) $run['id'],
            'status' => $run['status'],
            'run_type' => $run['run_type'],
            'total' => $total,
            'done' => $idx,
            'current' => $current,
            'current_plugin' => $current_plugin,
            'phase' => (is_array($queue) && isset($queue['sweep']['phase'])) ? (int) $queue['sweep']['phase'] : null,
            'elapsed_seconds' => $elapsed,
            'eta_seconds' => $eta,
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

        // Staleness is judged by PROGRESS, not age: a full sweep legitimately runs for
        // hours. A run with no progress for 30 minutes gets its batch event re-kicked
        // (the driver tab may have closed with no event pending); only a run that still
        // makes no progress after 3 hours is failed.
        $runs = SSPA_Schema::table('runs');
        $candidates = $wpdb->get_results(
            "SELECT id, status, started FROM $runs WHERE status IN ('queued','crawling','analysing')",
            ARRAY_A
        );
        foreach ($candidates as $r) {
            $run_id = (int) $r['id'];
            $queue = get_option('sspa_queue_' . $run_id);
            if (is_array($queue) && !empty($queue['last_progress'])) {
                $idle = time() - (int) $queue['last_progress'];
                if ($idle > 3 * HOUR_IN_SECONDS) {
                    self::fail($run_id, 'stale - no progress in 3 hours');
                } elseif ($idle > 30 * MINUTE_IN_SECONDS && !wp_next_scheduled('sspa_process_batch_event', array($run_id))) {
                    wp_schedule_single_event(time() + 5, 'sspa_process_batch_event', array($run_id));
                }
            } elseif (strtotime($r['started'] . ' UTC') < time() - 2 * HOUR_IN_SECONDS) {
                self::fail($run_id, 'stale - timed out');
            }
        }

        // Refresh the community rules feed daily-ish (transient-gated inside).
        if (false === get_transient(SSPA_Rules_Feed::CACHE_KEY)) {
            SSPA_Rules_Feed::refresh();
            SSPA_Rules::flush();
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
        $type = 'baseline';
        if (isset($_POST['type']) && in_array($_POST['type'], array('deep', 'cache_impact'), true)) {
            $type = $_POST['type'];
        }
        $args = array(
            'type' => $type,
            'swap_dropin' => !empty($_POST['swap_dropin']),
            'oc_sitewide' => !empty($_POST['oc_sitewide']),
            'include_writes' => !empty($_POST['include_writes']),
        );
        if ('deep' === $type && !empty($_POST['suspects'])) {
            $args['suspects'] = array_map('sanitize_key', (array) $_POST['suspects']);
        }
        if (isset($_POST['page_keys'])) {
            $args['page_keys'] = array_map('sanitize_text_field', (array) $_POST['page_keys']);
            if ('baseline' === $args['type']) {
                // A page-filtered run is a spot check, not a full baseline - recording it
                // as baseline made it the "latest analysis" everywhere and hid the full run.
                $args['type'] = 'spot';
            }
        }
        $run_id = self::start($args);
        if (is_wp_error($run_id)) {
            wp_send_json_error($run_id->get_error_message());
        }
        wp_send_json_success(self::status($run_id));
    }

    public static function ajax_replace_stale_dropin() {
        self::ajax_guard();
        $result = SSPA_Helper_Files::replace_stale_qm_dropin();
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        wp_send_json_success();
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
            'boot' => isset($capture['boot']) ? $capture['boot'] : null,
            'profile' => isset($capture['profile']) ? $capture['profile'] : null,
        ));
    }

    /**
     * Per-plugin measured-impact breakdown: every page x cache-mode delta from the
     * plugin's most recent sweep. Feeds the Plugins tab drill-down.
     */
    public static function ajax_plugin_detail() {
        global $wpdb;
        self::ajax_guard();
        $plugin = isset($_POST['plugin']) ? sanitize_text_field(wp_unslash($_POST['plugin'])) : '';
        if ('' === $plugin) {
            wp_send_json_error('no plugin');
        }
        $table = SSPA_Schema::table('plugin_impacts');
        $test_run_id = (int) $wpdb->get_var($wpdb->prepare("SELECT MAX(test_run_id) FROM $table WHERE plugin = %s", $plugin));
        if (!$test_run_id) {
            wp_send_json_error(__('No measured impacts for this plugin yet - run Deep Analysis.', 'super-speedy-performance-analysis'));
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT page_key, object_cache_mode, delta_ttfb_ms, delta_sql_ms, delta_http_ms,
                    delta_mem_bytes, delta_queries, noise_floor_ms, confidence, created
             FROM $table WHERE plugin = %s AND test_run_id = %d ORDER BY page_key, id",
            $plugin,
            $test_run_id
        ), ARRAY_A);
        wp_send_json_success(array(
            'rows' => $rows,
            'measured_at' => $rows ? $rows[0]['created'] : null,
        ));
    }

    public static function ajax_share_optin() {
        self::ajax_guard();
        update_option('sspa_share_optin', !empty($_POST['optin']) ? 1 : 0, false);
        wp_send_json_success(array('optin' => SSPA_Submitter::opted_in()));
    }

    public static function ajax_payload_preview() {
        self::ajax_guard();
        $payload = SSPA_Anonymiser::build();
        if (is_wp_error($payload)) {
            wp_send_json_error($payload->get_error_message());
        }
        wp_send_json_success(array('payload' => wp_json_encode($payload, JSON_PRETTY_PRINT)));
    }

    public static function ajax_submit_now() {
        self::ajax_guard();
        $result = SSPA_Submitter::submit();
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        wp_send_json_success();
    }

    public static function ajax_prune_blobs() {
        global $wpdb;
        self::ajax_guard();

        // Share-before-delete: an opted-in site contributes its data before pruning it.
        if (SSPA_Submitter::opted_in()) {
            SSPA_Submitter::submit();
        }

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
