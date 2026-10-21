<?php
defined('ABSPATH') || exit;

/** One time-led explanation shared by the panel and the Markdown export. */
class SSPA_Page_Summary {
    /** Read-only interpretation of retained pre-fix SQL; the archive stays untouched. */
    public static function site_capture($capture) {
        if (!is_array($capture) || isset($capture['sql']['profiler_overhead']) || empty($capture['sql']['queries'])) return $capture;
        $observer = array('count' => 0, 'total_ms' => 0, 'legacy_view' => true);
        $owners = array();
        $site_queries = array();
        foreach ($capture['sql']['queries'] as $query) {
            $owner = isset($query['component']) ? $query['component'] : '';
            if (!in_array($owner, array('mu:sspa-loader', 'super-speedy-performance-analysis'), true)) { $site_queries[] = $query; continue; }
            $observer['count']++;
            $observer['total_ms'] += (float) $query['ms'];
            if (!isset($owners[$owner])) $owners[$owner] = array('count' => 0, 'ms' => 0, 'rows' => 0);
            $owners[$owner]['count']++;
            $owners[$owner]['ms'] += (float) $query['ms'];
            $owners[$owner]['rows'] += isset($query['rows']) ? (int) $query['rows'] : 0;
        }
        if (!$observer['count']) return $capture;
        $capture['sql']['queries'] = $site_queries;
        $capture['sql']['profiler_overhead'] = $observer;
        if (isset($capture['sql']['count'])) $capture['sql']['count'] -= $observer['count'];
        if (isset($capture['sql']['total_ms'])) $capture['sql']['total_ms'] = round($capture['sql']['total_ms'] - $observer['total_ms'], 3);
        foreach ($owners as $owner => $cost) {
            if (isset($capture['sql']['rows_total'])) $capture['sql']['rows_total'] -= $cost['rows'];
            if (empty($capture['components'][$owner])) continue;
            $stats = &$capture['components'][$owner];
            if (isset($stats['query_count'])) $stats['query_count'] -= $cost['count'];
            if (isset($stats['sql_count'])) $stats['sql_count'] -= $cost['count'];
            if (isset($stats['sql_ms'])) $stats['sql_ms'] = round($stats['sql_ms'] - $cost['ms'], 3);
            if (isset($stats['rows'])) $stats['rows'] -= $cost['rows'];
            $stats['slowest_ms'] = 0;
            if (empty($stats['http_ms']) && empty($stats['mail_count'])) unset($capture['components'][$owner]);
            unset($stats);
        }
        if (!empty($capture['sql']['dupe_details'])) {
            $capture['sql']['dupe_details'] = array_values(array_filter($capture['sql']['dupe_details'], function ($detail) use (&$capture, $owners) {
                if (!isset($owners[$detail['component']])) return true;
                if (isset($capture['sql']['dupe_count'])) $capture['sql']['dupe_count'] -= $detail['count'] - 1;
                return false;
            }));
        }
        return $capture;
    }

    public static function representative($row, $capture) {
        $fields = array('page_gen_ms' => array('overview', 'gen_ms'), 'sql_ms' => array('sql', 'total_ms'),
            'sql_count' => array('sql', 'count'), 'http_ms' => array('http', 'total_ms'),
            'http_count' => array('http', 'count'), 'peak_mem_bytes' => array('overview', 'peak_mem'),
            'response_code' => array('overview', 'code'), 'cache_hits' => array('cache', 'hits'), 'cache_misses' => array('cache', 'misses'),
            'rows_returned_total' => array('sql', 'rows_total'), 'dupe_query_count' => array('sql', 'dupe_count'),
            'mail_count' => array('mail', 'count'), 'mail_ms' => array('mail', 'total_construct_ms'));
        foreach ($fields as $field => $path) {
            if (isset($capture[$path[0]]) && array_key_exists($path[1], $capture[$path[0]])) $row[$field] = $capture[$path[0]][$path[1]];
        }
        if (isset($row['page_gen_ms'], $row['sql_ms'], $row['http_ms'])) $row['php_ms'] = max(0, $row['page_gen_ms'] - $row['sql_ms'] - $row['http_ms']);
        return $row;
    }

