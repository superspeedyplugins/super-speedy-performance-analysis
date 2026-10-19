<?php
// Test-only third-party option filter fault, dormant outside the named isolated case.
add_filter('pre_option_admin_email', static function($pre) {
    return get_option('sspa_w5b_invalid_email') ? 'invalid' : $pre;
});
add_filter('wp_insert_post_empty_content', static function($empty, $postarr) {
    return get_option('sspa_w5b_empty_temp') && !empty($postarr['meta_input']['_sspa_temp']) ? true : $empty;
}, PHP_INT_MAX, 2);
