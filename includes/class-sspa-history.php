<?php
defined('ABSPATH') || exit;

/**
 * Read-only comparison of completed analysis runs plus small, hash-only declared
 * expectations. The completed runs remain the source of truth and are never mutated.
 */
class SSPA_History {

    const EXPORT_SCHEMA = 'sspa/performance-history-comparison@1';
    const ASSERTIONS_OPTION = 'sspa_history_assertions';

    public static function sanitise_run_context($context) {
        if (!is_array($context)) {
            return array();
        }
        $out = array(
            'baseline_run_id' => !empty($context['baseline_run_id']) ? (int) $context['baseline_run_id'] : 0,
        );
        if (!empty($context['change_set']) && is_array($context['change_set'])) {
            $change_set = $context['change_set'];
            $changes = array();
            // The same record model the change set writes with: a stored change it would
            // reject is dropped here rather than rendered with a missing or impossible version.
            foreach (isset($change_set['changes']) ? (array) $change_set['changes'] : array() as $change) {
                $record = SSPA_Plugin_Change::from_array($change);
                if ($record) {
                    $changes[] = $record->to_array();
                }
            }
            $out['change_set'] = array(
                'id' => self::safe_uuid(isset($change_set['id']) ? $change_set['id'] : ''),
                'first_detected_at' => self::safe_timestamp(isset($change_set['first_detected_at']) ? $change_set['first_detected_at'] : ''),
                'last_detected_at' => self::safe_timestamp(isset($change_set['last_detected_at']) ? $change_set['last_detected_at'] : ''),
                'changes' => $changes,
            );
        }
        return $out;
    }

    /** @return array|WP_Error */
    public static function compare($before_id, $after_id) {
        $before_id = (int) $before_id;
        $after_id = (int) $after_id;
        $before_run = SSPA_Run_Controller::run_row($before_id);
        $after_run = SSPA_Run_Controller::run_row($after_id);
        foreach (array($before_run, $after_run) as $run) {
            if (!$run) {
                return new WP_Error('sspa_history_missing', sprintf(__('No saved analysis exists for one of the selected IDs (%1$d, %2$d).', 'super-speedy-performance-analysis'), $before_id, $after_id));
            }
        }

        $before = self::snapshot($before_run);
        $after = self::snapshot($after_run);
        if (is_wp_error($before)) {
            return $before;
        }
        if (is_wp_error($after)) {
            return $after;
        }

        return SSPA_History_Comparison::compare($before, $after, self::assertions());
    }

