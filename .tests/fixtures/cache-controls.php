<?php
// Scoped page-cache API fault injection for the authenticated admin-bar journey.
if (!defined('ABSPATH') || !preg_match('/^tests(-|$)/', basename(rtrim(ABSPATH, '/')))) return;
if (get_option('sspa_cache_control_fixture')) {
    function w3tc_flush_all() {
        $mode = get_option('sspa_cache_control_fixture');
        // Prove ordering using the real persistent cache, rather than a copied dispatcher.
        update_option('sspa_cache_control_observed', wp_cache_get('sspa_all_probe', 'sspa_journey'));
        if ('throw' === $mode) throw new RuntimeException('Synthetic page-cache purge failure');
        return 'refuse' !== $mode;
    }
    function w3tc_flush_url($url) { return true; }
}
