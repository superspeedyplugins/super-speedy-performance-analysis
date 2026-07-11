<?php
defined('ABSPATH') || exit;

/**
 * Turns a completed run's profiles into findings - Dave's performance SOP as explicit
 * heuristics (brainstorm 3.5). Each heuristic is a method; thresholds and recommendation
 * texts come from the rules snapshot so they can improve without code changes.
 */
class SSPA_Analysis_Engine {

    private $run_id;
    private $profiles = array();
    private $captures = array();
    private $demographics;
    private $findings = 0;

    public function analyse($run_id, $demographics) {
        global $wpdb;
        $this->run_id = (int) $run_id;
        $this->demographics = $demographics;

        $this->profiles = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . SSPA_Schema::table('profiles') . ' WHERE run_id = %d',
            $this->run_id
        ), ARRAY_A);

        foreach ($this->profiles as $p) {
            if (!empty($p['profile_blob'])) {
                $json = @gzuncompress($p['profile_blob']);
                $capture = $json ? json_decode($json, true) : null;
                if (is_array($capture)) {
                    $this->captures[$p['id']] = $capture;
                }
            }
        }

        $this->slow_queries();
        $this->big_result_sets();
        $this->query_hogs();
        $this->dupe_queries();
        $this->slow_http();
        $this->autoload_bloat();
        $this->environment();
        $this->duplicate_functionality();
        $this->security_blocks();

        return $this->findings;
    }

    private function add($severity, $type, $component, $page_key, $evidence, $rec_key, $confidence = 'inferred') {
        global $wpdb;
        $wpdb->insert(SSPA_Schema::table('findings'), array(
            'run_id' => $this->run_id,
            'severity' => $severity,
            'finding_type' => $type,
            'component' => $component !== null ? substr($component, 0, 191) : null,
            'page_key' => $page_key,
            'evidence' => wp_json_encode($evidence),
            'recommendation_key' => $rec_key,
            'confidence' => $confidence,
            'created' => gmdate('Y-m-d H:i:s'),
        ));
        $this->findings++;
    }

    private function skip_component($component) {
        return in_array($component, array('super-speedy-performance-analysis', 'mu:sspa-loader'), true);
    }

    private function page_key($profile_id) {
        foreach ($this->profiles as $p) {
            if ((int) $p['id'] === (int) $profile_id) {
                return $p['page_key'];
            }
        }
        return null;
    }

    // ---------------- heuristics ----------------

    private function slow_queries() {
        $threshold = (float) SSPA_Rules::threshold('slow_query_ms');
        $seen = array(); // component|fingerprint -> worst finding data
        foreach ($this->captures as $profile_id => $capture) {
            foreach ($capture['sql']['queries'] as $q) {
                if ($q['ms'] < $threshold || $this->skip_component($q['component'])) {
                    continue;
                }
                $key = $q['component'] . '|' . md5($q['fp']);
                if (isset($seen[$key]) && $seen[$key]['ms'] >= $q['ms']) {
                    continue;
                }
                $seen[$key] = array(
                    'ms' => $q['ms'],
                    'rows' => $q['rows'],
                    'sql' => $q['sql'] !== null ? $q['sql'] : $q['fp'],
                    'fp' => $q['fp'],
                    'caller' => $q['caller'],
                    'component' => $q['component'],
                    'page_key' => $this->page_key($profile_id),
                );
            }
        }
        foreach ($seen as $f) {
            $shape = self::classify_query_shape($f['sql']);
            $this->add(
                $f['ms'] >= $threshold * 5 ? 'critical' : 'warn',
                'slow_query',
                $f['component'],
                $f['page_key'],
                $f + array('shape' => $shape),
                'slow_query_' . $shape
            );
        }
    }

    public static function classify_query_shape($sql) {
        global $wpdb;
        $upper = strtoupper($sql);
        if (strpos($upper, 'SQL_CALC_FOUND_ROWS') !== false) {
            return 'found_rows';
        }
        if (preg_match('/ORDER\s+BY\s+RAND\s*\(/i', $sql)) {
            return 'rand';
        }
        if (preg_match("/LIKE\s+'%/i", $sql) || preg_match('/LIKE\s+\?/', $sql) && strpos($sql, "'%") !== false) {
            return 'like';
        }
        $postmeta = isset($wpdb->postmeta) ? $wpdb->postmeta : 'wp_postmeta';
        if (substr_count($upper, strtoupper($postmeta)) >= 2 || (strpos($upper, strtoupper($postmeta)) !== false && strpos($upper, 'JOIN') !== false)) {
            return 'postmeta';
        }
        $term_rel = isset($wpdb->term_relationships) ? $wpdb->term_relationships : 'wp_term_relationships';
        if (substr_count($upper, strtoupper($term_rel)) >= 2) {
            return 'tax';
        }
        return 'generic';
    }

    private function big_result_sets() {
        $threshold = (int) SSPA_Rules::threshold('big_result_rows');
        $seen = array();
        foreach ($this->captures as $profile_id => $capture) {
            foreach ($capture['sql']['queries'] as $q) {
                if ((int) $q['rows'] < $threshold || $this->skip_component($q['component'])) {
                    continue;
                }
                $key = $q['component'] . '|' . md5($q['fp']);
                if (isset($seen[$key]) && $seen[$key]['rows'] >= $q['rows']) {
                    continue;
                }
                $seen[$key] = array(
                    'rows' => (int) $q['rows'],
                    'ms' => $q['ms'],
                    'sql' => $q['sql'] !== null ? $q['sql'] : $q['fp'],
                    'caller' => $q['caller'],
                    'component' => $q['component'],
                    'page_key' => $this->page_key($profile_id),
                );
            }
        }
        foreach ($seen as $f) {
            $this->add(
                $f['rows'] >= $threshold * 5 ? 'critical' : 'warn',
                'big_result_set',
                $f['component'],
                $f['page_key'],
                $f,
                'big_result_set'
            );
        }
    }

    private function query_hogs() {
        global $wpdb;
        $threshold = (int) SSPA_Rules::threshold('query_hog_count');
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT cs.component, cs.query_count, cs.sql_ms, cs.rows_returned, p.page_key
             FROM ' . SSPA_Schema::table('component_stats') . ' cs
             JOIN ' . SSPA_Schema::table('profiles') . " p ON p.id = cs.profile_id
             WHERE cs.run_id = %d AND cs.component_type = 'plugin' AND cs.query_count >= %d",
            $this->run_id,
            $threshold
        ), ARRAY_A);

        $worst = array();
        foreach ($rows as $r) {
            if ($this->skip_component($r['component'])) {
                continue;
            }
            if (!isset($worst[$r['component']]) || $r['query_count'] > $worst[$r['component']]['query_count']) {
                $worst[$r['component']] = $r;
            }
        }
        foreach ($worst as $component => $r) {
            $this->add(
                $r['query_count'] >= $threshold * 3 ? 'critical' : 'warn',
                'query_loop',
                $component,
                $r['page_key'],
                array('query_count' => (int) $r['query_count'], 'sql_ms' => (float) $r['sql_ms'], 'rows' => (int) $r['rows_returned']),
                'query_loop'
            );
        }
    }

    private function dupe_queries() {
        $threshold = (int) SSPA_Rules::threshold('dupe_query_count');
        $seen = array();
        foreach ($this->captures as $profile_id => $capture) {
            if (empty($capture['sql']['dupe_details'])) {
                continue;
            }
            foreach ($capture['sql']['dupe_details'] as $d) {
                if ($d['count'] < $threshold || $this->skip_component($d['component'])) {
                    continue;
                }
                $key = $d['component'] . '|' . md5($d['sql']);
                if (isset($seen[$key]) && $seen[$key]['count'] >= $d['count']) {
                    continue;
                }
                $seen[$key] = $d + array('page_key' => $this->page_key($profile_id));
            }
        }
        foreach ($seen as $d) {
            $this->add('warn', 'dupe_queries', $d['component'], $d['page_key'], $d, 'dupe_queries');
        }
    }

    private function slow_http() {
        $threshold = (float) SSPA_Rules::threshold('slow_http_ms');
        $seen = array();
        foreach ($this->captures as $profile_id => $capture) {
            if (empty($capture['http']['calls'])) {
                continue;
            }
            foreach ($capture['http']['calls'] as $call) {
                if ($call['ms'] === null || $call['ms'] < $threshold || !$call['blocking'] || $this->skip_component($call['component'])) {
                    continue;
                }
                $key = $call['component'] . '|' . $call['url'];
                if (isset($seen[$key]) && $seen[$key]['ms'] >= $call['ms']) {
                    continue;
                }
                $seen[$key] = array(
                    'ms' => $call['ms'],
                    'url' => $call['url'],
                    'code' => $call['code'],
                    'component' => $call['component'],
                    'caller' => $call['caller'],
                    'page_key' => $this->page_key($profile_id),
                    'is_admin' => !empty($capture['overview']['is_admin']),
                );
            }
        }
        foreach ($seen as $f) {
            // Blocking calls on front-end pages are worse than in wp-admin.
            $severity = (!$f['is_admin'] || $f['ms'] >= $threshold * 3) ? 'critical' : 'warn';
            $this->add($severity, 'slow_http', $f['component'], $f['page_key'], $f, 'slow_http');
        }
    }

    private function autoload_bloat() {
        $threshold = (int) SSPA_Rules::threshold('autoload_bytes');
        $bytes = isset($this->demographics['metrics']['autoload_bytes']) ? (int) $this->demographics['metrics']['autoload_bytes'] : 0;
        if ($bytes > $threshold) {
            $this->add('warn', 'autoload_bloat', null, null, array('autoload_bytes' => $bytes), 'autoload_bloat');
        }
    }

    private function environment() {
        $m = isset($this->demographics['metrics']) ? $this->demographics['metrics'] : array();
        if (!empty($m['php']) && version_compare($m['php'], '8.0', '<')) {
            $this->add('warn', 'environment', null, null, array('php' => $m['php']), 'old_php');
        }
        $postmeta = isset($m['postmeta_rows']) ? (int) $m['postmeta_rows'] : 0;
        $products = isset($m['post_counts']['product']) ? (int) $m['post_counts']['product'] : 0;
        if (empty($m['object_cache']) && ($postmeta > 500000 || $products > 10000)) {
            $this->add('warn', 'environment', null, null, array('postmeta_rows' => $postmeta, 'products' => $products), 'no_object_cache');
        }
    }

    private function duplicate_functionality() {
        $active = isset($this->demographics['metrics']['active_plugins']) ? $this->demographics['metrics']['active_plugins'] : array();
        foreach (SSPA_Rules::categories() as $category => $slugs) {
            $overlap = array_values(array_intersect($slugs, $active));
            if (count($overlap) > 1) {
                $this->add('warn', 'duplicate_functionality', null, null, array('category' => $category, 'plugins' => $overlap), 'duplicate_functionality');
            }
        }
    }

    private function security_blocks() {
        $blocked = array();
        foreach ($this->profiles as $p) {
            if (!empty($p['blocked_by'])) {
                $blocked[$p['blocked_by']][] = $p['page_key'];
            }
        }
        foreach ($blocked as $layer => $pages) {
            $this->add('warn', 'security_block', null, $pages[0], array('layer' => $layer, 'pages' => $pages), 'security_block');
        }
    }

    // ---------------- scoring ----------------

    public static function score($run_id) {
        global $wpdb;
        $counts = $wpdb->get_results($wpdb->prepare(
            'SELECT severity, COUNT(*) c FROM ' . SSPA_Schema::table('findings') . ' WHERE run_id = %d GROUP BY severity',
            $run_id
        ), ARRAY_A);
        $critical = 0;
        $warn = 0;
        foreach ($counts as $c) {
            if ('critical' === $c['severity']) {
                $critical = (int) $c['c'];
            } elseif ('warn' === $c['severity']) {
                $warn = (int) $c['c'];
            }
        }
        $score = 100 - min(60, $critical * 8) - min(30, $warn * 2);
        return max(5, $score);
    }
}