    private static function snapshot($run) {
        $usage = SSPA_Report::page_plugin_usage((int) $run['id']);
        if (is_wp_error($usage)) {
            return $usage;
        }

        $usage_pages = array();
        $diagnostics = array('fatals' => 0, 'transport_errors' => 0, 'http_errors' => 0, 'warnings' => 0, 'critical_findings' => 0);
        foreach ((array) $usage['pages'] as $page) {
            $key = SSPA_History_Series::page_identity(array(
                'page_key' => $page['page_key'],
                'method' => 'GET',
                'variant' => isset($page['variant']) ? $page['variant'] : 'anon',
                'object_cache_mode' => 'normal',
            ));
            $usage_pages[$key] = $page;
            if (!empty($page['diagnostics'])) {
                $diagnostics['fatals'] += count((array) $page['diagnostics']['fatals']);
                $diagnostics['transport_errors'] += count((array) $page['diagnostics']['transport_errors']);
                $diagnostics['http_errors'] += (int) $page['diagnostics']['http_errors'];
            }
        }
        $report = SSPA_Report::build((int) $run['id']);
        if (is_wp_error($report)) {
            return $report;
        }
        $diagnostics['warnings'] = isset($report['summary']['findings']['warn']) ? (int) $report['summary']['findings']['warn'] : 0;
        $diagnostics['critical_findings'] = isset($report['summary']['findings']['critical']) ? (int) $report['summary']['findings']['critical'] : 0;

        $pages = array();
        foreach (SSPA_History_Series::profile_rows((int) $run['id']) as $page) {
            $key = SSPA_History_Series::page_identity($page);
            $extra = isset($usage_pages[$key]) ? $usage_pages[$key] : array();
            $pages[$key] = array(
                'key' => $key,
                'page_key' => sanitize_key($page['page_key']),
                'variant' => sanitize_key(isset($page['variant']) ? $page['variant'] : 'anon'),
                'method' => strtoupper(sanitize_key(isset($page['method']) ? $page['method'] : 'GET')),
                'object_cache_mode' => sanitize_key(isset($page['object_cache_mode']) ? $page['object_cache_mode'] : 'normal'),
                'generation_ms' => self::number_or_null($page['page_gen_ms']),
                'ttfb_ms' => self::number_or_null($page['ttfb_ms']),
                'sql_ms' => self::number_or_null($page['sql_ms']),
                'sql_count' => self::number_or_null($page['sql_count']),
                'rows_fetched' => self::number_or_null($page['rows_returned_total']),
                'http_ms' => self::number_or_null($page['http_ms']),
                'php_ms' => self::number_or_null($page['php_ms']),
                'peak_mem_bytes' => self::number_or_null($page['peak_mem_bytes']),
                'duplicate_queries' => self::number_or_null($page['dupe_query_count']),
                'mail_count' => self::number_or_null($page['mail_count']),
                'response_code' => isset($page['response_code']) ? (int) $page['response_code'] : null,
                'blocked_by' => !empty($page['blocked_by']) ? sanitize_text_field($page['blocked_by']) : '',
                'output_stable' => array_key_exists('output_stable', $extra) ? $extra['output_stable'] : null,
                'output_signature' => !empty($extra['output_signature']) && preg_match('/^[a-f0-9]{32}$/', (string) $extra['output_signature'])
                    ? (string) $extra['output_signature'] : null,
                'diagnostics' => !empty($extra['diagnostics']) ? $extra['diagnostics'] : array(),
            );
        }

        return array(
            'identity' => self::run_identity($run),
            'pages' => $pages,
            'diagnostics' => $diagnostics,
        );
    }

    private static function run_identity($run) {
        $versions = SSPA_Run_Controller::decode_component_versions($run['plugin_set']);
        $components = array();
        foreach ($versions as $key => $version) {
            $bits = explode(':', $key, 2);
            $components[] = array(
                'type' => isset($bits[1]) ? sanitize_key($bits[0]) : 'plugin',
                'slug' => sanitize_key(isset($bits[1]) ? $bits[1] : $bits[0]),
                'version' => self::safe_version($version),
            );
        }
        $context = json_decode((string) $run['share_context'], true);
        return array(
            'id' => (int) $run['id'],
            'uuid' => self::safe_uuid($run['run_uuid']),
            'type' => sanitize_key($run['run_type']),
            'status' => sanitize_key($run['status']),
            'measurement_version' => (int) $run['measurement_version'],
            'trigger' => sanitize_key($run['trigger_source']),
            'started' => self::safe_timestamp($run['started']),
            'finished' => self::safe_timestamp($run['finished']),
            'components' => $components,
            'component_state' => SSPA_Community_State::sanitise_stored_records(
                is_array($context) && !empty($context['component_state']) ? $context['component_state'] : array()
            ),
            'history_context' => is_array($context) && !empty($context['history_comparison'])
                ? self::sanitise_run_context($context['history_comparison']) : array(),
        );
    }

    private static function assertions() {
        $state = get_option(self::ASSERTIONS_OPTION, array());
        if (!is_array($state) || empty($state['expectations']) || !is_array($state['expectations'])) {
            return array();
        }
        $out = array();
        foreach ($state['expectations'] as $key => $expected) {
            if (!is_array($expected) || !preg_match('/^[A-Za-z0-9_-]+(?:\|[A-Za-z0-9_-]+){1,3}$/', (string) $key)) {
                continue;
            }
            $page_key = sanitize_key(isset($expected['page_key']) ? $expected['page_key'] : '');
            $variant = sanitize_key(isset($expected['variant']) ? $expected['variant'] : '');
            $method = strtoupper(sanitize_key(isset($expected['method']) ? $expected['method'] : ''));
            $cache_mode = sanitize_key(isset($expected['object_cache_mode']) ? $expected['object_cache_mode'] : '');
            $expected_key = $method && $cache_mode
                ? SSPA_History_Series::page_identity(array(
                    'page_key' => $page_key,
                    'method' => $method,
                    'variant' => $variant,
                    'object_cache_mode' => $cache_mode,
                ))
                : self::legacy_page_key($page_key, $variant);
            if ((string) $key !== $expected_key) {
                continue;
            }
            $signature = isset($expected['output_signature']) ? strtolower((string) $expected['output_signature']) : '';
            $out[$key] = array(
                'page_key' => $page_key,
                'variant' => $variant,
                'method' => $method,
                'object_cache_mode' => $cache_mode,
                'response_code' => isset($expected['response_code']) ? (int) $expected['response_code'] : null,
                'output_signature' => preg_match('/^[a-f0-9]{32}$/', $signature) ? $signature : '',
                'source_run_uuid' => self::safe_uuid(isset($expected['source_run_uuid']) ? $expected['source_run_uuid'] : ''),
            );
        }
        return $out;
    }

