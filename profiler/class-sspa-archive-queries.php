<?php
defined('ABSPATH') || exit;
// Records the WP_Query instances that Super Speedy Archives could optimise, so its two
// configuration decisions - which columns to carry on the mirror table, and which meta keys to
// materialise as typed columns - can be answered from a profiled run instead of by hand.
//
// WHY QUERY VARS AND NOT THE SQL:
// three of the required fields are absent from the SQL at any length. The stored data TYPE of a
// meta key is not in it (a CAST is what the query asked for, not what is stored); attributing
// each of several postmeta joins to ordering versus filtering is guesswork; and the requested
// ordering intent never appears at all - WooCommerce's orderby=popularity resolves to
// wc_product_meta_lookup.total_sales and the alias is gone by the time the SQL exists.
//
// WHY TWO HOOKS:
// query vars alone are wrong for WooCommerce, which is the case that matters most here. Woo
// applies catalogue ordering at posts_clauses (WC_Query::order_by_popularity_post_clauses and
// friends), not in query vars, and Scalability Pro rewrites ORDER BY at the same hook. So the
// requested form is read at the_posts - genuinely final, unlike any pre_get_posts priority,
// which posts_clauses runs after - and the resolved form is taken from the clauses themselves.
//
// See .docs/2026-08-12-archive-query-profile-contract.md for the full contract.

