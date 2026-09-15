<?php
/**
 * Plugin Name: SSPA Query Monitor Probe
 * Description: Test fixture. When a request carries ?sspa_qm_probe=<id>, records what the real Query Monitor collectors measured for that request, after QM has processed them, so a case can compare the plugin's own measurement of the same page against QM's. Installed by case 88; retained on the test site.
 * Version: 1.0.0
 */
// Only on this plugin's isolated test sites: the default scenario `tests` and any `tests-*`.
if (!defined('ABSPATH') || !preg_match('/^tests(-|$)/', basename(rtrim(ABSPATH, '/')))) return;

// PA finalizes in a PHP shutdown callback, after WordPress's shutdown action.
// Read QM at that same boundary: its priority-9 snapshot omits later WooCommerce
// shutdown queries and is not comparable to PA's complete request count.
register_shutdown_function(static function () {
    if (empty($_GET['sspa_qm_probe']) || !class_exists('QM_Collectors')) {
        return;
    }
    $id = preg_replace('/[^a-z0-9]/', '', strtolower((string) $_GET['sspa_qm_probe']));
    if ('' === $id) {
        return;
    }
    global $wpdb;
    $collector = QM_Collectors::get('db_queries');
    // Reprocess QM's real collector at this boundary, including for anonymous viewers.
    // process() resets its totals before reading the actual database object; it does not
    // enable SAVEQUERIES or fabricate an anonymous per-query log.
    if ($collector) {
        $collector->process();
    }
    $data = $collector ? $collector->get_data() : null;
    $rows = $data && isset($data->rows) && is_array($data->rows) ? $data->rows : array();
    $time_ms = 0.0;
    foreach ($rows as $row) {
        $time_ms += isset($row['ltime']) ? (float) $row['ltime'] * 1000 : 0;
    }
    update_option('sspa_qm_probe_' . $id, array(
        'total_qs' => $data && isset($data->total_qs) ? (int) $data->total_qs : null,
        'rows' => count($rows),
        'time_ms' => round($time_ms, 3),
        'wpdb_num_queries' => (int) $wpdb->num_queries,
        'queries_logged' => is_array($wpdb->queries) ? count($wpdb->queries) : 0,
        'wpdb_class' => get_class($wpdb),
        'viewer' => get_current_user_id(),
        'savequeries' => defined('SAVEQUERIES') && SAVEQUERIES,
        'dropin' => file_exists(WP_CONTENT_DIR . '/db.php') && is_link(WP_CONTENT_DIR . '/db.php') ? 'qm-symlink' : (file_exists(WP_CONTENT_DIR . '/db.php') ? 'file' : 'absent'),
        'qm_version' => defined('QM_VERSION') ? QM_VERSION : null,
        'realpath_cache_ttl' => (int) ini_get('realpath_cache_ttl'),
    ), false);
});
