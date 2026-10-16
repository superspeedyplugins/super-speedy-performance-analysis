<?php
defined('WP_UNINSTALL_PLUGIN') || exit;

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
            require_once ABSPATH . 'wp-admin/includes/user.php';
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
        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ($sspa_test_users as $sspa_user_id) {
            wp_delete_user((int) $sspa_user_id);
        }
    }

    foreach (array('sspa_cleanup_event', 'sspa_submission_worker_event', 'sspa_traffic_collection_tick', 'sspa_process_batch_event') as $sspa_hook) {
        wp_clear_scheduled_hook($sspa_hook);
    }

    global $wpdb;
    foreach (array('sspa\\_%', '\\_transient\\_sspa\\_%', '\\_transient\\_timeout\\_sspa\\_%') as $sspa_like) {
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $sspa_like
        ));
    }
}

// Live observer configuration must not survive uninstall, even when history is retained.
$sspa_uploads = wp_upload_dir(null, false);
$sspa_traffic_config = $sspa_uploads['basedir'] . '/super-speedy-performance-analysis/traffic-config.json';
if (is_file($sspa_traffic_config)) {
    unlink($sspa_traffic_config);
}
delete_option('sspa_profiler_enabled');

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
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- uninstall removes only helper files this plugin wrote; WP_Filesystem is not reliably initialisable during plugin deletion.
            unlink($sspa_file);
        }
    }
}
if (file_exists(WPMU_PLUGIN_DIR . '/sspa-traffic-observer.stopped')) {
    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- uninstall removes only this plugin's own observer stop-flag.
    unlink(WPMU_PLUGIN_DIR . '/sspa-traffic-observer.stopped');
}

// Collection HMAC keys are transient privacy material, not retained analysis data.
global $wpdb;
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
    $wpdb->esc_like('sspa_traffic_key_') . '%'
));
// Recovery only for drop-ins displaced by versions before 0.43.4. Never replace a
// file installed by another plugin while the old hold was pending.
foreach (array('db.php' => 'sspa_dropin_hold', 'object-cache.php' => 'sspa_oc_hold') as $sspa_name => $sspa_option) {
    $sspa_path = WP_CONTENT_DIR . '/' . $sspa_name;
    $sspa_hold = $sspa_path . '.sspa-hold';
    if (!file_exists($sspa_hold) && !is_link($sspa_hold)) {
        delete_option($sspa_option);
    } elseif (!file_exists($sspa_path) && !is_link($sspa_path)) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- returns a foreign file held by an older version; no new holds are created.
        if (rename($sspa_hold, $sspa_path)) {
            delete_option($sspa_option);
        }
    }
}
