<?php
defined('ABSPATH') || exit;

/**
 * Turns the per-request `archives` captures into one answer per site: which composite indexes
 * the archives on this site would actually use, and which columns have to exist before those
 * indexes can be built.
 *
 * This is the surface Super Speedy Archives and Scalability Pro consume to configure the
 * mirror table without a human filling in the settings tab by hand.
 *
 * WHY A COMPOSITE AND NOT JUST A COLUMN: wp_posts is already indexed on post_date, but the
 * moment a query joins term_relationships to filter by term, that index stops being usable for
 * the sort - the filter and the sort live in different tables, so MySQL filters and then
 * filesorts. The mirror table's value is carrying the term columns AND the ordering column in
 * one index, which is why the ordering tuple is recorded in order and with per-column
 * direction: that order is the index's column order.
 *
 * See .docs/2026-08-12-archive-query-profile-contract.md.
 */
class SSPA_Archive_Profile {

    /** Report-side schema. Independent of SSPA_Capture::SCHEMA_VERSION. */
    const SCHEMA = 1;

    /** An archive query this slow is worth an index whatever it returned. */
    const SLOW_MS = 50;

    /** Optimiser row estimate above which a scan is worth removing. */
    const EST_ROWS = 1000;

    /**
     * Size floor below which no index is worth building, measured as the larger of the rows
     * the archive holds and the rows the optimiser expects to examine.
     *
     * Rows are a poor COST signal - an archive that examines two million rows to return twelve
     * is the case this whole feature exists for - but they are the right signal for "is this
     * worth an index at all". WordPress runs its own taxonomy-filtered queries on every front
     * end request (wp_global_styles, wp_template_part, both scoped by the wp_theme taxonomy),
     * and those hold one row and filesort like everything else. Without a size floor they
     * dominate the recommendations: 18 of 20 on a real run.
     */
    const MIN_INDEXABLE_ROWS = 200;

    /**
     * Instruments, not pages. `baseline` deliberately writes no capture at all - the mu-loader
     * short-circuits it so it can measure the server's noise floor - so counting it as a page
     * that failed made `complete` false on every full run, which tells every consumer to refuse
     * the data. Spot runs scoped to real page keys hid this.
     */
    private static $probe_page_keys = array('baseline', 'mail-probe');

    public static function build($run_id) {
        global $wpdb;

        $run_id = (int) $run_id;
        if (!$run_id) {
            return new WP_Error('sspa_no_run', __('No run id given.', 'super-speedy-performance-analysis'));
        }

        $cache_key = 'sspa_archive_profile_' . $run_id;
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $profiles = $wpdb->get_results($wpdb->prepare(
            'SELECT id, page_key, variant, response_code, profile_blob
             FROM ' . SSPA_Schema::table('profiles') . ' WHERE run_id = %d',
            $run_id
        ), ARRAY_A);

        if (!$profiles) {
            return new WP_Error('sspa_no_profiles', __('That run profiled no pages.', 'super-speedy-performance-analysis'));
        }

        $archives = array();
        $unsupported = array();
        $needed = array('postmeta' => array(), 'other_table' => array());
        $posts_columns = array();
        $composites = array();
        $pages_seen = 0;
        $complete = true;
        $predates = 0;

        foreach ($profiles as $profile) {
            if (self::is_probe($profile['page_key'])) {
                continue;
            }

            // A page that failed or was blocked has not proved that its archive needs nothing.
            // It has proved nothing about it at all, which is a different answer entirely.
            if ((int) $profile['response_code'] !== 200) {
                $complete = false;
                continue;
            }

            $capture = self::decode($profile['profile_blob']);
            if (!is_array($capture)) {
                $complete = false;
                continue;
            }

            // A capture written before the contract existed has no `archives` key. That is
            // "unknown", never "this site has no archives" - the two are indistinguishable
            // from the payload, and treating one as the other would silently under-report.
            if (!array_key_exists('archives', $capture) || null === $capture['archives']) {
                $predates++;
                $complete = false;
                continue;
            }

            $pages_seen++;
            $section = $capture['archives'];
            if (!empty($section['truncated'])) {
                $complete = false;
            }

            $sql_queries = (isset($capture['sql']['queries']) && is_array($capture['sql']['queries']))
                ? $capture['sql']['queries']
                : array();

            foreach ((isset($section['queries']) ? (array) $section['queries'] : array()) as $record) {
                $entry = self::entry($profile['page_key'], $record, $sql_queries);

                $skip = self::unsupported_reason($record, $capture);
                if (null !== $skip) {
                    $unsupported[] = array('page_key' => $profile['page_key'], 'reason' => $skip);
                    continue;
                }

                // Every archive that ran is reported, with its size and its time, because the
                // consumer is the one entitled to decide what it optimises for. Only the ones
                // that pass both the size and the cost bar feed the recommendations below.
                $entry['qualifies'] = self::qualifies($entry);
                $entry['worth_indexing'] = self::worth_indexing($entry);
                $archives[] = $entry;

                if (!$entry['worth_indexing']) {
                    continue;
                }

                self::collect_needed($record, $needed, $posts_columns);
                self::collect_composite($record, $entry, $composites);
            }
        }

        $types = SSPA_Archive_Types::for_run($run_id, $needed);

        $largest = 0;
        $indexable = 0;
        foreach ($archives as $entry) {
            $largest = max($largest, (int) $entry['found_rows']);
            if ($entry['worth_indexing']) {
                $indexable++;
            }
        }

        $out = array(
            'run_id' => $run_id,
            'schema' => self::SCHEMA,
            'complete' => $complete,
            'pages_seen' => $pages_seen,
            'predates_contract' => $predates,
            'archives' => $archives,
            'archives_worth_indexing' => $indexable,
            'candidate_composites' => array_values($composites),
            'materialise' => self::materialise($types, $posts_columns),
            'largest_archive_rows' => $largest,
            'thresholds' => array('min_rows' => self::MIN_INDEXABLE_ROWS, 'slow_ms' => self::SLOW_MS),
            'unsupported' => $unsupported,
        );

        set_transient($cache_key, $out, 12 * HOUR_IN_SECONDS);
        return $out;
    }

