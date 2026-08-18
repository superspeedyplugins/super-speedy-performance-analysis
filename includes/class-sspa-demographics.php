<?php
defined('ABSPATH') || exit;

/**
 * Site demographics snapshot + sector inference. Stored per run in sspa_site_metrics.
 * Row counts for big tables use information_schema estimates - practise what we preach,
 * no COUNT(*) table scans on a customer's 10M-row postmeta.
 */
class SSPA_Demographics {

    /** Above this many rows in the posts table, a legacy order count is not worth its cost. */
    const LEGACY_ORDER_COUNT_CEILING = 2000000;

    public static function snapshot($run_id = 0) {
        global $wpdb;

        $post_counts = array();
        foreach (get_post_types(array('public' => true), 'names') as $pt) {
            $counts = wp_count_posts($pt);
            $post_counts[$pt] = isset($counts->publish) ? (int) $counts->publish : 0;
        }

        // Recent order ACTIVITY: a real count, not a capped one.
        //
        // This used to fetch up to N order ids and count them, which quietly broke the
        // measurement for the only stores it mattered for. A shop doing 40,000 orders a month
        // would come back as the cap and band as "<10k" - not imprecise, wrong, and wrong
        // about precisely the busiest stores in the cohort.
        //
        // A count is also no more expensive. Unlike the all-time total, this one is bounded by
        // its own WHERE clause: the window is 30 days and the date column is indexed under both
        // storage backends (HPOS `type_status_date`/`date_created`, legacy `type_status_date`),
        // so the database reads the orders in the window and nothing else. Asking WooCommerce
        // for the total rather than querying a table directly keeps it HPOS-compatible.
        $orders_30d = null;
        $orders_30d_basis = null;
        if (function_exists('wc_get_orders')) {
            $page = wc_get_orders(array(
                'date_created' => '>' . gmdate('Y-m-d', time() - 30 * DAY_IN_SECONDS),
                'return' => 'ids',
                'limit' => 1,
                'paginate' => true,
            ));
            if (is_object($page) && isset($page->total)) {
                $orders_30d = (int) $page->total;
                $orders_30d_basis = 'query_count';
            }
        }

        $tables = self::table_rows();
        $rows = $tables['rows'];
        $db_bytes = $tables['bytes'];

        $hpos = self::hpos();
        $orders = self::orders_total($hpos, $rows);

        $theme = wp_get_theme();
        $active_plugins = array();
        foreach ((array) get_option('active_plugins', array()) as $file) {
            $active_plugins[] = dirname($file) !== '.' ? dirname($file) : basename($file, '.php');
        }

        $metrics = array(
            'post_counts' => $post_counts,
            'orders_30d' => $orders_30d,
            'orders_30d_basis' => $orders_30d_basis,
            'orders_total' => $orders['count'],
            'orders_total_basis' => $orders['basis'],
            'hpos' => $hpos,
            'checkout_type' => class_exists('SSPA_Checkout_Preflight') && class_exists('WooCommerce')
                ? SSPA_Checkout_Preflight::checkout_type()
                : null,
            'users' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}"),
            'comments' => (int) (wp_count_comments()->approved ?? 0),
            'terms' => isset($rows[$wpdb->terms]) ? $rows[$wpdb->terms] : null,
            'postmeta_rows' => isset($rows[$wpdb->postmeta]) ? $rows[$wpdb->postmeta] : null,
            'options_rows' => isset($rows[$wpdb->options]) ? $rows[$wpdb->options] : null,
            'usermeta_rows' => isset($rows[$wpdb->usermeta]) ? $rows[$wpdb->usermeta] : null,
            'db_bytes' => $db_bytes,
            'autoload_bytes' => strlen(serialize(wp_load_alloptions())),
            'theme' => $theme->get_stylesheet(),
            'theme_parent' => $theme->parent() ? $theme->parent()->get_stylesheet() : null,
            'active_plugins' => $active_plugins,
            'wp' => get_bloginfo('version'),
            'php' => PHP_VERSION,
            'mysql' => $wpdb->db_version(),
            'db_family' => self::database_family(),
            'object_cache' => wp_using_ext_object_cache(),
            'object_cache_category' => self::dropin_category(WP_CONTENT_DIR . '/object-cache.php'),
            'page_cache' => self::dropin_category(WP_CONTENT_DIR . '/advanced-cache.php'),
            'memory_limit' => ini_get('memory_limit'),
            'multisite' => is_multisite(),
            'locale' => get_locale(),
            // Whether this is a real site at all. WordPress's own closed set:
            // production / staging / development / local. A developer's laptop copy of a store
            // measures nothing about how that store performs for its customers - different
            // hardware, no traffic, often a partial database - so a corpus that pools it with
            // production is quietly wrong about every cohort it contains. Declared rather than
            // guessed, and the guess would be poor: "localhost" is not a reliable signal and a
            // staging site usually looks exactly like the live one.
            'environment_type' => function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production',
        );

        $sector = self::infer_sector($post_counts, $active_plugins);

        $wpdb->insert(SSPA_Schema::table('site_metrics'), array(
            'blog_id' => get_current_blog_id(),
            'metrics' => wp_json_encode($metrics),
            'sector' => $sector,
            'created' => gmdate('Y-m-d H:i:s'),
        ));
        $metrics_id = (int) $wpdb->insert_id;

        if ($run_id) {
            $wpdb->update(SSPA_Schema::table('runs'), array('site_metrics_id' => $metrics_id), array('id' => $run_id));
        }

