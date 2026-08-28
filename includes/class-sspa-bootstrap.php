<?php
defined('ABSPATH') || exit;

/** Cheap hook registration. Feature classes load only when WordPress calls their hook. */
class SSPA_Bootstrap {

    public static function autoload($class) {
        if (0 !== strpos($class, 'SSPA_')) {
            return;
        }
        $slug = 'class-' . strtolower(str_replace('_', '-', $class)) . '.php';
        foreach (array('includes/', 'includes/admin/', 'includes/community/', 'includes/traffic/', 'includes/cli/') as $dir) {
            $file = SSPA_PLUGIN_DIR . $dir . $slug;
            if (is_file($file)) {
                require_once $file;
                return;
            }
        }
    }

    public static function register() {
        spl_autoload_register(array(__CLASS__, 'autoload'));

        if (SSPA_DB_VERSION !== get_option('sspa_db_version')) {
            add_action('plugins_loaded', array('SSPA_Install', 'maybe_upgrade'));
        }
        add_action('sspa_process_batch_event', array('SSPA_Run_Controller', 'process_batch'));
        add_action('sspa_cleanup_event', array('SSPA_Run_Controller', 'cleanup'));
        add_action('sspa_submission_worker_event', array('SSPA_Community_Worker', 'run'));
        add_action('sspa_traffic_collection_tick', array('SSPA_Traffic_Collection', 'scheduled_tick'), 10, 1);

        if (get_option('sspa_dropin_hold') || get_option('sspa_oc_hold')) {
            add_action('plugins_loaded', array('SSPA_Helper_Files', 'stale_hold_check'), 20);
        }
        if (get_option('sspa_share_optin') || get_option('sspa_share_manual_pending')) {
            add_action('init', array('SSPA_Community_Worker', 'maybe_nudge'));
        }

        $ajax = array(
            'sspa_start_run' => array('SSPA_Run_Controller', 'ajax_start_run'),
            'sspa_process_batch' => array('SSPA_Run_Controller', 'ajax_process_batch'),
            'sspa_run_status' => array('SSPA_Run_Controller', 'ajax_run_status'),
            'sspa_cancel_run' => array('SSPA_Run_Controller', 'ajax_cancel_run'),
            'sspa_plugin_detail' => array('SSPA_Run_Controller', 'ajax_plugin_detail'),
            'sspa_attribution' => array('SSPA_Run_Controller', 'ajax_attribution'),
            'sspa_render_tab' => array('SSPA_Run_Controller', 'ajax_render_tab'),
            'sspa_submission_tick' => array('SSPA_Run_Controller', 'ajax_submission_tick'),
            'sspa_prune_blobs' => array('SSPA_Run_Controller', 'ajax_prune_blobs'),
            'sspa_replace_stale_dropin' => array('SSPA_Run_Controller', 'ajax_replace_stale_dropin'),
            'sspa_tools_recheck' => array('SSPA_Run_Controller', 'ajax_tools_recheck'),
            'sspa_share_optin' => array('SSPA_Run_Controller', 'ajax_share_optin'),
            'sspa_publisher_toggle' => array('SSPA_Run_Controller', 'ajax_publisher_toggle'),
            'sspa_payload_preview' => array('SSPA_Run_Controller', 'ajax_payload_preview'),
            'sspa_submit_now' => array('SSPA_Run_Controller', 'ajax_submit_now'),
            'sspa_community_backfill' => array('SSPA_Run_Controller', 'ajax_community_backfill'),
            'sspa_outbox_action' => array('SSPA_Run_Controller', 'ajax_outbox_action'),
            'sspa_share_run' => array('SSPA_Run_Controller', 'ajax_share_run'),
            'sspa_uninstall_setting' => array('SSPA_Run_Controller', 'ajax_uninstall_setting'),
            'sspa_cache_recon_export' => array('SSPA_Cache_Recon', 'ajax_export'),
            'sspa_cache_delivery_prepare' => array('SSPA_Cache_Delivery', 'ajax_prepare'),
            'sspa_cache_delivery_server_probe' => array('SSPA_Cache_Delivery', 'ajax_server_probe'),
            'sspa_cache_delivery_complete' => array('SSPA_Cache_Delivery', 'ajax_complete'),
            'sspa_browser_next' => array('SSPA_Browser_Transport', 'ajax_next'),
            'sspa_browser_record' => array('SSPA_Browser_Transport', 'ajax_record'),
            'sspa_checkout_preflight' => array('SSPA_Checkout_Flow', 'ajax_preflight'),
            'sspa_checkout_start' => array('SSPA_Checkout_Flow', 'ajax_start'),
            'sspa_checkout_result' => array('SSPA_Checkout_Flow', 'ajax_result'),
            'sspa_adhoc_start' => array('SSPA_Adhoc', 'ajax_start'),
            'sspa_adhoc_result' => array('SSPA_Adhoc', 'ajax_result'),
            'sspa_admin_save_prepare' => array('SSPA_Admin_Save', 'ajax_prepare'),
            'sspa_admin_save_finish' => array('SSPA_Admin_Save', 'ajax_finish'),
            'sspa_workflow_targets' => array('SSPA_Workflow_Analysis', 'ajax_targets'),
            'sspa_workflow_launch' => array('SSPA_Workflow_Analysis', 'ajax_launch'),
            'sspa_profile_panel' => array('SSPA_Profile_Panel', 'ajax_panel'),
            'sspa_profile_export' => array('SSPA_Profile_Panel', 'ajax_export'),
            'sspa_impact_plan' => array('SSPA_Profile_Panel', 'ajax_impact_plan'),
            'sspa_markdown_export' => array('SSPA_Markdown_Export', 'ajax_export'),
            'sspa_traffic_start' => array('SSPA_Traffic_Ajax', 'start'),
            'sspa_traffic_status' => array('SSPA_Traffic_Ajax', 'status'),
            'sspa_traffic_stop' => array('SSPA_Traffic_Ajax', 'stop'),
            'sspa_traffic_observations' => array('SSPA_Traffic_Ajax', 'observations'),
            'sspa_traffic_delete' => array('SSPA_Traffic_Ajax', 'delete'),
        );
        foreach ($ajax as $action => $callback) {
            add_action('wp_ajax_' . $action, $callback);
        }

        if (!empty($GLOBALS['sspa_flags'])) {
            add_action('template_redirect', array('SSPA_Probes', 'maybe_handle'), 0);
            if (isset($GLOBALS['sspa_flags']['ck']) && 'flow' === $GLOBALS['sspa_flags']['ck']) {
                add_action('plugins_loaded', array('SSPA_Checkout_Flow', 'maybe_arm_request'), 1);
            }
        }
        // Synthetic measurement accounts are refused the credential login path.
        SSPA_Auth::register();

        // The PA menu is registered first (80) so the analysis nodes below can hang off it.
        SSPA_Admin_Bar::register();
        add_action('admin_bar_menu', array('SSPA_Adhoc', 'admin_bar_node'), 90);
        add_action('admin_bar_menu', array('SSPA_Admin_Save', 'admin_bar_node'), 91);
        add_action('wp_enqueue_scripts', function () {
            if (is_admin_bar_showing()) {
                SSPA_Adhoc::enqueue();
                SSPA_Browser_Transport::register_script();
            }
        });
        add_action('admin_enqueue_scripts', array('SSPA_Adhoc', 'enqueue'));
        add_action('admin_enqueue_scripts', array('SSPA_Admin_Save', 'enqueue'));
        add_action('admin_enqueue_scripts', array('SSPA_Browser_Transport', 'register_script'));
    }
}