    // ---------------- per-query ----------------

    private static function entry($page_key, $record, $sql_queries) {
        return array(
            'page_key' => $page_key,
            'main' => !empty($record['main']),
            'post_type' => isset($record['post_type']) ? $record['post_type'] : array(),
            'taxonomy' => self::taxonomies($record, true),
            'taxonomy_excluded' => self::taxonomies($record, false),
            'found_rows' => isset($record['found_rows']) ? $record['found_rows'] : null,
            'rows_returned' => isset($record['rows_returned']) ? $record['rows_returned'] : null,
            'ms' => isset($record['ms']) ? $record['ms'] : null,
            // Served from the object cache, so it cost nothing on this hit. The plan below is
            // still real - it is what the database WOULD do on a cold hit, which is what a
            // growing catalogue eventually gets.
            'cached' => !empty($record['cached']),
            'explain' => self::explain_for($record, $sql_queries),
            'orderby' => isset($record['orderby_final']) ? $record['orderby_final'] : array(),
            'orderby_requested' => isset($record['orderby_requested']) ? $record['orderby_requested'] : array(),
        );
    }

    /**
     * Narrowing clauses and excluding clauses are not interchangeable.
     *
     * `IN` narrows to a term and belongs in the index prefix. `NOT IN` excludes, and Archives
     * implements it as a NOT EXISTS subquery against the mirror table rather than as a leading
     * index column - so putting it in the prefix would describe an index that helps nothing.
     * WooCommerce adds a product_visibility NOT IN clause to EVERY product query, so without
     * this split every Woo composite would be prefixed on the one taxonomy that never narrows.
     *
     * @param bool $narrowing true for the clauses that narrow, false for the ones that exclude.
     */
    private static function taxonomies($record, $narrowing) {
        $excluding = array('NOT IN', 'NOT EXISTS');
        $out = array();
        if (empty($record['filters']['taxonomy'])) {
            return $out;
        }
        foreach ($record['filters']['taxonomy'] as $clause) {
            $is_excluding = in_array(strtoupper($clause['operator']), $excluding, true);
            if ($is_excluding === $narrowing) {
                continue;
            }
            $out[] = $clause['taxonomy'];
        }
        return array_values(array_unique($out));
    }

