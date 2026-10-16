<?php
// The removed shortcut must never appear, with either real cache backend mode.
defined('ABSPATH') || exit;
wp_set_current_user(1);
show_admin_bar(true);
require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
$failures = 0;
$check = function ($ok, $label) use (&$failures) {
    echo ($ok ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$ok) $failures++;
};
$original = wp_using_ext_object_cache();
try {
    foreach (array(false, true) as $persistent) {
        // Rendering only: never flush a backend while overriding the availability flag.
        wp_using_ext_object_cache($persistent);
        $bar = new WP_Admin_Bar();
        $bar->initialize();
        SSPA_Admin_Bar::nodes($bar);
        $label = $persistent ? 'Persistent-cache' : 'Database-backed';
        $check(null === $bar->get_node('sspa-clear-ours'), $label . ' menu has no Super Speedy caches node');
        $urls = array_filter($bar->get_nodes(), function ($node) {
            return !empty($node->href) && false !== strpos(html_entity_decode($node->href), 'do=clear_ours');
        });
        $check(!$urls, $label . ' menu has no clear_ours action URL');
        $check(null !== $bar->get_node('sspa-clear-transients'), $label . ' keeps All transients');
        $check((null !== $bar->get_node('sspa-flush-object-cache')) === $persistent, $label . ' keeps conditional Object cache');
        $check((null !== $bar->get_node('sspa-opcache')) === function_exists('opcache_reset'), $label . ' keeps conditional OPcache');
        $check(null !== $bar->get_node('sspa-forget-page'), $label . ' keeps stored-result action');
    }
} finally { wp_using_ext_object_cache($original); }
if ($failures) exit(1);
