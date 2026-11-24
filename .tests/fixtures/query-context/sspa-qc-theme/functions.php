<?php
// Test-only. One widget area, used by sidebar.php.
add_action('widgets_init', static function () {
    register_sidebar(array('name' => 'QC sidebar', 'id' => 'qc-sidebar'));
});