    /**
     * EXPLAIN is the only thing here that can establish "unindexed". Without it the claim is an
     * assumption, so a query whose full SQL was not retained reports a null plan rather than a
     * guess. Fingerprints have their literals stripped, and explaining a re-bound invention
     * would produce a plan for a query the site never ran.
     */
    private static function explain_for($record, $sql_queries) {
        // The statement WP_Query built, whether or not it reached the database. On a site with
        // a persistent object cache a warm archive is answered from cache and never appears in
        // the query log, so matching by fingerprint would find nothing and the plan - the only
        // evidence that can establish "unindexed" - would be permanently unavailable on exactly
        // the sites that need this most.
        $sql = isset($record['request']) ? (string) $record['request'] : '';
        if ('' === $sql) {
            foreach ($sql_queries as $q) {
                if (isset($q['fp'], $record['fp']) && $q['fp'] === $record['fp'] && !empty($q['sql'])) {
                    $sql = $q['sql'];
                    break;
                }
            }
        }

        if ('' === $sql || !SSPA_Explain::is_explainable($sql)) {
            return null;
        }
        $plan = SSPA_Explain::explain($sql);
        if (!is_array($plan)) {
            return null;
        }
        return array(
            'scan' => !empty($plan['scan']),
            'filesort' => !empty($plan['filesort']),
            'key' => isset($plan['key']) ? $plan['key'] : null,
            'est_rows' => isset($plan['est_rows']) ? (int) $plan['est_rows'] : null,
            'est_rows_total' => isset($plan['est_rows_total']) ? (int) $plan['est_rows_total'] : null,
        );
    }

    /**
     * Cost examined, never rows returned. The archive this work exists to fix scans two million
     * rows in the term join to return twelve, so a result-size threshold would discard exactly
     * the queries worth fixing. found_rows is recorded but is a GROWTH signal - a large archive
     * that is currently fast will not stay fast - and is deliberately not a qualifier here.
     */
    private static function qualifies($entry) {
        $reasons = array();

        if (null !== $entry['ms'] && $entry['ms'] >= self::SLOW_MS) {
            $reasons[] = 'ms';
        }
        if (!empty($entry['explain']['filesort'])) {
            $reasons[] = 'filesort';
        }
        if (!empty($entry['explain']['scan'])) {
            $reasons[] = 'scan';
        }
        if (!empty($entry['explain']['est_rows']) && $entry['explain']['est_rows'] >= self::EST_ROWS) {
            $reasons[] = 'est_rows';
        }

        return $reasons;
    }

    /**
     * Whether an index is worth building for this archive: it has to be big enough to matter
     * AND have something wrong with its plan. Both, not either.
     *
     * The size half is what keeps WordPress's own one-row taxonomy queries out of the
     * recommendations without naming them - no hardcoded list of post types to fall out of
     * date, and a site whose wp_template_part table somehow grew to 50,000 rows would be
     * reported like anything else that size.
     */
    private static function worth_indexing($entry) {
        return self::size_of($entry) >= self::MIN_INDEXABLE_ROWS && (bool) $entry['qualifies'];
    }

    /**
     * The larger of what the archive holds and what the optimiser expects to examine. A query
     * that examines two million rows to return twelve is large by the measure that matters,
     * and found_rows is null whenever no_found_rows was set, so neither alone is enough.
     */
    private static function size_of($entry) {
        return max(
            (int) $entry['found_rows'],
            (int) $entry['rows_returned'],
            isset($entry['explain']['est_rows']) ? (int) $entry['explain']['est_rows'] : 0
        );
    }

    private static function is_probe($page_key) {
        return in_array($page_key, self::$probe_page_keys, true) || 0 === strpos($page_key, 'write-');
    }

    private static function unsupported_reason($record, $capture) {
        if (!empty($record['orderby_unindexable'])) {
            return 'orderby_' . strtolower($record['orderby_unindexable'][0]);
        }
        // An OR relation is built with LEFT JOINs, and Archives skips the column rewrite
        // entirely for those, because projecting wp_posts columns onto a NULL alias silently
        // drops rows. Declining is the honest answer.
        if (!empty($record['filters']['relation']) && 'OR' === $record['filters']['relation']) {
            return 'tax_relation_or';
        }
        if (!empty($capture['conditionals']) && in_array('is_search', (array) $capture['conditionals'], true)) {
            return 'search';
        }
        if (empty($record['orderby_final'])) {
            return 'no_ordering';
        }
        return null;
    }

    // ---------------- aggregation ----------------

    private static function collect_needed($record, &$needed, &$posts_columns) {
        foreach ((array) $record['orderby_final'] as $term) {
            self::note_source($term, 'order', $needed, $posts_columns);
        }
        foreach ((array) $record['filters']['meta'] as $filter) {
            if (!empty($filter['key'])) {
                self::add_role($needed['postmeta'], $filter['key'], 'filter');
            }
        }
        foreach ((array) $record['filters']['other'] as $filter) {
            if (!empty($filter['table']) && !empty($filter['column'])) {
                if (!isset($needed['other_table'][$filter['table']])) {
                    $needed['other_table'][$filter['table']] = array();
                }
                self::add_role($needed['other_table'][$filter['table']], $filter['column'], 'filter');
            }
        }
    }

