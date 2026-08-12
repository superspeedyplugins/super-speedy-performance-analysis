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

    /** Caught reactions waiting to be shown to the site owner, cleared when he dismisses. */
    const REACTION_NOTICE_OPTION = 'sspa_reaction_notice';

    public static function register() {
        add_action('sspa_process_batch_event', array(__CLASS__, 'process_batch'));
        add_action('sspa_cleanup_event', array(__CLASS__, 'cleanup'));
        add_action('plugins_loaded', array('SSPA_Helper_Files', 'stale_hold_check'), 20);

        add_action('wp_ajax_sspa_start_run', array(__CLASS__, 'ajax_start_run'));
        add_action('wp_ajax_sspa_process_batch', array(__CLASS__, 'ajax_process_batch'));
        add_action('wp_ajax_sspa_run_status', array(__CLASS__, 'ajax_run_status'));
        add_action('wp_ajax_sspa_cancel_run', array(__CLASS__, 'ajax_cancel_run'));
        add_action('wp_ajax_sspa_plugin_detail', array(__CLASS__, 'ajax_plugin_detail'));
        add_action('wp_ajax_sspa_attribution', array(__CLASS__, 'ajax_attribution'));
        add_action('wp_ajax_sspa_render_tab', array(__CLASS__, 'ajax_render_tab'));
        add_action('wp_ajax_sspa_submission_tick', array(__CLASS__, 'ajax_submission_tick'));
        add_action('wp_ajax_sspa_prune_blobs', array(__CLASS__, 'ajax_prune_blobs'));
        add_action('wp_ajax_sspa_replace_stale_dropin', array(__CLASS__, 'ajax_replace_stale_dropin'));
        add_action('wp_ajax_sspa_tools_recheck', array(__CLASS__, 'ajax_tools_recheck'));
        add_action('wp_ajax_sspa_save_settings', array(__CLASS__, 'ajax_save_settings'));
        add_action('wp_ajax_sspa_share_optin', array(__CLASS__, 'ajax_share_optin'));
        add_action('wp_ajax_sspa_publisher_toggle', array(__CLASS__, 'ajax_publisher_toggle'));
        add_action('wp_ajax_sspa_payload_preview', array(__CLASS__, 'ajax_payload_preview'));
        add_action('wp_ajax_sspa_submit_now', array(__CLASS__, 'ajax_submit_now'));
        add_action('wp_ajax_sspa_community_backfill', array(__CLASS__, 'ajax_community_backfill'));
        add_action('wp_ajax_sspa_outbox_action', array(__CLASS__, 'ajax_outbox_action'));
        add_action('wp_ajax_sspa_share_run', array(__CLASS__, 'ajax_share_run'));
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
                // The foreign drop-in may already be displaced (swap happens above); an
                // aborted start must not leave the site running our shim indefinitely.
                SSPA_Helper_Files::restore_held_dropin();
                return $jobs;
            }
        } elseif ('cache_impact' === $type) {
            $jobs = self::build_cache_impact_jobs($args, $oc_mode);
            if (is_wp_error($jobs)) {
                SSPA_Helper_Files::restore_held_dropin();
                return $jobs;
            }
        } elseif ('checkout' === $type) {
            // One purchase per run (doc A4): a pre-flight inventory, then the flow. Two
            // jobs rather than one so the existing status polling, cancel button, lock and
            // stale-run janitor all keep working unchanged.
            if (!class_exists('WooCommerce')) {
                SSPA_Helper_Files::restore_held_dropin();
                return new WP_Error('sspa_no_store', __('WooCommerce is not active, so there is no checkout to profile.', 'super-speedy-performance-analysis'));
            }
            $jobs = array(
                array('page_key' => 'flow-preflight', 'checkout' => 'preflight', 'variant' => 'guest', 'url' => home_url('/?sspa_flow_probe=1')),
                array('page_key' => 'flow', 'checkout' => 'flow', 'variant' => 'guest', 'url' => wc_get_checkout_url()),
            );
        } elseif ('adhoc' === $type) {
            // Admin-bar "Analyse this page": one URL, stored under a URL-derived page
            // key. Runs of this type are excluded from the Overview/Pages "latest
            // analysis" queries so a one-page check never replaces a full run.
            $job = SSPA_Adhoc::job_for(!empty($args['url']) ? $args['url'] : '');
            if (is_wp_error($job)) {
                SSPA_Helper_Files::restore_held_dropin();
                return $job;
            }
            $jobs = array($job);
        } else {
            $jobs = SSPA_Catalogue::build(!empty($args['page_keys']) ? (array) $args['page_keys'] : array());
            if (!$jobs) {
                SSPA_Helper_Files::restore_held_dropin();
                return new WP_Error('sspa_no_jobs', __('No pages found to profile.', 'super-speedy-performance-analysis'));
            }
            if (!empty($args['include_writes'])) {
                $jobs = array_merge($jobs, self::write_jobs());
            }
        }

        $share_context = array();
        if (!empty($args['share_context']['plugin_toggle']) && is_array($args['share_context']['plugin_toggle'])) {
            $toggle = $args['share_context']['plugin_toggle'];
            $slug = isset($toggle['slug']) ? sanitize_key($toggle['slug']) : '';
            $action = isset($toggle['action']) ? sanitize_key($toggle['action']) : '';
            if ($slug && in_array($action, array('activated', 'deactivated'), true)) {
                $share_context['plugin_toggle'] = array('slug' => $slug, 'action' => $action);
            }
        }
        // Which half of a before/after cycle this run is, when an orchestrator (Scalability Pro's
        // good-settings flow, Archives' apply flow) is driving one.
        if (!empty($args['share_context']['change_cycle'])) {
            $cycle = SSPA_Community_State::change_cycle($args['share_context']['change_cycle']);
            if ($cycle) {
                $share_context['change_cycle'] = $cycle;
            }
        }
        $component_inventory = SSPA_Community_Exporter::component_inventory_snapshot();

        // Configuration state is captured HERE, at run start, for the same reason plugin_set is:
        // the run is a measurement of the site as it was configured at this moment. Asking at
        // export time would stamp today's settings onto last week's baseline and silently
        // mislabel exactly the before/after pairs this exists to tell apart. The failure mode is
        // a perfectly well-formed payload that is wrong, which is the kind that goes unnoticed.
        $component_state = SSPA_Community_State::collect(array(
            'run_type' => $type,
            'trigger' => !empty($args['trigger']) ? $args['trigger'] : 'manual',
        ));
        if ($component_state) {
            $share_context['component_state'] = $component_state;
        }
        $wpdb->insert(SSPA_Schema::table('runs'), array(
            'run_uuid' => wp_generate_uuid4(),
            'blog_id' => get_current_blog_id(),
            'run_type' => $type,
            'measurement_version' => SSPA_Community_Schema::MEASUREMENT_VERSION,
            'trigger_source' => !empty($args['trigger']) ? $args['trigger'] : 'manual',
            'status' => 'crawling',
            'plugin_set' => wp_json_encode(array(
                'components' => $component_inventory,
                'user_id' => $user_id,
            )),
            'plugin_set_hash' => md5(wp_json_encode(get_option('active_plugins', array()))),
            'share_context' => $share_context ? wp_json_encode($share_context) : null,
            'started' => gmdate('Y-m-d H:i:s'),
        ));
        $run_id = (int) $wpdb->insert_id;

        // Every run type needs a measurement-time site snapshot. Normal baseline/spot
        // completion refreshes it after the crawl; other run types retain this start snapshot.
        SSPA_Demographics::snapshot($run_id);

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
        if ('checkout' === $type) {
            // The resolved run user (never a temporary admin), so the order-management steps
            // authenticate as them.
            $args['user_id'] = $user_id;
            $queue['checkout'] = array(
                'opts' => self::checkout_opts($args, $run_id),
                'inventory' => null,
                'result' => null,
            );
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
     * @param array $args {suspects?: [slugs] to restrict the sweep, page_keys?: [keys],
     *                     url?: one URL to scope the whole sweep to,
     *                     cache_modes?: bool - whether phase 2 may add the cache modes}
     * @param array $sweep Set by reference; stored in the queue for the phase-2
     *                     extension, finish and cleanup.
     * @return array|WP_Error ordered phase-1 job list
     */
    private static function build_sweep_jobs($args, &$sweep) {
        global $wpdb;

        // Excluding a plugin can provoke a reaction from another one, and the guard that
        // refuses destructive statements while that can happen lives in OUR db.php shim.
        // No shim, no guard - so no exclusion sweep. Degraded mode (core SAVEQUERIES under
        // a foreign drop-in) is fine for observation-only runs and unacceptable here.
        $dropin = SSPA_Helper_Files::dropin_status();
        if ('ours' !== $dropin) {
            $why = in_array($dropin, array('foreign', 'qm'), true)
                ? __('Another plugin currently owns wp-content/db.php: start the analysis from the Overview tab with "Temporarily swap db.php for this run" ticked, and the original is restored the moment the run finishes.', 'super-speedy-performance-analysis')
                : __('It could not be installed - wp-content is not writable.', 'super-speedy-performance-analysis');
            return new WP_Error('sspa_dropin_required', sprintf(
                /* translators: %s: what to do about the missing drop-in */
                __('Plugin impact analysis needs this plugin\'s own database drop-in in place - it is what refuses destructive database statements if a plugin reacts to another being excluded during a measurement. %s', 'super-speedy-performance-analysis'),
                $why
            ));
        }

        // One URL, measured where you are looking at it. The source run is only needed to
        // choose pages and rank suspects - the deltas come from baselines this run takes for
        // itself at each page block - so a page the last full analysis never profiled (a
        // particular product, an order screen) is measurable without one.
        $scoped_url = !empty($args['url']) ? (string) $args['url'] : '';

        $source_run_id = (int) $wpdb->get_var(
            'SELECT id FROM ' . SSPA_Schema::table('runs') . " WHERE status = 'done' AND run_type IN ('baseline','spot') ORDER BY id DESC LIMIT 1"
        );
        if (!$source_run_id && !$scoped_url) {
            return new WP_Error('sspa_no_baseline', __('Run a normal analysis first - plugin impact analysis sweeps the pages it profiled.', 'super-speedy-performance-analysis'));
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

        $page_jobs = array();
        if ($scoped_url) {
            $scoped_job = SSPA_Adhoc::job_for($scoped_url);
            if (is_wp_error($scoped_job)) {
                return $scoped_job;
            }
            $page_jobs[$scoped_job['page_key']] = $scoped_job;
        } else {
            // Pages: everything real the source run successfully profiled, re-resolved to
            // crawlable jobs now. Write/mail probes cannot be re-crawled and are skipped.
            $page_keys = $wpdb->get_col($wpdb->prepare(
                'SELECT DISTINCT page_key FROM ' . SSPA_Schema::table('profiles') . "
                 WHERE run_id = %d AND blocked_by IS NULL AND page_gen_ms IS NOT NULL
                 AND page_key NOT IN ('baseline', 'mail-probe') AND page_key NOT LIKE 'write-%%'",
                $source_run_id
            ));
            // A sweep can be narrowed to specific pages. Without this a targeted run still
            // covers every page the source run profiled, which is the difference between
            // re-measuring one fixed plugin on one page in a couple of minutes and re-running
            // for hours.
            if (!empty($args['page_keys'])) {
                $requested = array_values(array_intersect($page_keys, (array) $args['page_keys']));
                if (!$requested) {
                    return new WP_Error(
                        'sspa_pages_not_profiled',
                        __('None of those pages were profiled by the last analysis, so there is no baseline to compare against.', 'super-speedy-performance-analysis')
                    );
                }
                $page_keys = $requested;
            }
            $page_jobs_list = $page_keys ? SSPA_Catalogue::build($page_keys) : array();
            if (!$page_jobs_list) {
                return new WP_Error('sspa_no_jobs', __('No profiled pages from the last analysis could be resolved - run a fresh analysis first.', 'super-speedy-performance-analysis'));
            }
            foreach ($page_jobs_list as $job) {
                $page_jobs[$job['page_key']] = $job;
            }
        }

        // Cache modes need the per-request object-cache toggle, i.e. OUR db.php shim.
        $has_oc = wp_using_ext_object_cache() || file_exists(WP_CONTENT_DIR . '/object-cache.php');
        $oc_capable = $has_oc && 'ours' === SSPA_Helper_Files::dropin_status();

        // Slowest resolvable page: the screening page every plugin gets tested on. A sweep
        // scoped to one URL has no source run to rank pages by, and only one page to pick.
        $slowest = !$source_run_id ? null : $wpdb->get_var($wpdb->prepare(
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
        //
        // A plugin that cannot run without another one goes in the SAME payload as it. Left
        // out, it loads, finds its dependency missing and acts - Rank Math Pro deactivates
        // itself and tries to reactivate Rank Math, running two installers' worth of hooks
        // inside a measurement. Excluding the pair together is both safer and a better
        // number: nobody runs Rank Math Pro without Rank Math, so the cost of the pair is
        // the cost of having it.
        $together = SSPA_Dependency_Map::must_exclude_together();
        $hashes = array(); // hash => slug ('theme' for the theme swap)
        $groups = array(); // slug => [slugs excluded alongside it]
        foreach ($candidates as $slug) {
            if (!isset($files[$slug])) {
                continue;
            }
            $group_files = array($files[$slug]);
            $group_slugs = array();
            foreach ((isset($together[$slug]) ? $together[$slug] : array()) as $dependant) {
                if (isset($files[$dependant]) && !in_array($files[$dependant], $group_files, true)) {
                    $group_files[] = $files[$dependant];
                    $group_slugs[] = $dependant;
                }
            }
            $payload = array('plugins' => $group_files, 'theme' => null);
            $hash = md5(wp_json_encode($payload));
            update_option('sspa_isolation_' . $hash, $payload, false);
            $hashes[$hash] = $slug;
            if ($group_slugs) {
                $groups[$slug] = $group_slugs;
            }
        }
        $theme = empty($args['suspects']) ? self::default_theme() : null;
        if ($theme) {
            $payload = array('plugins' => array(), 'theme' => $theme);
            $theme_hash = md5(wp_json_encode($payload));
            update_option('sspa_isolation_' . $theme_hash, $payload, false);
            $hashes[$theme_hash] = 'theme';
        }

        // Screening pages per plugin. A targeted run (Measure button / --suspects) skips
        // the screen and covers every page for its suspects directly. A run scoped to one URL
        // is targeted by construction: there is one page, so screening it at two samples and
        // then confirming the same cell would only add measurements.
        $targeted = !empty($args['suspects']) || (bool) $scoped_url;
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
                    'group' => isset($groups[$slug]) ? $groups[$slug] : array(),
                );
            }
        }
        $jobs = self::sweep_block_jobs($page_jobs, $plan);

        $sweep = array(
            'source_run_id' => $source_run_id,
            'hashes' => $hashes,
            // slug => the dependants excluded in the same cell, so the verdict can say who
            // it covers rather than crediting the whole delta to one plugin.
            'groups' => $groups,
            'oc_capable' => $oc_capable,
            // Whether phase 2 may spend three measurements per cell on the cache modes.
            // Site sweeps always have; a page-scoped sweep asks first, because there the
            // difference between 24 and 72 measurements is the difference between waiting
            // and walking away.
            'cache_modes' => !isset($args['cache_modes']) || !empty($args['cache_modes']),
            'scoped_url' => $scoped_url ? $scoped_url : null,
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
                    $jobs[] = self::sweep_job(
                        $base,
                        $mode,
                        $cell['hash'],
                        $cell['slug'],
                        $cell['samples'],
                        isset($cell['group']) ? (array) $cell['group'] : array()
                    );
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
    private static function sweep_job($base, $mode, $hash, $slug, $samples, $group = array()) {
        $job = $base;
        if ($hash) {
            $job['ps'] = $hash;
            $job['plugin'] = $slug;
            if ($group) {
                $job['group'] = array_values($group);
            }
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
        $cache_modes = !isset($sweep['cache_modes']) || !empty($sweep['cache_modes']);
        $plan = array();
        foreach ($impacted as $slug) {
            $screened = isset($sweep['phase1_pages'][$slug]) ? (array) $sweep['phase1_pages'][$slug] : array();
            foreach ($sweep['page_jobs'] as $page_key => $j) {
                if ('theme' === $slug && 'anon' !== $j['variant']) {
                    continue;
                }
                if (!in_array($page_key, $screened, true)) {
                    $modes = array('normal');
                } elseif (!empty($sweep['oc_capable']) && $cache_modes) {
                    $modes = array('disabled', 'prime');
                } else {
                    continue; // already screened, and no cache modes wanted or available
                }
                $plan[$page_key][] = array(
                    'slug' => $slug,
                    'hash' => $slug_to_hash[$slug],
                    'modes' => $modes,
                    'samples' => 0,
                    'group' => isset($sweep['groups'][$slug]) ? (array) $sweep['groups'][$slug] : array(),
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

    // ---------------- checkout flow (doc .docs/2026-08-07-checkout-flow-profiling.md) ----------------

    /**
     * Everything the flow needs, resolved once at start so a run can be replayed from its
     * own notes. Any switch the admin turned off is recorded, because a partial run must
     * never later be mistaken for a full one.
     */
    private static function checkout_opts($args, $run_id) {
        $mail_mode = !empty($args['mail_mode']) ? $args['mail_mode'] : sspa_get_option('checkout_mail_mode');
        $payment_mode = !empty($args['payment_mode']) ? $args['payment_mode'] : sspa_get_option('checkout_payment_mode');
        return array(
            'run_id' => (int) $run_id,
            // The order-management steps (view + complete) run as the admin who started the
            // run - no temporary admin users (Dave's rule) - so the flow needs their id.
            'user_id' => !empty($args['user_id']) ? (int) $args['user_id'] : get_current_user_id(),
            'product_id' => !empty($args['product_id']) ? (int) $args['product_id'] : (int) sspa_get_option('checkout_product_id'),
            'quantity' => !empty($args['quantity']) ? (int) $args['quantity'] : max(1, (int) sspa_get_option('checkout_quantity')),
            'mail_mode' => in_array($mail_mode, array('deliver', 'construct', 'suppress'), true) ? $mail_mode : 'deliver',
            // Whitelist: an unknown mode falls through to no payment, never to a payment.
            'payment_mode' => ('sandbox' === $payment_mode) ? 'sandbox' : 'no_payment',
            'allow_integrations' => isset($args['allow_integrations'])
                ? (bool) $args['allow_integrations']
                : (bool) sspa_get_option('checkout_allow_integrations'),
            'allow_webhooks' => isset($args['allow_webhooks'])
                ? (bool) $args['allow_webhooks']
                : (bool) sspa_get_option('checkout_allow_webhooks'),
            'plugin_set_hash' => !empty($args['plugin_set_hash']) ? $args['plugin_set_hash'] : '',
        );
    }

    /**
     * One checkout job. The pre-flight is a profiled probe request; the flow is one
     * routine driving every step, because the cart item key, the shipping rate id, the
     * order id and the confirmation URL are all only knowable at run time.
     */
    private static function process_checkout_job($run_id, $job, &$queue) {
        require_once SSPA_PLUGIN_DIR . 'includes/class-sspa-checkout-flow.php';
        require_once SSPA_PLUGIN_DIR . 'includes/class-sspa-checkout-preflight.php';
        $opts = $queue['checkout']['opts'];

        if ('preflight' === $job['checkout']) {
            $crawler = new SSPA_Crawler();
            $flags = array('v' => 'guest', 'ck' => 'pre');
            if (!empty($opts['plugin_set_hash'])) {
                $flags['ps'] = $opts['plugin_set_hash'];
            }
            $sample = $crawler->send_profiled($job['url'], array('method' => 'GET', 'flags' => $flags));
            $queue['checkout']['inventory'] = is_array($sample['json']) ? $sample['json'] : null;
            self::save_checkout_step($run_id, array(
                'page_key' => 'flow-preflight',
                'method' => 'GET',
                'url' => $job['url'],
                'sample' => $sample,
            ), $opts);
            return;
        }

        $result = SSPA_Checkout_Flow::run($opts);
        foreach ($result['steps'] as $step) {
            if (empty($step['sample'])) {
                continue; // a skipped step measured nothing; the reason goes in the notes
            }
            self::save_checkout_step($run_id, $step, $opts);
        }
        $queue['checkout']['result'] = array(
            'outcome' => $result['outcome'],
            'notes' => $result['notes'],
            'skipped' => array_values(array_filter(array_map(function ($step) {
                return $step['skipped'] ? array('step' => $step['page_key'], 'why' => $step['skipped']) : null;
            }, $result['steps']))),
        );
    }

    private static function save_checkout_step($run_id, $step, $opts) {
        SSPA_Profile_Store::save($run_id, array(
            'page_key' => $step['page_key'],
            'url' => $step['url'],
            'method' => $step['method'],
            // The checkout steps are a guest's; the order-management steps are the admin's,
            // and carry their own variant so the panel and the community page-class are right.
            'variant' => isset($step['variant']) ? $step['variant'] : 'guest',
            'samples' => array($step['sample']),
            'blocked_by' => $step['sample']['blocked_by'],
            'plugin_set_hash' => !empty($opts['plugin_set_hash']) ? $opts['plugin_set_hash'] : '',
            'object_cache_mode' => 'normal',
        ));
    }

    /**
     * Checkout completion: findings pass, run notes, done. The flow itself already deleted
     * its orders and verified stock in its own `finally`; this records what happened.
     */
    private static function finish_checkout($run_id) {
        global $wpdb;
        SSPA_Helper_Files::restore_held_dropin();

        $queue = get_option('sspa_queue_' . $run_id);
        $checkout = (is_array($queue) && isset($queue['checkout'])) ? $queue['checkout'] : array();
        delete_option('sspa_queue_' . $run_id);
        self::set_status($run_id, 'analysing');

        $result = isset($checkout['result']) ? $checkout['result'] : array();
        $inventory = isset($checkout['inventory']) ? $checkout['inventory'] : null;
        $flow_notes = isset($result['notes']) ? $result['notes'] : array();

        $engine = new SSPA_Analysis_Engine();
        $findings = $engine->analyse_checkout($run_id, $inventory, $flow_notes);

        $notes = array(
            'type' => 'checkout',
            'outcome' => isset($result['outcome']) ? $result['outcome'] : 'unknown',
            'flow' => $flow_notes,
            'skipped' => isset($result['skipped']) ? $result['skipped'] : array(),
            'findings' => $findings,
            // The safety report, stated rather than assumed: the panel prints these.
            'safety' => array(
                'orders_deleted' => isset($flow_notes['orders_deleted']) ? (int) $flow_notes['orders_deleted'] : 0,
                'orders_left' => isset($flow_notes['orders_left']) ? (int) $flow_notes['orders_left'] : 0,
                'stock_before' => isset($flow_notes['stock_before']) ? $flow_notes['stock_before'] : null,
                'stock_after' => isset($flow_notes['stock_after']) ? $flow_notes['stock_after'] : null,
                'stock_restored_manually' => !empty($flow_notes['stock_restored_manually']),
                'users_deleted' => isset($flow_notes['users_deleted']) ? (int) $flow_notes['users_deleted'] : 0,
                'users_left' => isset($flow_notes['users_left']) ? (int) $flow_notes['users_left'] : 0,
            ),
            'inventory' => is_array($inventory) ? array(
                'emails_deferred' => isset($inventory['emails_deferred']) ? (bool) $inventory['emails_deferred'] : null,
                'webhooks_inline' => isset($inventory['webhooks_inline']) ? (bool) $inventory['webhooks_inline'] : null,
                'webhooks' => isset($inventory['webhooks']) ? $inventory['webhooks'] : array(),
                'order_hooks' => isset($inventory['order_hooks']) ? $inventory['order_hooks'] : array(),
                'checkout_type' => isset($inventory['checkout_type']) ? $inventory['checkout_type'] : null,
                // Where orders live. The order screens the management steps measure are a
                // different piece of software under HPOS, so a management timing shared
                // without it cannot be compared with anybody else's.
                'hpos' => isset($inventory['hpos']) ? (bool) $inventory['hpos'] : null,
            ) : null,
        );

        $wpdb->update(SSPA_Schema::table('runs'), array(
            // A flow that never completed the purchase is a failed run, not a quiet one
            // with missing rows.
            'status' => ('ok' === $notes['outcome']) ? 'done' : 'failed',
            'finished' => gmdate('Y-m-d H:i:s'),
            'notes' => wp_json_encode($notes),
        ), array('id' => $run_id));
        SSPA_Community_Outbox::on_terminal_run($run_id);
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

    /** How many completed measurements status() reports back, newest last. */
    const STATUS_RECENT = 40;

    /**
     * One measurement in plain English: what was requested, with which plugin set, in which
     * cache mode. This is the line the running-analysis feed shows, so it has to read as an
     * action ("home with super-speedy-search disabled") rather than as a job record.
     */
    public static function job_label($job) {
        $page = isset($job['page_key']) ? $job['page_key'] : '?';
        if (empty($job['plugin'])) {
            $label = sprintf(
                /* translators: %s: page key */
                __('%s with every plugin active', 'super-speedy-performance-analysis'),
                $page
            );
        } else {
            $plugin = ('theme' === $job['plugin']) ? get_stylesheet() . ' (theme)' : $job['plugin'];
            // A grouped cell excludes a plugin and everything that cannot run without it, so
            // the feed has to name them all or the measurement looks like it covered one.
            if (!empty($job['group'])) {
                $plugin .= ' + ' . implode(' + ', (array) $job['group']);
            }
            $label = sprintf(
                /* translators: 1: page key, 2: plugin slug */
                __('%1$s with %2$s disabled', 'super-speedy-performance-analysis'),
                $page,
                $plugin
            );
        }
        if (!empty($job['oc_label']) && 'normal' !== $job['oc_label']) {
            $oc_labels = array(
                'disabled' => __('object cache off', 'super-speedy-performance-analysis'),
                'prime' => __('priming cache', 'super-speedy-performance-analysis'),
                'warm' => __('warm cache', 'super-speedy-performance-analysis'),
            );
            if (isset($oc_labels[$job['oc_label']])) {
                $label .= ', ' . $oc_labels[$job['oc_label']];
            }
        }
        return $label;
    }

    public static function run_row($run_id) {
        global $wpdb;
        $table = SSPA_Schema::table('runs');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $run_id), ARRAY_A);
    }

    /**
     * Measurement-time component versions for one run, keyed "type:lowercased-slug".
     *
     * Read from the run's own plugin_set snapshot, never from the site's current plugin
     * headers: a plugin updated since the run must not relabel what was actually measured.
     * Runs recorded before 0.12 stored a bare file list with no versions, so their
     * components are legitimately unknown and stay null rather than being guessed at.
     *
     * @return array<string,string|null>
     */
    public static function component_versions($run_id) {
        global $wpdb;
        static $cache = array();

        $run_id = (int) $run_id;
        if (isset($cache[$run_id])) {
            return $cache[$run_id];
        }
        $plugin_set = $wpdb->get_var($wpdb->prepare(
            'SELECT plugin_set FROM ' . SSPA_Schema::table('runs') . ' WHERE id = %d',
            $run_id
        ));
        $cache[$run_id] = self::decode_component_versions($plugin_set);
        return $cache[$run_id];
    }

    /**
     * The same map from an already-loaded runs.plugin_set value, so callers that selected
     * the run row do not pay for a second query per run.
     *
     * @return array<string,string|null>
     */
    public static function decode_component_versions($plugin_set) {
        $stored = json_decode((string) $plugin_set, true);
        $versions = array();
        if (is_array($stored) && !empty($stored['components'])) {
            foreach ((array) $stored['components'] as $component) {
                if (!is_array($component) || empty($component['slug'])) {
                    continue;
                }
                $type = !empty($component['type']) ? $component['type'] : 'plugin';
                $version = isset($component['version']) ? trim((string) $component['version']) : '';
                $versions[$type . ':' . strtolower($component['slug'])] = ('' !== $version) ? $version : null;
            }
        }
        return $versions;
    }

    /**
     * The version of one component as it was at the time of $run_id, or null when that run
     * did not record one (a mu-plugin, WordPress core, or a pre-0.12 run).
     */
    public static function component_version($run_id, $component, $component_type = 'plugin') {
        $versions = self::component_versions($run_id);
        $key = $component_type . ':' . strtolower((string) $component);
        return isset($versions[$key]) ? $versions[$key] : null;
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

                // A checkout flow is one long job that can exceed BATCH_SECONDS. Already
                // tolerated: the deadline is checked BEFORE starting a job, never during.
                if (!empty($job['checkout'])) {
                    self::process_checkout_job($run_id, $job, $queue);
                    $queue['idx']++;
                    $queue['last_progress'] = time();
                    update_option('sspa_queue_' . $run_id, $queue, false);
                    $run = self::run_row($run_id);
                    if (!$run || 'crawling' !== $run['status']) {
                        return; // cancelled mid-batch
                    }
                    continue;
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
                } elseif ('checkout' === $run['run_type']) {
                    self::finish_checkout($run_id);
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
        $reacted_cells = array();
        $now = gmdate('Y-m-d H:i:s');

        foreach ($deltas as $d) {
            if (!empty($d['unresolved'])) {
                $unresolved++;
                if (!empty($d['reacted'])) {
                    $reacted_cells[] = $d;
                }
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
            // Record the version that was actually measured, not whatever is installed when
            // someone later reads the row - a deep-analysis verdict belongs to one version.
            $is_theme = ('theme' === $d['slug']);
            $component = $is_theme ? get_stylesheet() : $d['slug'];
            $group = (isset($sweep['groups'][$d['slug']]) && is_array($sweep['groups'][$d['slug']]))
                ? $sweep['groups'][$d['slug']]
                : array();
            $wpdb->insert(SSPA_Schema::table('plugin_impacts'), array(
                'blog_id' => get_current_blog_id(),
                'plugin' => $component,
                'plugin_version' => self::component_version($run_id, $component, $is_theme ? 'theme' : 'plugin'),
                // What else came out with it. The delta belongs to the group, not to the one
                // plugin whose name is on the row, and a reader has to be told that.
                'group_members' => $group ? implode(',', $group) : null,
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
                // A sweep scoped to one URL takes its own baselines, so it has no source run
                // to point at - null rather than a nonexistent run 0.
                'baseline_run_id' => !empty($sweep['source_run_id']) ? (int) $sweep['source_run_id'] : null,
                'test_run_id' => $run_id,
                'created' => $now,
            ));
        }

        $reactions = self::record_reactions($run_id, $reacted_cells, $now);

        foreach (array_keys($hashes) as $hash) {
            delete_option('sspa_isolation_' . $hash);
        }
        delete_option('sspa_queue_' . $run_id);

        $wpdb->update(SSPA_Schema::table('runs'), array(
            'status' => 'done',
            'finished' => $now,
            'notes' => wp_json_encode(array(
                'type' => 'deep',
                'reactions' => $reactions,
                'impacts' => $measured,
                'measurements' => count($deltas),
                'cells' => count($deltas) - $unresolved,
                'unresolved' => $unresolved,
                'plugins' => isset($sweep['plugins']) ? (int) $sweep['plugins'] : null,
                'pages' => isset($sweep['pages']) ? (int) $sweep['pages'] : null,
                'phase2_plugins' => isset($sweep['phase2_plugins']) ? (int) $sweep['phase2_plugins'] : 0,
                'modes' => (!empty($sweep['oc_capable']) && (!isset($sweep['cache_modes']) || !empty($sweep['cache_modes'])))
                    ? array('normal', 'disabled', 'prime')
                    : array('normal'),
                'fatal_cells' => array_values($fatal_cells),
            )),
        ), array('id' => $run_id));
        SSPA_Community_Outbox::on_terminal_run($run_id);
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
            // A cell during which some plugin REACTED to the exclusion - tried to
            // (de)activate something, or ran a destructive statement the shim refused - is
            // not a measurement of the excluded plugin. Everything was neutralised, but the
            // page did different work, so the delta is reported as a reaction, never
            // trusted, and finish_sweep turns the pair into a learned group.
            if (self::samples_reacted($p['samples'])) {
                $deltas[] = array(
                    'slug' => $slug,
                    'page_key' => $p['page_key'],
                    'mode' => $p['object_cache_mode'],
                    'unresolved' => true,
                    'reacted' => true,
                    'profile_id' => (int) $p['id'],
                );
                continue;
            }
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
     * Turn reacted cells into the three things a reaction is FOR:
     *
     *  1. a finding on this run, so it is visible and - findings being part of every shared
     *     payload - reaches superspeedy.org, where enough of them can become a community
     *     dependency map;
     *  2. a learned group, so the NEXT sweep excludes the pair together and the reaction can
     *     never recur on this site;
     *  3. the run notes, so History says why some cells have no verdict.
     *
     * @return array[] {excluded, reactor, ops} for the run notes
     */
    private static function record_reactions($run_id, $reacted_cells, $now) {
        global $wpdb;
        if (!$reacted_cells) {
            return array();
        }

        // One pair may react on every page and in every cache mode; report it once.
        $pairs = array(); // "excluded|reactor" => {excluded, reactor, ops: [op => count], sql: sample, page_key}
        foreach ($reacted_cells as $cell) {
            $blob = $wpdb->get_var($wpdb->prepare(
                'SELECT profile_blob FROM ' . SSPA_Schema::table('profiles') . ' WHERE id = %d',
                (int) $cell['profile_id']
            ));
            $capture = $blob ? json_decode((string) @gzuncompress($blob), true) : null;
            if (!is_array($capture) || empty($capture['reactions'])) {
                continue;
            }
            foreach ((array) $capture['reactions'] as $reaction) {
                $reactor = isset($reaction['component']) ? (string) $reaction['component'] : '';
                if ('' === $reactor || $reactor === $cell['slug']) {
                    continue;
                }
                $key = $cell['slug'] . '|' . $reactor;
                if (!isset($pairs[$key])) {
                    $pairs[$key] = array(
                        'excluded' => $cell['slug'],
                        'reactor' => $reactor,
                        'ops' => array(),
                        'sql' => null,
                        'page_key' => $cell['page_key'],
                    );
                }
                $op = isset($reaction['op']) ? (string) $reaction['op'] : 'unknown';
                $pairs[$key]['ops'][$op] = (isset($pairs[$key]['ops'][$op]) ? $pairs[$key]['ops'][$op] : 0) + 1;
                if ('sql' === $op && null === $pairs[$key]['sql'] && !empty($reaction['sql'])) {
                    $pairs[$key]['sql'] = (string) $reaction['sql'];
                }
            }
        }

        $learned = (array) get_option(SSPA_Dependency_Map::LEARNED_OPTION, array());
        $notes = array();
        foreach ($pairs as $pair) {
            // The privacy allowlist for shared findings passes 'shape' and fingerprints an
            // 'sql' key, so the payload carries what KIND of reaction and, for a refused
            // statement, its fingerprint - never the raw SQL.
            $shape = isset($pair['ops']['sql']) ? 'destructive_sql'
                : (isset($pair['ops']['activate']) ? 'activate_dependency' : 'deactivate_self');
            $wpdb->insert(SSPA_Schema::table('findings'), array(
                'run_id' => $run_id,
                'severity' => 'warn',
                'finding_type' => 'isolation_reaction',
                'component' => substr($pair['reactor'], 0, 191),
                'page_key' => $pair['page_key'],
                'evidence' => wp_json_encode(array(
                    'excluded' => $pair['excluded'],
                    'ops' => $pair['ops'],
                    'shape' => $shape,
                    'sql' => $pair['sql'],
                )),
                'recommendation_key' => 'isolation_reaction',
                'confidence' => 'measured',
                'created' => $now,
            ));

            // Learn the pair - except the theme swap, which has no slug to group by.
            if ('theme' !== $pair['excluded']) {
                $existing = isset($learned[$pair['excluded']]) ? (array) $learned[$pair['excluded']] : array();
                if (!in_array($pair['reactor'], $existing, true)) {
                    $existing[] = $pair['reactor'];
                    $learned[$pair['excluded']] = $existing;
                }
            }
            $notes[] = array(
                'excluded' => $pair['excluded'],
                'reactor' => $pair['reactor'],
                'ops' => $pair['ops'],
            );
        }
        if ($notes) {
            update_option(SSPA_Dependency_Map::LEARNED_OPTION, $learned, false);
            // 4. an admin notice, because a sweep can finish while nobody is looking at the
            //    analysis screen and "a plugin tried to switch itself off on your live site"
            //    is not something to leave sitting in a tab he might not open.
            $seen = (array) get_option(self::REACTION_NOTICE_OPTION, array());
            foreach ($notes as $note) {
                $seen[$note['excluded'] . '|' . $note['reactor']] = array(
                    'excluded' => $note['excluded'],
                    'reactor' => $note['reactor'],
                );
            }
            update_option(self::REACTION_NOTICE_OPTION, $seen, false);
        }
        return $notes;
    }

    /** Did any sample of this profile record a reaction to the excluded set? */
    private static function samples_reacted($samples_json) {
        foreach ((array) json_decode((string) $samples_json, true) as $s) {
            if (!empty($s['reactions'])) {
                return true;
            }
        }
        return false;
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
        if ($verified) {
            SSPA_Community_Outbox::on_terminal_run($run_id);
        }
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
        SSPA_Community_Outbox::on_terminal_run($run_id);
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
        $run = self::run_row($run_id);
        SSPA_Helper_Files::restore_held_dropin();
        SSPA_Helper_Files::restore_object_cache();
        self::cleanup_run_state($run_id);
        $wpdb->update(SSPA_Schema::table('runs'), array(
            'status' => 'failed',
            'finished' => gmdate('Y-m-d H:i:s'),
            'notes' => $note,
        ), array('id' => $run_id));
        if ($run && 'checkout' === $run['run_type']) {
            SSPA_Community_Outbox::on_terminal_run($run_id);
        }
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
            $current = self::job_label($job);
            if (!empty($job['plugin'])) {
                $current_plugin = ('theme' === $job['plugin']) ? get_stylesheet() . ' (theme)' : $job['plugin'];
            }
        }

        // The measurements already taken, newest last. A progress bar alone says nothing about
        // what a deep run is actually doing; polling only 'current' would also drop every job
        // that starts and finishes between two polls.
        $recent = array();
        if (is_array($queue) && !empty($queue['jobs'])) {
            $from = max(0, $idx - self::STATUS_RECENT);
            for ($i = $from; $i < $idx; $i++) {
                if (isset($queue['jobs'][$i])) {
                    $recent[] = array('i' => $i, 'label' => self::job_label($queue['jobs'][$i]));
                }
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
            'recent' => $recent,
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

        self::sweep_flow_orders();

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

        // Refresh the community rules feed daily-ish (transient- and backoff-gated inside).
        // Only a successful refresh changes what SSPA_Rules would return, so a failed one
        // must not throw away the merged rules we already have.
        if (false === get_transient(SSPA_Rules_Feed::CACHE_KEY)
            && true === SSPA_Rules_Feed::refresh()) {
            SSPA_Rules::flush();
        }
    }

    /**
     * Crash-safety net for the checkout flow: an order a crashed run left behind is
     * cancelled (restoring stock) and deleted, provided it carries this plugin's own
     * marker. Nothing without that marker is ever touched, whatever the option says.
     *
     * Two nets, because an orphan order in a customer's store is the one outcome this
     * feature must never produce: the ids the run recorded, and a sweep for marked orders
     * older than an hour that no run ever got round to recording.
     */
    private static function sweep_flow_orders() {
        global $wpdb;
        if (!function_exists('wc_get_order')) {
            return;
        }
        require_once SSPA_PLUGIN_DIR . 'includes/class-sspa-checkout-flow.php';
        $cutoff = time() - HOUR_IN_SECONDS;

        $temp = get_option(SSPA_Checkout_Flow::TEMP_OPTION, array());
        $keep = array();
        foreach ((array) $temp as $entry) {
            if (empty($entry['id']) || empty($entry['ts'])) {
                continue;
            }
            if ((int) $entry['ts'] > $cutoff) {
                $keep[] = $entry; // still plausibly in flight
                continue;
            }
            if (isset($entry['type']) && 'user' === $entry['type']) {
                self::delete_marked_user((int) $entry['id']);
            } else {
                self::delete_marked_order((int) $entry['id']);
            }
        }
        if ($keep) {
            update_option(SSPA_Checkout_Flow::TEMP_OPTION, $keep, false);
        } else {
            delete_option(SSPA_Checkout_Flow::TEMP_OPTION);
        }

        // HPOS keeps order meta in its own table; check both rather than assume a layout.
        $ids = array();
        $meta_tables = array($wpdb->postmeta => 'post_id');
        $hpos_meta = $wpdb->prefix . 'wc_orders_meta';
        if ($hpos_meta === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $hpos_meta))) {
            $meta_tables[$hpos_meta] = 'order_id';
        }
        foreach ($meta_tables as $table => $id_col) {
            $ids = array_merge($ids, (array) $wpdb->get_col($wpdb->prepare(
                "SELECT $id_col FROM $table WHERE meta_key = %s LIMIT 50",
                SSPA_Checkout_Flow::TEMP_META
            )));
        }
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $order = wc_get_order($id);
            if (!$order) {
                continue;
            }
            $created = $order->get_date_created();
            if ($created && $created->getTimestamp() > $cutoff) {
                continue;
            }
            self::delete_marked_order($id);
        }

        // Marked customer accounts a crash orphaned (the store auto-creates one per
        // purchase when guest checkout is disabled).
        $stale_users = get_users(array(
            'meta_key' => SSPA_Checkout_Flow::TEMP_META,
            'meta_value' => '1',
            'number' => 50,
            'fields' => 'all',
        ));
        foreach ($stale_users as $user) {
            if (strtotime($user->user_registered . ' UTC') < $cutoff) {
                self::delete_marked_user((int) $user->ID);
            }
        }
    }

    /**
     * A customer account a crashed run left behind - one the store auto-created for the
     * purchase because guest checkout is disabled. Only ever one carrying our marker.
     */
    private static function delete_marked_user($user_id) {
        if (!get_user_meta($user_id, SSPA_Checkout_Flow::TEMP_META, true)) {
            return;
        }
        if (!function_exists('wp_delete_user')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }
        wp_delete_user($user_id);
    }

    private static function delete_marked_order($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || !$order->get_meta(SSPA_Checkout_Flow::TEMP_META)) {
            return;
        }
        if (!$order->has_status(array('cancelled', 'refunded'))) {
            $order->update_status('cancelled'); // restores stock before the row goes
        }
        $order->delete(true);
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
        if ('deep' === $type) {
            // Page-scoped impact, started from the profile panel: one URL, and an explicit
            // answer on whether the cache modes are wanted. Absent, both keep their
            // site-sweep behaviour - every page, cache modes included.
            if (!empty($_POST['url'])) {
                $args['url'] = esc_url_raw(wp_unslash($_POST['url']));
            }
            if (isset($_POST['cache_modes'])) {
                $args['cache_modes'] = !empty($_POST['cache_modes']);
            }
        }
        if (isset($_POST['page_keys'])) {
            $args['page_keys'] = array_map('sanitize_text_field', (array) $_POST['page_keys']);
            if ('baseline' === $args['type']) {
                // A page-filtered run is a spot check, not a full baseline - recording it
                // as baseline made it the "latest analysis" everywhere and hid the full run.
                $args['type'] = 'spot';
            }
        }
        $toggled = get_transient('sspa_plugin_toggled');
        if ('spot' === $args['type'] && is_array($toggled) && !empty($toggled['slug']) && !empty($toggled['action'])) {
            $args['share_context'] = array(
                'plugin_toggle' => array(
                    'slug' => sanitize_key($toggled['slug']),
                    'action' => in_array($toggled['action'], array('activated', 'deactivated'), true) ? $toggled['action'] : 'unknown',
                ),
            );
        }
        $run_id = self::start($args);
        if (is_wp_error($run_id)) {
            wp_send_json_error($run_id->get_error_message());
        }
        if (isset($args['share_context']['plugin_toggle'])) {
            delete_transient('sspa_plugin_toggled');
        }
        wp_send_json_success(self::status($run_id));
    }

    /**
     * Re-render the Tools tab in place: the old Re-check link reloaded the whole page
     * and lost the active tab. The template is self-contained, so re-including it
     * re-runs every detection fresh.
     */
    public static function ajax_tools_recheck() {
        self::ajax_guard();
        ob_start();
        include SSPA_PLUGIN_DIR . 'includes/admin/tabs/tools.php';
        wp_send_json_success(array('html' => ob_get_clean()));
    }

    /**
     * Re-render one or more tabs server-side.
     *
     * Every control on this screen updates in place, so nothing may reload the page: a reload
     * loses the selected tab, the scroll position, any open drill-down, and on a slow admin it
     * simply feels broken. Each tab file is already a self-contained template that fetches what
     * it needs, so re-including it is the whole implementation.
     */
    public static function ajax_render_tab() {
        self::ajax_guard();
        $requested = isset($_POST['tabs']) ? (string) wp_unslash($_POST['tabs']) : '';
        $html = array();
        foreach (array_filter(array_map('sanitize_key', explode(',', $requested))) as $tab) {
            // sanitize_key leaves only [a-z0-9_-], so no path can escape the tabs directory.
            $file = SSPA_PLUGIN_DIR . 'includes/admin/tabs/' . $tab . '.php';
            if (file_exists($file)) {
                ob_start();
                include $file;
                $html[$tab] = ob_get_clean();
            }
        }
        if (!$html) {
            wp_send_json_error(__('No such tab.', 'super-speedy-performance-analysis'));
        }
        wp_send_json_success(array('tabs' => $html, 'active_run' => self::active_run_id()));
    }

    /**
     * Settings tab save. The sanitiser is the crawler's own, so what gets stored is
     * exactly what gets used, and the response echoes the clamped value back so the
     * field can show what was actually saved rather than what was typed.
     */
    public static function ajax_save_settings() {
        self::ajax_guard();
        $timeout = SSPA_Crawler::sanitise_loopback_timeout(
            isset($_POST['loopback_timeout']) ? wp_unslash($_POST['loopback_timeout']) : 0
        );
        sspa_update_option('loopback_timeout', $timeout);
        wp_send_json_success(array('loopback_timeout' => $timeout));
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

    /**
     * Re-render the Plugins tab table under the other attribution mode.
     *
     * A read-only view switch, so it stays a table swap rather than a page load - every other
     * control on this screen updates in place. Caller mode is recomputed from the stored
     * capture blobs, which is why this is a server round trip and not a client-side sort.
     */
    public static function ajax_attribution() {
        self::ajax_guard();
        $mode = SSPA_Attribution::sanitise_mode(
            isset($_POST['mode']) ? sanitize_key(wp_unslash($_POST['mode'])) : ''
        );
        $run_id = SSPA_Plugins_Table::latest_run_id();
        if (!$run_id) {
            wp_send_json_error(__('There is no completed analysis to attribute yet.', 'super-speedy-performance-analysis'));
        }
        wp_send_json_success(array(
            'mode' => $mode,
            'html' => SSPA_Plugins_Table::render($run_id, $mode),
            'describe' => SSPA_Attribution::describe($mode),
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
        // Newest measurement per page and cache mode, matching the Plugins tab: a sweep scoped
        // to one page must not hide every other page it did not re-measure.
        $rows = $wpdb->get_results(SSPA_Plugins_Table::latest_impacts_sql($plugin), ARRAY_A);
        if (!$rows) {
            wp_send_json_error(__('No measured impacts for this plugin yet - run a plugin impact analysis.', 'super-speedy-performance-analysis'));
        }
        $measured_version = null;
        foreach ($rows as $row) {
            if (!empty($row['plugin_version'])) {
                $measured_version = $row['plugin_version'];
                break;
            }
        }
        wp_send_json_success(array(
            'rows' => $rows,
            'measured_at' => $rows ? $rows[0]['created'] : null,
            'measured_version' => $measured_version,
        ));
    }

    public static function ajax_share_optin() {
        self::ajax_guard();
        $optin = !empty($_POST['optin']);
        update_option('sspa_share_optin', $optin ? 1 : 0, false);
        if ($optin) {
            update_option('sspa_share_consent_version', SSPA_Community_Schema::CONSENT_VERSION, false);
            update_option('sspa_share_consented_at', gmdate('Y-m-d H:i:s'), false);
            SSPA_Community_Outbox::nudge();
        } else {
            update_option('sspa_share_disabled_at', gmdate('Y-m-d H:i:s'), false);
        }
        wp_send_json_success(array('optin' => SSPA_Submitter::opted_in()));
    }

    /**
     * Switch one publishing plugin on or off.
     *
     * Deliberately independent of the site-wide sharing setting: a site owner can decide that
     * Scalability Pro may publish its settings and another plugin may not, without turning
     * sharing off altogether. The decision is read at run start, so it takes effect on the next
     * analysis rather than retrospectively - runs already measured keep what they captured, and
     * the Share tab's per-run preview shows exactly what each one holds.
     */
    public static function ajax_publisher_toggle() {
        self::ajax_guard();
        $slug = isset($_POST['slug']) ? sanitize_text_field(wp_unslash($_POST['slug'])) : '';
        $enabled = !empty($_POST['enabled']);
        SSPA_Community_State::set_enabled($slug, $enabled);
        wp_send_json_success(array(
            'slug' => $slug,
            'enabled' => $enabled,
            'publishers' => SSPA_Community_State::publishers(),
        ));
    }

    public static function ajax_payload_preview() {
        self::ajax_guard();
        $outbox_id = isset($_POST['outbox_id']) ? (int) $_POST['outbox_id'] : 0;
        $json = SSPA_Submitter::preview($outbox_id);
        if (is_wp_error($json)) {
            wp_send_json_error($json->get_error_message());
        }
        wp_send_json_success(array(
            'payload' => $json,
            'summary' => SSPA_Submitter::describe_payload($json),
            'bytes' => strlen($json),
            'filename' => 'sspa-shared-data-' . gmdate('Ymd-His') . '.json',
        ));
    }

    /**
     * Deliver one queued submission, driven by the browser.
     *
     * WP-Cron only fires when someone visits, and plenty of hosts disable it outright in favour
     * of a real crontab, so a queue that depends on it can sit for days. The browser is the
     * reliable driver while an admin is on the screen - the same reason runs are already driven
     * this way, with cron as the fallback rather than the mechanism.
     *
     * Duplicate delivery is prevented where it has to be: the compare-and-set claim in
     * begin_attempt(), and the receiver's own submission_uuid idempotency.
     */
    public static function ajax_submission_tick() {
        self::ajax_guard();
        SSPA_Community_Worker::run();
        $due = SSPA_Community_Outbox::due();
        $counts = SSPA_Community_Outbox::counts();
        wp_send_json_success(array(
            'more' => (bool) $due,
            'counts' => $counts,
        ));
    }

    public static function ajax_submit_now() {
        self::ajax_guard();
        $result = SSPA_Submitter::submit();
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        wp_send_json_success();
    }

    /**
     * Share one analysis on its own. This is an explicit per-run consent action, so it works
     * with the site-wide setting off, and it never turns that setting on.
     */
    public static function ajax_share_run() {
        self::ajax_guard();
        $run_id = isset($_POST['run_id']) ? (int) $_POST['run_id'] : 0;
        $result = SSPA_Community_Outbox::share_run($run_id);
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        wp_send_json_success(array(
            'outbox_id' => (int) $result['id'],
            'state' => $result['state'],
            'compressed_bytes' => (int) $result['compressed_bytes'],
        ));
    }

    public static function ajax_community_backfill() {
        self::ajax_guard();
        $result = SSPA_Community_Backfill::queue_batch(!empty($_POST['restart']));
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        wp_send_json_success($result);
    }

    public static function ajax_outbox_action() {
        self::ajax_guard();
        $id = isset($_POST['outbox_id']) ? (int) $_POST['outbox_id'] : 0;
        $operation = isset($_POST['operation']) ? sanitize_key(wp_unslash($_POST['operation'])) : '';
        if (!$id || !in_array($operation, array('retry', 'pause', 'resume'), true)) {
            wp_send_json_error(__('Invalid submission action.', 'super-speedy-performance-analysis'));
        }
        if ('retry' === $operation) {
            $result = SSPA_Community_Outbox::retry_now($id);
        } elseif ('pause' === $operation) {
            $result = SSPA_Community_Outbox::pause($id);
        } else {
            $result = SSPA_Community_Outbox::resume($id);
        }
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        wp_send_json_success();
    }

    public static function ajax_prune_blobs() {
        global $wpdb;
        self::ajax_guard();

        $keep = max(1, (int) sspa_get_option('blob_retention_runs'));
        $runs_table = SSPA_Schema::table('runs');
        $keep_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM $runs_table ORDER BY id DESC LIMIT %d", $keep));
        $keep_ids = array_map('intval', $keep_ids);

        // Preserve every opted-in run as immutable outbox bytes before deleting source blobs.
        // Delivery can then happen later even if the collector is offline during this request.
        if (SSPA_Submitter::opted_in()) {
            $where = $keep_ids ? 'AND id NOT IN (' . implode(',', $keep_ids) . ')' : '';
            $share_ids = $wpdb->get_col(
                "SELECT id FROM $runs_table
                 WHERE (status = 'done' OR (run_type = 'checkout' AND status = 'failed')) $where
                 ORDER BY id ASC"
            );
            foreach ($share_ids as $share_id) {
                $queued = SSPA_Community_Outbox::queue_run((int) $share_id);
                if (is_wp_error($queued)) {
                    wp_send_json_error(sprintf(
                        /* translators: 1: run ID, 2: error message */
                        __('Detailed data was not deleted because run %1$d could not be preserved for sharing: %2$s', 'super-speedy-performance-analysis'),
                        (int) $share_id,
                        $queued->get_error_message()
                    ));
                }
            }
            SSPA_Community_Outbox::nudge();
        }

        $profiles = SSPA_Schema::table('profiles');
        if ($keep_ids) {
            $in = implode(',', $keep_ids);
            $wpdb->query("UPDATE $profiles SET profile_blob = NULL WHERE run_id NOT IN ($in)");
        }
        $bytes = (int) $wpdb->get_var("SELECT COALESCE(SUM(LENGTH(profile_blob)), 0) FROM $profiles");
        wp_send_json_success(array('bytes' => $bytes, 'human' => size_format($bytes)));
    }
}
