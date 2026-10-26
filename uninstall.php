<?php
defined('WP_UNINSTALL_PLUGIN') || exit;
defined('ABSPATH') || exit;
require_once __DIR__ . '/includes/class-sspa-filesystem.php';
require_once __DIR__ . '/includes/locations.php';
require_once __DIR__ . '/includes/class-sspa-mail.php';
require_once __DIR__ . '/includes/class-sspa-probes.php';
require_once __DIR__ . '/includes/class-sspa-workflow-analysis.php';
SSPA_Mail::register();
SSPA_Probes::sweep_abandoned(true);

$sspa_options = get_option('sspa_options', array());

if (!empty($sspa_options['remove_data_on_uninstall'])) {
    require_once __DIR__ . '/includes/class-sspa-schema.php';
    SSPA_Schema::drop_tables();

    // Remove plugin-owned temporary WooCommerce objects before deleting their ledger.
    $sspa_temp_entries = get_option('sspa_flow_temp', array());
    foreach ((array) $sspa_temp_entries as $sspa_entry) {
        $sspa_id = !empty($sspa_entry['id']) ? (int) $sspa_entry['id'] : 0;
        if (!$sspa_id || empty($sspa_entry['type'])) {
            continue;
        }
        if ('order' === $sspa_entry['type'] && function_exists('wc_get_order')) {
            $sspa_order = wc_get_order($sspa_id);
            if ($sspa_order && $sspa_order->get_meta('_sspa_temp', true)) {
                $sspa_order->delete(true);
            }
        } elseif ('user' === $sspa_entry['type'] && get_user_meta($sspa_id, '_sspa_temp', true)) {
            if (!function_exists('wp_delete_user')) { require_once SSPA_CORE_DIR . 'wp-admin/includes/user.php'; }
            wp_delete_user($sspa_id);
        }
    }

    // The reusable hidden checkout product and low-privilege profiling customer.
    $sspa_product_ids = get_posts(array(
        'post_type' => 'product',
        'post_status' => 'any',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'meta_key' => '_sspa_temp',
        'meta_value' => 'checkout_product',
    ));
    foreach ($sspa_product_ids as $sspa_product_id) {
        wp_delete_post((int) $sspa_product_id, true);
    }
    $sspa_test_users = get_users(array('meta_key' => 'sspa_test_account', 'meta_value' => '1', 'fields' => 'ID'));
    if ($sspa_test_users) {
        foreach ($sspa_test_users as $sspa_user_id) {
            if (!function_exists('wp_delete_user')) { require_once SSPA_CORE_DIR . 'wp-admin/includes/user.php'; }
            wp_delete_user((int) $sspa_user_id);
        }
    }

    foreach (array('sspa_cleanup_event', 'sspa_submission_worker_event', 'sspa_traffic_collection_tick', 'sspa_process_batch_event') as $sspa_hook) {
        wp_clear_scheduled_hook($sspa_hook);
    }

    // Per-user share panel choices.
    foreach (array('sspa_share_prompt_snoozed_until', 'sspa_share_prompt_never') as $sspa_user_key) {
        delete_metadata('user', 0, $sspa_user_key, '', true);
    }

    global $wpdb;
    foreach (array('sspa\\_%', '\\_transient\\_sspa\\_%', '\\_transient\\_timeout\\_sspa\\_%') as $sspa_like) {
        $wpdb->query($wpdb->prepare(
            "DELETE FROM %i WHERE option_name LIKE %s",
            $wpdb->options,
            $sspa_like
        ));
    }
}

// Live observer configuration must not survive uninstall, even when history is retained.
$sspa_uploads = wp_upload_dir(null, false);
$sspa_traffic_config = $sspa_uploads['basedir'] . '/super-speedy-performance-analysis/traffic-config.json';
if (is_file($sspa_traffic_config)) {
    SSPA_Filesystem::direct()->delete($sspa_traffic_config);
}
delete_option('sspa_profiler_enabled');
delete_option('sspa_dropin_restore_notice');
delete_option('sspa_traffic_authority');
// Our own folder only: the lock file and the direct-access guards written by SSPA_Traffic_Helper::data_dir().
foreach (array('sspa-traffic.lock', '.htaccess', 'index.html') as $sspa_data_file) {
    $sspa_own_file = $sspa_uploads['basedir'] . '/super-speedy-performance-analysis/' . $sspa_data_file;
    if (is_file($sspa_own_file)) { SSPA_Filesystem::direct()->delete($sspa_own_file); }
}
foreach (array('sspa-traffic-authority.php', 'sspa-traffic-authority.json', 'sspa-traffic-observer.stopped') as $sspa_data_file) {
    foreach (array($sspa_uploads['basedir'] . '/super-speedy-performance-analysis/', SSPA_MU_DIR . '/') as $sspa_data_dir) {
        if (is_file($sspa_data_dir . $sspa_data_file)) { SSPA_Filesystem::direct()->delete($sspa_data_dir . $sspa_data_file); }
    }
}


// Remove our helper files if present (installed from Phase 1 onwards). Signature-checked so
// we never delete another plugin's file.
$sspa_helper_files = array(
    SSPA_MU_DIR . '/sspa-loader.php',
    SSPA_MU_DIR . '/sspa-traffic-observer.php',
    SSPA_CONTENT_DIR . '/db.php',
);
foreach ($sspa_helper_files as $sspa_file) {
    if (file_exists($sspa_file)) {
        $sspa_head = SSPA_Filesystem::get_contents($sspa_file, false, null, 0, 512);
        if ($sspa_head !== false && strpos($sspa_head, 'Super Speedy Performance Analysis') !== false) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- uninstall removes only helper files this plugin wrote; WP_Filesystem is not reliably initialisable during plugin deletion.
            SSPA_Filesystem::direct()->delete($sspa_file);
        }
    }
}
// Collection HMAC keys are transient privacy material, not retained analysis data.
global $wpdb;
$wpdb->query($wpdb->prepare(
    "DELETE FROM %i WHERE option_name LIKE %s",
    $wpdb->options,
    $wpdb->esc_like('sspa_traffic_key_') . '%'
));
// Recovery only for drop-ins displaced by versions before 0.43.4. Never replace a
// file installed by another plugin while the old hold was pending.
foreach (array('db.php' => 'sspa_dropin_hold', 'object-cache.php' => 'sspa_oc_hold') as $sspa_name => $sspa_option) {
    $sspa_path = SSPA_CONTENT_DIR . '/' . $sspa_name;
    $sspa_hold = $sspa_path . '.sspa-hold';
    if (!file_exists($sspa_hold) && !is_link($sspa_hold)) {
        delete_option($sspa_option);
    } elseif (!file_exists($sspa_path) && !is_link($sspa_path)) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- returns a foreign file held by an older version; no new holds are created.
        if (SSPA_Filesystem::direct()->move($sspa_hold, $sspa_path, false)) {
            delete_option($sspa_option);
        }
    }
}

// Remove interrupted atomic publications and the empty plugin-owned uploads directory.
$sspa_data_dir = $sspa_uploads['basedir'] . '/super-speedy-performance-analysis';
foreach ((array) glob($sspa_data_dir . '/*.tmp-*') as $sspa_temporary) {
    if (is_file($sspa_temporary)) { SSPA_Filesystem::direct()->delete($sspa_temporary); }
}
if (is_dir($sspa_data_dir) && count((array) scandir($sspa_data_dir)) === 2) { SSPA_Filesystem::direct()->rmdir($sspa_data_dir); }
