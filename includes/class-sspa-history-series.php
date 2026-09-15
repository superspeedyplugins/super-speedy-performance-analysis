<?php
defined('ABSPATH') || exit;

/**
 * Presents retained measurements from two selected runs without metadata eligibility gates.
 */
class SSPA_History_Series {

    const SCHEMA = 'sspa/history-series@1';
    const SCENARIO_REVISION = 1;

    /** All retained runs are selectable, regardless of age, status or type. */
    public static function recent_runs() {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM %i ORDER BY id DESC',
            SSPA_Schema::table('runs')
        ), ARRAY_A);
    }

    /** Plot retained fields; inventory and environment metadata never determine eligibility. */
    public static function build($after_id = 0, $metric = 'request_wall_ms', $before_id = 0, $mode = 'setup') {
        $metrics = self::metrics();
        $metric = sanitize_key($metric);
        if (!isset($metrics[$metric])) {
            $metric = 'request_wall_ms';
        }
        $runs = (!$after_id || (!$before_id && 'pair' !== $mode)) ? self::recent_runs() : array();
        // Defaults choose adjacent saved runs, including unchanged and incomplete setups.
        if (!$after_id && 'pair' !== $mode && $runs) {
            $after_id = (int) $runs[0]['id'];
        }
        if (!$after_id && !$before_id && $runs) {
            $after_id = (int) $runs[0]['id'];
        }
        if (!$before_id && 'pair' !== $mode) {
            foreach ($runs as $index => $run) {
                if ((int) $run['id'] === (int) $after_id && isset($runs[$index + 1])) {
                    $before_id = (int) $runs[$index + 1]['id'];
                    break;
                }
            }
        }
        $after = $after_id ? SSPA_Run_Controller::run_row((int) $after_id) : null;
        $before = $before_id ? SSPA_Run_Controller::run_row((int) $before_id) : null;
        $current = $after ? array(self::prepared_run($after)) : array();
        $previous = $before ? array(self::prepared_run($before)) : array();
        $anchor = $after ? $after : array('id' => (int) $after_id);
        return self::document($anchor, $previous, $current, $metric, $metrics[$metric], 'pair', array());
    }

    private static function prepared_run($run) {
        $run['_sspa_setup_fingerprint'] = self::setup_fingerprint($run);
        $run['_sspa_profiles'] = self::profile_rows((int) $run['id']);
        return $run;
    }

    private static function document($anchor, $previous_group, $current_group, $metric, $definition, $mode, $warnings) {
        $current = $current_group ? self::period($current_group) : null;
        $previous = $previous_group ? self::period($previous_group) : null;
        $pages = self::pages($previous_group, $current_group, $metric, $definition);

        return array(
            'schema' => self::SCHEMA,
            'selection_mode' => $mode,
            'before_run_id' => $previous_group ? (int) $previous_group[0]['id'] : null,
            'after_run_id' => (int) $anchor['id'],
            'metric' => array_merge(array(
                'key' => $metric,
                'description' => 'retained_request_samples' === $definition['source']
                    ? __('Each point is one retained request. Grey shows previous measurements, blue shows recent measurements and red marks errors.', 'super-speedy-performance-analysis')
                    : __("Each point is one analysis's page median, not an individual request. Grey shows previous measurements, blue shows recent measurements and red marks errors.", 'super-speedy-performance-analysis'),
                'change_label' => __('Change', 'super-speedy-performance-analysis'),
            ), $definition),
            'anchor_run_id' => (int) $anchor['id'],
            'previous' => $previous,
            'current' => $current,
            'setup_changes' => $previous && $current
                ? self::component_changes($previous_group[0], $current_group[0])
                : array(),
            'pages' => $pages,
            'warnings' => array_values(array_unique($warnings)),
            'empty_state' => $previous ? null : __('Select saved runs to compare their available measurements.', 'super-speedy-performance-analysis'),
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

    /** Kept for existing callers; every retained run can be selected. */
    public static function latest_compatible_run_id($page_keys = array()) {
        $runs = self::recent_runs();
        return $runs ? (int) $runs[0]['id'] : 0;
    }

    public static function is_compatible_run_id($run_id, $page_keys = array()) {
        return (bool) SSPA_Run_Controller::run_row((int) $run_id);
    }

    public static function quick_comparison_page_keys() {
        $available = array_map('sanitize_key', wp_list_pluck(SSPA_Catalogue::build(), 'page_key'));
        return array_values(array_intersect(array('home', 'shop', 'baseline'), $available));
    }

    public static function setup_fingerprint($run) {
        $components = SSPA_Run_Controller::decode_component_versions(isset($run['plugin_set']) ? $run['plugin_set'] : '');
        if (!$components) {
            return '';
        }
        ksort($components, SORT_STRING);
        return hash('sha256', wp_json_encode($components));
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
            "SELECT id, page_key, url, method, variant, object_cache_mode, plugin_set_hash, samples,
                    page_gen_ms, ttfb_ms, sql_ms, sql_count, rows_returned_total,
                    http_ms, php_ms, peak_mem_bytes, dupe_query_count, mail_count,
                    response_code, blocked_by
             FROM %i WHERE run_id = %d ORDER BY id ASC",
            SSPA_Schema::table('profiles'),
            $run_id
        ), ARRAY_A);
        return $cache[$run_id];
    }

    public static function page_identity($profile) {
        return sanitize_key($profile['page_key']) . '|'
            . strtoupper(sanitize_key(isset($profile['method']) ? $profile['method'] : 'GET')) . '|'
            . sanitize_key(isset($profile['variant']) ? $profile['variant'] : 'anon') . '|'
            . sanitize_key(isset($profile['object_cache_mode']) ? $profile['object_cache_mode'] : 'normal')
            . (!empty($profile['plugin_set_hash']) ? '|' . sanitize_key($profile['plugin_set_hash']) : '');
    }

    private static function period($runs) {
        $ascending = array_reverse($runs);
        $components = SSPA_Run_Controller::decode_component_versions($ascending[0]['plugin_set']);
        ksort($components, SORT_STRING);
        return array(
            'fingerprint' => $runs[0]['_sspa_setup_fingerprint'],
            'run_ids' => array_map('intval', wp_list_pluck($ascending, 'id')),
            'run_count' => count($runs),
            'status' => $runs[0]['status'],
            'measurement_version' => (int) $runs[0]['measurement_version'],
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
                            'relative_url' => self::relative_url($profile['url']),
                            'configuration' => !empty($profile['plugin_set_hash']) ? $profile['plugin_set_hash'] : '',
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
                            'profile_id' => null,
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
        $profile_id = (int) $profile['id'];
        if ('request_wall_ms' === $metric_key) {
            $samples = json_decode((string) $profile['samples'], true);
            if (!is_array($samples) || !$samples) {
                $period['faults'][] = array(
                    'run_id' => $run_id,
                    'profile_id' => $profile_id,
                    'sample' => null,
                    'response_code' => !empty($profile['response_code']) ? (int) $profile['response_code'] : null,
                    'state' => 'missing',
                );
                return;
            }
            foreach ((array) $samples as $index => $sample) {
                $sample = is_array($sample) ? $sample : array();
                $code = isset($sample['code']) ? (int) $sample['code'] : 0;
                $valid = isset($sample['wall_ms']) && is_numeric($sample['wall_ms']) && is_finite((float) $sample['wall_ms']);
                if ($valid) {
                    $period['points'][] = array(
                        'run_id' => $run_id,
                        'profile_id' => $profile_id,
                        'evidence' => self::sample_evidence($sample),
                        'sample' => (int) $index + 1,
                        'value' => round((float) $sample['wall_ms'], 2),
                        'response_code' => $code,
                        'state' => !empty($sample['blocked_by']) ? 'blocked'
                            : (!empty($sample['error']) ? 'transport_error'
                                : ($code < 200 || $code >= 400 ? 'http_error' : null)),
                    );
                } else {
                    $period['faults'][] = array(
                        'run_id' => $run_id,
                        'profile_id' => $profile_id,
                        'evidence' => self::sample_evidence($sample),
                        'sample' => (int) $index + 1,
                        'response_code' => $code ?: null,
                        'state' => !empty($sample['blocked_by']) ? 'blocked'
                            : (!empty($sample['error']) ? 'transport_error'
                                : ($code < 200 || $code >= 400 ? 'http_error' : 'missing')),
                    );
                }
            }
            return;
        }

        $code = isset($profile['response_code']) ? (int) $profile['response_code'] : 0;
        $column = $metric['column'];
        $valid = isset($profile[$column]) && '' !== $profile[$column] && is_numeric($profile[$column]) && is_finite((float) $profile[$column]);
        if ($valid) {
            $period['points'][] = array(
                'run_id' => $run_id,
                'profile_id' => $profile_id,
                'evidence' => array('source' => 'per_run_median'),
                'sample' => null,
                'value' => round((float) $profile[$column], 2),
                'response_code' => $code,
                'state' => $code < 200 || $code >= 400 ? 'http_error' : null,
            );
        } else {
            $period['faults'][] = array(
                'run_id' => $run_id,
                'profile_id' => $profile_id,
                'evidence' => array('source' => 'per_run_median'),
                'sample' => null,
                'response_code' => $code ?: null,
                'state' => $code < 200 || $code >= 400 ? 'http_error' : 'missing',
            );
        }
    }

    /** Only fields retained on this request; the prunable median capture is separate. */
    private static function sample_evidence($sample) {
        $evidence = array(
            'source' => 'retained_request_sample',
            'php_diagnostics' => self::php_diagnostics(isset($sample['php_diagnostics']) ? $sample['php_diagnostics'] : null),
        );
        foreach (array('error', 'error_message', 'blocked_by', 'blocked_reason', 'blocked_confidence') as $key) {
            if (isset($sample[$key]) && is_scalar($sample[$key])) {
                $evidence[$key] = substr(sanitize_text_field((string) $sample[$key]), 0, 1000);
            }
        }
        if (isset($sample['cached'])) {
            $evidence['cached'] = (bool) $sample['cached'];
        }
        if (isset($sample['gen_ms']) && is_numeric($sample['gen_ms'])) {
            $evidence['gen_ms'] = round((float) $sample['gen_ms'], 2);
        }
        if (isset($sample['reactions']) && is_numeric($sample['reactions'])) {
            $evidence['reactions'] = max(0, (int) $sample['reactions']);
        }
        if (!empty($sample['fatal']) && is_array($sample['fatal'])) {
            $evidence['fatal'] = array();
            foreach (array('component', 'type', 'fingerprint') as $key) {
                if (isset($sample['fatal'][$key]) && is_scalar($sample['fatal'][$key])) {
                    $evidence['fatal'][$key] = substr(sanitize_text_field((string) $sample['fatal'][$key]), 0, 191);
                }
            }
        }
        return $evidence;
    }

    /** Bounded local presentation of the request's own retained diagnostic section. */
    private static function php_diagnostics($saved) {
        $unavailable = array('schema' => 1, 'coverage' => 'unavailable', 'reason' => 'legacy_capture', 'count' => null, 'retained_count' => 0, 'truncated' => false, 'events' => array());
        if (!is_array($saved) || !isset($saved['schema']) || 1 !== (int) $saved['schema']
            || !isset($saved['coverage']) || !in_array($saved['coverage'], array('observer_delivery', 'partial', 'unavailable'), true)) {
            return $unavailable;
        }
        $events = array();
        foreach (array_slice(isset($saved['events']) && is_array($saved['events']) ? $saved['events'] : array(), 0, 20) as $event) {
            if (!is_array($event)) {
                continue;
            }
            $safe = array();
            foreach (array('severity', 'component', 'component_type', 'file', 'message') as $key) {
                if (isset($event[$key]) && is_scalar($event[$key])) {
                    $safe[$key] = substr(sanitize_text_field((string) $event[$key]), 0, 'message' === $key ? 1000 : 191);
                }
            }
            $safe['type'] = isset($event['type']) ? (int) $event['type'] : 0;
            $safe['line'] = isset($event['line']) ? max(0, (int) $event['line']) : 0;
            $safe['message_truncated'] = !empty($event['message_truncated']);
            $events[] = $safe;
        }
        return array(
            'schema' => 1,
            'coverage' => $saved['coverage'],
            'reason' => !empty($saved['reason']) ? sanitize_key($saved['reason']) : null,
            'count' => 'unavailable' !== $saved['coverage'] && isset($saved['count']) ? max(0, (int) $saved['count']) : null,
            'retained_count' => count($events),
            'truncated' => !empty($saved['truncated']) || (isset($saved['events']) && is_array($saved['events']) && count($saved['events']) > count($events)),
            'events' => $events,
        );
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
                'state' => !array_key_exists($key, $before) ? 'added' : (!array_key_exists($key, $after) ? 'removed' : 'version_changed'),
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

    private static function relative_url($url) {
        $parts = wp_parse_url($url);
        if (!$parts || empty($parts['path'])) {
            return '';
        }
        $query = array();
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        foreach (array_keys($query) as $key) {
            if (preg_match('/sspa|nonce|token|password|secret|auth|signature/i', $key)) {
                unset($query[$key]);
            }
        }
        return $parts['path'] . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
    }
}
