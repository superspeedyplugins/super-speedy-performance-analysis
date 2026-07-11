<?php
function sspa_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

require_once WP_PLUGIN_DIR . '/super-speedy-performance-analysis/profiler/class-sspa-component-map.php';
$map = new SSPA_Component_Map();

$c = $map->classify_file(WP_PLUGIN_DIR . '/woocommerce/includes/class-wc-query.php');
sspa_t($c['component'] === 'woocommerce' && $c['type'] === 'plugin', 'plugin file classified');

$c = $map->classify_file(ABSPATH . 'wp-includes/class-wp-query.php');
sspa_t($c['type'] === 'core', 'core file classified');

$c = $map->classify_file(WP_CONTENT_DIR . '/themes/twentytwentyfive/functions.php');
sspa_t($c['component'] === 'twentytwentyfive' && $c['type'] === 'theme', 'theme file classified');

$c = $map->classify_file(WPMU_PLUGIN_DIR . '/sspa-loader.php');
sspa_t($c['type'] === 'mu-plugin', 'mu-plugin classified');

// Attribution: innermost core frames skipped, first plugin frame wins.
$frames = array(
    array(ABSPATH . 'wp-includes/class-wp-query.php', 100, 'WP_Query::get_posts'),
    array(ABSPATH . 'wp-includes/post.php', 200, 'get_posts'),
    array(WP_PLUGIN_DIR . '/woocommerce/includes/wc-core-functions.php', 50, 'wc_get_products'),
    array(WP_CONTENT_DIR . '/themes/storefront/index.php', 10, 'require'),
);
$attr = $map->attribute($frames);
sspa_t($attr['component'] === 'woocommerce', 'first non-core frame wins attribution');

// Our own frames never claim attribution.
$frames = array(
    array(WP_PLUGIN_DIR . '/super-speedy-performance-analysis/profiler/class-sspa-capture.php', 10, 'finalize'),
    array(ABSPATH . 'wp-includes/option.php', 20, 'get_option'),
);
$attr = $map->attribute($frames);
sspa_t($attr['component'] === 'core', 'profiler frames excluded from attribution');

// Degraded mode: resolve callable names via reflection.
$attr = $map->attribute_from_summary("require('wp-load.php'), wp_not_installed, get_option");
sspa_t($attr['type'] === 'core', 'summary attribution resolves core callables: ' . $attr['component']);
