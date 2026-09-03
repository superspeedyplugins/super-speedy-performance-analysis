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
            foreach (isset($change_set['changes']) ? (array) $change_set['changes'] : array() as $change) {
                if (!is_array($change) || empty($change['slug'])) {
                    continue;
                }
                $changes[] = array(
                    'slug' => sanitize_key($change['slug']),
                    'action' => sanitize_key(isset($change['action']) ? $change['action'] : ''),
                    'from_version' => self::safe_version(isset($change['from_version']) ? $change['from_version'] : ''),
                    'to_version' => self::safe_version(isset($change['to_version']) ? $change['to_version'] : ''),
                );
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
        if (!$before_id || !$after_id || $before_id === $after_id) {
            return new WP_Error('sspa_history_pair', __('Choose two different completed analyses.', 'super-speedy-performance-analysis'));
        }

        $before_run = SSPA_Run_Controller::run_row($before_id);
        $after_run = SSPA_Run_Controller::run_row($after_id);
        foreach (array($before_run, $after_run) as $run) {
            if (!$run || 'done' !== $run['status'] || !in_array($run['run_type'], array('baseline', 'spot'), true)) {
                return new WP_Error('sspa_history_incompatible', __('Only completed full scans and spot checks can be compared.', 'super-speedy-performance-analysis'));
            }
        }
        $compatibility = SSPA_History_Series::pair_compatibility($before_run, $after_run);
        if (is_wp_error($compatibility)) {
            return $compatibility;
        }

        $before = self::snapshot($before_run);
        $after = self::snapshot($after_run);
        if (is_wp_error($before)) {
            return $before;
        }
        if (is_wp_error($after)) {
            return $after;
        }

        $assertions = self::assertions();
        $pages = array();
        $all_keys = array_values(array_unique(array_merge(array_keys($before['pages']), array_keys($after['pages']))));
        sort($all_keys);
        foreach ($all_keys as $key) {
            $left = isset($before['pages'][$key]) ? $before['pages'][$key] : null;
            $right = isset($after['pages'][$key]) ? $after['pages'][$key] : null;
            $legacy_key = self::legacy_page_key(
                $right ? $right['page_key'] : $left['page_key'],
                $right ? $right['variant'] : $left['variant']
            );
            $expected = isset($assertions[$key]) ? $assertions[$key]
                : (isset($assertions[$legacy_key]) ? $assertions[$legacy_key] : null);
            $pages[] = self::compare_page($key, $left, $right, $expected);
        }

        if (!$pages) {
            return new WP_Error('sspa_history_no_pages', __('These analyses contain no comparable page evidence.', 'super-speedy-performance-analysis'));
        }

        $before_headline = self::headline_value($before['pages']);
        $after_headline = self::headline_value($after['pages']);
        $new_diagnostics = array(
            'fatals' => max(0, $after['diagnostics']['fatals'] - $before['diagnostics']['fatals']),
            'transport_errors' => max(0, $after['diagnostics']['transport_errors'] - $before['diagnostics']['transport_errors']),
            'http_errors' => max(0, $after['diagnostics']['http_errors'] - $before['diagnostics']['http_errors']),
            'warnings' => max(0, $after['diagnostics']['warnings'] - $before['diagnostics']['warnings']),
            'critical_findings' => max(0, $after['diagnostics']['critical_findings'] - $before['diagnostics']['critical_findings']),
        );

        $changed = 0;
        $failed_validity = 0;
        $failed_declared = 0;
        foreach ($pages as $page) {
            if ('changed' === $page['output']['state']) {
                $changed++;
            }
            if ('fail' === $page['validity']['after']) {
                $failed_validity++;
            }
            if ('fail' === $page['declared']['state']) {
                $failed_declared++;
            }
        }
        $new_faults = array_sum($new_diagnostics) + $failed_validity;
        $status = $new_faults || $failed_declared ? 'attention' : ($changed ? 'review' : 'observed');

        $setup_changes = self::component_changes($before['identity']['components'], $after['identity']['components']);
        return array(
            'schema' => 1,
            'status' => $status,
            'before' => $before['identity'],
            'after' => $after['identity'],
            'setup_changes_available' => is_array($setup_changes),
            'setup_changes' => is_array($setup_changes) ? $setup_changes : array(),
            'configuration_changes' => self::component_state_changes($before['identity']['component_state'], $after['identity']['component_state']),
            'headline' => self::delta($before_headline, $after_headline, 'ms'),
            'new_diagnostics' => $new_diagnostics,
            'summary' => array(
                'pages' => count($pages),
                'output_changes' => $changed,
                'failed_validity_cases' => $failed_validity,
                'failed_declared_cases' => $failed_declared,
            ),
            'pages' => $pages,
        );
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
            if ('baseline' === $page['page_key']) {
                continue;
            }
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

    private static function compare_page($key, $before, $after, $expected) {
        $metrics = array();
        foreach (array(
            'generation_ms' => 'ms', 'ttfb_ms' => 'ms', 'sql_ms' => 'ms',
            'sql_count' => 'count', 'rows_fetched' => 'count', 'http_ms' => 'ms',
            'php_ms' => 'ms', 'peak_mem_bytes' => 'bytes', 'duplicate_queries' => 'count',
            'mail_count' => 'count',
        ) as $metric => $unit) {
            $metrics[$metric] = self::delta(
                $before ? $before[$metric] : null,
                $after ? $after[$metric] : null,
                $unit
            );
        }

        $before_validity = self::validity($before);
        $after_validity = self::validity($after);
        $output = array('state' => 'unavailable', 'before_signature' => null, 'after_signature' => null);
        if ($before && $after && !empty($before['output_signature']) && !empty($after['output_signature'])) {
            $output = array(
                'state' => hash_equals($before['output_signature'], $after['output_signature']) ? 'unchanged' : 'changed',
                'before_signature' => $before['output_signature'],
                'after_signature' => $after['output_signature'],
            );
        }

        $declared = array('state' => 'not_declared', 'expected_signature' => null, 'source_run_uuid' => null);
        if (is_array($expected)) {
            $declared['expected_signature'] = isset($expected['output_signature']) ? $expected['output_signature'] : null;
            $declared['source_run_uuid'] = isset($expected['source_run_uuid']) ? $expected['source_run_uuid'] : null;
            if (!$after || empty($after['output_signature']) || empty($expected['output_signature'])) {
                $declared['state'] = 'unknown';
            } else {
                $declared['state'] = hash_equals((string) $expected['output_signature'], (string) $after['output_signature']) ? 'pass' : 'fail';
            }
        }

        $page = $after ? $after : $before;
        return array(
            'key' => $key,
            'page_key' => $page ? $page['page_key'] : '',
            'variant' => $page ? $page['variant'] : '',
            'method' => $page ? $page['method'] : '',
            'object_cache_mode' => $page ? $page['object_cache_mode'] : '',
            'present' => array('before' => (bool) $before, 'after' => (bool) $after),
            'validity' => array('before' => $before_validity, 'after' => $after_validity),
            'response_code' => array(
                'before' => $before ? $before['response_code'] : null,
                'after' => $after ? $after['response_code'] : null,
            ),
            'metrics' => $metrics,
            'output' => $output,
            'declared' => $declared,
        );
    }

    private static function validity($page) {
        if (!$page) {
            return 'unknown';
        }
        if (!empty($page['blocked_by']) || null === $page['response_code']
            || $page['response_code'] < 200 || $page['response_code'] >= 400
            || null === $page['generation_ms']) {
            return 'fail';
        }
        return 'pass';
    }

    private static function headline_value($pages) {
        $values = array();
        foreach ((array) $pages as $page) {
            if (null !== $page['ttfb_ms']) {
                $values[] = $page['ttfb_ms'];
            }
        }
        return SSPA_Profile_Store::median($values);
    }

    private static function delta($before, $after, $unit) {
        $before = self::number_or_null($before);
        $after = self::number_or_null($after);
        if (null === $before || null === $after) {
            return array('before' => $before, 'after' => $after, 'delta' => null, 'percent' => null, 'direction' => 'unknown', 'unit' => $unit);
        }
        $delta = $after - $before;
        $percent = 0.0 !== (float) $before ? ($delta / abs($before)) * 100 : null;
        return array(
            'before' => round($before, 2),
            'after' => round($after, 2),
            'delta' => round($delta, 2),
            'percent' => null === $percent ? null : round($percent, 1),
            'direction' => abs($delta) < 0.01 ? 'unchanged' : ($delta > 0 ? 'higher' : 'lower'),
            'unit' => $unit,
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

    private static function component_state_changes($before, $after) {
        $left = array();
        $right = array();
        foreach ((array) $before as $record) {
            if (!empty($record['component']['slug'])) {
                $left[$record['component']['type'] . ':' . $record['component']['slug']] = $record;
            }
        }
        foreach ((array) $after as $record) {
            if (!empty($record['component']['slug'])) {
                $right[$record['component']['type'] . ':' . $record['component']['slug']] = $record;
            }
        }
        $keys = array_values(array_unique(array_merge(array_keys($left), array_keys($right))));
        sort($keys);
        $changes = array();
        foreach ($keys as $key) {
            $old = isset($left[$key]) ? $left[$key] : null;
            $new = isset($right[$key]) ? $right[$key] : null;
            if ($old && $new && wp_json_encode($old) === wp_json_encode($new)) {
                continue;
            }
            $component = $new ? $new['component'] : $old['component'];
            $changes[] = array(
                'type' => $component['type'],
                'slug' => $component['slug'],
                'state' => !$old ? 'added' : (!$new ? 'removed' : 'changed'),
            );
        }
        return $changes;
    }

    private static function component_changes($before, $after) {
        if (!$before || !$after) {
            return null;
        }
        $left = array();
        $right = array();
        foreach ((array) $before as $component) {
            $left[$component['type'] . ':' . $component['slug']] = $component;
        }
        foreach ((array) $after as $component) {
            $right[$component['type'] . ':' . $component['slug']] = $component;
        }
        $keys = array_values(array_unique(array_merge(array_keys($left), array_keys($right))));
        sort($keys);
        $changes = array();
        foreach ($keys as $key) {
            $old = isset($left[$key]) ? $left[$key] : null;
            $new = isset($right[$key]) ? $right[$key] : null;
            if ($old && $new && $old['version'] === $new['version']) {
                continue;
            }
            $component = $new ? $new : $old;
            $changes[] = array(
                'type' => $component['type'],
                'slug' => $component['slug'],
                'before_version' => $old ? $old['version'] : '',
                'after_version' => $new ? $new['version'] : '',
                'state' => !$old ? 'added' : (!$new ? 'removed' : 'version_changed'),
            );
        }
        return $changes;
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
        if (is_wp_error($comparison)) {
            return '<div class="notice notice-error inline"><p>' . esc_html($comparison->get_error_message()) . '</p></div>';
        }
        $headline = $comparison['headline'];
        $failed_validity = (int) $comparison['summary']['failed_validity_cases'];
        $failed_declared = (int) $comparison['summary']['failed_declared_cases'];
        $attention = array();
        foreach (array(
            'fatals' => array(__('new fatal error', 'super-speedy-performance-analysis'), __('new fatal errors', 'super-speedy-performance-analysis')),
            'transport_errors' => array(__('new transport error', 'super-speedy-performance-analysis'), __('new transport errors', 'super-speedy-performance-analysis')),
            'http_errors' => array(__('new HTTP error', 'super-speedy-performance-analysis'), __('new HTTP errors', 'super-speedy-performance-analysis')),
            'warnings' => array(__('new warning', 'super-speedy-performance-analysis'), __('new warnings', 'super-speedy-performance-analysis')),
            'critical_findings' => array(__('new critical finding', 'super-speedy-performance-analysis'), __('new critical findings', 'super-speedy-performance-analysis')),
        ) as $diagnostic => $labels) {
            if (!empty($comparison['new_diagnostics'][$diagnostic])) {
                $count = (int) $comparison['new_diagnostics'][$diagnostic];
                $attention[] = $count . ' ' . (1 === $count ? $labels[0] : $labels[1]);
            }
        }
        if ($failed_validity) {
            $attention[] = $failed_validity . ' ' . (1 === $failed_validity
                ? __('failed validity check', 'super-speedy-performance-analysis')
                : __('failed validity checks', 'super-speedy-performance-analysis'));
        }
        if ($failed_declared) {
            $attention[] = $failed_declared . ' ' . (1 === $failed_declared
                ? __('failed declared expectation', 'super-speedy-performance-analysis')
                : __('failed declared expectations', 'super-speedy-performance-analysis'));
        }
        ob_start();
        ?>
        <section class="sspa-history-comparison" data-before-run="<?php echo (int) $comparison['before']['id']; ?>" data-after-run="<?php echo (int) $comparison['after']['id']; ?>">
            <div class="sspa-history-summary sspa-history-status-<?php echo esc_attr($comparison['status']); ?>">
                <div>
                    <span class="sspa-history-kicker"><?php esc_html_e('Response time', 'super-speedy-performance-analysis'); ?></span>
                    <strong><?php echo null !== $headline['before'] ? esc_html(number_format($headline['before'], 1)) . ' ms' : '-'; ?></strong>
                    <span aria-hidden="true">&rarr;</span>
                    <strong><?php echo null !== $headline['after'] ? esc_html(number_format($headline['after'], 1)) . ' ms' : '-'; ?></strong>
                    <?php if (null !== $headline['delta']) : ?>
                        <span class="sspa-history-delta sspa-history-<?php echo esc_attr($headline['direction']); ?>">
                            <?php echo esc_html(sprintf('%+.1f ms', $headline['delta'])); ?>
                            <?php echo null !== $headline['percent'] ? esc_html(sprintf('(%+.1f%%)', $headline['percent'])) : ''; ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div>
                    <?php if ($attention) : ?>
                        <strong><?php esc_html_e('Needs attention:', 'super-speedy-performance-analysis'); ?></strong>
                        <span><?php echo esc_html(implode(' · ', $attention)); ?></span>
                    <?php elseif ($comparison['summary']['output_changes']) : ?>
                        <strong><?php /* translators: %d: number of changed page outputs */ printf(esc_html(_n('%d output changed for review', '%d outputs changed for review', $comparison['summary']['output_changes'], 'super-speedy-performance-analysis')), $comparison['summary']['output_changes']); ?></strong>
                    <?php else : ?>
                        <strong><?php esc_html_e('No new fault or output change found', 'super-speedy-performance-analysis'); ?></strong>
                    <?php endif; ?>
                </div>
            </div>

            <p class="description">
                <?php /* translators: 1: before run id, 2: after run id */ printf(esc_html__('Comparing point in time #%1$d with #%2$d. Missing evidence remains unknown rather than passing.', 'super-speedy-performance-analysis'), (int) $comparison['before']['id'], (int) $comparison['after']['id']); ?>
            </p>

            <?php if (empty($comparison['setup_changes_available'])) : ?>
                <p class="description"><?php esc_html_e('Setup-change evidence is unavailable for one of these older runs.', 'super-speedy-performance-analysis'); ?></p>
            <?php elseif (!empty($comparison['setup_changes'])) : ?>
                <details class="sspa-history-setup-changes">
                    <summary><?php /* translators: %d: number of component changes */ printf(esc_html(_n('%d setup change', '%d setup changes', count($comparison['setup_changes']), 'super-speedy-performance-analysis')), count($comparison['setup_changes'])); ?></summary>
                    <ul>
                    <?php foreach ($comparison['setup_changes'] as $change) : ?>
                        <li>
                            <code><?php echo esc_html($change['slug']); ?></code>
                            <span class="description">
                                <?php if ('added' === $change['state']) : ?>
                                    <?php echo esc_html(sprintf(__('added at %s', 'super-speedy-performance-analysis'), $change['after_version'] ?: __('unknown version', 'super-speedy-performance-analysis'))); ?>
                                <?php elseif ('removed' === $change['state']) : ?>
                                    <?php echo esc_html(sprintf(__('removed (was %s)', 'super-speedy-performance-analysis'), $change['before_version'] ?: __('unknown version', 'super-speedy-performance-analysis'))); ?>
                                <?php else : ?>
                                    <?php echo esc_html(($change['before_version'] ?: __('unknown', 'super-speedy-performance-analysis')) . ' → ' . ($change['after_version'] ?: __('unknown', 'super-speedy-performance-analysis'))); ?>
                                <?php endif; ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>

            <?php if (!empty($comparison['configuration_changes'])) : ?>
                <details class="sspa-history-setup-changes">
                    <summary><?php /* translators: %d: number of components whose published configuration state changed */ printf(esc_html(_n('%d configuration change', '%d configuration changes', count($comparison['configuration_changes']), 'super-speedy-performance-analysis')), count($comparison['configuration_changes'])); ?></summary>
                    <ul>
                    <?php foreach ($comparison['configuration_changes'] as $change) : ?>
                        <li><code><?php echo esc_html($change['slug']); ?></code> <span class="description"><?php echo esc_html($change['state']); ?></span></li>
                    <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>

            <table class="widefat striped sspa-history-compare-table">
                <thead><tr>
                    <th><?php esc_html_e('Page / variant', 'super-speedy-performance-analysis'); ?></th>
                    <th><?php esc_html_e('Validity', 'super-speedy-performance-analysis'); ?></th>
                    <th><?php esc_html_e('Generation time', 'super-speedy-performance-analysis'); ?></th>
                    <th><?php esc_html_e('Output', 'super-speedy-performance-analysis'); ?></th>
                    <th><?php esc_html_e('Declared expectation', 'super-speedy-performance-analysis'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($comparison['pages'] as $page) :
                    $gen = $page['metrics']['generation_ms']; ?>
                    <tr>
                        <td><code><?php echo esc_html($page['page_key']); ?></code><br><span class="description"><?php echo esc_html($page['variant']); ?></span></td>
                        <td>
                            <span class="sspa-history-validity-<?php echo esc_attr($page['validity']['before']); ?>"><?php echo esc_html($page['validity']['before']); ?></span>
                            &rarr;
                            <span class="sspa-history-validity-<?php echo esc_attr($page['validity']['after']); ?>"><?php echo esc_html($page['validity']['after']); ?></span>
                        </td>
                        <td>
                            <?php echo null !== $gen['before'] ? esc_html(number_format($gen['before'], 1)) : '-'; ?> &rarr;
                            <?php echo null !== $gen['after'] ? esc_html(number_format($gen['after'], 1)) : '-'; ?> ms
                            <?php if (null !== $gen['delta']) : ?><br><strong><?php echo esc_html(sprintf('%+.1f ms', $gen['delta'])); ?></strong><?php endif; ?>
                        </td>
                        <td><span class="sspa-history-output-<?php echo esc_attr($page['output']['state']); ?>"><?php echo esc_html($page['output']['state']); ?></span></td>
                        <td>
                            <span class="sspa-history-declared-<?php echo esc_attr($page['declared']['state']); ?>"><?php echo esc_html($page['declared']['state']); ?></span>
                            <?php if (!empty($page['output']['after_signature'])) : ?>
                                <button type="button" class="button button-small sspa-history-assert" data-mode="approve" data-page-identity="<?php echo esc_attr($page['key']); ?>"><?php esc_html_e('Use After as expected', 'super-speedy-performance-analysis'); ?></button>
                            <?php endif; ?>
                            <?php if ('not_declared' !== $page['declared']['state']) : ?>
                                <button type="button" class="button-link-delete sspa-history-assert" data-mode="clear" data-page-identity="<?php echo esc_attr($page['key']); ?>"><?php esc_html_e('Clear', 'super-speedy-performance-analysis'); ?></button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="sspa-history-export-actions">
                <button type="button" class="button sspa-history-preview-export"><?php esc_html_e('Preview privacy-safe evidence', 'super-speedy-performance-analysis'); ?></button>
                <button type="button" class="button sspa-history-download-export" disabled><?php esc_html_e('Download reviewed evidence', 'super-speedy-performance-analysis'); ?></button>
                <span class="spinner" aria-hidden="true"></span>
            </p>
            <pre class="sspa-history-export-preview" hidden></pre>
        </section>
        <?php
        return ob_get_clean();
    }

    private static function ajax_guard() {
        check_ajax_referer('sspa_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('forbidden', 403);
        }
    }

    public static function ajax_compare() {
        self::ajax_guard();
        $comparison = self::compare(
            isset($_POST['before_run_id']) ? (int) $_POST['before_run_id'] : 0,
            isset($_POST['after_run_id']) ? (int) $_POST['after_run_id'] : 0
        );
        if (is_wp_error($comparison)) {
            wp_send_json_error($comparison->get_error_message());
        }
        wp_send_json_success(array('html' => self::render($comparison), 'comparison' => $comparison));
    }

    public static function ajax_setting() {
        self::ajax_guard();
        $enabled = !empty($_POST['plugin_update_detection']);
        sspa_update_option('plugin_update_detection', $enabled);
        if (!$enabled) {
            SSPA_Change_Set::dismiss();
        }
        wp_send_json_success(array('plugin_update_detection' => (bool) sspa_get_option('plugin_update_detection')));
    }

    public static function ajax_assertion() {
        self::ajax_guard();
        $mode = isset($_POST['mode']) ? sanitize_key(wp_unslash($_POST['mode'])) : '';
        $page = isset($_POST['page_identity']) ? sanitize_text_field(wp_unslash($_POST['page_identity'])) : '';
        $before_id = isset($_POST['before_run_id']) ? (int) $_POST['before_run_id'] : 0;
        $after_id = isset($_POST['after_run_id']) ? (int) $_POST['after_run_id'] : 0;
        $result = 'approve' === $mode ? self::approve_assertion($after_id, $page) : self::clear_assertion($page);
        if (is_wp_error($result) || !$result) {
            wp_send_json_error(is_wp_error($result) ? $result->get_error_message() : __('That expectation could not be changed.', 'super-speedy-performance-analysis'));
        }
        $comparison = self::compare($before_id, $after_id);
        if (is_wp_error($comparison)) {
            wp_send_json_error($comparison->get_error_message());
        }
        wp_send_json_success(array('html' => self::render($comparison)));
    }

    public static function ajax_export() {
        self::ajax_guard();
        $comparison = self::compare(
            isset($_POST['before_run_id']) ? (int) $_POST['before_run_id'] : 0,
            isset($_POST['after_run_id']) ? (int) $_POST['after_run_id'] : 0
        );
        if (is_wp_error($comparison)) {
            wp_send_json_error($comparison->get_error_message());
        }
        $payload = self::export($comparison);
        wp_send_json_success(array(
            'payload' => $payload,
            'filename' => sspa_download_filename(sprintf('sspa-history-%d-vs-%d.json', (int) $comparison['before']['id'], (int) $comparison['after']['id'])),
        ));
    }

    private static function legacy_page_key($page_key, $variant) {
        return sanitize_key($page_key) . '|' . sanitize_key($variant);
    }

    private static function number_or_null($value) {
        return null === $value || '' === $value ? null : (float) $value;
    }

    private static function safe_version($version) {
        $version = trim((string) $version);
        return preg_match('/^[0-9A-Za-z][0-9A-Za-z.+_-]{0,63}$/', $version) ? $version : '';
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