    public static function build($row, $capture) {
        $boot = isset($capture['boot']) ? $capture['boot'] : array();
        // Detailed evidence belongs to one real sample. Never reconcile it against
        // independent medians from other requests.
        $gen = isset($capture['overview']['gen_ms']) ? (float) $capture['overview']['gen_ms'] : (float) $row['page_gen_ms'];
        $sql = isset($capture['sql']['total_ms']) ? (float) $capture['sql']['total_ms'] : null;
        $http = isset($capture['http']['total_ms']) ? (float) $capture['http']['total_ms'] : null;
        $render = isset($boot['segments']['render_and_output']) ? (float) $boot['segments']['render_and_output'] : null;
        $residual = null !== $sql && null !== $http ? max(0, $gen - $sql - $http) : null;
        $share = function ($ms) use ($gen) { return $gen > 0 ? number_format($ms / $gen * 100, 1) . '%' : 'unknown'; };
        $category = 'unknown';
        if ($gen <= 0) {
            $verdict = __('Generation time was not captured.', 'super-speedy-performance-analysis');
        } elseif (null === $residual) {
            /* translators: %s: measured generation time in milliseconds. */
            $verdict = sprintf(__('%s ms to generate. SQL or HTTP coverage is unavailable; the dominant cost is unknown.', 'super-speedy-performance-analysis'), number_format($gen, 1));
        } else {
            $dominant = max($sql, $http, $residual);
            $category = $dominant === $residual ? 'php' : ($dominant === $sql ? 'sql' : 'http');
            if ($residual === $dominant) {
                $where = __('PHP or waiting', 'super-speedy-performance-analysis');
            } else {
                $where = $sql === $dominant ? __('SQL', 'super-speedy-performance-analysis') : __('WordPress HTTP calls', 'super-speedy-performance-analysis');
            }
            /* translators: 1: milliseconds, 2: dominant share, 3: category, 4: SQL share, 5: HTTP share */
            $verdict = sprintf(__('%1$s ms to generate. %2$s is %3$s; SQL %4$s, WordPress HTTP %5$s.', 'super-speedy-performance-analysis'), number_format($gen, 1), $share($dominant), $where, $share($sql), $share($http));
            if (null !== $render && $render - $sql - $http > $gen / 2) {
                /* translators: 1: generation milliseconds, 2: render share, 3: SQL share, 4: WordPress HTTP share. */
                $verdict = sprintf(__('%1$s ms to generate. PHP or waiting during page render dominates (render %2$s; SQL %3$s; WordPress HTTP %4$s).', 'super-speedy-performance-analysis'), number_format($gen, 1), $share($render), $share($sql), $share($http));
            }
        }
        $timed = isset($boot['render']['timed_ms']) ? (float) $boot['render']['timed_ms'] : null;
        $untimed = isset($boot['render']['untimed_ms']) ? (float) $boot['render']['untimed_ms'] : null;
        if (null !== $timed && null !== $untimed) {
            /* translators: 1: attributed render milliseconds, 2: total render milliseconds, 3: unattributed render milliseconds. */
            $coverage = sprintf(__('Attributed: %1$s ms of %2$s ms during render. Unattributed: %3$s ms during render.', 'super-speedy-performance-analysis'), number_format($timed, 1), number_format($render, 1), number_format($untimed, 1));
        } else {
            $coverage = __('Attributed: unknown. Unattributed: unknown. This capture has no render coverage.', 'super-speedy-performance-analysis');
        }
        if (!isset($boot['timing_basis']) && null !== $timed) $coverage .= ' ' . __('Legacy callback totals can overlap; unattributed time is an estimate.', 'super-speedy-performance-analysis');
        $coverage_headline = $coverage;
        if (!empty($boot['template_files'])) $coverage .= ' ' . __('Attribution includes residual time in bounded template loads; those windows may call other uninstrumented PHP.', 'super-speedy-performance-analysis');
        if (!empty($boot['reference_callbacks_untimed'])) {
            /* translators: %d: number of reference callbacks left intact. */
            $coverage .= ' ' . sprintf(__('%d reference callbacks retain native behaviour and are not individually timed.', 'super-speedy-performance-analysis'), count($boot['reference_callbacks_untimed']));
        }
        $coverage .= ' ' . (!empty($capture['raw_streams']['enabled'])
            ? __('Opt-in HTTP/HTTPS stream wrappers ran: $http_response_header scope changes and plugins reading it may behave differently. Direct cURL remains outside named HTTP coverage.', 'super-speedy-performance-analysis')
            : __('Raw HTTP streams and direct cURL are outside named HTTP coverage.', 'super-speedy-performance-analysis'));
        if (!empty($capture['raw_streams']['enabled'])) {
            $reads = $capture['raw_streams']['calls'];
            usort($reads, function ($a, $b) { return $b['ms'] <=> $a['ms']; });
            foreach (array_slice($reads, 0, 3) as $read) {
                /* translators: 1: URL without credentials or query values, 2: milliseconds, 3: calling PHP file. */
                $coverage .= ' ' . sprintf(__('Raw stream %1$s: %2$s ms, caller %3$s.', 'super-speedy-performance-analysis'), $read['url'], number_format($read['ms'], 1), self::file($read['file']) . ':' . $read['line']);
            }
        }
        if (!empty($boot['unreflected_callbacks_untimed'])) {
            /* translators: %d: callback signatures that Reflection could not inspect. */
            $coverage .= ' ' . sprintf(__('%d callback signatures could not be inspected and remain untimed.', 'super-speedy-performance-analysis'), count($boot['unreflected_callbacks_untimed']));
            /* translators: %s: up to ten hook and callback names, without timing claims. */
            $coverage .= ' ' . sprintf(__('Untimed signatures (up to ten): %s.', 'super-speedy-performance-analysis'), implode(', ', array_slice($boot['unreflected_callbacks_untimed'], 0, 10)));
        }
        $gap = isset($boot['largest_gap']) ? $boot['largest_gap'] : null;
        if ($gap) {
            /* translators: 1: duration, 2: template, 3: preceding event, 4: following event */
            $coverage .= ' ' . sprintf(__('Largest untimed gap: %1$s ms in %2$s, between %3$s and %4$s.', 'super-speedy-performance-analysis'), number_format($gap['ms'], 1), self::file($gap['template']), $gap['after'], $gap['before']);
        }
        $culprits = array();
        $ranked = isset($boot['render']['top']) && null !== $render && $render > $gen / 2 ? $boot['render']['top'] : (isset($boot['top_callbacks']) ? $boot['top_callbacks'] : array());
        $component_times = isset($boot['render']['components']) && null !== $render && $render > $gen / 2
            ? $boot['render']['components'] : (isset($boot['components']) ? $boot['components'] : array());
        arsort($component_times);
        foreach (array_slice($component_times, 0, 3, true) as $component => $ms) {
            $detail = '';
            foreach ($ranked as $entry) {
                if ($entry['component'] !== $component) continue;
                $detail = $entry['hook'];
                if (in_array($entry['hook'], array('shortcode', 'widget', 'block', 'template file'), true)) $detail .= ' ' . $entry['label'];
                $detail .= ' [' . (isset($entry['evidence']) ? $entry['evidence'] : 'inclusive callback wall time, legacy capture') . ']';
                break;
            }
            $culprits[] = $component . ': ' . number_format($ms, 1) . ' ms' . ($detail ? '; ' . $detail : ' [file includes]');
        }
        if (in_array($category, array('sql', 'http'), true)) {
            $culprits = array();
            $costs = array();
            foreach ((array) (isset($capture['components']) ? $capture['components'] : array()) as $component => $stats) {
                if (isset($stats[$category . '_ms']) && $stats[$category . '_ms'] > 0) $costs[$component] = $stats[$category . '_ms'];
            }
            arsort($costs);
            foreach (array_slice($costs, 0, 3, true) as $component => $ms) {
                $culprits[] = $component . ': ' . number_format($ms, 1) . ' ms [' . ('sql' === $category ? 'measured SQL' : 'WordPress HTTP API calls') . ']';
            }
        }
        if ('unknown' === $category) {
            $action = __('Re-run this page to capture the missing timing evidence.', 'super-speedy-performance-analysis');
        } elseif ('sql' === $category) {
            $action = __('Inspect the largest SQL groups and their query plans below.', 'super-speedy-performance-analysis');
        } elseif ('http' === $category) {
            $action = __('Inspect the slowest WordPress HTTP calls and their callers below.', 'super-speedy-performance-analysis');
        } else {
            $action = $untimed > $gen * .2
                ? __('Inspect the template and the events around the largest untimed gap; direct network calls, disk waits and sleep may be here.', 'super-speedy-performance-analysis')
                : __('Measure which plugins cost this page the most.', 'super-speedy-performance-analysis');
        }
        $cpu = isset($boot['cpu']) ? $boot['cpu'] : null;
        if ($cpu && !empty($cpu['available'])) {
            /* translators: 1: process CPU milliseconds, 2: non-CPU elapsed milliseconds. */
            $cpu_line = sprintf(__('CPU: %1$s ms; non-CPU wall time: %2$s ms (profiler arm to request end). Non-CPU time includes waiting and scheduler delays.', 'super-speedy-performance-analysis'), number_format($cpu['cpu_ms'], 1), number_format($cpu['non_cpu_ms'], 1));
        } else {
            $cpu_line = __('CPU time was not captured.', 'super-speedy-performance-analysis');
        }
        return array('verdict' => $verdict, 'culprits' => $culprits, 'coverage' => $coverage, 'coverage_headline' => $coverage_headline, 'gap' => $gap, 'next_action' => $action, 'cpu' => $cpu_line, 'samples' => self::samples($row),
            'generation_ms' => $gen, 'unattributed_ms' => $untimed);
    }

