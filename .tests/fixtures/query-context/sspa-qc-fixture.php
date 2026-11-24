<?php
/**
 * Plugin Name: SSPA query-context fixture
 * Description: Test-only (.tests/query-context/run.sh). A footer shortcode running a slow
 * SQL_CALC_FOUND_ROWS WP_Query with a tax_query, a sidebar widget running a showposts query with
 * a meta_query, and a slowed main query. Each is slowed only by SLEEP() in its own WHERE.
 */
defined('ABSPATH') || exit;

// SLEEP inside the statement, so the time is the query's own, in a one-row derived table so the
// server runs it once (a SLEEP in WHERE is re-evaluated per row). Each query keeps a known order:
// footer 150 ms, sidebar 70 ms, main 40 ms, loop 25 ms.
add_filter('posts_join', static function ($join, $query) {
    $delays = array('footer' => '0.15', 'sidebar' => '0.07', 'loop' => '0.025');
    $which = $query->get('sspa_qc');
    $delay = isset($delays[$which]) ? $delays[$which] : ((!is_admin() && $query->is_main_query() && $query->is_home()) ? '0.04' : null);
    return null === $delay ? $join : $join . ' JOIN (SELECT SLEEP(' . $delay . ') AS sspa_qc_slept) AS sspa_qc_once';
}, 10, 2);

// A persistent object cache answers repeat WP_Query results without SQL; the main query must run.
add_action('pre_get_posts', static function ($query) {
    if (!is_admin() && $query->is_main_query()) {
        $query->set('cache_results', false);
    }
});

add_shortcode('sspa_qc_footer_posts', static function () {
    $posts = new WP_Query(array(
        'posts_per_page' => 10,
        'tax_query' => array(array('taxonomy' => 'category', 'field' => 'slug', 'terms' => array('qc-news'))),
        'sspa_qc' => 'footer',
        // Read generically: any spro_* query var set on the query is reported with its value.
        'spro_fixture_flag' => 'set-by-fixture',
        'cache_results' => false,
    ));
    // A retained write: Analyse rows must refuse anything but a SELECT.
    global $wpdb;
    $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = option_value WHERE option_name = %s AND (SELECT SLEEP(0.06)) = 0 LIMIT 1", 'sspa_qc_touch'));
    return '<div class="qc-footer-posts">' . (int) $posts->post_count . ' of ' . (int) $posts->found_posts . '</div>';
});

class SSPA_QC_Widget extends WP_Widget {
    public function __construct() {
        parent::__construct('sspa_qc_widget', 'QC recent posts');
    }
    public function widget($args, $instance) {
        $posts = new WP_Query(array(
            'showposts' => 5,
            'no_found_rows' => true,
            'meta_query' => array(array('key' => '_sspa_qc_featured', 'compare' => 'NOT EXISTS')),
            'sspa_qc' => 'sidebar',
            'cache_results' => false,
        ));
        echo $args['before_widget'] . (int) $posts->post_count . ' recent' . $args['after_widget'];
    }
}
add_action('widgets_init', static function () {
    register_widget('SSPA_QC_Widget');
});
