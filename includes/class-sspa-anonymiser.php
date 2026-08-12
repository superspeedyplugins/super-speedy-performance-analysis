<?php
defined('ABSPATH') || exit;

/**
 * Builds the community submission payload. The anonymisation contract (brainstorm 3.9):
 * no domain or URLs (identity = random install UUID; a salted domain hash exists purely
 * for dedupe), SQL only as normalised fingerprints (never raw - raw SQL can contain
 * emails/order data), plugin slugs + versions, counts bucketed. The Share tab shows this
 * exact payload before anything is sent - trust is the product.
 */
class SSPA_Anonymiser {

    const SCHEMA = 1;

    public static function install_uuid() {
        $uuid = get_option('sspa_install_uuid');
        if (!$uuid) {
            $uuid = wp_generate_uuid4();
            add_option('sspa_install_uuid', $uuid, '', false);
        }
        return $uuid;
    }

    public static function build() {
        global $wpdb;

        $run_id = (int) $wpdb->get_var(
            'SELECT id FROM ' . SSPA_Schema::table('runs') . " WHERE status = 'done' AND run_type IN ('baseline','spot') ORDER BY id DESC LIMIT 1"
        );
        if (!$run_id) {
            return new WP_Error('sspa_no_run', __('Run an analysis first - there is nothing to share yet.', 'super-speedy-performance-analysis'));
        }

        $demo = SSPA_Demographics::latest();
        $m = $demo ? $demo['metrics'] : array();

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all_plugins = get_plugins();
        $plugins = array();
        foreach ((array) get_option('active_plugins', array()) as $file) {
            $slug = dirname($file) !== '.' ? dirname($file) : basename($file, '.php');
            $plugins[] = array(
                'slug' => $slug,
                'version' => isset($all_plugins[$file]['Version']) ? $all_plugins[$file]['Version'] : null,
            );
        }

        $profiles = array();
        foreach ($wpdb->get_results($wpdb->prepare(
            'SELECT page_key, variant, page_gen_ms, sql_ms, sql_count, http_ms, php_ms, peak_mem_bytes,
                    rows_returned_total, dupe_query_count, mail_count, mail_ms, response_code
             FROM ' . SSPA_Schema::table('profiles') . ' WHERE run_id = %d',
            $run_id
        ), ARRAY_A) as $p) {
            $profiles[] = array_map(function ($v) {
                return is_numeric($v) ? $v + 0 : $v;
            }, $p);
        }

        $observations = $wpdb->get_results($wpdb->prepare(
            'SELECT cs.component, cs.component_type, p.page_key, cs.query_count, cs.sql_ms,
                    cs.rows_returned, cs.slowest_query_ms, cs.http_ms
             FROM ' . SSPA_Schema::table('component_stats') . ' cs
             JOIN ' . SSPA_Schema::table('profiles') . ' p ON p.id = cs.profile_id
             WHERE cs.run_id = %d',
            $run_id
        ), ARRAY_A);

        $findings = array();
        foreach ($wpdb->get_results($wpdb->prepare(
            'SELECT finding_type, severity, component, page_key, recommendation_key, confidence, evidence
             FROM ' . SSPA_Schema::table('findings') . ' WHERE run_id = %d',
            $run_id
        ), ARRAY_A) as $f) {
            $evidence = json_decode((string) $f['evidence'], true);
            $safe = array();
            if (is_array($evidence)) {
                // Whitelist: numbers, shape labels, and the normalised fingerprint ONLY.
                foreach (array('ms', 'rows', 'query_count', 'sql_ms', 'count', 'saved_pct', 'queries_on', 'queries_off', 'construct_ms', 'shape', 'autoload_bytes') as $key) {
                    if (isset($evidence[$key])) {
                        $safe[$key] = $evidence[$key];
                    }
                }
                // The excluded plugin of an isolation reaction: without it the shared finding
                // says a plugin reacted but not what to, and the pair is what makes a
                // community dependency map possible. A slug identifies a plugin, not a site,
                // and every active slug is already in this payload.
                if (isset($evidence['excluded']) && preg_match('/^[A-Za-z0-9._-]{1,96}$/', (string) $evidence['excluded'])) {
                    $safe['excluded'] = (string) $evidence['excluded'];
                }
                if (isset($evidence['ops']) && is_array($evidence['ops'])) {
                    $ops = array();
                    foreach ($evidence['ops'] as $op => $count) {
                        if (in_array($op, array('deactivate', 'activate', 'sql', 'unknown'), true) && is_numeric($count)) {
                            $ops[$op] = (int) $count;
                        }
                    }
                    if ($ops) {
                        $safe['ops'] = $ops;
                    }
                }
                if (isset($evidence['fp'])) {
                    $safe['fingerprint'] = $evidence['fp'];
                } elseif (isset($evidence['sql'])) {
                    require_once SSPA_PLUGIN_DIR . 'profiler/fingerprint.php';
                    $safe['fingerprint'] = sspa_sql_fingerprint($evidence['sql']);
                }
            }
            unset($f['evidence']);
            $f['evidence'] = $safe;
            $findings[] = $f;
        }

        $impacts = $wpdb->get_results(
            'SELECT plugin, page_key, method, object_cache_mode, delta_ttfb_ms, delta_sql_ms, delta_http_ms,
                    delta_mem_bytes, delta_queries, noise_floor_ms, confidence
             FROM ' . SSPA_Schema::table('plugin_impacts') . ' ORDER BY id DESC LIMIT 1000',
            ARRAY_A
        );

        $cache_notes = $wpdb->get_var(
            'SELECT notes FROM ' . SSPA_Schema::table('runs') . " WHERE run_type = 'cache_impact' AND status = 'done' ORDER BY id DESC LIMIT 1"
        );
        $cache = array();
        if ($cache_notes) {
            $decoded = json_decode($cache_notes, true);
            if (is_array($decoded) && !empty($decoded['components'])) {
                foreach ($decoded['components'] as $component => $c) {
                    if (isset($c['saved_pct'])) {
                        $cache[] = array('component' => $component, 'saved_pct' => $c['saved_pct'], 'queries_off' => $c['off']);
                    }
                }
            }
        }

        $host = (string) parse_url(home_url(), PHP_URL_HOST);

        return array(
            'schema' => self::SCHEMA,
            'install' => self::install_uuid(),
            'domain_hash' => hash('sha256', 'sspa-dedupe:' . $host),
            'generated_at' => gmdate('c'),
            'site' => array(
                'wp' => isset($m['wp']) ? $m['wp'] : get_bloginfo('version'),
                'php' => isset($m['php']) ? $m['php'] : PHP_VERSION,
                'mysql' => isset($m['mysql']) ? $m['mysql'] : null,
                'object_cache' => !empty($m['object_cache']),
                'sector' => $demo ? $demo['sector'] : null,
                'theme' => isset($m['theme']) ? $m['theme'] : null,
                'sizes' => array(
                    'posts' => self::bucket(isset($m['post_counts']['post']) ? $m['post_counts']['post'] : 0),
                    'products' => self::bucket(isset($m['post_counts']['product']) ? $m['post_counts']['product'] : 0),
                    'postmeta' => self::bucket(isset($m['postmeta_rows']) ? $m['postmeta_rows'] : 0),
                    'users' => self::bucket(isset($m['users']) ? $m['users'] : 0),
                    'db_bytes' => self::bucket(isset($m['db_bytes']) ? $m['db_bytes'] : 0),
                ),
            ),
            'plugins' => $plugins,
            'profiles' => $profiles,
            'archives' => self::archives($run_id),
            'observations' => $observations,
            'findings' => $findings,
            'impacts' => $impacts,
            'cache_effectiveness' => $cache,
        );
    }