    public static function approve_assertion($run_id, $page_identity) {
        $run = SSPA_Run_Controller::run_row((int) $run_id);
        if (!$run || 'done' !== $run['status'] || !in_array($run['run_type'], array('baseline', 'spot'), true)) {
            return new WP_Error('sspa_history_run', __('That completed analysis could not be found.', 'super-speedy-performance-analysis'));
        }
        $snapshot = self::snapshot($run);
        if (is_wp_error($snapshot) || empty($snapshot['pages'][$page_identity]['output_signature'])) {
            return new WP_Error('sspa_history_signature', __('That page has no stable output signature to approve.', 'super-speedy-performance-analysis'));
        }
        $page = $snapshot['pages'][$page_identity];
        $state = get_option(self::ASSERTIONS_OPTION, array('schema' => 1, 'expectations' => array()));
        if (!is_array($state)) {
            $state = array('schema' => 1, 'expectations' => array());
        }
        if (empty($state['expectations']) || !is_array($state['expectations'])) {
            $state['expectations'] = array();
        }
        $state['schema'] = 1;
        $state['expectations'][$page_identity] = array(
            'page_key' => $page['page_key'],
            'variant' => $page['variant'],
            'method' => $page['method'],
            'object_cache_mode' => $page['object_cache_mode'],
            'response_code' => $page['response_code'],
            'output_signature' => $page['output_signature'],
            'source_run_uuid' => $snapshot['identity']['uuid'],
            'approved_at' => gmdate('c'),
        );
        update_option(self::ASSERTIONS_OPTION, $state, false);
        return true;
    }

    public static function clear_assertion($page_identity) {
        $state = get_option(self::ASSERTIONS_OPTION, array());
        if (!is_array($state) || empty($state['expectations'][$page_identity])) {
            return false;
        }
        unset($state['expectations'][$page_identity]);
        update_option(self::ASSERTIONS_OPTION, $state, false);
        return true;
    }

    /** Exact allowlisted local document used by both preview and download. */
    public static function export($comparison) {
        return array(
            'schema' => self::EXPORT_SCHEMA,
            'generated_at' => gmdate('c'),
            'source_id' => wp_generate_uuid4(),
            'comparison' => $comparison,
        );
    }

    public static function render($comparison) {
        return SSPA_History_View::render($comparison);
    }

    public static function ajax_compare() {
        return SSPA_History_Ajax::ajax_compare();
    }

    public static function ajax_setting() {
        return SSPA_History_Ajax::ajax_setting();
    }

    public static function ajax_assertion() {
        return SSPA_History_Ajax::ajax_assertion();
    }

    public static function ajax_export() {
        return SSPA_History_Ajax::ajax_export();
    }

    private static function legacy_page_key($page_key, $variant) {
        return sanitize_key($page_key) . '|' . sanitize_key($variant);
    }

    private static function number_or_null($value) {
        return null === $value || '' === $value ? null : (float) $value;
    }

    private static function safe_version($version) {
        return SSPA_Version::normalise($version);
    }

    private static function safe_uuid($uuid) {
        $uuid = strtolower(trim((string) $uuid));
        return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $uuid)
            ? $uuid : '';
    }

    private static function safe_timestamp($timestamp) {
        $timestamp = trim((string) $timestamp);
        return preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})?$/', $timestamp)
            ? $timestamp : '';
    }
}
