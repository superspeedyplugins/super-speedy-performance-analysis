<?php
defined('ABSPATH') || exit;

/**
 * Per-component aggregates for a run, under either attribution mode.
 *
 * CODE_OWNER reads sspa_component_stats directly - that table is written from the
 * code-owner resolution at capture time and is the fast path.
 *
 * CALLER is RECOMPUTED on demand from each profile's stored capture blob, using the
 * "type:component" chain recorded per query and HTTP call. Deliberately not a second set of
 * rows in component_stats: six places read that table, and any one of them forgetting to
 * filter by mode would silently double every total. Recomputing costs one gzuncompress per
 * profile and cannot corrupt the stored numbers.
 *
 * @see SSPA_Component_Map for what the two modes mean and why code-owner is the default.
 */
class SSPA_Attribution {

    const MODE_CODE_OWNER = 'code_owner';
    const MODE_CALLER = 'caller';

    public static function modes() {
        return array(
            self::MODE_CODE_OWNER => __('Code owner', 'super-speedy-performance-analysis'),
            self::MODE_CALLER => __('Caller', 'super-speedy-performance-analysis'),
        );
    }

    public static function sanitise_mode($mode) {
        return ($mode === self::MODE_CALLER) ? self::MODE_CALLER : self::MODE_CODE_OWNER;
    }

    public static function describe($mode) {
        return ($mode === self::MODE_CALLER)
            ? __('Cost is charged to whatever ASKED for the work. A plugin calling a WooCommerce function in a loop is charged for it, rather than WooCommerce.', 'super-speedy-performance-analysis')
            : __('Cost is charged to the component whose code actually RAN. Work a plugin triggered inside WooCommerce is charged to WooCommerce.', 'super-speedy-performance-analysis');
    }

    /**
     * One row per component per profile, shaped like the component_stats columns the
     * callers already use, plus page_key.
     *
     * @return array of {profile_id, page_key, component, component_type, query_count,
     *                   sql_ms, rows_returned, slowest_query_ms, http_ms}
     */
    public static function component_rows($run_id, $mode) {
        global $wpdb;
        $run_id = (int) $run_id;

        if (self::sanitise_mode($mode) === self::MODE_CODE_OWNER) {
            $rows = $wpdb->get_results($wpdb->prepare(
                'SELECT cs.profile_id, p.page_key, cs.component, cs.component_type,
                        cs.query_count, cs.sql_ms, cs.rows_returned, cs.slowest_query_ms, cs.http_ms
                 FROM ' . SSPA_Schema::table('component_stats') . ' cs
                 JOIN ' . SSPA_Schema::table('profiles') . ' p ON p.id = cs.profile_id
                 WHERE cs.run_id = %d',
                $run_id
            ), ARRAY_A);
            foreach ($rows as &$row) {
                $row['ran_in'] = array(); // in code-owner mode the component IS the executor
            }
            unset($row);
            return $rows;
        }

        $rows = array();
        foreach ($wpdb->get_results($wpdb->prepare(
            'SELECT id, page_key, profile_blob FROM ' . SSPA_Schema::table('profiles') . '
             WHERE run_id = %d AND profile_blob IS NOT NULL',
            $run_id
        ), ARRAY_A) as $profile) {
            $capture = self::unpack($profile['profile_blob']);
            if ($capture === null) {
                continue;
            }
            foreach (self::aggregate_caller($capture) as $component => $agg) {
                $rows[] = array(
                    'profile_id' => (int) $profile['id'],
                    'page_key' => $profile['page_key'],
                    'component' => $component,
                    'component_type' => $agg['type'],
                    'query_count' => $agg['query_count'],
                    'sql_ms' => $agg['sql_ms'],
                    'rows_returned' => $agg['rows'],
                    'slowest_query_ms' => $agg['slowest_ms'],
                    'http_ms' => $agg['http_ms'],
                    'ran_in' => self::top_executors($agg['ran_in']),
                );
            }
        }
        return $rows;
    }