    /**
     * What the archives on this site filter and order by, as SHAPES.
     *
     * Built from the aggregate rather than from profile_blob, which is never submitted and must
     * stay that way: the blob holds SQL with its literals (search terms, order numbers,
     * customer emails in WHERE clauses), absolute server paths, outbound URLs carrying API keys
     * and every option name the request read.
     *
     * What goes out here is column names, index shapes and plan verdicts - facts about
     * WordPress, WooCommerce and MySQL, not about this business. Row counts are bucketed like
     * every other size. Meta keys are the one judgement call and are allowlisted; see
     * SSPA_Rules::known_meta_keys(). Meta VALUES never leave, at any point.
     *
     * The point of collecting it: "what do real archives actually sort by, and how often does
     * the database have to filesort them" is a question no single site can answer.
     */
    private static function archives($run_id) {
        if (!class_exists('SSPA_Archive_Profile')) {
            return null;
        }
        $profile = SSPA_Archive_Profile::build($run_id);
        if (is_wp_error($profile)) {
            return null;
        }

        $known = array_flip(SSPA_Rules::known_meta_keys());

        $archives = array();
        foreach ($profile['archives'] as $entry) {
            $orderby = array();
            foreach ($entry['orderby'] as $term) {
                $orderby[] = array(
                    'source' => $term['source'],
                    'table' => $term['table'],
                    // A postmeta term is named only when the key is recognised software; the
                    // column name on a meta join is always "meta_value" and says nothing.
                    'column' => ('postmeta' === $term['source'])
                        ? self::share_meta_key($term['meta_key'], $known)
                        : $term['column'],
                    'order' => $term['order'],
                    'cast' => $term['cast'],
                );
            }

            $archives[] = array(
                'page_key' => $entry['page_key'],
                'main' => !empty($entry['main']),
                'post_type' => $entry['post_type'],
                'taxonomy' => $entry['taxonomy'],
                'rows' => self::bucket($entry['found_rows']),
                'ms' => $entry['ms'],
                'filesort' => !empty($entry['explain']['filesort']),
                'scan' => !empty($entry['explain']['scan']),
                'index_used' => isset($entry['explain']['key']) ? $entry['explain']['key'] : null,
                'est_rows' => isset($entry['explain']['est_rows']) ? self::bucket($entry['explain']['est_rows']) : null,
                'orderby' => $orderby,
            );
        }

        $materialise = array('postmeta' => array(), 'other_table' => array());
        foreach ($profile['materialise']['postmeta'] as $key => $verdict) {
            $name = self::share_meta_key($key, $known);
            $materialise['postmeta'][] = array(
                'key' => $name,
                'sql_type' => isset($verdict['sql_type']) ? $verdict['sql_type'] : null,
                'confidence' => isset($verdict['confidence']) ? $verdict['confidence'] : null,
                'used_for' => isset($verdict['used_for']) ? $verdict['used_for'] : array(),
            );
        }
        foreach ($profile['materialise']['other_table'] as $key => $verdict) {
            $materialise['other_table'][] = array(
                'column' => $key,
                'sql_type' => isset($verdict['sql_type']) ? $verdict['sql_type'] : null,
                'confidence' => isset($verdict['confidence']) ? $verdict['confidence'] : null,
                'used_for' => isset($verdict['used_for']) ? $verdict['used_for'] : array(),
            );
        }

        $composites = array();
        foreach ($profile['candidate_composites'] as $composite) {
            $columns = array();
            foreach ($composite['columns'] as $column) {
                // Mirror-table meta columns carry the key in their name, so they need the same
                // allowlisting as the key itself.
                $columns[] = (0 === strpos($column, 'meta_'))
                    ? self::share_meta_column($column, $known)
                    : $column;
            }
            $composites[] = array(
                'columns' => $columns,
                'pages' => count($composite['seen_on']),
                'rows' => self::bucket($composite['max_rows']),
            );
        }

        return array(
            'schema' => SSPA_Archive_Profile::SCHEMA,
            'complete' => !empty($profile['complete']),
            'pages_seen' => (int) $profile['pages_seen'],
            'archives' => $archives,
            'candidate_composites' => $composites,
            'materialise' => $materialise,
            'posts_columns' => $profile['materialise']['posts_columns'],
            'unsupported' => $profile['unsupported'],
        );
    }

