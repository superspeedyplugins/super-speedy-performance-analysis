<?php
// The selective cache shortcut must not offer a whole persistent-cache flush.
defined('ABSPATH') || exit;
wp_set_current_user(1);
$failures = 0;
$check = function ($ok, $label) use (&$failures) {
    echo ($ok ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$ok) $failures++;
};
if (!wp_using_ext_object_cache()) throw new RuntimeException('This regression needs the native test site persistent object cache.');
require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
$menu = new ReflectionMethod('SSPA_Admin_Bar', 'group_clear');
$menu->setAccessible(true);
$bar = new WP_Admin_Bar();
$menu->invoke(null, $bar);
$check(null === $bar->get_node('sspa-clear-ours'), 'Persistent-cache menu omits the misleading selective action');
$global = $bar->get_node('sspa-flush-object-cache');
$check($global && 'Object cache' === $global->title, 'The explicit Object cache action remains available');
wp_cache_set('sspa_scope_foreign', 'preserve', 'foreign_fixture', 600);
set_transient('sspa_scope_owned', 'preserve', 600);
$clear = new ReflectionMethod('SSPA_Admin_Bar', 'clear_our_caches');
$clear->setAccessible(true);
$message = $clear->invoke(null);
$check('preserve' === wp_cache_get('sspa_scope_foreign', 'foreign_fixture'), 'An old selective-action request preserves unrelated persistent cache data');
$check('preserve' === get_transient('sspa_scope_owned'), 'The unavailable selective action does not alter cached transients');
$check(false !== strpos($message, 'Object cache') && false !== strpos($message, 'Nothing was cleared'), 'The unavailable operation explains what happened and names the explicit action');
// Menu availability for the ordinary database-backed case; do not invoke a cache flush
// while simulating that flag over the real persistent backend.
wp_using_ext_object_cache(false);
try {
    $bar = new WP_Admin_Bar();
    $menu->invoke(null, $bar);
    $check(null !== $bar->get_node('sspa-clear-ours'), 'Database-backed sites keep their selective shortcut');
} finally { wp_using_ext_object_cache(true); }
if ($failures) throw new RuntimeException($failures . ' cache scope assertions failed');