    private static function note_source($term, $role, &$needed, &$posts_columns) {
        if (!isset($term['source'])) {
            return;
        }
        if ('posts_column' === $term['source']) {
            $posts_columns[$term['column']] = true;
            return;
        }
        if ('postmeta' === $term['source'] && !empty($term['meta_key'])) {
            self::add_role($needed['postmeta'], $term['meta_key'], $role);
            return;
        }
        if ('other_table' === $term['source'] && !empty($term['table'])) {
            if (!isset($needed['other_table'][$term['table']])) {
                $needed['other_table'][$term['table']] = array();
            }
            self::add_role($needed['other_table'][$term['table']], $term['column'], $role);
        }
    }

    private static function add_role(&$bucket, $key, $role) {
        if (!isset($bucket[$key])) {
            $bucket[$key] = array();
        }
        if (!in_array($role, $bucket[$key], true)) {
            $bucket[$key][] = $role;
        }
    }

    /**
     * The index Archives would create: the filter columns that form the prefix, then the
     * ordering columns in order, each with its direction. MySQL 8 serves a mixed ASC/DESC tuple
     * only from an index declared with matching directions, so the direction is part of the
     * definition rather than a detail.
     */
    private static function collect_composite($record, $entry, &$composites) {
        $columns = array('post_type', 'post_status');

        // Only a narrowing taxonomy clause earns the term columns in the prefix. See
        // taxonomies() - a NOT IN exclusion is served by a NOT EXISTS subquery instead.
        if ($entry['taxonomy']) {
            $columns[] = 'taxonomy';
            $columns[] = 'topterm_id';
        }
        foreach ((array) $record['filters']['other'] as $filter) {
            $columns[] = $filter['column'];
        }

        foreach ((array) $record['orderby_final'] as $term) {
            $column = self::mirror_column($term);
            if (null === $column) {
                continue;
            }
            $columns[] = $column . ' ' . $term['order'];
        }

        if (count($columns) <= 2) {
            return;
        }

        $key = implode('|', $columns);
        if (!isset($composites[$key])) {
            $composites[$key] = array(
                'columns' => $columns,
                // Which taxonomies this index actually serves. Empty means it carries no term
                // columns at all, and the two must agree: term columns without a narrowing
                // taxonomy would be an index built on a filter that never narrows.
                'taxonomy' => array(),
                'seen_on' => array(),
                'max_rows' => 0,
            );
        }
        foreach ($entry['taxonomy'] as $taxonomy) {
            if (!in_array($taxonomy, $composites[$key]['taxonomy'], true)) {
                $composites[$key]['taxonomy'][] = $taxonomy;
            }
        }
        if (!in_array($entry['page_key'], $composites[$key]['seen_on'], true)) {
            $composites[$key]['seen_on'][] = $entry['page_key'];
        }
        $composites[$key]['max_rows'] = max($composites[$key]['max_rows'], (int) $entry['found_rows']);
    }

    /**
     * The column's name ON THE MIRROR TABLE, which is what the index will be built against.
     * Meta keys follow Archives' own naming so the two agree without the consumer translating.
     */
    private static function mirror_column($term) {
        if ('posts_column' === $term['source']) {
            return $term['column'];
        }
        if ('postmeta' === $term['source'] && !empty($term['meta_key'])) {
            return 'meta_' . sanitize_key($term['meta_key']);
        }
        if ('other_table' === $term['source']) {
            return $term['column'];
        }
        return null;
    }

    private static function materialise($types, $posts_columns) {
        $out = array(
            'postmeta' => array(),
            'other_table' => array(),
            'posts_columns' => array_keys($posts_columns),
        );

        foreach ($types as $key => $verdict) {
            if (0 === strpos($key, 'postmeta:')) {
                $out['postmeta'][substr($key, strlen('postmeta:'))] = $verdict;
                continue;
            }
            $out['other_table'][$key] = $verdict;
        }

        return $out;
    }

    private static function decode($blob) {
        if (empty($blob)) {
            return null;
        }
        $json = @gzuncompress($blob);
        if (false === $json) {
            $json = $blob;
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }
}