    /**
     * Caller-mode aggregates for ONE already-decoded capture.
     *
     * The profile panel shows both modes for a single page and has the capture in hand, so it
     * must not go back to the database for the second table. Same computation as
     * component_rows() performs per profile, exposed for that one caller.
     *
     * @return array component => {type, query_count, sql_ms, rows, slowest_ms, http_ms, ran_in}
     */
    public static function caller_aggregate($capture) {
        return is_array($capture) ? self::aggregate_caller($capture) : array();
    }

    private static function unpack($blob) {
        if (empty($blob)) {
            return null;
        }
        $json = @gzuncompress($blob);
        if ($json === false) {
            return null;
        }
        $capture = json_decode($json, true);
        return is_array($capture) ? $capture : null;
    }

    /**
     * Re-attribute one capture's queries and HTTP calls to their CALLER.
     *
     * A stored entry's `chain` is only present when it has two or more links, i.e. only when
     * the two modes can disagree. Absent chain means both modes agree, so the stored
     * component stands. Where a chain IS present, link 1 is the caller - including for
     * vendored code, where the stored component already IS the caller and link 1 repeats it.
     */
    private static function aggregate_caller($capture) {
        $agg = array();

        $bump = function (&$agg, $key, $type) {
            if (!isset($agg[$key])) {
                $agg[$key] = array('type' => $type, 'query_count' => 0, 'sql_ms' => 0.0,
                                   'rows' => 0, 'slowest_ms' => 0.0, 'http_ms' => 0.0,
                                   'ran_in' => array());
            }
        };

        if (!empty($capture['sql']['queries'])) {
            foreach ($capture['sql']['queries'] as $q) {
                list($component, $type) = self::caller_of($q, isset($q['ctype']) ? $q['ctype'] : 'plugin');
                $bump($agg, $component, $type);
                $executor = self::executor_of($q);
                if ($executor !== null && $executor !== $component) {
                    $agg[$component]['ran_in'][$executor] = isset($agg[$component]['ran_in'][$executor])
                        ? $agg[$component]['ran_in'][$executor] + 1 : 1;
                }
                $agg[$component]['query_count']++;
                $agg[$component]['sql_ms'] += (float) $q['ms'];
                $agg[$component]['rows'] += (int) $q['rows'];
                $agg[$component]['slowest_ms'] = max($agg[$component]['slowest_ms'], (float) $q['ms']);
            }
        }

        if (!empty($capture['http']['calls'])) {
            foreach ($capture['http']['calls'] as $call) {
                list($component, $type) = self::caller_of($call, isset($call['ctype']) ? $call['ctype'] : 'plugin');
                $bump($agg, $component, $type);
                $agg[$component]['http_ms'] += (float) $call['ms'];
            }
        }

        foreach ($agg as &$a) {
            $a['sql_ms'] = round($a['sql_ms'], 3);
            $a['http_ms'] = round($a['http_ms'], 1);
            $a['slowest_ms'] = round($a['slowest_ms'], 3);
        }
        return $agg;
    }

    /**
     * Where the query actually RAN, when that differs from who is being charged. This is the
     * other half of the sentence: "plugin-b ran 70 queries" is far less useful than
     * "plugin-b ran 70 queries, 70 of them inside woocommerce".
     *
     * @return string|null
     */
    private static function executor_of($entry) {
        if (!empty($entry['chain']) && is_array($entry['chain']) && isset($entry['chain'][0])) {
            $parts = explode(':', $entry['chain'][0], 2);
            if (count($parts) === 2 && $parts[1] !== '') {
                return $parts[1];
            }
        }
        return null;
    }

    /** Busiest executors first, so a finding can name the one that matters. */
    private static function top_executors($ran_in) {
        if (empty($ran_in)) {
            return array();
        }
        arsort($ran_in);
        return array_slice($ran_in, 0, 3, true);
    }

    /** @return array [component, type] */
    private static function caller_of($entry, $fallback_type) {
        if (!empty($entry['chain']) && is_array($entry['chain']) && isset($entry['chain'][1])) {
            $parts = explode(':', $entry['chain'][1], 2);
            if (count($parts) === 2 && $parts[1] !== '') {
                return array($parts[1], $parts[0]);
            }
        }
        return array($entry['component'], $fallback_type);
    }
}
