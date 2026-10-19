<?php
defined('ABSPATH') || exit;

/**
 * Activation, deactivation and upgrade handling.
 */
class SSPA_Install {

    public static function activate($network_wide = false) {
        SSPA_Schema::create_tables();
        SSPA_Traffic_Authority::migrate();
        // Sites that never used Traffic need no authority file or writable MU directory.
        if (is_file(SSPA_Traffic_Authority::path()) || SSPA_Traffic_Helper::is_ours()) {
            SSPA_Traffic_Collection::deactivate();
            SSPA_Traffic_Authority::activate();
        }
        SSPA_Token::secret();
        if ($network_wide || !is_multisite()) { SSPA_Helper_Files::ensure_installed(); }
        if (!wp_next_scheduled('sspa_cleanup_event')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'sspa_cleanup_event');
        }
    }

    public static function deactivate($network_wide = false) {
        SSPA_Probes::sweep_abandoned(true);
        // Remove everything we placed outside the plugin folder and restore any hold.
        SSPA_Traffic_Collection::deactivate();
        if ($network_wide || !is_multisite()) { SSPA_Helper_Files::remove_all(); }
        wp_clear_scheduled_hook('sspa_cleanup_event');
        wp_clear_scheduled_hook('sspa_submission_worker_event');
        wp_clear_scheduled_hook('sspa_traffic_collection_tick');
        $run_id = SSPA_Run_Controller::active_run_id();
        if ($run_id) {
            SSPA_Run_Controller::cancel($run_id);
        }
    }

    public static function maybe_upgrade() {
        // Automatic upgrades run from admin_init, after authentication, never on visitors.
        $owner = SSPA_Atomic_Claim::acquire('sspa_upgrade_lock', 300);
        if (!$owner) { return; }
        try {
            self::upgrade_locked();
            delete_option('sspa_upgrade_error');
        } catch (Throwable $error) {
            update_option('sspa_upgrade_error', $error->getMessage(), false);
        } finally {
            SSPA_Atomic_Claim::release('sspa_upgrade_lock', $owner);
        }
    }

    public static function admin_upgrade() {
        if (current_user_can('manage_options')) { self::maybe_upgrade(); }
    }

    public static function upgrade_notice() {
        if (!current_user_can('manage_options') || !SSPA_Mail::is_pa_screen()) { return; }
        if (get_option('sspa_mail_options_removed')) {
            echo '<div class="notice notice-info"><p>' . esc_html__('Performance Analysis removed obsolete checkout email settings; synthetic emails now go to the administrator.', 'super-speedy-performance-analysis') . '</p></div>';
            delete_option('sspa_mail_options_removed');
        }
        $retirement = get_option('sspa_traffic_retirement_error');
        if ($retirement) { echo '<div class="notice notice-error"><p>' . esc_html($retirement) . '</p></div>'; }
        $error = get_option('sspa_upgrade_error');
        if ($error) {
            /* translators: %s: the migration error reported by the failing operation. */
            echo '<div class="notice notice-error"><p>' . esc_html(sprintf(__('Performance Analysis upgrade failed: %s. It will retry on your next wp-admin request.', 'super-speedy-performance-analysis'), $error)) . '</p></div>';
        }
    }

    private static function upgrade_locked() {
        if (get_option('sspa_behaviour_version') !== SSPA_VERSION) {
            SSPA_Traffic_Authority::migrate();
            SSPA_Helper_Files::migrate_profiler_consent();
            delete_transient('sspa_rules_feed');
            delete_transient('sspa_rules_feed_backoff');
            delete_option('sspa_rules_pubkey');
            delete_option('sspa_hub_url');
            $options = get_option('sspa_options', array());
            if (array_key_exists('checkout_mail_mode', $options) || array_key_exists('checkout_email', $options)) {
                unset($options['checkout_mail_mode'], $options['checkout_email']);
                update_option('sspa_options', $options);
                update_option('sspa_mail_options_removed', true, false);
            }
            SSPA_Probes::sweep_abandoned();
            $traffic = SSPA_Traffic_Helper::migrate_config();
            if (is_wp_error($traffic) || false === $traffic) {
                throw new RuntimeException(esc_html(is_wp_error($traffic) ? $traffic->get_error_message() : 'Could not migrate traffic configuration.'));
            }
            update_option('sspa_behaviour_version', SSPA_VERSION, true);
        }
        $installed = get_option('sspa_db_version');
        if ($installed !== SSPA_Schema::DB_VERSION) {
            SSPA_Schema::upgrade($installed);
        }
    }
}
