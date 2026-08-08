<?php
defined('ABSPATH') || exit;

/**
 * Activation, deactivation and upgrade handling.
 */
class SSPA_Install {

    public static function activate() {
        SSPA_Schema::create_tables();
        SSPA_Token::secret();
        SSPA_Helper_Files::ensure_installed();
    }

    public static function deactivate() {
        // Remove everything we placed outside the plugin folder and restore any hold.
        SSPA_Helper_Files::remove_all();
        wp_clear_scheduled_hook('sspa_cleanup_event');
        wp_clear_scheduled_hook('sspa_submission_worker_event');
        $run_id = SSPA_Run_Controller::active_run_id();
        if ($run_id) {
            SSPA_Run_Controller::cancel($run_id);
        }
    }

    public static function maybe_upgrade() {
        $installed = get_option('sspa_db_version');
        if ($installed !== SSPA_Schema::DB_VERSION) {
            SSPA_Schema::upgrade($installed);
        }
    }
}
