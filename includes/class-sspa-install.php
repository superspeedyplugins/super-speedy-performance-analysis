<?php
defined('ABSPATH') || exit;

/**
 * Activation, deactivation and upgrade handling.
 */
class SSPA_Install {

    public static function activate() {
        SSPA_Schema::create_tables();
        // Sites that never used Traffic need no authority file or writable MU directory.
        if (is_file(SSPA_Traffic_Authority::path()) || SSPA_Traffic_Helper::is_ours()) {
            SSPA_Traffic_Collection::deactivate();
            SSPA_Traffic_Authority::activate();
        }
        SSPA_Token::secret();
        SSPA_Helper_Files::ensure_installed();
        if (!wp_next_scheduled('sspa_cleanup_event')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'sspa_cleanup_event');
        }
    }

    public static function deactivate() {
        // Remove everything we placed outside the plugin folder and restore any hold.
        SSPA_Traffic_Collection::deactivate();
        SSPA_Helper_Files::remove_all();
        wp_clear_scheduled_hook('sspa_cleanup_event');
        wp_clear_scheduled_hook('sspa_submission_worker_event');
        wp_clear_scheduled_hook('sspa_traffic_collection_tick');
        $run_id = SSPA_Run_Controller::active_run_id();
        if ($run_id) {
            SSPA_Run_Controller::cancel($run_id);
        }
    }

    public static function maybe_upgrade() {
        if (get_option('sspa_behaviour_version') !== SSPA_VERSION) {
            SSPA_Helper_Files::migrate_profiler_consent();
            delete_transient('sspa_rules_feed');
            delete_transient('sspa_rules_feed_backoff');
            delete_option('sspa_rules_pubkey');
            delete_option('sspa_hub_url');
            $traffic = SSPA_Traffic_Helper::migrate_config();
            if (is_wp_error($traffic) || false === $traffic) {
                throw new RuntimeException(is_wp_error($traffic) ? $traffic->get_error_message() : 'Could not migrate traffic configuration.');
            }
            update_option('sspa_behaviour_version', SSPA_VERSION, true);
        }
        $installed = get_option('sspa_db_version');
        if ($installed !== SSPA_Schema::DB_VERSION) {
            SSPA_Schema::upgrade($installed);
        }
    }
}
