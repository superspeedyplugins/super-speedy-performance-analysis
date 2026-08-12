<?php
defined('ABSPATH') || exit;

/**
 * Builds one coherent, explicitly share-safe payload from one local analysis run.
 */
class SSPA_Community_Exporter {

    public static function component_inventory_snapshot() {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $headers = get_plugins();
        $inventory = array();
        foreach ((array) get_option('active_plugins', array()) as $file) {
            $slug = ('.' !== dirname($file)) ? dirname($file) : basename($file, '.php');
            $inventory[] = array(
                'type' => 'plugin',
                'slug' => strtolower($slug),
                'version' => self::safe_version(isset($headers[$file]['Version']) ? $headers[$file]['Version'] : null),
            );
        }
        $theme = wp_get_theme();
        $inventory[] = array(
            'type' => 'theme',
            'slug' => strtolower($theme->get_stylesheet()),
            'version' => self::safe_version($theme->get('Version')),
        );
        usort($inventory, array(__CLASS__, 'sort_inventory'));
        return $inventory;
    }

    /**
     * @param string $consent_scope 'automatic' or 'manual'. A manual share is itself the act of
     *                              consent, so the version in force is the current one rather
     *                              than whatever the site-wide setting last recorded.
     */
    public static function build($run_id, $submission_uuid = null, $payload_created_at = null, $consent_scope = 'automatic') {
        global $wpdb;
        $run = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . SSPA_Schema::table('runs') . ' WHERE id = %d',
            (int) $run_id
        ), ARRAY_A);
        if (!$run) {
            return new WP_Error('sspa_run_not_found', __('The analysis run no longer exists.', 'super-speedy-performance-analysis'));
        }
        if ('done' !== $run['status'] && !('checkout' === $run['run_type'] && 'failed' === $run['status'])) {
            return new WP_Error('sspa_run_not_shareable', __('Only completed runs and valid partial checkout runs can be shared.', 'super-speedy-performance-analysis'));
        }
        if (!wp_is_uuid($run['run_uuid'], 4)) {
            return new WP_Error('sspa_run_uuid_missing', __('The analysis run has no stable UUID.', 'super-speedy-performance-analysis'));
        }

        $submission_uuid = $submission_uuid ?: wp_generate_uuid4();
        $payload_created_at = $payload_created_at ?: SSPA_Community_Schema::canonical_time();
        if (!wp_is_uuid($submission_uuid, 4) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $payload_created_at)) {
            return new WP_Error('sspa_payload_identity_invalid', __('The community payload identity is invalid.', 'super-speedy-performance-analysis'));
        }

        $measurement_version = max(1, (int) $run['measurement_version']);
        $inventory = self::inventory_for_run($run, $submission_uuid);
        $versions = array();
        foreach ($inventory as $component) {
            $versions[$component['type'] . ':' . $component['slug']] = $component['version'];
        }
        $site_snapshot = self::site_snapshot($run, $inventory);
        $evidence = array();
        self::add_evidence($evidence, 'sspa/site-snapshot', $measurement_version, $site_snapshot);

        $profiles = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . SSPA_Schema::table('profiles') . ' WHERE run_id = %d ORDER BY id ASC',
            (int) $run_id
        ), ARRAY_A);
        $page_refs = array();
        $page_refs_by_key = array();
        $captures = array();
        foreach ($profiles as $profile) {
            $page_ref = wp_generate_uuid4();
            $page_refs[(int) $profile['id']] = $page_ref;
            $page_refs_by_key[$profile['page_key']] = $page_ref;
            $capture = self::unpack_capture($profile['profile_blob']);
            $captures[(int) $profile['id']] = $capture;
            self::add_evidence(
                $evidence,
                'sspa/page-profile',
                $measurement_version,
                self::page_profile($profile, $page_ref),
                $page_ref
            );
            if (isset($capture['profile']) && is_array($capture['profile'])) {
                $excimer = self::excimer_profile($capture['profile'], $page_ref, $submission_uuid, $versions);
                if ($excimer) {
                    self::add_evidence($evidence, 'sspa/excimer-profile', $measurement_version, $excimer);
                }
            }
        }

        $components = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . SSPA_Schema::table('component_stats') . ' WHERE run_id = %d ORDER BY id ASC',
            (int) $run_id
        ), ARRAY_A);
        foreach ($components as $row) {
            $component = SSPA_Community_Privacy::component($row['component'], $row['component_type'], $submission_uuid);
            self::add_evidence($evidence, 'sspa/component-observation', $measurement_version, array(
                'page_profile_uuid' => isset($page_refs[(int) $row['profile_id']]) ? $page_refs[(int) $row['profile_id']] : null,
                'component' => $component,
                'component_version' => isset($versions[$component['type'] . ':' . $component['slug']]) ? $versions[$component['type'] . ':' . $component['slug']] : null,
                'attribution_version' => 1,
                'metrics' => self::numeric_fields($row, array(
                    'query_count', 'sql_ms', 'rows_returned', 'slowest_query_ms', 'http_ms',
                    'mail_ms', 'cache_hits', 'cache_misses',
                )),
            ));
        }

        self::add_findings($evidence, $run_id, $measurement_version, $submission_uuid);
        if ('deep' === $run['run_type']) {
            self::add_impacts($evidence, $run, $measurement_version, $submission_uuid, $versions);
        }
        if ('cache_impact' === $run['run_type']) {
            self::add_cache_impact($evidence, $run, $measurement_version, $submission_uuid, $versions);
        }
        if ('checkout' === $run['run_type']) {
            self::add_checkout($evidence, $run, $measurement_version, $page_refs_by_key);
            self::add_order_management($evidence, $run, $measurement_version, $page_refs_by_key);
        }
        if ('adhoc' === $run['run_type'] && $profiles) {
            self::add_evidence($evidence, 'sspa/adhoc-page-profile', $measurement_version, array(
                'page_profile_uuid' => $page_refs[(int) $profiles[0]['id']],
                'page_class' => SSPA_Community_Privacy::page_class($profiles[0]['page_key'], $profiles[0]['variant']),
                'surface' => ('admin' === $profiles[0]['variant']) ? 'admin' : 'frontend',
            ));
        }
        self::add_toggle_context($evidence, $run, $measurement_version, $submission_uuid, $versions);

        usort($evidence, array(__CLASS__, 'sort_evidence'));
        $manifest = self::manifest($evidence);
        $outcome = ('done' === $run['status']) ? 'complete' : 'failed';
        $payload = array(
            'payload_schema' => array(
                'major' => SSPA_Community_Schema::PAYLOAD_SCHEMA_MAJOR,
                'minor' => SSPA_Community_Schema::PAYLOAD_SCHEMA_MINOR,
            ),
            'submission_uuid' => $submission_uuid,
            'install_uuid' => SSPA_Community_Identity::install_uuid(),
            'client' => array(
                'name' => 'super-speedy-performance-analysis',
                'version' => SSPA_VERSION,
                'consent_version' => ('manual' === $consent_scope)
                    ? SSPA_Community_Schema::CONSENT_VERSION
                    : max(0, (int) get_option('sspa_share_consent_version', 0)),
            ),
            'anonymisation_version' => SSPA_Community_Schema::ANONYMISATION_VERSION,
            'payload_created_at' => $payload_created_at,
            'site_snapshot' => $site_snapshot,
            'run' => array(
                'run_uuid' => $run['run_uuid'],
                'run_type' => sanitize_key($run['run_type']),
                'trigger_source' => sanitize_key($run['trigger_source']),
                'measurement_version' => $measurement_version,
                'started_at' => SSPA_Community_Schema::canonical_time($run['started']),
                'finished_at' => SSPA_Community_Schema::canonical_time($run['finished']),
                'outcome' => $outcome,
                'incomplete_reason' => ('complete' === $outcome) ? null : 'run_failed',
            ),
            'component_inventory' => $inventory,
            'evidence_manifest' => $manifest,
            'evidence' => $evidence,
        );

        $valid = SSPA_Community_Privacy::validate($payload);
        return is_wp_error($valid) ? $valid : $payload;
    }

    private static function site_snapshot($run, $inventory) {
        global $wpdb;
        $metrics = array();
        $sector = null;
        if (!empty($run['site_metrics_id'])) {
            $row = $wpdb->get_row($wpdb->prepare(
                'SELECT metrics, sector FROM ' . SSPA_Schema::table('site_metrics') . ' WHERE id = %d',
                (int) $run['site_metrics_id']
            ), ARRAY_A);
            if ($row) {
                $metrics = json_decode((string) $row['metrics'], true);
                $metrics = is_array($metrics) ? $metrics : array();
                $sector = sanitize_key($row['sector']);
            }
        }
        $theme = null;
        foreach ($inventory as $component) {
            if ('theme' === $component['type']) {
                $theme = $component;
                break;
            }
        }
        $cohort = SSPA_Site_Characteristics::cohort_dimensions($metrics);

        // Version 2 is a SUPERSET of version 1, not a replacement. The same array is both the
        // `sspa/site-snapshot` evidence record and the payload's top-level `site_snapshot`,
        // and the top-level field is read by receivers that predate this change: dropping or
        // renaming a v1 key there would break payloads that are otherwise perfectly readable.
        // So every v1 key keeps its name and meaning, `sizes` grows new members alongside the
        // five it already had, and everything new sits under `classification`,
        // `primary_content` and `environment`.
        //
        // `sector` is the retired single-label inference. It stays for continuity - the
        // receiver has years of rows keyed on it - but `classification.primary_purpose` is
        // what new work should group by: it is versioned, carries its signals and admits that
        // a store with a magazine attached is both things.
        return array(
            'wordpress_version' => self::safe_version(isset($metrics['wp']) ? $metrics['wp'] : null),
            'php_version' => self::safe_version(isset($metrics['php']) ? $metrics['php'] : null),
            'database_version' => self::safe_version(isset($metrics['mysql']) ? $metrics['mysql'] : null),
            'object_cache' => isset($metrics['object_cache']) ? (bool) $metrics['object_cache'] : null,
            'sector' => $sector ?: null,
            'theme' => $theme,
            'sizes' => $cohort['sizes'],
            'classification' => $cohort['classification'],
            'primary_content' => $cohort['primary_content'],
            'environment' => $cohort['environment'],
        );
    }

    private static function inventory_for_run($run, $submission_uuid) {
        $stored = json_decode((string) $run['plugin_set'], true);
        $items = (is_array($stored) && isset($stored['components'])) ? $stored['components'] : array();
        if (!$items && is_array($stored) && isset($stored['plugins'])) {
            foreach ((array) $stored['plugins'] as $plugin) {
                if (is_array($plugin)) {
                    $items[] = $plugin;
                } else {
                    $items[] = array(
                        'type' => 'plugin',
                        'slug' => ('.' !== dirname($plugin)) ? dirname($plugin) : basename($plugin, '.php'),
                        'version' => null,
                    );
                }
            }
        }
        $inventory = array();
        foreach ($items as $item) {
            $safe = SSPA_Community_Privacy::component(
                isset($item['slug']) ? $item['slug'] : '',
                isset($item['type']) ? $item['type'] : 'plugin',
                $submission_uuid
            );
            $safe['version'] = self::safe_version(isset($item['version']) ? $item['version'] : null);
            $inventory[$safe['type'] . ':' . $safe['slug']] = $safe;
        }
        $inventory = array_values($inventory);
        usort($inventory, array(__CLASS__, 'sort_inventory'));
        return $inventory;
    }

    private static function page_profile($row, $page_ref) {
        $samples = json_decode((string) $row['samples'], true);
        $sample_summaries = array();
        foreach ((array) $samples as $sample) {
            $sample_summaries[] = array(
                'wall_ms' => isset($sample['wall_ms']) && is_numeric($sample['wall_ms']) ? $sample['wall_ms'] + 0 : null,
                'response_code' => isset($sample['code']) ? (int) $sample['code'] : null,
                'failed' => !empty($sample['error']),
                'cached' => !empty($sample['cached']),
                'generation_ms' => isset($sample['gen_ms']) && is_numeric($sample['gen_ms']) ? $sample['gen_ms'] + 0 : null,
            );
        }
        return array(
            'page_profile_uuid' => $page_ref,
            'page_class' => SSPA_Community_Privacy::page_class($row['page_key'], $row['variant']),
            'classification_version' => 1,
            'method' => in_array($row['method'], array('GET', 'POST'), true) ? $row['method'] : 'OTHER',
            'variant' => in_array($row['variant'], array('anon', 'admin', 'guest'), true) ? $row['variant'] : 'other',
            'object_cache_mode' => sanitize_key($row['object_cache_mode']),
            'metrics' => self::numeric_fields($row, array(
                'ttfb_ms', 'page_gen_ms', 'sql_ms', 'sql_count', 'http_ms', 'http_count',
                'php_ms', 'peak_mem_bytes', 'rows_returned_total', 'dupe_query_count',
                'cache_hits', 'cache_misses', 'mail_count', 'mail_ms', 'response_code',
            )),
            'samples' => $sample_summaries,
            'detailed_capture_available' => !empty($row['profile_blob']),
            'blocked' => !empty($row['blocked_by']),
            'incomplete' => (null === $row['page_gen_ms']),
        );
    }

    private static function excimer_profile($profile, $page_ref, $submission_uuid, $versions) {
        if ('excimer' !== (isset($profile['collector']) ? $profile['collector'] : '')) {
            return null;
        }
        $functions = array();
        foreach ((array) (isset($profile['functions']) ? $profile['functions'] : array()) as $function) {
            $component = SSPA_Community_Privacy::component(
                isset($function['component']) ? $function['component'] : '',
                isset($function['ctype']) ? $function['ctype'] : '',
                $submission_uuid
            );
            $name = SSPA_Community_Privacy::function_name(isset($function['fn']) ? $function['fn'] : '', $component['type']);
            if (null === $name) {
                continue;
            }
            $drivers = array();
            foreach ((array) (isset($function['by']) ? $function['by'] : array()) as $driver => $ms) {
                $safe_driver = SSPA_Community_Privacy::function_name($driver, $component['type']);
                if (null !== $safe_driver && is_numeric($ms)) {
                    $drivers[] = array('function' => $safe_driver, 'inclusive_ms' => $ms + 0);
                }
            }
            $functions[] = array(
                'function' => $name,
                'component' => $component,
                'component_version' => isset($versions[$component['type'] . ':' . $component['slug']]) ? $versions[$component['type'] . ':' . $component['slug']] : null,
                'inclusive_ms' => isset($function['incl_ms']) ? $function['incl_ms'] + 0 : 0,
                'self_ms' => isset($function['self_ms']) ? $function['self_ms'] + 0 : 0,
                'drivers' => $drivers,
            );
        }
        $components = array();
        foreach ((array) (isset($profile['components']) ? $profile['components'] : array()) as $name => $ms) {
            $component = SSPA_Community_Privacy::component($name, self::component_type($name), $submission_uuid);
            $components[] = array('component' => $component, 'sampled_ms' => is_numeric($ms) ? $ms + 0 : 0);
        }
        $phases = array();
        foreach ((array) (isset($profile['phases']) ? $profile['phases'] : array()) as $phase => $info) {
            $phase_functions = array();
            foreach ((array) (isset($info['functions']) ? $info['functions'] : array()) as $function) {
                $component = SSPA_Community_Privacy::component(
                    isset($function['component']) ? $function['component'] : '',
                    self::component_type(isset($function['component']) ? $function['component'] : ''),
                    $submission_uuid
                );
                $name = SSPA_Community_Privacy::function_name(isset($function['fn']) ? $function['fn'] : '', $component['type']);
                if (null !== $name) {
                    $phase_functions[] = array(
                        'function' => $name,
                        'component' => $component,
                        'self_ms' => isset($function['self_ms']) ? $function['self_ms'] + 0 : 0,
                    );
                }
            }
            $phases[] = array(
                'phase' => preg_match('/^[a-z0-9_-]{1,64}$/', (string) $phase) ? $phase : 'other',
                'total_ms' => isset($info['total_ms']) ? $info['total_ms'] + 0 : 0,
                'functions' => $phase_functions,
            );
        }
        return array(
            'page_profile_uuid' => $page_ref,
            'collector' => 'excimer',
            'period_ms' => isset($profile['period_ms']) ? $profile['period_ms'] + 0 : null,
            'sample_count' => isset($profile['samples']) ? (int) $profile['samples'] : 0,
            'sampled_wall_ms' => isset($profile['wall_ms']) ? $profile['wall_ms'] + 0 : 0,
            'functions' => $functions,
            'components' => $components,
            'phases' => $phases,
        );
    }

    private static function add_findings(&$evidence, $run_id, $measurement_version, $submission_uuid) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . SSPA_Schema::table('findings') . ' WHERE run_id = %d ORDER BY id ASC',
            (int) $run_id
        ), ARRAY_A);
        foreach ($rows as $row) {
            $component = empty($row['component']) ? null : SSPA_Community_Privacy::component($row['component'], self::component_type($row['component']), $submission_uuid);
            $raw = json_decode((string) $row['evidence'], true);
            self::add_evidence($evidence, 'sspa/finding', $measurement_version, array(
                'finding_type' => sanitize_key($row['finding_type']),
                'severity' => in_array($row['severity'], array('info', 'warn', 'error'), true) ? $row['severity'] : 'info',
                'component' => $component,
                'page_class' => empty($row['page_key']) ? null : SSPA_Community_Privacy::page_class($row['page_key']),
                'recommendation_key' => sanitize_key($row['recommendation_key']),
                'confidence' => sanitize_key($row['confidence']),
                'evidence' => SSPA_Community_Privacy::finding_evidence(is_array($raw) ? $raw : array()),
            ));
        }
    }

    private static function add_impacts(&$evidence, $run, $measurement_version, $submission_uuid, $versions) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . SSPA_Schema::table('plugin_impacts') . ' WHERE test_run_id = %d ORDER BY id ASC',
            (int) $run['id']
        ), ARRAY_A);
        foreach ($rows as $row) {
            $component = SSPA_Community_Privacy::component($row['plugin'], ('theme' === $row['plugin']) ? 'theme' : 'plugin', $submission_uuid);
            $baseline_uuid = null;
            if (!empty($row['baseline_run_id'])) {
                $baseline_uuid = $wpdb->get_var($wpdb->prepare(
                    'SELECT run_uuid FROM ' . SSPA_Schema::table('runs') . ' WHERE id = %d',
                    (int) $row['baseline_run_id']
                ));
            }
            self::add_evidence($evidence, 'sspa/plugin-impact', $measurement_version, array(
                'component' => $component,
                'component_version' => isset($versions[$component['type'] . ':' . $component['slug']]) ? $versions[$component['type'] . ':' . $component['slug']] : null,
                'page_class' => SSPA_Community_Privacy::page_class($row['page_key']),
                'method' => sanitize_key($row['method']),
                'object_cache_mode' => sanitize_key($row['object_cache_mode']),
                'metrics' => self::numeric_fields($row, array(
                    'delta_ttfb_ms', 'delta_sql_ms', 'delta_http_ms', 'delta_mem_bytes',
                    'delta_queries', 'noise_floor_ms',
                )),
                'confidence' => sanitize_key($row['confidence']),
                'evidence_class' => 'plugin_isolation',
                'baseline_run_uuid' => wp_is_uuid($baseline_uuid, 4) ? $baseline_uuid : null,
                'test_run_uuid' => $run['run_uuid'],
                'isolation_method_version' => 1,
            ));
        }
    }

    private static function add_cache_impact(&$evidence, $run, $measurement_version, $submission_uuid, $versions) {
        $notes = json_decode((string) $run['notes'], true);
        foreach ((array) (isset($notes['components']) ? $notes['components'] : array()) as $name => $row) {
            $component = SSPA_Community_Privacy::component($name, isset($row['type']) ? $row['type'] : self::component_type($name), $submission_uuid);
            self::add_evidence($evidence, 'sspa/cache-impact', $measurement_version, array(
                'component' => $component,
                'component_version' => isset($versions[$component['type'] . ':' . $component['slug']]) ? $versions[$component['type'] . ':' . $component['slug']] : null,
                'cache_mode' => 'persistent-object-cache',
                'verified' => !empty($notes['verified']),
                'queries_on' => isset($row['on']) ? (int) $row['on'] : 0,
                'queries_off' => isset($row['off']) ? (int) $row['off'] : 0,
                'sql_ms_on' => isset($row['sql_ms_on']) ? $row['sql_ms_on'] + 0 : null,
                'sql_ms_off' => isset($row['sql_ms_off']) ? $row['sql_ms_off'] + 0 : null,
                'saved_pct' => isset($row['saved_pct']) ? (int) $row['saved_pct'] : null,
                'method_version' => 1,
            ));
        }
    }

    private static function add_checkout(&$evidence, $run, $measurement_version, $page_refs_by_key) {
        $waterfall = SSPA_Checkout_Flow::waterfall((int) $run['id']);
        if (!is_array($waterfall)) {
            return;
        }
        // Each segment is walked with its own label rather than asking, per step, which array
        // it came from. `in_array($step, $waterfall['secured'], true)` compared whole step
        // arrays: place-order contributes TWO rows that share a page key and differ only in
        // their label and timing, so segment membership rested on those two rows never
        // becoming equal. A future step with the same numbers as a harness row would have
        // been silently relabelled. Walking the groups cannot be wrong in that way.
        $steps = array();
        foreach (array('at_risk' => 'at_risk', 'secured' => 'secured', 'excluded' => 'harness') as $group => $classification) {
            foreach ((array) $waterfall[$group] as $step) {
                $steps[] = array(
                    'page_profile_uuid' => isset($page_refs_by_key[$step['page_key']]) ? $page_refs_by_key[$step['page_key']] : null,
                    'page_class' => SSPA_Community_Privacy::page_class($step['page_key']),
                    'method' => in_array($step['method'], array('GET', 'POST'), true) ? $step['method'] : 'OTHER',
                    'generation_ms' => isset($step['gen_ms']) ? $step['gen_ms'] + 0 : null,
                    'sql_ms' => isset($step['sql_ms']) ? $step['sql_ms'] + 0 : null,
                    'query_count' => isset($step['sql_count']) ? (int) $step['sql_count'] : null,
                    'http_ms' => isset($step['http_ms']) ? $step['http_ms'] + 0 : null,
                    'response_code' => isset($step['code']) ? (int) $step['code'] : null,
                    'blocked' => !empty($step['blocked_by']),
                    'classification' => $classification,
                );
            }
        }
        $notes = json_decode((string) $run['notes'], true);
        self::add_evidence($evidence, 'sspa/checkout-flow', $measurement_version, array(
            'checkout_type' => isset($notes['inventory']['checkout_type']) ? sanitize_key($notes['inventory']['checkout_type']) : null,
            'outcome' => ('done' === $run['status']) ? 'complete' : 'failed',
            'steps' => $steps,
            'at_risk_ms' => isset($waterfall['at_risk_ms']) ? $waterfall['at_risk_ms'] + 0 : 0,
            'secured_ms' => isset($waterfall['secured_ms']) ? $waterfall['secured_ms'] + 0 : 0,
            'total_ms' => isset($waterfall['total_ms']) ? $waterfall['total_ms'] + 0 : 0,
            'slowest_step' => isset($waterfall['slowest_step']) ? SSPA_Community_Privacy::page_class($waterfall['slowest_step']) : null,
            'payment_boundary_known' => isset($waterfall['boundary_known']) ? $waterfall['boundary_known'] : null,
            'mail' => array(
                'count' => isset($waterfall['mail']['count']) ? (int) $waterfall['mail']['count'] : 0,
                'construction_ms' => isset($waterfall['mail']['ms']) ? $waterfall['mail']['ms'] + 0 : 0,
                'untimed' => isset($waterfall['mail']['untimed']) ? (int) $waterfall['mail']['untimed'] : 0,
            ),
            'http' => array(
                'count' => count((array) $waterfall['http']),
                'total_ms' => array_sum(array_map(function ($call) {
                    return isset($call['ms']) && is_numeric($call['ms']) ? (float) $call['ms'] : 0.0;
                }, (array) $waterfall['http'])),
            ),
            'methodology_version' => 1,
        ));
    }

    /**
     * The shop owner's post-sale work: open the order, mark it completed.
     *
     * Its own evidence type, and its own total. Checkout time is what a customer waits
     * through and can abandon during; this is administrative work after the money is taken.
     * Adding the two together would flatter neither and answer no question anybody has.
     *
     * Only emitted when the run's own rows prove the steps were ATTEMPTED. A run from before
     * order management existed has no such rows and gets no record - inventing a "skipped"
     * one would tell the receiver that a store was measured and found to skip management,
     * which is not what happened.
     */
    private static function add_order_management(&$evidence, $run, $measurement_version, $page_refs_by_key) {
        $waterfall = SSPA_Checkout_Flow::waterfall((int) $run['id']);
        if (!is_array($waterfall) || empty($waterfall['management'])) {
            return;
        }

        $steps = array();
        $blocked = false;
        $incomplete = false;
        foreach ((array) $waterfall['management'] as $step) {
            $step_blocked = !empty($step['blocked_by']);
            $blocked = $blocked || $step_blocked;
            if (null === $step['gen_ms']) {
                $incomplete = true;
            }
            $steps[] = array(
                'page_profile_uuid' => isset($page_refs_by_key[$step['page_key']]) ? $page_refs_by_key[$step['page_key']] : null,
                'page_class' => SSPA_Community_Privacy::page_class($step['page_key'], 'admin'),
                'method' => in_array($step['method'], array('GET', 'POST'), true) ? $step['method'] : 'OTHER',
                'generation_ms' => isset($step['gen_ms']) ? $step['gen_ms'] + 0 : null,
                'sql_ms' => isset($step['sql_ms']) ? $step['sql_ms'] + 0 : null,
                'query_count' => isset($step['sql_count']) ? (int) $step['sql_count'] : null,
                'http_ms' => isset($step['http_ms']) ? $step['http_ms'] + 0 : null,
                'response_code' => isset($step['code']) ? (int) $step['code'] : null,
                'blocked' => $step_blocked,
            );
        }

        // The slowest MANAGEMENT step, which is not the waterfall's slowest step: that one
        // ranks across the customer journey too and is usually place-order.
        $slowest = null;
        foreach ((array) $waterfall['management'] as $step) {
            if (null !== $step['gen_ms'] && (!$slowest || $step['gen_ms'] > $slowest['gen_ms'])) {
                $slowest = $step;
            }
        }

        $from = self::order_status($waterfall['complete_from_status']);
        $to = self::order_status($waterfall['complete_to_status']);
        $both_steps = count($steps) >= 2;
        if ('done' !== $run['status']) {
            $outcome = 'failed';
        } elseif ($blocked) {
            $outcome = 'blocked';
        } elseif (!$both_steps || $incomplete || null === $to) {
            // Attempted and measured something, but not the whole sequence - say which of the
            // two it was rather than presenting a half-run as a clean one.
            $outcome = 'partial';
        } else {
            $outcome = 'complete';
        }

        $notes = json_decode((string) $run['notes'], true);
        self::add_evidence($evidence, 'sspa/order-management-flow', $measurement_version, array(
            'outcome' => $outcome,
            'checkout_type' => isset($notes['inventory']['checkout_type']) ? sanitize_key($notes['inventory']['checkout_type']) : null,
            'order_storage' => isset($notes['inventory']['hpos'])
                ? ($notes['inventory']['hpos'] ? 'hpos' : 'posts')
                : null,
            'steps' => $steps,
            'management_ms' => isset($waterfall['management_ms']) ? $waterfall['management_ms'] + 0 : 0,
            'slowest_step' => $slowest ? SSPA_Community_Privacy::page_class($slowest['page_key'], 'admin') : null,
            'from_status' => $from,
            'to_status' => $to,
            'methodology_version' => 1,
        ));
    }

    /**
     * A WooCommerce order status, canonicalised to the standard set. A store can register its
     * own statuses and they get named after the business ("awaiting-pallet-collection"), so
     * anything outside the core list becomes 'other'.
     */
    private static function order_status($status) {
        $status = strtolower(trim((string) $status));
        if ('' === $status) {
            return null;
        }
        if (0 === strpos($status, 'wc-')) {
            $status = substr($status, 3);
        }
        $known = array(
            'pending', 'processing', 'on-hold', 'completed', 'cancelled',
            'refunded', 'failed', 'checkout-draft',
        );
        return in_array($status, $known, true) ? $status : 'other';
    }

    private static function add_toggle_context(&$evidence, $run, $measurement_version, $submission_uuid, $versions) {
        if ('spot' !== $run['run_type'] || empty($run['share_context'])) {
            return;
        }
        $context = json_decode((string) $run['share_context'], true);
        if (!is_array($context) || empty($context['plugin_toggle']['slug'])) {
            return;
        }
        $toggle = $context['plugin_toggle'];
        $component = SSPA_Community_Privacy::component($toggle['slug'], 'plugin', $submission_uuid);
        self::add_evidence($evidence, 'sspa/plugin-toggle-spot', $measurement_version, array(
            'component' => $component,
            'component_version' => isset($versions[$component['type'] . ':' . $component['slug']]) ? $versions[$component['type'] . ':' . $component['slug']] : null,
            'action' => in_array($toggle['action'], array('activated', 'deactivated'), true) ? $toggle['action'] : 'unknown',
            'run_uuid' => $run['run_uuid'],
            'evidence_class' => 'after_toggle_observation',
        ));
    }

    private static function manifest($evidence) {
        $groups = array();
        foreach ($evidence as $item) {
            $key = $item['type'] . '|' . $item['version'] . '|' . $item['measurement_version'];
            if (!isset($groups[$key])) {
                $groups[$key] = array(
                    'type' => $item['type'],
                    'version' => (int) $item['version'],
                    'measurement_version' => (int) $item['measurement_version'],
                    'count' => 0,
                );
            }
            $groups[$key]['count']++;
        }
        $manifest = array_values($groups);
        usort($manifest, function ($a, $b) {
            return array($a['type'], $a['version'], $a['measurement_version']) <=> array($b['type'], $b['version'], $b['measurement_version']);
        });
        return $manifest;
    }

    private static function add_evidence(&$evidence, $type, $measurement_version, $data, $uuid = null) {
        $version = SSPA_Community_Schema::evidence_version($type);
        if (!$version || !SSPA_Community_Schema::valid_evidence_type($type)) {
            return;
        }
        $evidence[] = array(
            'evidence_uuid' => $uuid ?: wp_generate_uuid4(),
            'type' => $type,
            'version' => $version,
            'measurement_version' => (int) $measurement_version,
            'data' => $data,
        );
    }

    private static function unpack_capture($blob) {
        if (empty($blob)) {
            return array();
        }
        $json = @gzuncompress($blob);
        $capture = $json ? json_decode($json, true) : null;
        return is_array($capture) ? $capture : array();
    }

    private static function numeric_fields($row, $keys) {
        $output = array();
        foreach ($keys as $key) {
            $output[$key] = isset($row[$key]) && is_numeric($row[$key]) ? $row[$key] + 0 : null;
        }
        return $output;
    }

    private static function component_type($name) {
        if ('core' === $name || 'wordpress-core' === $name) {
            return 'core';
        }
        if (0 === strpos((string) $name, 'mu:')) {
            return 'mu-plugin';
        }
        return 'plugin';
    }

    private static function safe_version($version) {
        $version = trim((string) $version);
        return ('' !== $version && preg_match('/^[0-9A-Za-z][0-9A-Za-z.+_-]{0,31}$/', $version)) ? $version : null;
    }

    private static function sort_inventory($a, $b) {
        return array($a['type'], $a['slug'], (string) $a['version']) <=> array($b['type'], $b['slug'], (string) $b['version']);
    }

    private static function sort_evidence($a, $b) {
        return array($a['type'], $a['version'], $a['evidence_uuid']) <=> array($b['type'], $b['version'], $b['evidence_uuid']);
    }
}
