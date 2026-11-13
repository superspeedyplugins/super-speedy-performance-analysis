<?php
/**
 * Test fixture for case 158 (archive main-query time and meta casts). Active only on requests
 * carrying ?sspa_t158=1: the main query of a category archive gains a NUMERIC BETWEEN meta_query
 * on _sspa_t158, and its statement is made to take at least 100 ms with an uncorrelated SLEEP
 * subquery and is kept out of WordPress's query cache, so the time PA records for it can be told apart from any other statement's.
 * Copied into wp-content/mu-plugins by the case; never part of a customer build.
 */
defined('ABSPATH') || exit;

if (!empty($_GET['sspa_t158'])) {
    add_action('pre_get_posts', function ($query) {
        if ($query->is_main_query() && $query->is_category()) {
            $query->set('meta_query', array(array(
                'key' => '_sspa_t158',
                'value' => array(1, 5),
                'compare' => 'BETWEEN',
                'type' => 'NUMERIC',
            )));
            // The suite's site has a persistent object cache, which would answer a warm
            // sample's query from WordPress's query cache and leave no statement to time.
            $query->set('cache_results', false);
        }
    });
    add_filter('posts_where', function ($where, $query) {
        if ($query->is_main_query() && $query->is_category()) {
            $where .= ' AND (SELECT SLEEP(0.1)) = 0';
        }
        return $where;
    }, 10, 2);
}
