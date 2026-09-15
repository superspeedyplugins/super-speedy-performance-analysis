<?php
defined('ABSPATH') || exit;

/** Comparison rules over normalised snapshots; no persistence, rendering or transport. */
class SSPA_History_Comparison {
    public static function compare($before, $after, $assertions) {
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

        $headline = self::headline_values($pages);
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
            'headline' => self::delta($headline['before'], $headline['after'], 'ms'),
            'new_diagnostics' => $new_diagnostics,
            'summary' => array(
                'pages' => count($pages),
                'headline_pages' => $headline['count'],
                'output_changes' => $changed,
                'failed_validity_cases' => $failed_validity,
                'failed_declared_cases' => $failed_declared,
            ),
            'pages' => $pages,
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

        // A declared expectation carries two independent claims from the approved run: the
        // output signature and the HTTP response code. Each is judged on its own, so a
        // code mismatch is never mistaken for a body change or for missing evidence, and the
        // overall verdict fails if either claim fails.
        $declared = array(
            'state' => 'not_declared',
            'expected_signature' => null,
            'source_run_uuid' => null,
            'signature_state' => 'not_declared',
            'response_code' => array('expected' => null, 'actual' => $after ? $after['response_code'] : null, 'state' => 'not_declared'),
        );
        if (is_array($expected)) {
            $declared['expected_signature'] = isset($expected['output_signature']) ? $expected['output_signature'] : null;
            $declared['source_run_uuid'] = isset($expected['source_run_uuid']) ? $expected['source_run_uuid'] : null;
            if (!$after || empty($after['output_signature']) || empty($expected['output_signature'])) {
                $declared['signature_state'] = 'unknown';
            } else {
                $declared['signature_state'] = hash_equals((string) $expected['output_signature'], (string) $after['output_signature']) ? 'pass' : 'fail';
            }
            $expected_code = isset($expected['response_code']) && null !== $expected['response_code'] ? (int) $expected['response_code'] : null;
            $declared['response_code']['expected'] = $expected_code;
            if (null === $expected_code) {
                $declared['response_code']['state'] = 'not_declared';
            } elseif (!$after || null === $after['response_code']) {
                $declared['response_code']['state'] = 'unknown';
            } else {
                $declared['response_code']['state'] = $expected_code === (int) $after['response_code'] ? 'pass' : 'fail';
            }
            $verdicts = array($declared['signature_state'], $declared['response_code']['state']);
            if (in_array('fail', $verdicts, true)) {
                $declared['state'] = 'fail';
            } elseif (in_array('unknown', $verdicts, true)) {
                $declared['state'] = 'unknown';
            } else {
                $declared['state'] = 'pass';
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

    private static function headline_values($pages) {
        $before = array();
        $after = array();
        foreach ((array) $pages as $page) {
            $timing = $page['metrics']['ttfb_ms'];
            if (null !== $timing['before'] && null !== $timing['after']) {
                $before[] = $timing['before'];
                $after[] = $timing['after'];
            }
        }
        return array('before' => SSPA_Profile_Store::median($before), 'after' => SSPA_Profile_Store::median($after), 'count' => count($before));
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

    private static function legacy_page_key($page_key, $variant) {
        return sanitize_key($page_key) . '|' . sanitize_key($variant);
    }

    private static function number_or_null($value) {
        return null === $value || '' === $value ? null : (float) $value;
    }

}