    /** Relative evidence paths keep private absolute server paths out of Markdown. */
    public static function file($file) {
        $file = str_replace('\\', '/', (string) $file);
        foreach (array('/wp-content/', '/wp-includes/', '/wp-admin/') as $marker) {
            $at = strpos($file, $marker);
            if (false !== $at) return substr($file, $at + 1);
        }
        return basename($file);
    }

    public static function samples($row) {
        $samples = isset($row['samples']) ? $row['samples'] : array();
        if (is_string($samples)) $samples = json_decode($samples, true);
        $values = array();
        foreach ((array) $samples as $sample) {
            if (isset($sample['gen_ms']) && empty($sample['error']) && empty($sample['blocked_by'])) $values[] = (float) $sample['gen_ms'];
        }
        if (!$values) return __('Sample variance is unavailable.', 'super-speedy-performance-analysis');
        $first = reset($values);
        $later = array_slice($values, 1);
        sort($values);
        $n = count($values);
        $median = $n % 2 ? $values[(int) floor($n / 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
        /* translators: 1: minimum milliseconds, 2: median milliseconds, 3: maximum milliseconds, 4: successful sample count. */
        $line = sprintf(__('Samples: min %1$s ms / median %2$s ms / max %3$s ms (%4$d successful samples).', 'super-speedy-performance-analysis'), number_format($values[0], 1), number_format($median, 1), number_format($values[$n - 1], 1), $n);
        if (count($later) >= 2 && $first > max($later) * 1.5 && $first - max($later) > 100) {
            $line .= ' ' . __('First-sample-only cost observed; the cause is not established.', 'super-speedy-performance-analysis');
        }
        return $line;
    }
}
