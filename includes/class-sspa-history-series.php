<?php
defined('ABSPATH') || exit;

/**
 * Resolves two contiguous measured setups into one renderer-neutral chart document.
 * Storage, compatibility and aggregation stay here; the browser only plots the result.
 */
class SSPA_History_Series {

    const SCHEMA = 'sspa/history-series@1';
    const SCENARIO_REVISION = 1;

    /** The same bounded run window the History tab has always used. */
    public static function recent_runs() {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM %i ORDER BY id DESC LIMIT 50',
            SSPA_Schema::table('runs')
        ), ARRAY_A);
    }

    /**
     * @return array|WP_Error Immutable, renderer-neutral chart document.
     */
    public static function build($after_id = 0, $metric = 'request_wall_ms', $before_id = 0) {
        $metrics = self::metrics();
        $metric = sanitize_key($metric);
        if (!isset($metrics[$metric])) {
            return new WP_Error('sspa_history_metric', __('That History metric is not supported.', 'super-speedy-performance-analysis'));
        }

        $runs = array_values(array_filter(self::recent_runs(), array(__CLASS__, 'is_candidate')));
        if (!$runs) {
            return new WP_Error('sspa_history_no_runs', __('Run an analysis to create the first measured setup.', 'super-speedy-performance-analysis'));
        }

        $warnings = array();
        if (!$after_id) {
            foreach ($runs as $candidate) {
                if ((int) $candidate['measurement_version'] !== (int) SSPA_Community_Schema::MEASUREMENT_VERSION
                    || !self::setup_fingerprint($candidate)) {
                    $warnings[] = sprintf(
                        /* translators: %d: run id */
                        __('Run #%d was skipped because it is not compatible with the current measurement format.', 'super-speedy-performance-analysis'),
                        (int) $candidate['id']
                    );
                    continue;
                }
                $candidate_identity = self::compatibility_identity($candidate);
                if (is_wp_error($candidate_identity)) {
                    $warnings[] = sprintf(
                        /* translators: 1: run id, 2: reason */
                        __('Run #%1$d was skipped: %2$s', 'super-speedy-performance-analysis'),
                        (int) $candidate['id'],
                        $candidate_identity->get_error_message()
                    );
                    continue;
                }
                $after_id = (int) $candidate['id'];
                break;
            }
        } else {
            $after_id = (int) $after_id;
        }
        if (!$after_id) {
            return new WP_Error('sspa_history_no_compatible_run', __('No compatible measured setup is available yet.', 'super-speedy-performance-analysis'));
        }
        $anchor_index = null;
        foreach ($runs as $index => $run) {
            if ((int) $run['id'] === $after_id) {
                $anchor_index = $index;
                break;
            }
        }
        if (null === $anchor_index) {
            return new WP_Error('sspa_history_anchor', __('That completed History run is outside the retained comparison window.', 'super-speedy-performance-analysis'));
        }
        $runs = array_slice($runs, $anchor_index);

        $groups = self::setup_groups($runs);
        if (is_wp_error($groups)) {
            return $groups;
        }
        $anchor = $runs[0];
        $compatibility = self::compatibility_identity($anchor);
        if (is_wp_error($compatibility)) {
            return $compatibility;
        }

        $current_group = self::compatible_group($groups[0], $compatibility, $warnings);
        if (!$current_group) {
            return new WP_Error('sspa_history_current_incompatible', __('The latest analysis has no compatible page evidence to chart.', 'super-speedy-performance-analysis'));
        }

        $preferred_before_id = $before_id ? (int) $before_id : self::bound_before_id($anchor);
        $previous_group = array();
        if ($preferred_before_id) {
            foreach (array_slice($groups, 1) as $group) {
                if (in_array($preferred_before_id, array_map('intval', wp_list_pluck($group, 'id')), true)) {
                    $previous_group = self::compatible_group($group, $compatibility, $warnings);
                    break;
                }
            }
        }
        if (!$previous_group) {
            foreach (array_slice($groups, 1) as $group) {
                $candidate = self::compatible_group($group, $compatibility, $warnings);
                if ($candidate) {
                    $previous_group = $candidate;
                    break;
                }
            }
        }

        $current = self::period($current_group);
        $previous = $previous_group ? self::period($previous_group) : null;
        $pages = self::pages($previous_group, $current_group, $metric, $metrics[$metric]);

        return array(
            'schema' => self::SCHEMA,
            'metric' => array_merge(array(
                'key' => $metric,
                'description' => 'retained_request_samples' === $metrics[$metric]['source']
                    ? __('Each point is one retained request. Lines show the median of those request measurements.', 'super-speedy-performance-analysis')
                    : __("Each point is one analysis's page median. Lines show the median of those per-analysis medians, not a raw request distribution.", 'super-speedy-performance-analysis'),
                'change_label' => __('Change', 'super-speedy-performance-analysis'),
            ), $metrics[$metric]),
            'anchor_run_id' => (int) $anchor['id'],
            'previous' => $previous,
            'current' => $current,
            'setup_changes' => $previous
                ? self::component_changes($previous_group[0], $current_group[0])
                : array(),
            'pages' => $pages,
            'warnings' => array_values(array_unique($warnings)),
            'empty_state' => $previous ? null : __('Run Performance Analysis again after changing plugins or the theme to compare two measured setups.', 'super-speedy-performance-analysis'),
        );
    }

    public static function metrics() {
        return array(
            'request_wall_ms' => array(
                'label' => __('Request wall time', 'super-speedy-performance-analysis'),
                'unit' => 'ms',
                'source' => 'retained_request_samples',
            ),
            'generation_ms' => array(
                'label' => __('Page generation time', 'super-speedy-performance-analysis'),
                'unit' => 'ms',
                'source' => 'per_run_median',
                'column' => 'page_gen_ms',
            ),
            'sql_ms' => array(
                'label' => __('Database time', 'super-speedy-performance-analysis'),
                'unit' => 'ms',
                'source' => 'per_run_median',
                'column' => 'sql_ms',
            ),
            'sql_count' => array(
                'label' => __('Database queries', 'super-speedy-performance-analysis'),
                'unit' => 'count',
                'source' => 'per_run_median',
                'column' => 'sql_count',
            ),
            'http_ms' => array(
                'label' => __('Outbound HTTP time', 'super-speedy-performance-analysis'),
                'unit' => 'ms',
                'source' => 'per_run_median',
                'column' => 'http_ms',
            ),
            'peak_mem_bytes' => array(
                'label' => __('Peak memory', 'super-speedy-performance-analysis'),
                'unit' => 'bytes',
                'source' => 'per_run_median',
                'column' => 'peak_mem_bytes',
            ),
        );
    }

    /** Latest saved run that is structurally usable as a quick-comparison baseline. */
    public static function latest_compatible_run_id($page_keys = array()) {
        return self::compatible_candidate_id(0, $page_keys);
    }

    /** Validate the exact baseline shown to the administrator before a quick comparison. */
    public static function is_compatible_run_id($run_id, $page_keys = array()) {
        $run_id = (int) $run_id;
        return $run_id > 0 && $run_id === self::compatible_candidate_id($run_id, $page_keys);
    }

    /** The bounded customer-facing quick scan, limited to pages this site can measure. */
    public static function quick_comparison_page_keys() {
        $available = array_map('sanitize_key', wp_list_pluck(SSPA_Catalogue::build(), 'page_key'));
        return array_values(array_intersect(array('home', 'shop', 'baseline'), $available));
    }

    private static function compatible_candidate_id($wanted_id, $page_keys) {
        $page_keys = array_values(array_unique(array_map('sanitize_key', (array) $page_keys)));
        foreach (self::recent_runs() as $run) {
            if ($wanted_id && (int) $run['id'] !== (int) $wanted_id) {
                continue;
            }
            if (!self::is_candidate($run)
                || (int) $run['measurement_version'] !== (int) SSPA_Community_Schema::MEASUREMENT_VERSION
                || !self::setup_fingerprint($run)) {
                if ($wanted_id) {
                    return 0;
                }
                continue;
            }
            $identity = self::compatibility_identity($run);
            if (is_wp_error($identity)) {
                if ($wanted_id) {
                    return 0;
                }
                continue;
            }
            $covered = array_values(array_unique(array_map('sanitize_key', wp_list_pluck($identity['profiles'], 'page_key'))));
            if (array_diff($page_keys, $covered)) {
                if ($wanted_id) {
                    return 0;
                }
                continue;
            }
            return (int) $run['id'];
        }
        return 0;
    }

    private static function is_candidate($run) {
        return is_array($run)
            && 'done' === $run['status']
            && in_array($run['run_type'], array('baseline', 'spot'), true);
    }

    /** Groups newest-first candidate rows by adjacent, version-aware setup identity. */
    private static function setup_groups($runs) {
        $groups = array();
        $boundary = true;
        foreach ($runs as $run) {
            $fingerprint = self::setup_fingerprint($run);
            if (!$fingerprint) {
                $boundary = true;
                continue;
            }
            $last = count($groups) - 1;
            if ($boundary || $last < 0 || $groups[$last][0]['_sspa_setup_fingerprint'] !== $fingerprint) {
                $groups[] = array();
                $last++;
            }
            $run['_sspa_setup_fingerprint'] = $fingerprint;
            $groups[$last][] = $run;
            $boundary = false;
        }
        if (!$groups) {
            return new WP_Error('sspa_history_setup_unknown', __('Saved runs do not contain versioned plugin and theme inventories.', 'super-speedy-performance-analysis'));
        }
        return $groups;
    }

    public static function setup_fingerprint($run) {
        if (!is_array($run) || empty($run['plugin_set'])) {
            return '';
        }
        $components = SSPA_Run_Controller::decode_component_versions($run['plugin_set']);
        if (!$components) {
            return '';
        }
        ksort($components, SORT_STRING);
        foreach ($components as $version) {
            if (null === $version || '' === trim((string) $version)) {
                return '';
            }
        }
        return hash('sha256', wp_json_encode($components));
    }

    private static function bound_before_id($run) {
        $context = json_decode((string) $run['share_context'], true);
        return is_array($context) && !empty($context['history_comparison']['baseline_run_id'])
            ? (int) $context['history_comparison']['baseline_run_id'] : 0;
    }

    private static function compatible_group($group, $anchor_identity, &$warnings) {
        $compatible = array();
        foreach ($group as $run) {
            $identity = self::compatibility_identity($run);
            if (is_wp_error($identity)) {
                $warnings[] = sprintf(
                    /* translators: 1: run id, 2: reason */
                    __('Run #%1$d was excluded: %2$s', 'super-speedy-performance-analysis'),
                    (int) $run['id'],
                    $identity->get_error_message()
                );
                continue;
            }
            if ($identity['fingerprint'] !== $anchor_identity['fingerprint']
                || !array_intersect($identity['coverage'], $anchor_identity['coverage'])) {
                $warnings[] = sprintf(
                    /* translators: %d: run id */
                    __('Run #%d was excluded because its measurement environment or page scenarios differ.', 'super-speedy-performance-analysis'),
                    (int) $run['id']
                );
                continue;
            }
            $run['_sspa_profiles'] = $identity['profiles'];
            $compatible[] = $run;
        }
        return $compatible;
    }

    /** Complete non-component identity for one run. */
    private static function compatibility_identity($run) {
        $profiles = self::profile_rows((int) $run['id']);
        if (!$profiles) {
            return new WP_Error('sspa_history_profiles_missing', __('no full-setup page profiles were retained', 'super-speedy-performance-analysis'));
        }
        $environment = self::environment_identity($run);
        if (is_wp_error($environment)) {
            return $environment;
        }
        $coverage = array();
        foreach ($profiles as $profile) {
            $coverage[] = self::page_identity($profile);
        }
        sort($coverage, SORT_STRING);
        $identity = array(
            'scenario_revision' => self::SCENARIO_REVISION,
            'measurement_version' => (int) $run['measurement_version'],
            'environment' => $environment,
        );
        return array(
            'fingerprint' => hash('sha256', wp_json_encode($identity)),
            'identity' => $identity,
            'coverage' => $coverage,
            'profiles' => $profiles,
        );
    }

    /** Purpose-specific, bounded evidence read documented in the SQL review. */
    public static function profile_rows($run_id) {
        global $wpdb;
        static $cache = array();
        $run_id = (int) $run_id;
        if (isset($cache[$run_id])) {
            return $cache[$run_id];
        }
        $cache[$run_id] = $wpdb->get_results($wpdb->prepare(
            "SELECT id, page_key, method, variant, object_cache_mode, samples,
                    page_gen_ms, ttfb_ms, sql_ms, sql_count, rows_returned_total,
                    http_ms, php_ms, peak_mem_bytes, dupe_query_count, mail_count,
                    response_code, blocked_by
             FROM %i WHERE run_id = %d AND plugin_set_hash = '' ORDER BY id ASC",
            SSPA_Schema::table('profiles'),
            $run_id
        ), ARRAY_A);
        return $cache[$run_id];
    }

    private static function environment_identity($run) {
        global $wpdb;
        static $cache = array();
        $metrics_id = isset($run['site_metrics_id']) ? (int) $run['site_metrics_id'] : 0;
        if (!$metrics_id) {
            return new WP_Error('sspa_history_environment_missing', __('its measurement environment was not retained', 'super-speedy-performance-analysis'));
        }
        if (!array_key_exists($metrics_id, $cache)) {
            $cache[$metrics_id] = $wpdb->get_row($wpdb->prepare(
                'SELECT id, metrics, sector, created FROM %i WHERE id = %d LIMIT 1',
                SSPA_Schema::table('site_metrics'),
                $metrics_id
            ), ARRAY_A);
        }
        if (!$cache[$metrics_id]) {
            return new WP_Error('sspa_history_environment_missing', __('its measurement environment was not retained', 'super-speedy-performance-analysis'));
        }
        $metrics = json_decode((string) $cache[$metrics_id]['metrics'], true);
        if (!is_array($metrics)) {
            return new WP_Error('sspa_history_environment_invalid', __('its measurement environment is unreadable', 'super-speedy-performance-analysis'));
        }
        $identity = array();
        foreach (array(
            'wp', 'php', 'mysql', 'db_family', 'object_cache', 'object_cache_category',
            'page_cache', 'hpos', 'checkout_type', 'multisite', 'locale', 'environment_type',
        ) as $key) {
            $identity[$key] = array_key_exists($key, $metrics) ? $metrics[$key] : null;
        }
        return $identity;
    }

    public static function page_identity($profile) {
        return sanitize_key($profile['page_key']) . '|'
            . strtoupper(sanitize_key(isset($profile['method']) ? $profile['method'] : 'GET')) . '|'
            . sanitize_key(isset($profile['variant']) ? $profile['variant'] : 'anon') . '|'
            . sanitize_key(isset($profile['object_cache_mode']) ? $profile['object_cache_mode'] : 'normal');
    }

    /** Compatibility gate shared by direct Before/After comparison. */
    public static function pair_compatibility($before, $after) {
        if ((int) $before['measurement_version'] !== (int) $after['measurement_version']) {
            return new WP_Error('sspa_history_incompatible', __('These analyses use different measurement formats.', 'super-speedy-performance-analysis'));
        }
        $before_environment = self::environment_identity($before);
        $after_environment = self::environment_identity($after);
        if (is_wp_error($before_environment) || is_wp_error($after_environment)) {
            return new WP_Error('sspa_history_incompatible', __('One analysis has no readable measurement environment.', 'super-speedy-performance-analysis'));
        }
        if (wp_json_encode($before_environment) !== wp_json_encode($after_environment)) {
            return new WP_Error('sspa_history_incompatible', __('These analyses were measured in different environments.', 'super-speedy-performance-analysis'));
        }
        $before_keys = array_map(array(__CLASS__, 'page_identity'), self::profile_rows((int) $before['id']));
        $after_keys = array_map(array(__CLASS__, 'page_identity'), self::profile_rows((int) $after['id']));
        if (!array_intersect($before_keys, $after_keys)) {
            return new WP_Error('sspa_history_incompatible', __('These analyses contain no matching page scenarios.', 'super-speedy-performance-analysis'));
        }
        return true;
    }

    private static function period($runs) {
        $ascending = array_reverse($runs);
        $components = SSPA_Run_Controller::decode_component_versions($ascending[0]['plugin_set']);
        ksort($components, SORT_STRING);
        return array(
            'fingerprint' => $runs[0]['_sspa_setup_fingerprint'],
            'run_ids' => array_map('intval', wp_list_pluck($ascending, 'id')),
            'run_count' => count($runs),
            'started' => (string) $ascending[0]['started'],
            'finished' => (string) $runs[0]['finished'],
            'components' => $components,
        );
    }

    private static function pages($previous_runs, $current_runs, $metric_key, $metric) {
        $by_page = array();
        $seen = array();
        foreach (array('previous' => $previous_runs, 'current' => $current_runs) as $period => $runs) {
            foreach (array_reverse($runs) as $run) {
                $profiles = isset($run['_sspa_profiles']) ? $run['_sspa_profiles'] : self::profile_rows((int) $run['id']);
                foreach ($profiles as $profile) {
                    $identity = self::page_identity($profile);
                    $seen[$period][$identity][(int) $run['id']] = true;
                    if (!isset($by_page[$identity])) {
                        $by_page[$identity] = array(
                            'key' => $identity,
                            'page_key' => sanitize_key($profile['page_key']),
                            'method' => strtoupper(sanitize_key($profile['method'])),
                            'variant' => sanitize_key($profile['variant']),
                            'object_cache_mode' => sanitize_key($profile['object_cache_mode']),
                            'label' => self::page_label($profile['page_key']),
                            'previous' => array('points' => array(), 'faults' => array(), 'output_signatures' => array()),
                            'current' => array('points' => array(), 'faults' => array(), 'output_signatures' => array()),
                        );
                    }
                    $signature = self::stable_output_signature($profile['samples']);
                    if ($signature) {
                        $by_page[$identity][$period]['output_signatures'][] = $signature;
                    }
                    self::add_profile($by_page[$identity][$period], $run, $profile, $metric_key, $metric);
                }
            }
        }
        foreach ($by_page as &$page) {
            foreach (array('previous' => $previous_runs, 'current' => $current_runs) as $period => $runs) {
                foreach ($runs as $run) {
                    if (empty($seen[$period][$page['key']][(int) $run['id']])) {
                        $page[$period]['faults'][] = array(
                            'run_id' => (int) $run['id'],
                            'sample' => null,
                            'response_code' => null,
                            'state' => 'missing',
                        );
                    }
                }
                $values = wp_list_pluck($page[$period]['points'], 'value');
                $page[$period]['median'] = SSPA_Profile_Store::median($values);
                $page[$period]['point_count'] = count($values);
                $page[$period]['fault_count'] = count($page[$period]['faults']);
                $page[$period]['output_signatures'] = array_values(array_unique($page[$period]['output_signatures']));
            }
            $page['delta'] = self::delta($page['previous']['median'], $page['current']['median']);
            $page['output_state'] = 1 === count($page['previous']['output_signatures'])
                && 1 === count($page['current']['output_signatures'])
                ? ($page['previous']['output_signatures'][0] === $page['current']['output_signatures'][0] ? 'unchanged' : 'changed')
                : 'unavailable';
        }
        unset($page);
        $pages = array_values($by_page);
        $order = self::catalogue_order();
        usort($pages, function ($a, $b) use ($order) {
            $left = isset($order[$a['page_key']]) ? $order[$a['page_key']] : PHP_INT_MAX;
            $right = isset($order[$b['page_key']]) ? $order[$b['page_key']] : PHP_INT_MAX;
            return $left === $right ? strcmp($a['key'], $b['key']) : ($left <=> $right);
        });
        return $pages;
    }

    private static function add_profile(&$period, $run, $profile, $metric_key, $metric) {
        $run_id = (int) $run['id'];
        if ('request_wall_ms' === $metric_key) {
            $samples = json_decode((string) $profile['samples'], true);
            foreach ((array) $samples as $index => $sample) {
                $code = isset($sample['code']) ? (int) $sample['code'] : 0;
                $valid = empty($profile['blocked_by']) && empty($sample['error'])
                    && $code >= 200 && $code < 400 && isset($sample['wall_ms']) && is_numeric($sample['wall_ms']);
                if ($valid) {
                    $period['points'][] = array(
                        'run_id' => $run_id,
                        'sample' => (int) $index + 1,
                        'value' => round((float) $sample['wall_ms'], 2),
                        'response_code' => $code,
                    );
                } else {
                    $period['faults'][] = array(
                        'run_id' => $run_id,
                        'sample' => (int) $index + 1,
                        'response_code' => $code ?: null,
                        'state' => !empty($profile['blocked_by']) ? 'blocked'
                            : (!empty($sample['error']) ? 'transport_error'
                                : ($code < 200 || $code >= 400 ? 'http_error' : 'missing')),
                    );
                }
            }
            return;
        }

        $code = isset($profile['response_code']) ? (int) $profile['response_code'] : 0;
        $column = $metric['column'];
        $valid = empty($profile['blocked_by']) && $code >= 200 && $code < 400
            && isset($profile[$column]) && '' !== $profile[$column] && is_numeric($profile[$column]);
        if ($valid) {
            $period['points'][] = array(
                'run_id' => $run_id,
                'sample' => null,
                'value' => round((float) $profile[$column], 2),
                'response_code' => $code,
            );
        } else {
            $period['faults'][] = array(
                'run_id' => $run_id,
                'sample' => null,
                'response_code' => $code ?: null,
                'state' => !empty($profile['blocked_by']) ? 'blocked' : ($code < 200 || $code >= 400 ? 'http_error' : 'missing'),
            );
        }
    }

    private static function stable_output_signature($samples_json) {
        $samples = json_decode((string) $samples_json, true);
        $hashes = array();
        foreach ((array) $samples as $sample) {
            if (!is_array($sample) || empty($sample['body_hash']) || !preg_match('/^[a-f0-9]{32}$/', (string) $sample['body_hash'])) {
                continue;
            }
            $hashes[] = (string) $sample['body_hash'];
        }
        $hashes = array_values(array_unique($hashes));
        return 1 === count($hashes) ? $hashes[0] : null;
    }

    private static function delta($before, $after) {
        if (null === $before || null === $after) {
            return array('absolute' => null, 'percent' => null, 'direction' => 'unknown');
        }
        $absolute = (float) $after - (float) $before;
        return array(
            'absolute' => round($absolute, 2),
            'percent' => 0.0 !== (float) $before ? round(($absolute / abs((float) $before)) * 100, 1) : null,
            'direction' => abs($absolute) < 0.01 ? 'unchanged' : ($absolute > 0 ? 'higher' : 'lower'),
        );
    }

    private static function component_changes($before_run, $after_run) {
        $before = SSPA_Run_Controller::decode_component_versions($before_run['plugin_set']);
        $after = SSPA_Run_Controller::decode_component_versions($after_run['plugin_set']);
        $keys = array_values(array_unique(array_merge(array_keys($before), array_keys($after))));
        sort($keys, SORT_STRING);
        $changes = array();
        foreach ($keys as $key) {
            $old = array_key_exists($key, $before) ? $before[$key] : null;
            $new = array_key_exists($key, $after) ? $after[$key] : null;
            if ($old === $new) {
                continue;
            }
            list($type, $slug) = array_pad(explode(':', $key, 2), 2, '');
            $changes[] = array(
                'type' => sanitize_key($type),
                'slug' => sanitize_key($slug),
                'before_version' => $old,
                'after_version' => $new,
                'state' => null === $old ? 'added' : (null === $new ? 'removed' : 'version_changed'),
            );
        }
        return $changes;
    }

    private static function catalogue_order() {
        $order = array();
        foreach (SSPA_Catalogue::build() as $index => $job) {
            if (!empty($job['page_key']) && !isset($order[$job['page_key']])) {
                $order[$job['page_key']] = $index;
            }
        }
        return $order;
    }

    private static function page_label($page_key) {
        return ucwords(str_replace(array('-', '_'), ' ', sanitize_key($page_key)));
    }
}
