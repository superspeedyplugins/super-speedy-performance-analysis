<?php
defined('ABSPATH') || exit;

/**
 * Site demographics snapshot + sector inference. Stored per run in sspa_site_metrics.
 * Row counts for big tables use information_schema estimates - practise what we preach,
 * no COUNT(*) table scans on a customer's 10M-row postmeta.
 */
class SSPA_Demographics {

    public static function snapshot($run_id = 0) {
        global $wpdb;

        $post_counts = array();
        foreach (get_post_types(array('public' => true), 'names') as $pt) {
            $counts = wp_count_posts($pt);
            $post_counts[$pt] = isset($counts->publish) ? (int) $counts->publish : 0;
        }

        $orders_30d = 0;
        if (function_exists('wc_get_orders')) {
            $orders_30d = count(wc_get_orders(array(
                'date_created' => '>' . gmdate('Y-m-d', time() - 30 * DAY_IN_SECONDS),
                'return' => 'ids',
                'limit' => 500,
            )));
        }

        $table_rows = $wpdb->get_results($wpdb->prepare(
            'SELECT table_name AS t, table_rows AS r, (data_length + index_length) AS bytes
             FROM information_schema.tables WHERE table_schema = %s',
            DB_NAME
        ), ARRAY_A);
        $rows = array();
        $db_bytes = 0;
        foreach ((array) $table_rows as $tr) {
            $rows[$tr['t']] = (int) $tr['r'];
            $db_bytes += (int) $tr['bytes'];
        }

        $theme = wp_get_theme();
        $active_plugins = array();
        foreach ((array) get_option('active_plugins', array()) as $file) {
            $active_plugins[] = dirname($file) !== '.' ? dirname($file) : basename($file, '.php');
        }

        $metrics = array(
            'post_counts' => $post_counts,
            'orders_30d' => $orders_30d,
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
            'object_cache' => wp_using_ext_object_cache(),
            'memory_limit' => ini_get('memory_limit'),
            'multisite' => is_multisite(),
            'locale' => get_locale(),
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
        $row = $wpdb->get_row('SELECT * FROM ' . SSPA_Schema::table('site_metrics') . ' ORDER BY id DESC LIMIT 1', ARRAY_A);
        if (!$row) {
            return null;
        }
        $row['metrics'] = json_decode($row['metrics'], true);
        return $row;
    }
}
