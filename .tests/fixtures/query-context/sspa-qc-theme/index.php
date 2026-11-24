<?php
get_header();
echo '<main>';
if (have_posts()) {
    $first = true;
    while (have_posts()) {
        the_post();
        echo '<article><h2>' . esc_html(get_the_title()) . '</h2></article>';
        if ($first) {
            // The main-loop query: runs in index.php, inside the loop, in the content area.
            $related = new WP_Query(array('posts_per_page' => 3, 'post__not_in' => array(get_the_ID()), 'sspa_qc' => 'loop', 'cache_results' => false));
            echo '<aside class="qc-related">' . (int) $related->post_count . ' related</aside>';
            $first = false;
        }
    }
}
echo '</main>';
get_sidebar();
get_footer();
