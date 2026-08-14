<?php
defined('WP_UNINSTALL_PLUGIN') || exit;

$sspa_options = get_option('sspa_options', array());

if (!empty($sspa_options['remove_data_on_uninstall'])) {
    require_once __DIR__ . '/includes/class-sspa-schema.php';
    SSPA_Schema::drop_tables();
    delete_option('sspa_options');
}

// Remove our helper files if present (installed from Phase 1 onwards). Signature-checked so
// we never delete another plugin's file.
$sspa_helper_files = array(
    WPMU_PLUGIN_DIR . '/sspa-loader.php',
    WPMU_PLUGIN_DIR . '/sspa-traffic-observer.php',
    WP_CONTENT_DIR . '/db.php',
);
foreach ($sspa_helper_files as $sspa_file) {
    if (file_exists($sspa_file)) {
        $sspa_head = file_get_contents($sspa_file, false, null, 0, 512);
        if ($sspa_head !== false && strpos($sspa_head, 'Super Speedy Performance Analysis') !== false) {
            unlink($sspa_file);
        }
    }
}
if (file_exists(WPMU_PLUGIN_DIR . '/sspa-traffic-observer.stopped')) {
    unlink(WPMU_PLUGIN_DIR . '/sspa-traffic-observer.stopped');
}

// Collection HMAC keys are transient privacy material, not retained analysis data.
global $wpdb;
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
    $wpdb->esc_like('sspa_traffic_key_') . '%'
));
// If we were holding a displaced drop-in (run crashed mid-analysis), restore it.
if (!file_exists(WP_CONTENT_DIR . '/db.php') && file_exists(WP_CONTENT_DIR . '/db.php.sspa-hold')) {
    rename(WP_CONTENT_DIR . '/db.php.sspa-hold', WP_CONTENT_DIR . '/db.php');
}