        return array('id' => $metrics_id, 'metrics' => $metrics, 'sector' => $sector);
    }

    /**
     * Every table's estimated row count and total size, in one information_schema read.
     *
     * @return array {rows: table name => estimated rows, bytes: total data + index bytes}
     */
    public static function table_rows() {
        global $wpdb;
        $results = $wpdb->get_results($wpdb->prepare(
            'SELECT table_name AS t, table_rows AS r, (data_length + index_length) AS bytes
             FROM information_schema.tables WHERE table_schema = %s',
            DB_NAME
        ), ARRAY_A);
        $rows = array();
        $bytes = 0;
        foreach ((array) $results as $tr) {
            $rows[$tr['t']] = (int) $tr['r'];
            $bytes += (int) $tr['bytes'];
        }
        return array('rows' => $rows, 'bytes' => $bytes);
    }

    /** Is WooCommerce storing orders in its own tables rather than wp_posts? null = no WooCommerce. */
    public static function hpos() {
        if (!class_exists('WooCommerce')) {
            return null;
        }
        return class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }

    /**
     * Total orders ever - store SCALE, as opposed to the 30-day activity figure.
     *
     * Never a `COUNT(*)` on a large customer table: this plugin's whole argument is that
     * unbounded counts are what make shops slow, and running one to measure a shop would be
     * an embarrassing way to prove it. Three honest routes, and the payload says which was
     * taken:
     *
     *  - HPOS: the information_schema row estimate for the orders table, already fetched for
     *    the size figures. It is an estimate; a band is all that gets shared anyway.
     *  - legacy: `wp_count_posts()`, which is WordPress's own maintained and cached count.
     *  - legacy on a huge posts table: nothing. An honest null beats a slow number.
     *
     * Public, and takes the storage mode as an argument rather than reading it, so both
     * routes can be exercised on one site: WooCommerce refuses to switch the authoritative
     * order table while any order is out of sync, so a test cannot flip the real setting.
     *
     * @return array {count, basis}
     */
    public static function orders_total($hpos, $table_rows) {
        global $wpdb;
        if (null === $hpos) {
            return array('count' => null, 'basis' => null);
        }
        if ($hpos) {
            $table = $wpdb->prefix . 'wc_orders';
            return isset($table_rows[$table])
                ? array('count' => (int) $table_rows[$table], 'basis' => 'table_estimate')
                : array('count' => null, 'basis' => null);
        }
        $posts_rows = isset($table_rows[$wpdb->posts]) ? (int) $table_rows[$wpdb->posts] : 0;
        /**
         * How large the posts table may be before a legacy order count is abandoned.
         *
         * Filterable because the right answer depends on the host: the count is one grouped
         * scan of an indexed column, cheap on decent hardware and not cheap on a shared box.
         * Lowering it to 0 turns the count off entirely.
         */
        $ceiling = (int) apply_filters('sspa_legacy_order_count_ceiling', self::LEGACY_ORDER_COUNT_CEILING);
        if ($posts_rows > $ceiling) {
            return array('count' => null, 'basis' => 'skipped_large_table');
        }
        $counts = (array) wp_count_posts('shop_order');
        $total = 0;
        foreach ($counts as $status => $count) {
            if (is_numeric($count)) {
                $total += (int) $count;
            }
        }
        return array('count' => $total, 'basis' => 'maintained_count');
    }

    private static function database_family() {
        global $wpdb;
        $info = '';
        if (method_exists($wpdb, 'db_server_info')) {
            $info = strtolower((string) $wpdb->db_server_info());
        }
        return (false !== strpos($info, 'mariadb')) ? 'mariadb' : 'mysql';
    }

    /**
     * Which technology a cache drop-in is built on, as a generic category.
     *
     * The drop-in's own header names the plugin, and the plugin is already in the component
     * inventory; what the inventory cannot say is whether that plugin was pointed at Redis or
     * at disk, which is the part a cohort needs. Reads the head of the file only, and returns
     * a word from a closed list - never a path, a host or a line of the file.
     */
    private static function dropin_category($path) {
        if (!is_readable($path)) {
            return null;
        }
        $head = strtolower((string) @file_get_contents($path, false, null, 0, 8192));
        if ('' === $head) {
            return null;
        }
        foreach (array('redis' => 'redis', 'memcach' => 'memcached', 'apcu' => 'apcu', 'sqlite' => 'sqlite') as $needle => $category) {
            if (false !== strpos($head, $needle)) {
                return $category;
            }
        }
        return 'other';
    }

    private static function infer_sector($post_counts, $active_plugins) {
        $signatures = SSPA_Rules::sector_signatures();

        // Dominant non-core CPT wins if it has meaningful volume.
        $cpts = $post_counts;
        unset($cpts['post'], $cpts['page'], $cpts['attachment']);
        arsort($cpts);
        foreach ($cpts as $cpt => $count) {
            if ($count >= 10 && isset($signatures[$cpt])) {
                return $signatures[$cpt];
            }
        }
        // Fallback: any signature CPT present at all.
        foreach ($signatures as $cpt => $sector) {
            if (!empty($post_counts[$cpt])) {
                return $sector;
            }
        }
        if (!empty($post_counts['post']) && $post_counts['post'] > 100) {
            return 'publisher';
        }
        return 'general';
    }

    public static function latest() {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i ORDER BY id DESC LIMIT 1', SSPA_Schema::table('site_metrics')), ARRAY_A);
        if (!$row) {
            return null;
        }
        $row['metrics'] = json_decode($row['metrics'], true);
        return $row;
    }
}