if (!class_exists('SSPA_Archive_Queries')) {
    class SSPA_Archive_Queries {

        /**
         * Per-request ceiling. A page with a runaway number of loops truncates VISIBLY -
         * `truncated` is reported - rather than silently, which would read as "this page runs
         * few archive queries" when the opposite is true.
         */
        const MAX_QUERIES = 50;

        /** Ceiling on a retained statement, so a pathological query cannot bloat the capture. */
        const MAX_SQL_BYTES = 8192;

        /** Ordering terms an index can never serve, whatever is materialised. */
        private static $unindexable = array('rand', 'relevance', 'post__in', 'post_name__in', 'parent__in');

        private $records = array();
        private $clauses = array();
        private $truncated = false;

        public function arm() {
            // PHP_INT_MAX on both: after WooCommerce (10), after Scalability Pro (999), after
            // anything else that rewrites ORDER BY. We want what the database was actually
            // asked for, not what the theme originally requested.
            add_filter('posts_clauses', array($this, 'note_clauses'), PHP_INT_MAX, 2);
            add_filter('the_posts', array($this, 'record'), PHP_INT_MAX, 2);
        }

        /**
         * Stash the resolved clauses for a qualifying query. Keyed by object hash because the
         * same WP_Query instance reaches the_posts a moment later and there is no other handle
         * that survives between the two filters.
         */
        public function note_clauses($clauses, $query) {
            if (count($this->records) >= self::MAX_QUERIES) {
                return $clauses;
            }
            if (!$this->qualifies($query)) {
                return $clauses;
            }
            $this->clauses[spl_object_hash($query)] = array(
                'orderby' => isset($clauses['orderby']) ? (string) $clauses['orderby'] : '',
                'join' => isset($clauses['join']) ? (string) $clauses['join'] : '',
                'where' => isset($clauses['where']) ? (string) $clauses['where'] : '',
            );
            return $clauses;
        }

        public function record($posts, $query) {
            $hash = spl_object_hash($query);
            $clauses = isset($this->clauses[$hash]) ? $this->clauses[$hash] : null;
            unset($this->clauses[$hash]);

            if (null === $clauses) {
                return $posts;
            }
            if (count($this->records) >= self::MAX_QUERIES) {
                $this->truncated = true;
                return $posts;
            }

            $qv = isset($query->query_vars) ? $query->query_vars : array();
            $request = isset($query->request) ? trim((string) $query->request) : '';
            $aliases = $this->alias_map($clauses['join']);
            $meta_aliases = $this->meta_key_aliases($clauses['join']);

            $orderby_final = $this->parse_orderby($clauses['orderby'], $aliases, $meta_aliases, $qv);

            $unindexable = array();
            foreach ($this->requested_orderby($qv) as $req) {
                if (in_array(strtolower($req['by']), self::$unindexable, true)) {
                    $unindexable[] = $req['by'];
                }
            }

            // no_found_rows means WordPress deliberately did not compute the total. Report null
            // and say so, rather than running a COUNT purely to fill the field - that would
            // change the cost of the very request being measured.
            $found_rows = null;
            if (empty($qv['no_found_rows']) && isset($query->found_posts)) {
                $found_rows = (int) $query->found_posts;
            }

            $this->records[] = array(
                'main' => method_exists($query, 'is_main_query') ? (bool) $query->is_main_query() : false,
                'found_rows' => $found_rows,
                // Always available, unlike found_rows, which WordPress skips computing when
                // no_found_rows is set - as it is on most of its own internal queries. A
                // consumer deciding whether an index is worth building needs SOME size signal
                // for every archive, not only for the ones that asked for a total.
                'rows_returned' => is_array($posts) ? count($posts) : null,
                'posts_per_page' => isset($qv['posts_per_page']) ? (int) $qv['posts_per_page'] : null,
                // Filled in at report() time from the matched SQL entry, so the attribution
                // already computed for the query is reused rather than taking a second
                // backtrace here.
                'ms' => null,
                'component' => null,
                // The statement itself, kept because it is the ONLY way this survives a
                // persistent object cache. WordPress caches WP_Query results, so on a warm run
                // the archive query is answered without touching the database and never
                // appears in the query log at all - but $query->request still holds the exact
                // SQL it would have run, which is what EXPLAIN needs. Without this the whole
                // feature is dead on any site with a persistent object cache, which is most of
                // the sites it is for. Stays in profile_blob, which is never submitted.
                'request' => (strlen($request) <= self::MAX_SQL_BYTES) ? $request : null,
                'sql_index' => $this->find_sql_index($request),
                'post_type' => $this->normalise_post_type($qv),
                'filters' => array(
                    'taxonomy' => $this->taxonomy_filters($query),
                    'relation' => $this->tax_relation($query),
                    'post_status' => $this->normalise_list(isset($qv['post_status']) ? $qv['post_status'] : ''),
                    'meta' => $this->meta_filters($query),
                    'other' => $this->other_filters($clauses['where'], $aliases),
                ),
                'orderby_requested' => $this->requested_orderby($qv),
                'orderby_final' => $orderby_final,
                'orderby_unindexable' => $unindexable,
            );

            return $posts;
        }

        /**
         * Archives can only help a taxonomy-filtered front-end query: the term join is exactly
         * what makes the wp_posts index unusable for the sort, which is the problem the mirror
         * table's composite indexes solve. A query with no term filter already sorts by an
         * index and needs nothing.
         *
         * No cost threshold here on purpose. This is a read of already-parsed query vars, so it
         * is cheap enough to run on everything that qualifies; deciding what is worth an index
         * happens at analysis time, where EXPLAIN is available. Filtering on rows returned
         * would be backwards - the archive this exists to fix scans two million rows to return
         * twelve.
         */
        private function qualifies($query) {
            if (!is_object($query) || !isset($query->query_vars)) {
                return false;
            }
            if (function_exists('is_admin') && is_admin()) {
                return false;
            }
            if (method_exists($query, 'is_tax') && ($query->is_tax() || $query->is_category() || $query->is_tag())) {
                return true;
            }
            if (isset($query->tax_query) && is_object($query->tax_query) && !empty($query->tax_query->queries)) {
                return true;
            }
            $qv = $query->query_vars;
            foreach (array('cat', 'category_name', 'category__in', 'category__and', 'tag', 'tag_id',
                           'tag__in', 'tag__and', 'tag_slug__in', 'tax_query') as $var) {
                if (!empty($qv[$var])) {
                    return true;
                }
            }
            return false;
        }

        // ---------------- ordering ----------------

        /**
         * The requested form, straight from the query vars: the intent, before WooCommerce or
         * Scalability Pro resolved it to columns. WordPress accepts a string, a
         * comma-separated list, or an array keyed by field, so all three are normalised here.
         */
        private function requested_orderby($qv) {
            $orderby = isset($qv['orderby']) ? $qv['orderby'] : '';
            $default_order = (isset($qv['order']) && strtoupper((string) $qv['order']) === 'ASC') ? 'ASC' : 'DESC';
            $meta_key = isset($qv['meta_key']) ? (string) $qv['meta_key'] : null;
            $out = array();

            if (is_array($orderby)) {
                foreach ($orderby as $field => $order) {
                    $out[] = array(
                        'by' => $this->normalise_alias((string) $field),
                        'order' => (strtoupper((string) $order) === 'ASC') ? 'ASC' : 'DESC',
                        'meta_key' => $this->orderby_meta_key((string) $field, $meta_key),
                    );
                }
                return $out;
            }

            $orderby = trim((string) $orderby);
            if ($orderby === '') {
                return $out;
            }
            foreach (preg_split('/[\s,]+/', $orderby) as $field) {
                if ($field === '' || in_array(strtoupper($field), array('ASC', 'DESC'), true)) {
                    continue;
                }
                $out[] = array(
                    'by' => $this->normalise_alias($field),
                    'order' => $default_order,
                    'meta_key' => $this->orderby_meta_key($field, $meta_key),
                );
            }
            return $out;
        }

        /** WordPress's aliases resolved to the column they mean. Unambiguous ones only. */
        private function normalise_alias($field) {
            $map = array(
                'date' => 'post_date',
                'modified' => 'post_modified',
                'title' => 'post_title',
                'name' => 'post_name',
                'author' => 'post_author',
                'type' => 'post_type',
                'parent' => 'post_parent',
            );
            $lower = strtolower($field);
            return isset($map[$lower]) ? $map[$lower] : $field;
        }

        private function orderby_meta_key($field, $meta_key) {
            $lower = strtolower($field);
            if ($lower === 'meta_value' || $lower === 'meta_value_num') {
                return $meta_key;
            }
            return null;
        }

        /**
         * The resolved form, parsed out of the final ORDER BY. Returned in order, because that
         * order IS the column order of the composite index the consumer has to create.
         */
        private function parse_orderby($orderby, $aliases, $meta_aliases, $qv) {
            $orderby = trim((string) $orderby);
            if ($orderby === '') {
                return array();
            }

            $out = array();
            foreach ($this->split_terms($orderby) as $term) {
                $term = trim($term);
                if ($term === '') {
                    continue;
                }

                $order = 'ASC';
                if (preg_match('/\s+(ASC|DESC)\s*$/i', $term, $m)) {
                    $order = strtoupper($m[1]);
                    $term = trim(preg_replace('/\s+(ASC|DESC)\s*$/i', '', $term));
                }

                // A cast is the whole reason typed columns exist: an index cannot serve an
                // ORDER BY on CAST(meta_value AS DECIMAL), however good the index is. Record
                // what the query asked for so it can be checked against what is stored.
                $cast = null;
                if (preg_match('/^CAST\s*\((.+)\s+AS\s+([A-Za-z]+)/is', $term, $m)) {
                    $term = trim($m[1]);
                    $cast = strtoupper($m[2]);
                } elseif (preg_match('/^(.+?)\s*\+\s*0$/s', $term, $m)) {
                    $term = trim($m[1]);
                    $cast = 'NUMERIC';
                }

                if (!preg_match('/`?([A-Za-z0-9_]+)`?\s*\.\s*`?([A-Za-z0-9_]+)`?/', $term, $m)) {
                    $out[] = array(
                        'source' => 'expression',
                        'table' => null,
                        'column' => $term,
                        'meta_key' => null,
                        'order' => $order,
                        'cast' => $cast,
                    );
                    continue;
                }

                $alias = $m[1];
                $column = $m[2];
                $table = isset($aliases[$alias]) ? $aliases[$alias] : $alias;
                $out[] = $this->classify_column($alias, $table, $column, $meta_aliases, $qv, $order, $cast);
            }

            return $out;
        }

        /**
         * Three sources, all first-class. other_table is not an edge case: it is how every
         * WooCommerce catalogue sort works, since price/popularity/rating all resolve to
         * wc_product_meta_lookup columns rather than to postmeta or to wp_posts.
         */
        private function classify_column($alias, $table, $column, $meta_aliases, $qv, $order, $cast) {
            global $wpdb;

            $posts_table = isset($wpdb->posts) ? $wpdb->posts : 'wp_posts';
            $meta_table = isset($wpdb->postmeta) ? $wpdb->postmeta : 'wp_postmeta';

            if ($table === $posts_table) {
                return array(
                    'source' => 'posts_column',
                    'table' => null,
                    'column' => $column,
                    'meta_key' => null,
                    'order' => $order,
                    'cast' => $cast,
                );
            }

            if ($table === $meta_table) {
                $key = isset($meta_aliases[$alias]) ? $meta_aliases[$alias] : null;
                if (null === $key && !empty($qv['meta_key'])) {
                    $key = (string) $qv['meta_key'];
                }
                return array(
                    'source' => 'postmeta',
                    'table' => null,
                    'column' => $column,
                    'meta_key' => $key,
                    'order' => $order,
                    'cast' => $cast,
                );
            }

            return array(
                'source' => 'other_table',
                'table' => $this->strip_prefix($table),
                'column' => $column,
                'meta_key' => null,
                'order' => $order,
                'cast' => $cast,
            );
        }

        /**
         * Split an ORDER BY on its top-level commas only. CAST(x AS DECIMAL(10,2)) contains a
         * comma that is not a term separator, and splitting on it would invent two ordering
         * terms that never existed.
         */
        private function split_terms($sql) {
            $terms = array();
            $depth = 0;
            $buffer = '';
            $len = strlen($sql);
            for ($i = 0; $i < $len; $i++) {
                $char = $sql[$i];
                if ($char === '(') {
                    $depth++;
                } elseif ($char === ')') {
                    $depth--;
                } elseif ($char === ',' && $depth === 0) {
                    $terms[] = $buffer;
                    $buffer = '';
                    continue;
                }
                $buffer .= $char;
            }
            if (trim($buffer) !== '') {
                $terms[] = $buffer;
            }
            return $terms;
        }

        // ---------------- joins and aliases ----------------

        /** alias => real table name, read off the JOIN clauses. */
        private function alias_map($join) {
            $aliases = array();
            if (preg_match_all('/JOIN\s+`?([A-Za-z0-9_]+)`?(?:\s+AS)?\s+`?([A-Za-z0-9_]+)`?\s+ON/i', $join, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $aliases[$m[2]] = $m[1];
                }
            }
            // A join with no alias at all: the table name is its own alias.
            if (preg_match_all('/JOIN\s+`?([A-Za-z0-9_]+)`?\s+ON/i', $join, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    if (!isset($aliases[$m[1]])) {
                        $aliases[$m[1]] = $m[1];
                    }
                }
            }
            return $aliases;
        }

        /**
         * alias => meta key. WP_Meta_Query writes the key as a literal into the join's ON
         * clause, which is the only place the alias and the key appear together - and knowing
         * which alias carries which key is what separates an ordering key from a filter key.
         */
        private function meta_key_aliases($join) {
            $keys = array();
            if (preg_match_all('/`?([A-Za-z0-9_]+)`?\.meta_key\s*=\s*\'([^\']+)\'/i', $join, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $keys[$m[1]] = $m[2];
                }
            }
            return $keys;
        }

        private function strip_prefix($table) {
            global $wpdb;
            $prefix = isset($wpdb->prefix) ? $wpdb->prefix : '';
            if ($prefix !== '' && strpos($table, $prefix) === 0) {
                return substr($table, strlen($prefix));
            }
            return $table;
        }

        // ---------------- filters ----------------

        private function taxonomy_filters($query) {
            $out = array();
            if (!isset($query->tax_query) || !is_object($query->tax_query) || empty($query->tax_query->queries)) {
                return $out;
            }
            foreach ($this->flatten_clauses($query->tax_query->queries) as $clause) {
                if (empty($clause['taxonomy'])) {
                    continue;
                }
                $out[] = array(
                    'taxonomy' => (string) $clause['taxonomy'],
                    'field' => isset($clause['field']) ? (string) $clause['field'] : 'term_id',
                    'terms' => isset($clause['terms']) ? array_values((array) $clause['terms']) : array(),
                    'operator' => isset($clause['operator']) ? strtoupper((string) $clause['operator']) : 'IN',
                );
            }
            return $out;
        }

        /**
         * An OR relation is reported rather than glossed: Archives builds it with LEFT JOINs
         * and skips the column rewrite entirely, because projecting wp_posts columns onto a
         * NULL alias silently drops rows. The consumer needs to decline, not to be promised a
         * speed-up that will not arrive.
         */
        private function tax_relation($query) {
            if (isset($query->tax_query) && is_object($query->tax_query) && !empty($query->tax_query->relation)) {
                $count = count($this->flatten_clauses($query->tax_query->queries));
                return ($count > 1) ? strtoupper((string) $query->tax_query->relation) : null;
            }
            return null;
        }

        private function meta_filters($query) {
            $out = array();
            if (!isset($query->meta_query) || !is_object($query->meta_query) || empty($query->meta_query->queries)) {
                return $out;
            }
            foreach ($this->flatten_clauses($query->meta_query->queries) as $clause) {
                if (empty($clause['key'])) {
                    continue;
                }
                $out[] = array(
                    'key' => (string) $clause['key'],
                    'compare' => isset($clause['compare']) ? strtoupper((string) $clause['compare']) : '=',
                    'type' => isset($clause['type']) ? strtoupper((string) $clause['type']) : null,
                    'source' => 'postmeta',
                );
            }
            return $out;
        }

        /**
         * Columns of a joined third table that the WHERE filters on - a Woo price filter on
         * wc_product_meta_lookup, say. These belong to the index PREFIX just as much as the
         * taxonomy columns do, so a composite built without them would be the wrong index.
         */
        private function other_filters($where, $aliases) {
            global $wpdb;

            $out = array();
            $seen = array();
            // The taxonomy tables are excluded for the same reason wp_posts and wp_postmeta
            // are: they are not a joined THIRD table whose column belongs in an index prefix.
            // term_relationships is the table the mirror table exists to replace, so recording
            // its term_taxonomy_id as a filtered column would propose a composite carrying the
            // very column the optimisation removes.
            $skip = array(
                isset($wpdb->posts) ? $wpdb->posts : 'wp_posts',
                isset($wpdb->postmeta) ? $wpdb->postmeta : 'wp_postmeta',
                isset($wpdb->term_relationships) ? $wpdb->term_relationships : 'wp_term_relationships',
                isset($wpdb->term_taxonomy) ? $wpdb->term_taxonomy : 'wp_term_taxonomy',
                isset($wpdb->terms) ? $wpdb->terms : 'wp_terms',
            );

            // Every alias.column reference, without trying to pair it with its comparison
            // operator. Which columns are filtered is what decides the index prefix; the
            // operator does not, and reading it reliably is not worth the ambiguity - a column
            // can sit on either side of its operator (Woo's price filter emits
            // `%f < min_price`), and matching operator words invites the regex to find `IN`
            // inside `min_price`.
            if (!preg_match_all('/`?([A-Za-z0-9_]+)`?\s*\.\s*`?([A-Za-z0-9_]+)`?\b/', $where, $matches, PREG_SET_ORDER)) {
                return $out;
            }
            foreach ($matches as $m) {
                $alias = $m[1];
                // An alias with no JOIN behind it is something else entirely - a subquery, or a
                // bare column name the regex over-matched. Recording it as a table would invent
                // an index prefix that does not exist.
                if (!isset($aliases[$alias])) {
                    continue;
                }
                $table = $aliases[$alias];
                if (in_array($table, $skip, true)) {
                    continue;
                }
                $key = $table . '.' . $m[2];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $out[] = array(
                    'table' => $this->strip_prefix($table),
                    'column' => $m[2],
                );
            }
            return $out;
        }

        /** tax_query and meta_query nest arbitrarily; only the leaf clauses carry a key. */
        private function flatten_clauses($queries) {
            $out = array();
            foreach ((array) $queries as $key => $clause) {
                if ($key === 'relation' || !is_array($clause)) {
                    continue;
                }
                if (isset($clause['taxonomy']) || isset($clause['key'])) {
                    $out[] = $clause;
                    continue;
                }
                foreach ($this->flatten_clauses($clause) as $nested) {
                    $out[] = $nested;
                }
            }
            return $out;
        }

        private function normalise_post_type($qv) {
            $type = isset($qv['post_type']) ? $qv['post_type'] : '';
            return $this->normalise_list($type);
        }

        private function normalise_list($value) {
            if (is_array($value)) {
                return array_values(array_map('strval', $value));
            }
            $value = trim((string) $value);
            if ($value === '') {
                return array();
            }
            return array_values(array_filter(array_map('trim', explode(',', $value))));
        }

        // ---------------- SQL attribution ----------------

        /**
         * WP_Query keeps the exact SQL it ran in $query->request, and the profiling wpdb logs
         * every statement verbatim, so the two can be matched exactly. No heuristics, no
         * bracketing by log position, no guessing from fingerprints. Searched newest-first
         * because the query we want has just run.
         *
         * Returns null when there is no match, which is the honest answer for a query
         * short-circuited by posts_pre_query (Archives itself does this) - it never reached the
         * database, so it has no cost to attribute.
         */
        private function find_sql_index($request) {
            global $wpdb;

            $request = trim($request);
            if ($request === '' || !isset($wpdb)) {
                return null;
            }

            if (isset($wpdb->sspa_log) && is_array($wpdb->sspa_log)) {
                for ($i = count($wpdb->sspa_log) - 1; $i >= 0; $i--) {
                    if (isset($wpdb->sspa_log[$i]['sql']) && trim($wpdb->sspa_log[$i]['sql']) === $request) {
                        return $i;
                    }
                }
                return null;
            }

            if (!empty($wpdb->queries) && is_array($wpdb->queries)) {
                $index = 0;
                $found = null;
                foreach ($wpdb->queries as $q) {
                    if (!isset($q[0], $q[1], $q[2])) {
                        continue;
                    }
                    if (trim((string) $q[0]) === $request) {
                        $found = $index;
                    }
                    $index++;
                }
                return $found;
            }

            return null;
        }

        /**
         * Called at shutdown with the assembled sql section, so each record can borrow the cost
         * and component attribution already computed for the statement it matched.
         */
        public function report($sql) {
            $queries = (isset($sql['queries']) && is_array($sql['queries'])) ? $sql['queries'] : array();

            foreach ($this->records as $i => $record) {
                $index = $record['sql_index'];
                if (null !== $index && isset($queries[$index])) {
                    $this->records[$i]['ms'] = $queries[$index]['ms'];
                    $this->records[$i]['component'] = $queries[$index]['component'];
                    $this->records[$i]['fp'] = $queries[$index]['fp'];
                    $this->records[$i]['cached'] = false;
                } else {
                    // No matching statement in the log, but the query still returned posts:
                    // WordPress answered it from the object cache. Worth recording as a fact
                    // rather than leaving as an unexplained null - it means this archive costs
                    // nothing on a warm hit and everything on a cold one, and it is why `ms`
                    // is absent here.
                    $this->records[$i]['cached'] = ('' !== (string) $record['request']);
                }
                unset($this->records[$i]['sql_index']);
            }

            return array(
                'captured' => count($this->records),
                'truncated' => $this->truncated,
                'cap' => self::MAX_QUERIES,
                'queries' => array_values($this->records),
            );
        }
    }
}
