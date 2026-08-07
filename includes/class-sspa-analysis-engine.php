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
    private $plans = array();
    private $flagged_slow = array();
    private $digests = array();

    /** MySQL digest deltas for this run, keyed by fingerprint hash. May be empty. */
    public function set_digests($digests) {
        $this->digests = is_array($digests) ? $digests : array();
    }

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

        // Query plans first: the heuristics below attach them to their findings so a slow
        // query is reported WITH the reason it is slow, not just the fact of it.
        $this->build_plans();

        $this->slow_queries();
        $this->big_result_sets();
        $this->unindexed_queries();
        $this->over_examining_queries();
        $this->query_hogs();
        $this->dupe_queries();
        $this->slow_http();
        $this->blocking_mail();
        $this->autoload_bloat();
        $this->environment();
        $this->duplicate_functionality();
        $this->security_blocks();

        return $this->findings;
    }

    /**
     * Checkout-flow findings. A separate entry point from analyse(): a flow run has no
     * pages, one sample per step and no baseline, so the page heuristics either do not
     * apply or would compare against nothing.
     *
     * Steps nobody waits for - the pre-flight probe and the admin cleanup - are excluded
     * from every total here, exactly as they are in the panel (doc T9 rule 1).
     *
     * @param array|null $inventory The pre-flight inventory, when one was gathered.
     * @return int findings written
     */
    public function analyse_checkout($run_id, $inventory = null) {
        global $wpdb;
        $this->run_id = (int) $run_id;
        $this->findings = 0;

        $this->profiles = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . SSPA_Schema::table('profiles') . " WHERE run_id = %d AND page_key NOT IN ('flow-preflight','flow-delete-order')",
            $this->run_id
        ), ARRAY_A);
        $this->captures = array();
        foreach ($this->profiles as $p) {
            if (!empty($p['profile_blob'])) {
                $json = @gzuncompress($p['profile_blob']);
                $capture = $json ? json_decode($json, true) : null;
                if (is_array($capture)) {
                    $this->captures[$p['id']] = $capture;
                }
            }
        }
        if (!$this->profiles) {
            return 0;
        }

        $this->checkout_slow_steps();
        $this->checkout_component_cost();
        $this->checkout_blocking_http();
        $this->checkout_mail_inline($inventory);
        $this->checkout_dupe_queries();

        return $this->findings;
    }

    /** @return float Total server time the customer waited across the measured steps. */
    private function checkout_total_ms() {
        $total = 0.0;
        foreach ($this->profiles as $p) {
            $total += (float) $p['page_gen_ms'];
        }
        return $total;
    }

    /** The component that spent most SQL+HTTP time in one step, so a finding can name it. */
    private function checkout_dominant_component($profile_id) {
        $capture = isset($this->captures[$profile_id]) ? $this->captures[$profile_id] : null;
        if (!$capture || empty($capture['components'])) {
            return null;
        }
        $best = null;
        foreach ($capture['components'] as $component => $stats) {
            if ($this->skip_component($component)) {
                continue;
            }
            $cost = (float) $stats['sql_ms'] + (float) $stats['http_ms'];
            if (!$best || $cost > $best['ms']) {
                $best = array('component' => $component, 'ms' => round($cost, 1));
            }
        }
        return $best;
    }

    private function checkout_slow_steps() {
        $threshold = (float) SSPA_Rules::threshold('checkout_slow_step_ms');
        if ($threshold <= 0) {
            $threshold = 800;
        }
        foreach ($this->profiles as $p) {
            if (null === $p['page_gen_ms'] || (float) $p['page_gen_ms'] < $threshold) {
                continue;
            }
            $dominant = $this->checkout_dominant_component((int) $p['id']);
            $this->add(
                (float) $p['page_gen_ms'] >= $threshold * 2 ? 'critical' : 'warn',
                'checkout_slow_step',
                $dominant ? $dominant['component'] : null,
                $p['page_key'],
                array(
                    'step' => $p['page_key'],
                    'label' => SSPA_Checkout_Flow::step_label($p['page_key']),
                    'gen_ms' => round((float) $p['page_gen_ms'], 1),
                    'sql_ms' => (null !== $p['sql_ms']) ? round((float) $p['sql_ms'], 1) : null,
                    'http_ms' => (null !== $p['http_ms']) ? round((float) $p['http_ms'], 1) : null,
                    'dominant_ms' => $dominant ? $dominant['ms'] : null,
                ),
                'checkout_slow_step',
                'measured'
            );
        }
    }

    private function checkout_component_cost() {
        $share = (float) SSPA_Rules::threshold('checkout_component_share_pct');
        if ($share <= 0) {
            $share = 25;
        }
        $total = $this->checkout_total_ms();
        if ($total <= 0) {
            return;
        }

        $by_component = array();
        foreach ($this->profiles as $p) {
            $capture = isset($this->captures[(int) $p['id']]) ? $this->captures[(int) $p['id']] : null;
            if (!$capture || empty($capture['components'])) {
                continue;
            }
            foreach ($capture['components'] as $component => $stats) {
                if ($this->skip_component($component)) {
                    continue;
                }
                $cost = (float) $stats['sql_ms'] + (float) $stats['http_ms'];
                if (!isset($by_component[$component])) {
                    $by_component[$component] = array('ms' => 0.0, 'steps' => array());
                }
                $by_component[$component]['ms'] += $cost;
                $by_component[$component]['steps'][$p['page_key']] = round($cost, 1);
            }
        }

        foreach ($by_component as $component => $data) {
            $pct = 100 * $data['ms'] / $total;
            if ($pct < $share) {
                continue;
            }
            arsort($data['steps']);
            $this->add(
                $pct >= $share * 2 ? 'critical' : 'warn',
                'checkout_component_cost',
                $component,
                null,
                array(
                    'ms' => round($data['ms'], 1),
                    'flow_ms' => round($total, 1),
                    'share_pct' => round($pct),
                    'steps' => array_slice($data['steps'], 0, 5, true),
                ),
                'checkout_component_cost',
                'measured'
            );
        }
    }

    /**
     * A blocking outbound call inside place-order or the address step is the classic cause
     * of a six-second checkout: the customer sits waiting on somebody else's server.
     */
    private function checkout_blocking_http() {
        $threshold = (float) SSPA_Rules::threshold('checkout_http_ms');
        if ($threshold <= 0) {
            $threshold = 50;
        }
        $watched = array('flow-place-order', 'flow-update-customer');
        foreach ($this->profiles as $p) {
            if (!in_array($p['page_key'], $watched, true)) {
                continue;
            }
            $capture = isset($this->captures[(int) $p['id']]) ? $this->captures[(int) $p['id']] : null;
            if (!$capture || empty($capture['http']['calls'])) {
                continue;
            }
            // ONE finding per component per step. A purge plugin fires a dozen calls off
            // one order transition, and a dozen near-identical criticals is noise the
            // reader has to filter; what they need is the total, the count, and the worst
            // single call with who made it.
            $by_component = array();
            foreach ($capture['http']['calls'] as $call) {
                if (empty($call['blocking']) || null === $call['ms'] || $this->skip_component($call['component'])) {
                    continue;
                }
                $component = $call['component'];
                if (!isset($by_component[$component])) {
                    $by_component[$component] = array('calls' => 0, 'ms' => 0.0, 'hosts' => array(), 'failed' => 0, 'worst' => null);
                }
                $by_component[$component]['calls']++;
                $by_component[$component]['ms'] += (float) $call['ms'];
                $host = (string) strtok((string) $call['url'], '/');
                $by_component[$component]['hosts'][$host] = true;
                if (isset($call['code']) && is_string($call['code']) && 0 === strpos($call['code'], 'error:')) {
                    $by_component[$component]['failed']++;
                }
                if (!$by_component[$component]['worst'] || (float) $call['ms'] > (float) $by_component[$component]['worst']['ms']) {
                    $by_component[$component]['worst'] = $call;
                }
            }
            foreach ($by_component as $component => $agg) {
                if ($agg['ms'] < $threshold) {
                    continue;
                }
                $worst = $agg['worst'];
                $this->add(
                    $agg['ms'] >= $threshold * 10 ? 'critical' : 'warn',
                    'checkout_blocking_http',
                    $component,
                    $p['page_key'],
                    array(
                        'step' => $p['page_key'],
                        'label' => SSPA_Checkout_Flow::step_label($p['page_key']),
                        'calls' => $agg['calls'],
                        'ms' => round($agg['ms'], 1),
                        'failed' => $agg['failed'],
                        'hosts' => array_slice(array_keys($agg['hosts']), 0, 5),
                        'worst_ms' => round((float) $worst['ms'], 1),
                        'worst_url' => $worst['url'] . (!empty($worst['q']) ? '?' . $worst['q'] . '=…' : ''),
                        'worst_caller' => isset($worst['trace']) && $worst['trace'] ? $worst['trace'] : (isset($worst['caller']) ? $worst['caller'] : null),
                    ),
                    'checkout_blocking_http',
                    'measured'
                );
            }
        }
    }

    /**
     * Order emails sent inside the request versus handed to a deferred queue: the same
     * store with two very different checkouts (doc A2).
     */
    private function checkout_mail_inline($inventory) {
        $deferred = is_array($inventory) && !empty($inventory['emails_deferred']);
        $count = 0;
        $ms = 0.0;
        $steps = array();
        foreach ($this->profiles as $p) {
            if (!$p['mail_count']) {
                continue;
            }
            $count += (int) $p['mail_count'];
            $ms += (float) $p['mail_ms'];
            $steps[$p['page_key']] = (int) $p['mail_count'];
        }
        if ($deferred || $count < 1) {
            return; // deferred, or no mail was sent inside the customer's wait at all
        }
        $threshold = (float) SSPA_Rules::threshold('slow_mail_ms');
        $this->add(
            $ms >= max(1, $threshold) * 5 ? 'critical' : 'warn',
            'checkout_mail_inline',
            'woocommerce',
            null,
            array('count' => $count, 'ms' => round($ms, 1), 'steps' => $steps),
            'checkout_mail_inline',
            'measured'
        );
    }

    private function checkout_dupe_queries() {
        $threshold = (int) SSPA_Rules::threshold('dupe_query_count');
        foreach ($this->profiles as $p) {
            $capture = isset($this->captures[(int) $p['id']]) ? $this->captures[(int) $p['id']] : null;
            if (!$capture || empty($capture['sql']['dupe_details'])) {
                continue;
            }
            // One finding per component per step, not one per duplicated query shape: a
            // place-order step routinely repeats half a dozen different queries, and six
            // near-identical warnings naming the same component in the same step is noise
            // the reader has to filter rather than a list they can act on.
            $by_component = array();
            foreach ($capture['sql']['dupe_details'] as $d) {
                if ((int) $d['count'] < $threshold || $this->skip_component($d['component'])) {
                    continue;
                }
                $key = $d['component'];
                if (!isset($by_component[$key])) {
                    $by_component[$key] = array('shapes' => 0, 'wasted' => 0, 'ms' => 0.0, 'worst' => null);
                }
                $by_component[$key]['shapes']++;
                $by_component[$key]['wasted'] += (int) $d['count'] - 1;
                $by_component[$key]['ms'] += (float) $d['ms'];
                if (!$by_component[$key]['worst'] || (int) $d['count'] > (int) $by_component[$key]['worst']['count']) {
                    $by_component[$key]['worst'] = $d;
                }
            }
            foreach ($by_component as $component => $agg) {
                $this->add(
                    'warn',
                    'checkout_dupe_queries',
                    $component,
                    $p['page_key'],
                    array(
                        'step' => $p['page_key'],
                        'label' => SSPA_Checkout_Flow::step_label($p['page_key']),
                        'query_shapes' => $agg['shapes'],
                        'wasted_queries' => $agg['wasted'],
                        'ms' => round($agg['ms'], 1),
                        'count' => (int) $agg['worst']['count'],
                        'sql' => $agg['worst']['sql'],
                    ),
                    'checkout_dupe_queries',
                    'measured'
                );
            }
        }
    }

    /**
     * EXPLAIN every distinct query whose full SQL we kept, once per run. Keyed by
     * fingerprint so the same query shape on ten pages costs one EXPLAIN, not ten.
     *
     * @see SSPA_Explain for why this is safe and why fingerprint-only queries are skipped.
     */
    private function build_plans() {
        $this->plans = array();
        $seen_sql = array();

        foreach ($this->captures as $profile_id => $capture) {
            if (empty($capture['sql']['queries'])) {
                continue;
            }
            foreach ($capture['sql']['queries'] as $q) {
                if (empty($q['sql']) || empty($q['fp'])) {
                    continue; // fingerprint-only: literals are gone, any plan would be fiction
                }
                $key = md5($q['fp']);
                if (isset($seen_sql[$key])) {
                    continue;
                }
                if (count($seen_sql) >= SSPA_Explain::MAX_PER_RUN) {
                    break 2;
                }
                $seen_sql[$key] = true;
                $plan = SSPA_Explain::explain($q['sql']);
                if ($plan !== null) {
                    $plan['component'] = $q['component'];
                    $plan['fp'] = $q['fp'];
                    $plan['sql'] = $q['sql'];
                    $plan['page_key'] = $this->page_key($profile_id);
                    $this->plans[$key] = $plan;
                }
            }
        }
    }

    /** @return array|null The plan for a fingerprint, if one was produced. */
    private function plan_for($fp) {
        $key = md5((string) $fp);
        return isset($this->plans[$key]) ? $this->plans[$key] : null;
    }

    /**
     * Queries that are not slow enough to be flagged today but have no usable index, so they
     * degrade as the site grows. EXPLAIN is the only way to see these: on a small database a
     * full table scan is fast, right up until it is not.
     */
    private function unindexed_queries() {
        $threshold = (int) SSPA_Rules::threshold('unindexed_scan_rows');
        if ($threshold <= 0) {
            $threshold = 500;
        }
        foreach ($this->plans as $key => $plan) {
            if (empty($plan['scan']) || (int) $plan['est_rows'] < $threshold) {
                continue;
            }
            if (isset($this->flagged_slow[$key])) {
                continue; // already reported as a slow query, with the plan attached
            }
            if ($this->skip_component($plan['component'])) {
                continue;
            }
            $this->add(
                'warn',
                'unindexed_query',
                $plan['component'],
                $plan['page_key'],
                array(
                    'sql' => $plan['sql'],
                    'fp' => $plan['fp'],
                    'rows' => (int) $plan['est_rows'],
                    'plan_note' => SSPA_Explain::summarise($plan),
                    'table' => $plan['table'],
                ),
                'unindexed_query'
            );
        }
    }

    /**
     * Queries that read far more rows than they returned.
     *
     * This is the one thing only performance_schema can tell us. Our own capture sees the
     * rows that came BACK; EXPLAIN gives the optimiser's estimate of what it will read.
     * MySQL's digest counters give what it actually read. A query returning 12 rows after
     * examining 400,000 is doing a full scan behind an index that looks fine, and it is
     * invisible to every other signal we have.
     *
     * Only queries THIS run captured are reported. The digest table is server-wide, so
     * anything unmatched belongs to other traffic and is none of our business.
     */
    private function over_examining_queries() {
        if (empty($this->digests)) {
            return;
        }
        $ratio_threshold = (float) SSPA_Rules::threshold('rows_examined_ratio');
        if ($ratio_threshold <= 0) {
            $ratio_threshold = 100;
        }
        $min_examined = (int) SSPA_Rules::threshold('rows_examined_min');
        if ($min_examined <= 0) {
            $min_examined = 1000;
        }

        require_once SSPA_PLUGIN_DIR . 'profiler/fingerprint.php';

        // Map fingerprint hash -> the component we attributed it to, so a digest can be
        // blamed on a plugin rather than reported as a floating query.
        $owners = array();
        foreach ($this->captures as $profile_id => $capture) {
            if (empty($capture['sql']['queries'])) {
                continue;
            }
            foreach ($capture['sql']['queries'] as $q) {
                if (empty($q['fp'])) {
                    continue;
                }
                $key = md5(sspa_sql_fingerprint(SSPA_Digests::normalise($q['fp'])));
                if (!isset($owners[$key])) {
                    $owners[$key] = array(
                        'component' => $q['component'],
                        'page_key' => $this->page_key($profile_id),
                        'fp' => $q['fp'],
                    );
                }
            }
        }

        foreach ($this->digests as $key => $d) {
            if (!isset($owners[$key])) {
                continue; // other traffic on this database server, not ours to report
            }
            if ($d['examined'] < $min_examined) {
                continue;
            }
            $sent = max((int) $d['sent'], 1);
            $ratio = $d['examined'] / $sent;
            if ($ratio < $ratio_threshold) {
                continue;
            }
            $owner = $owners[$key];
            if ($this->skip_component($owner['component'])) {
                continue;
            }
            $this->add(
                $ratio >= $ratio_threshold * 10 ? 'critical' : 'warn',
                'over_examining_query',
                $owner['component'],
                $owner['page_key'],
                array(
                    'fp' => $owner['fp'],
                    'sql' => $owner['fp'],
                    'examined' => (int) $d['examined'],
                    'sent' => (int) $d['sent'],
                    'ratio' => round($ratio),
                    'count' => (int) $d['calls'],
                    'ms' => (float) $d['ms'],
                    'no_index' => (int) $d['no_index'],
                    'tmp_disk' => (int) $d['tmp_disk'],
                ),
                'over_examining_query'
            );
        }
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
                    'via' => isset($q['via']) ? $q['via'] : null,
                    'page_key' => $this->page_key($profile_id),
                );
            }
        }
        foreach ($seen as $f) {
            $shape = self::classify_query_shape($f['sql']);
            $plan = $this->plan_for($f['fp']);
            if ($plan !== null) {
                $this->flagged_slow[md5($f['fp'])] = true;
            }
            $this->add(
                $f['ms'] >= $threshold * 5 ? 'critical' : 'warn',
                'slow_query',
                $f['component'],
                $f['page_key'],
                $f + array('shape' => $shape, 'plan_note' => SSPA_Explain::summarise($plan)),
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
                    'fp' => $q['fp'],
                    'caller' => $q['caller'],
                    'component' => $q['component'],
                    'via' => isset($q['via']) ? $q['via'] : null,
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
                $f + array('plan_note' => SSPA_Explain::summarise($this->plan_for($f['fp']))),
                'big_result_set'
            );
        }
    }

    private function query_hogs() {
        $threshold = (int) SSPA_Rules::threshold('query_hog_count');

        // CALLER mode, deliberately, and regardless of the display setting. This finding is
        // the N+1 detector: a plugin calling wc_get_product() in a loop instead of one
        // aggregate query is the plugin's fault, not WooCommerce's, and code-owner mode
        // would file the whole thing under WooCommerce and let the plugin off.
        $rows = SSPA_Attribution::component_rows($this->run_id, SSPA_Attribution::MODE_CALLER);

        $worst = array();
        foreach ($rows as $r) {
            if ($r['component_type'] !== 'plugin' || (int) $r['query_count'] < $threshold) {
                continue;
            }
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
                array(
                    'query_count' => (int) $r['query_count'],
                    'sql_ms' => (float) $r['sql_ms'],
                    'rows' => (int) $r['rows_returned'],
                    // Which components these queries actually ran INSIDE. Without this the
                    // finding names the looping plugin but cannot say it was looping over
                    // someone else's API, which is the actionable half.
                    'ran_in' => isset($r['ran_in']) ? $r['ran_in'] : array(),
                ),
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

    private function blocking_mail() {
        $threshold = (float) SSPA_Rules::threshold('slow_mail_ms');
        $seen = array();
        foreach ($this->captures as $profile_id => $capture) {
            if (empty($capture['mail']['calls'])) {
                continue;
            }
            foreach ($capture['mail']['calls'] as $call) {
                if ($call['construct_ms'] === null || $call['construct_ms'] < $threshold) {
                    continue;
                }
                $key = $call['component'];
                if (isset($seen[$key]) && $seen[$key]['construct_ms'] >= $call['construct_ms']) {
                    continue;
                }
                $seen[$key] = array(
                    'construct_ms' => $call['construct_ms'],
                    'component' => $call['component'],
                    'page_key' => $this->page_key($profile_id),
                );
            }
        }
        foreach ($seen as $f) {
            $this->add('warn', 'blocking_mail', $f['component'], $f['page_key'], $f, 'blocking_mail');
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
