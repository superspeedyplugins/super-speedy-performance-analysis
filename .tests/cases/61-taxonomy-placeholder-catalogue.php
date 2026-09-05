<?php
defined('ABSPATH') || exit;

$GLOBALS['sspa_61_fails'] = 0;
function sspa_61_t($ok, $message) {
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $message . "\n";
    if (!$ok) {
        $GLOBALS['sspa_61_fails']++;
    }
}

wp_set_current_user(1);
global $wpdb;

$fixture = WPMU_PLUGIN_DIR . '/sspa-case-61-catalogue.php';
$fixture_code = <<<'PHP'
<?php
add_action('init', function () {
    register_taxonomy('sspa_listing_cat', 'sspa_listing', array('public' => true, 'rewrite' => array('slug' => 'listing-category')));
    register_taxonomy('sspa_empty_cat', 'sspa_empty_listing', array('public' => true));
    register_post_type('sspa_listing', array('public' => true, 'has_archive' => true, 'rewrite' => array('slug' => 'listings')));
    register_post_type('sspa_empty_listing', array('public' => true, 'has_archive' => true, 'rewrite' => array('slug' => 'empty-listings')));
    add_rewrite_rule('^listings/([^/]+)/?$', 'index.php?post_type=sspa_listing&sspa_listing_cat=$matches[1]', 'top');
});
add_filter('post_type_archive_link', function ($link, $post_type) {
    if ('sspa_listing' === $post_type) {
        return home_url('/listings/%sspa_listing_cat%/');
    }
    if ('sspa_empty_listing' === $post_type) {
        return home_url('/empty-listings/%sspa_empty_cat%/');
    }
    return $link;
}, 10, 2);
PHP;
file_put_contents($fixture, $fixture_code);

register_taxonomy('sspa_listing_cat', 'sspa_listing', array('public' => true, 'rewrite' => array('slug' => 'listing-category')));
register_taxonomy('sspa_empty_cat', 'sspa_empty_listing', array('public' => true));
register_post_type('sspa_listing', array('public' => true, 'has_archive' => true, 'rewrite' => array('slug' => 'listings')));
register_post_type('sspa_empty_listing', array('public' => true, 'has_archive' => true, 'rewrite' => array('slug' => 'empty-listings')));
add_rewrite_rule('^listings/([^/]+)/?$', 'index.php?post_type=sspa_listing&sspa_listing_cat=$matches[1]', 'top');
$archive_filter = function ($link, $post_type) {
    if ('sspa_listing' === $post_type) {
        return home_url('/listings/%sspa_listing_cat%/');
    }
    if ('sspa_empty_listing' === $post_type) {
        return home_url('/empty-listings/%sspa_empty_cat%/');
    }
    return $link;
};
add_filter('post_type_archive_link', $archive_filter, 10, 2);

foreach (get_posts(array('post_type' => array('sspa_listing', 'sspa_empty_listing'), 'post_status' => 'any', 'numberposts' => -1)) as $stale) {
    wp_delete_post($stale->ID, true);
}
foreach (get_terms(array('taxonomy' => 'sspa_listing_cat', 'hide_empty' => false)) as $stale_term) {
    wp_delete_term($stale_term->term_id, 'sspa_listing_cat');
}
$large = wp_insert_term('Large listings', 'sspa_listing_cat', array('slug' => 'large-listings'));
$small = wp_insert_term('Small listings', 'sspa_listing_cat', array('slug' => 'small-listings'));
$large_id = is_wp_error($large) ? (int) $large->get_error_data('term_exists') : (int) $large['term_id'];
$small_id = is_wp_error($small) ? (int) $small->get_error_data('term_exists') : (int) $small['term_id'];
for ($i = 0; $i < 3; $i++) {
    $post_id = wp_insert_post(array('post_type' => 'sspa_listing', 'post_status' => 'publish', 'post_title' => 'Large listing ' . $i));
    wp_set_object_terms($post_id, $large_id, 'sspa_listing_cat');
}
$small_post = wp_insert_post(array('post_type' => 'sspa_listing', 'post_status' => 'publish', 'post_title' => 'Small listing'));
wp_set_object_terms($small_post, $small_id, 'sspa_listing_cat');
flush_rewrite_rules(false);

$jobs = SSPA_Catalogue::build();
$by_key = array();
foreach ($jobs as $job) {
    $by_key[$job['page_key']] = $job;
}
$archive_key = 'cpt-sspa_listing-archive';
$empty_key = 'cpt-sspa_empty_listing-archive';
$resolved_url = isset($by_key[$archive_key]) ? $by_key[$archive_key]['url'] : '';
sspa_61_t(isset($by_key[$archive_key]), 'the placeholder-bearing custom post type archive is discovered');
sspa_61_t(false !== strpos($resolved_url, '/large-listings/'), 'the archive uses the largest non-empty taxonomy term');
sspa_61_t(false === strpos($resolved_url, '%'), 'the queued archive URL contains no unresolved placeholder');
sspa_61_t(!isset($by_key[$empty_key]), 'an archive with no resolvable non-empty taxonomy term is skipped');

$run_id = SSPA_Run_Controller::start(array('type' => 'spot', 'page_keys' => array($archive_key), 'user_id' => 1));
if (is_wp_error($run_id)) {
    echo 'FAIL  resolved archive run starts: ' . $run_id->get_error_message() . "\n";
    $GLOBALS['sspa_61_fails']++;
} else {
    $deadline = time() + 120;
    do {
        SSPA_Run_Controller::process_batch($run_id);
        $status = SSPA_Run_Controller::status($run_id);
    } while ($status && 'crawling' === $status['status'] && time() < $deadline);
    $profile = $wpdb->get_row($wpdb->prepare(
        'SELECT page_key,url,page_gen_ms,response_code FROM %i WHERE run_id=%d AND page_key=%s',
        SSPA_Schema::table('profiles'), $run_id, $archive_key
    ), ARRAY_A);
    sspa_61_t($status && 'done' === $status['status'], 'the resolved archive completes through the real controller');
    sspa_61_t($profile && $resolved_url === $profile['url'] && (float) $profile['page_gen_ms'] > 0 && 200 === (int) $profile['response_code'], 'the stored profile measures the resolved archive URL successfully');
}

remove_filter('post_type_archive_link', $archive_filter, 10);
// Retain the MU fixture so the measured archive routes still resolve after the test.
