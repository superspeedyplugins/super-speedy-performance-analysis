<?php
defined('ABSPATH') || exit;

/**
 * Turns a crawled job (N samples + captures) into a sspa_profiles row (medians + blob of
 * the median sample's capture) and extracts per-component aggregates into
 * sspa_component_stats so reporting never has to unpack blobs.
 */
class SSPA_Profile_Store {

    public static function save($run_id, $result) {
        global $wpdb;

        $samples = $result['samples'];
        $with_capture = array_values(array_filter($samples, function ($s) {
            return !empty($s['capture']);
        }));

        $median_capture = null;
        if ($with_capture) {
            usort($with_capture, function ($a, $b) {
                $ga = isset($a['capture']['overview']['gen_ms']) ? $a['capture']['overview']['gen_ms'] : 0;
                $gb = isset($b['capture']['overview']['gen_ms']) ? $b['capture']['overview']['gen_ms'] : 0;
                return $ga <=> $gb;
            });
            $median_capture = $with_capture[(int) floor((count($with_capture) - 1) / 2)]['capture'];
        }

        $metric = function ($path) use ($with_capture) {
            $values = array();
            foreach ($with_capture as $s) {
                $v = $s['capture'];
                foreach ($path as $key) {
                    $v = isset($v[$key]) ? $v[$key] : null;
                    if ($v === null) {
                        break;
                    }
                }
                if ($v !== null) {
                    $values[] = (float) $v;
                }
            }
            return self::median($values);
        };

        $wall_values = array();
        $codes = array();
        foreach ($samples as $s) {
            if (empty($s['blocked_by']) && empty($s['error'])) {
                $wall_values[] = $s['wall_ms'];
            }
            $codes[] = $s['code'];
        }

        $gen_ms = $metric(array('overview', 'gen_ms'));
        $sql_ms = $metric(array('sql', 'total_ms'));
        $http_ms = $metric(array('http', 'total_ms'));
        $php_ms = null;
        if ($gen_ms !== null) {
            $php_ms = max(0, $gen_ms - (float) $sql_ms - (float) $http_ms);
        }

        // Sample summaries only - full captures are large; the blob keeps the median one.
        $sample_summaries = array_map(function ($s) {
            return array(
                'wall_ms' => $s['wall_ms'],
                'code' => $s['code'],
                'error' => $s['error'],
                'cached' => $s['cached'],
                'gen_ms' => isset($s['capture']['overview']['gen_ms']) ? round($s['capture']['overview']['gen_ms'], 1) : null,
            );
        }, $samples);

        $wpdb->insert(SSPA_Schema::table('profiles'), array(
            'run_id' => $run_id,
            'page_key' => $result['page_key'],
            'url' => $result['url'],
            'method' => 'GET',
            'variant' => $result['variant'],
            'plugin_set_hash' => '',
            'object_cache_mode' => 'normal',
            'samples' => wp_json_encode($sample_summaries),
            'ttfb_ms' => self::median($wall_values),
            'page_gen_ms' => $gen_ms,
            'sql_ms' => $sql_ms,
            'sql_count' => $metric(array('sql', 'count')),
            'http_ms' => $http_ms,
            'http_count' => $metric(array('http', 'count')),
            'php_ms' => $php_ms,
            'peak_mem_bytes' => $metric(array('overview', 'peak_mem')),
            'rows_returned_total' => $metric(array('sql', 'rows_total')),
            'dupe_query_count' => $metric(array('sql', 'dupe_count')),
            'cache_hits' => $metric(array('cache', 'hits')),
            'cache_misses' => $metric(array('cache', 'misses')),
            'mail_count' => $metric(array('mail', 'count')),
            'mail_ms' => null,
            'response_code' => $codes ? (int) self::median($codes) : null,
            'blocked_by' => $result['blocked_by'],
            'profile_blob' => $median_capture ? gzcompress(wp_json_encode($median_capture), 6) : null,
            'created' => gmdate('Y-m-d H:i:s'),
        ));
        $profile_id = (int) $wpdb->insert_id;

        if ($median_capture && !empty($median_capture['components'])) {
            foreach ($median_capture['components'] as $component => $stats) {
                $wpdb->insert(SSPA_Schema::table('component_stats'), array(
                    'profile_id' => $profile_id,
                    'run_id' => $run_id,
                    'component' => substr($component, 0, 191),
                    'component_type' => $stats['type'],
                    'query_count' => (int) $stats['query_count'],
                    'sql_ms' => (float) $stats['sql_ms'],
                    'rows_returned' => (int) $stats['rows'],
                    'slowest_query_ms' => (float) $stats['slowest_ms'],
                    'http_ms' => (float) $stats['http_ms'],
                    'mail_ms' => 0,
                    'cache_hits' => 0,
                    'cache_misses' => 0,
                ));
            }
        }

        return $profile_id;
    }

    public static function median($values) {
        $values = array_values(array_filter($values, function ($v) {
            return $v !== null && $v !== '';
        }));
        if (!$values) {
            return null;
        }
        sort($values);
        $count = count($values);
        $mid = (int) floor(($count - 1) / 2);
        return ($count % 2) ? $values[$mid] : ($values[$mid] + $values[$mid + 1]) / 2;
    }
}