    /**
     * A recognised key identifies software and is shared by name. Anything else identifies a
     * business and is not - only the fact that A custom key was used survives.
     */
    private static function share_meta_key($key, $known) {
        if (!$key) {
            return null;
        }
        return isset($known[$key]) ? $key : 'custom';
    }

    private static function share_meta_column($column, $known) {
        // A composite column carries its sort direction ("meta__price DESC"), which is part of
        // the index definition and has to survive; only the key half is allowlisted.
        $direction = '';
        if (preg_match('/^(.*?)(\s+(?:ASC|DESC))$/i', $column, $m)) {
            $column = $m[1];
            $direction = $m[2];
        }

        $key = substr($column, strlen('meta_'));
        foreach (array_keys($known) as $candidate) {
            if (sanitize_key($candidate) === $key) {
                return $column . $direction;
            }
        }
        return 'meta_custom' . $direction;
    }

    public static function bucket($n) {
        $n = (float) $n;
        foreach (array(10, 100, 1000, 10000, 100000, 1000000, 10000000, 100000000, 1000000000) as $limit) {
            if ($n < $limit) {
                return '<' . self::human($limit);
            }
        }
        return '1b+';
    }

    private static function human($n) {
        if ($n >= 1000000000) {
            return ($n / 1000000000) . 'b';
        }
        if ($n >= 1000000) {
            return ($n / 1000000) . 'm';
        }
        if ($n >= 1000) {
            return ($n / 1000) . 'k';
        }
        return (string) $n;
    }
}
