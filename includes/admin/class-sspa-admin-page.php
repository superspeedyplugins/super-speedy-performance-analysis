<?php
defined('ABSPATH') || exit;

/**
 * The Performance Analysis admin page. All tabs render in one page load and switch
 * client-side (the house JS tab pattern) - no page reloads, no lost state.
 */
class SSPA_Admin_Page {

    /**
     * Retained method name for the existing bootstrap. The durable recorder also covers
     * plugin updates and is registered separately for cron/CLI update requests.
     */
    public static function register_toggle_prompt() {
        SSPA_Change_Set::register();
        add_action('admin_notices', array(__CLASS__, 'toggle_prompt_notice'));
    }

    public static function toggle_prompt_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }
        if (!empty($_GET['sspa_change_action']) && !empty($_GET['sspa_change_set']) && !empty($_GET['_wpnonce'])) {
            $action = sanitize_key(wp_unslash($_GET['sspa_change_action']));
            $change_set_id = sanitize_text_field(wp_unslash($_GET['sspa_change_set']));
            $nonce = sanitize_text_field(wp_unslash($_GET['_wpnonce']));
            if (wp_verify_nonce($nonce, 'sspa_change_set_action_' . $change_set_id)) {
                if ('snooze' === $action) {
                    SSPA_Change_Set::snooze($change_set_id);
                } elseif ('dismiss' === $action) {
                    SSPA_Change_Set::dismiss($change_set_id);
                }
            }
            return;
        }
        // Keep the context until the AJAX request has created the spot-check run.
        if (isset($_GET['sspa_autospot'])) {
            return;
        }
        $change_set = SSPA_Change_Set::pending();
        if (!$change_set || SSPA_Run_Controller::active_run_id()) {
            return;
        }
        $count = count($change_set['changes']);
        $quick_page_keys = SSPA_History_Series::quick_comparison_page_keys();
        $baseline_run_id = SSPA_History_Series::latest_compatible_run_id($quick_page_keys);
        $run_url = add_query_arg(array(
            'page' => 'sspa',
            'sspa_autospot' => '1',
            'sspa_change_set' => $change_set['id'],
            'sspa_baseline_run_id' => $baseline_run_id,
        ), admin_url('admin.php')) . '#overview';
        $base_action_url = add_query_arg(array(
            'sspa_change_set' => $change_set['id'],
        ));
        $snooze = wp_nonce_url(add_query_arg('sspa_change_action', 'snooze', $base_action_url), 'sspa_change_set_action_' . $change_set['id']);
        $dismiss = wp_nonce_url(add_query_arg('sspa_change_action', 'dismiss', $base_action_url), 'sspa_change_set_action_' . $change_set['id']);
        echo '<div class="notice notice-info"><p><strong>';
        /* translators: %d: number of changed plugins */
        echo esc_html(sprintf(_n('%d plugin change was detected.', '%d plugin changes were detected.', $count, 'super-speedy-performance-analysis'), $count));
        echo '</strong> ';
        esc_html_e('Run a quick Performance Analysis after your final update to compare this site with its previous point in time. If you are making more updates, finish them first.', 'super-speedy-performance-analysis');
        if ($baseline_run_id) {
            $baseline = SSPA_Run_Controller::run_row($baseline_run_id);
            echo ' ';
            echo esc_html(sprintf(
                /* translators: 1: analysis run ID, 2: analysis date */
                __('This will compare with Analysis #%1$d from %2$s.', 'super-speedy-performance-analysis'),
                (int) $baseline_run_id,
                mysql2date(get_option('date_format'), $baseline['started'], false)
            ));
        } else {
            echo ' ';
            esc_html_e('No compatible earlier analysis is available, so this run will become the first saved point for a later comparison.', 'super-speedy-performance-analysis');
        }
        echo '</p><p>';
        echo '<a class="button button-primary" href="' . esc_url($run_url) . '">' . esc_html__('Run quick comparison', 'super-speedy-performance-analysis') . '</a> ';
        echo '<a class="button" href="' . esc_url($snooze) . '">' . esc_html__('Remind me later', 'super-speedy-performance-analysis') . '</a> ';
        echo '<a href="' . esc_url($dismiss) . '">' . esc_html__('Dismiss this change set', 'super-speedy-performance-analysis') . '</a>';
        echo '</p></div>';
    }

    public static function addmenu() {
        global $admin_page_hooks;
        if (isset($admin_page_hooks['superspeedy'])) {
            // Shared Super Speedy menu registered by the settings submodule (or a sibling plugin).
            $page = add_submenu_page(
                'superspeedy',
                'Performance Analysis',
                'Performance Analysis',
                'manage_options',
                'sspa',
                array(__CLASS__, 'show'),
                40
            );
        } else {
            $page = add_menu_page(
                'Performance Analysis',
                'Performance',
                'manage_options',
                'sspa',
                array(__CLASS__, 'show'),
                'dashicons-performance'
            );
        }
        add_action('admin_print_scripts-' . $page, array(__CLASS__, 'enqueue_assets'));
    }

    public static function enqueue_assets() {
        wp_enqueue_script('sspa-chart-library', SSPA_PLUGIN_URL . 'includes/admin/js/sspa-chart-library.js', array('sspa-admin'), sspa_asset_version('includes/admin/js/sspa-chart-library.js'), true);
        wp_enqueue_script('sspa-measurement-chart', SSPA_PLUGIN_URL . 'includes/admin/js/sspa-measurement-chart.js', array('jquery', 'wp-i18n'), sspa_asset_version('includes/admin/js/sspa-measurement-chart.js'), true);
        wp_enqueue_script('sspa-ajax-profile', SSPA_PLUGIN_URL . 'includes/admin/js/sspa-ajax-profile.js', array('jquery', 'sspa-measurement-chart', 'sspa-chart-library'), sspa_asset_version('includes/admin/js/sspa-ajax-profile.js'), true);
        wp_enqueue_script('sspa-admin', SSPA_PLUGIN_URL . 'includes/admin/js/sspa-admin.js', array('jquery', 'sspa-transport'), sspa_asset_version('includes/admin/js/sspa-admin.js'), true);
        wp_enqueue_script('sspa-history-chart', SSPA_PLUGIN_URL . 'includes/admin/js/sspa-history-chart.js', array('jquery', 'sspa-chart-library', 'wp-i18n'), sspa_asset_version('includes/admin/js/sspa-history-chart.js'), true);
        wp_localize_script('sspa-history-chart', 'sspa_history_chart', array(
            /* translators: 1: analysis ID, 2: request sample number */
            'sample_heading' => __('Analysis #%1$d, request sample %2$d', 'super-speedy-performance-analysis'),
            /* translators: %d: analysis ID */
            'summary_heading' => __('Analysis #%d, page summary', 'super-speedy-performance-analysis'),
            /* translators: 1: source of evidence, 2: measurement state */
            'evidence_state' => __('Evidence: %1$s. %2$s.', 'super-speedy-performance-analysis'),
            'sources' => array(
                'retained_request_sample' => __('retained request sample', 'super-speedy-performance-analysis'),
                'per_run_median' => __('median across this analysis\'s requests', 'super-speedy-performance-analysis'),
            ),
            'retained_measurement' => __('retained measurement', 'super-speedy-performance-analysis'),
            'measured' => __('measured', 'super-speedy-performance-analysis'),
            'states' => array(
                'blocked' => __('blocked', 'super-speedy-performance-analysis'),
                'transport_error' => __('transport error', 'super-speedy-performance-analysis'),
                'http_error' => __('HTTP error', 'super-speedy-performance-analysis'),
                'missing' => __('missing measurement', 'super-speedy-performance-analysis'),
            ),
            'severities' => array(
                'error' => __('Error', 'super-speedy-performance-analysis'),
                'warning' => __('Warning', 'super-speedy-performance-analysis'),
                'notice' => __('Notice', 'super-speedy-performance-analysis'),
                'deprecated' => __('Deprecation', 'super-speedy-performance-analysis'),
                'strict' => __('Strict notice', 'super-speedy-performance-analysis'),
            ),
            /* translators: %d: HTTP response status code */
            'http_status' => __('HTTP status: %d', 'super-speedy-performance-analysis'),
            /* translators: %s: component name */
            'fatal' => __('Fatal error attributed to %s.', 'super-speedy-performance-analysis'),
            'unknown_component' => __('unknown component', 'super-speedy-performance-analysis'),
            /* translators: %d: observed plugin reaction count */
            'reactions' => __('Recorded plugin reactions: %d', 'super-speedy-performance-analysis'),
            'existing_handler' => __('PHP warning coverage is unavailable because another error handler was already installed. Its behaviour was left unchanged.', 'super-speedy-performance-analysis'),
            'unavailable' => __('Per-request PHP warnings were not recorded in this saved measurement. This does not mean the request had no warnings.', 'super-speedy-performance-analysis'),
            'aggregate' => __('This point is a page summary and has no per-request diagnostics. Choose Request wall time to inspect individual requests.', 'super-speedy-performance-analysis'),
            'partial' => __('Partial PHP diagnostic coverage: another handler replaced the observer.', 'super-speedy-performance-analysis'),
            'observed' => __('PHP diagnostics delivered to the observer while this request was being profiled. Other handlers may have consumed additional diagnostics.', 'super-speedy-performance-analysis'),
            /* translators: 1: observed diagnostic count, 2: retained diagnostic count */
            'counts' => __('Observed PHP diagnostics: %1$d; retained: %2$d.', 'super-speedy-performance-analysis'),
            'truncated' => __('Entries or messages were truncated.', 'super-speedy-performance-analysis'),
            'open_profile' => __('Open saved page profile', 'super-speedy-performance-analysis'),
            'open_measured_page' => __('Open measured page', 'super-speedy-performance-analysis'),
            'loading_measured_page' => __('Loading measured page link…', 'super-speedy-performance-analysis'),
            'measured_page_failed' => __('The measured page link could not be loaded.', 'super-speedy-performance-analysis'),
            'measured_page_action' => __('No ordinary page link was retained for this workflow. Open its saved profile to inspect the measured endpoint.', 'super-speedy-performance-analysis'),
            'profile_unavailable' => __('The profile viewer did not load. Reload the page to try again.', 'super-speedy-performance-analysis'),
            'representative_capture' => __('The full page profile contains its retained representative capture; it is not a separate full capture for every request sample.', 'super-speedy-performance-analysis'),
            /* translators: %s: diagnostic parsing error */
            'unreadable' => __('The saved diagnostic data could not be read: %s', 'super-speedy-performance-analysis'),
            /* translators: %d: observed diagnostic count */
            'tooltip_count' => __('Observed PHP diagnostics: %d', 'super-speedy-performance-analysis'),
            'tooltip_unavailable' => __('PHP warning coverage unavailable', 'super-speedy-performance-analysis'),
            'select_point' => __('Select this point for saved diagnostics', 'super-speedy-performance-analysis'),
        ));
        wp_enqueue_script('sspa-history-run-view', SSPA_PLUGIN_URL . 'includes/admin/js/sspa-history-run-view.js', array('jquery', 'sspa-admin'), sspa_asset_version('includes/admin/js/sspa-history-run-view.js'), true);
        wp_localize_script('sspa-history-run-view', 'sspa_history_run', array(
            'loading' => __('Loading saved report…', 'super-speedy-performance-analysis'),
            'failed' => __('The saved report could not be loaded.', 'super-speedy-performance-analysis'),
            'back' => __('Back to History', 'super-speedy-performance-analysis'),
            'retry' => __('Try again', 'super-speedy-performance-analysis'),
            'profile_unavailable' => __('The profile panel is unavailable. Reload this page to open the retained diagnostics.', 'super-speedy-performance-analysis'),
        ));
        wp_localize_script('sspa-admin', 'sspa_admin', array(
            'nonce' => wp_create_nonce('sspa_admin'),
            'download_prefix' => sspa_download_prefix(),
            'history_chart_asset' => SSPA_PLUGIN_URL . 'includes/admin/vendor/echarts-history.min.js',
            'quick_comparison_page_keys' => SSPA_History_Series::quick_comparison_page_keys(),
        ));
        wp_localize_script('sspa-admin', 'sspa_tools_i18n', array(
            'show' => __('Show installation steps', 'super-speedy-performance-analysis'),
            'hide' => __('Hide installation steps', 'super-speedy-performance-analysis'),
            'copied' => __('Copied', 'super-speedy-performance-analysis'),
        ));
        wp_enqueue_style('sspa-admin', SSPA_PLUGIN_URL . 'includes/admin/css/sspa-admin.css', array(), sspa_asset_version('includes/admin/css/sspa-admin.css'));
        wp_enqueue_script('sspa-workflows', SSPA_PLUGIN_URL . 'includes/admin/js/sspa-workflows.js', array('jquery'), sspa_asset_version('includes/admin/js/sspa-workflows.js'), true);
        wp_localize_script('sspa-workflows', 'sspa_workflows', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sspa_admin'),
            'i18n' => array(
                'loading' => __('Loading the latest items…', 'super-speedy-performance-analysis'),
                'ready' => __('Ready. The selected item will be saved with its current values.', 'super-speedy-performance-analysis'),
                'launching' => __('Loading the controlled editor…', 'super-speedy-performance-analysis'),
                'running' => __('Saving and profiling the update request…', 'super-speedy-performance-analysis'),
                'complete' => __('Save profiled. Opening the result…', 'super-speedy-performance-analysis'),
                'no_targets' => __('No editable items were found for this content type.', 'super-speedy-performance-analysis'),
                'no_transports' => __('No supported save transport was found.', 'super-speedy-performance-analysis'),
                'failed' => __('The workflow could not be started.', 'super-speedy-performance-analysis'),
            ),
        ));
    }

    private static function tabs() {
        return array(
            'overview' => __('Overview', 'super-speedy-performance-analysis'),
            'workflows' => __('Workflows', 'super-speedy-performance-analysis'),
            'pages' => __('Pages', 'super-speedy-performance-analysis'),
            'plugins' => __('Plugins', 'super-speedy-performance-analysis'),
            'ajax' => __('AJAX', 'super-speedy-performance-analysis'),
            'history' => __('History', 'super-speedy-performance-analysis'),
            'tools' => __('Tools', 'super-speedy-performance-analysis'),
            'traffic' => __('Traffic', 'super-speedy-performance-analysis'),
            'share' => __('Share', 'super-speedy-performance-analysis'),
        );
    }

    public static function show() {
        ?>
        <div class="wrap" id="sspa_main">
            <?php // The visible title lives in the tab bar; this keeps the h1 admin notices
                  // and screen readers both expect. ?>
            <h1 class="screen-reader-text">Super Speedy Performance Analysis</h1>
            <h2 class="nav-tab-wrapper sspa-tab-bar">
                <span class="sspa-brand">
                    Super Speedy Performance Analysis
                    <span class="sspa-ver-chip">v<?php echo esc_html(SSPA_VERSION); ?></span>
                </span>
                <?php
                $class = ' nav-tab-active';
                foreach (self::tabs() as $tab_id => $tab_name) {
                    echo '<a class="nav-tab' . esc_attr($class) . '" href="#' . esc_attr($tab_id) . '" data-tab="' . esc_attr($tab_id) . '">' . esc_html($tab_name) . '</a>';
                    $class = '';
                }
                ?>
            </h2>
            <?php
            foreach (array_keys(self::tabs()) as $tab_id) {
                $loaded = 'overview' === $tab_id;
                echo '<div class="tab-contents" data-tab="' . esc_attr($tab_id) . '" data-sspa-tab-loaded="' . ($loaded ? '1' : '0') . '"' . ($loaded ? '' : ' style="display:none"') . '>';
                if ($loaded) {
                    $tab_file = SSPA_PLUGIN_DIR . 'includes/admin/tabs/' . $tab_id . '.php';
                    if (file_exists($tab_file)) {
                        include $tab_file;
                    }
                } else {
                    echo '<p class="sspa-tab-loading"><span class="spinner is-active"></span> ' . esc_html__('This tab loads when first opened.', 'super-speedy-performance-analysis') . '</p>';
                }
                echo '</div>';
            }
            ?>

            <footer class="sspa-admin-footer">
                <div>
                    <?php esc_html_e('Performance Analysis', 'super-speedy-performance-analysis'); ?>
                    <span class="sspa-ver-chip">v<?php echo esc_html(SSPA_VERSION); ?></span>
                </div>
                <div>
                    <?php esc_html_e('By', 'super-speedy-performance-analysis'); ?>
                    <a href="https://www.superspeedyplugins.com/" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Super Speedy Plugins', 'super-speedy-performance-analysis'); ?></a>
                </div>
            </footer>

            <!-- Floating run monitor: visible on every tab while a run is active,
                 minimisable, survives page reloads (re-armed from the active run). -->
            <div id="sspa-runner" data-active-run="<?php echo esc_attr(SSPA_Run_Controller::active_run_id()); ?>" style="display:none">
                <div class="sspa-runner-head">
                    <span class="sspa-runner-title"><?php esc_html_e('Analysis running', 'super-speedy-performance-analysis'); ?></span>
                    <span class="sspa-runner-mini-summary"></span>
                    <button type="button" class="sspa-runner-toggle" aria-label="<?php esc_attr_e('Minimise', 'super-speedy-performance-analysis'); ?>">&#8211;</button>
                </div>
                <div class="sspa-runner-body">
                    <div class="sspa-progress-bar"><div class="sspa-progress-fill" style="width:0%"></div></div>
                    <p class="sspa-runner-counts"></p>
                    <p class="sspa-runner-current"></p>
                    <p class="sspa-runner-eta"></p>
                    <?php // Every measurement as it is taken. A bar alone does not show what a
                          // deep run is doing, and "216 measurements" reads as excessive until
                          // you can see it is one plugin across every page and cache mode. ?>
                    <ol class="sspa-runner-feed" aria-live="polite" aria-label="<?php esc_attr_e('Measurements taken', 'super-speedy-performance-analysis'); ?>"></ol>
                    <p class="sspa-runner-actions">
                        <button type="button" class="button" id="sspa-runner-cancel"><?php esc_html_e('Cancel run', 'super-speedy-performance-analysis'); ?></button>
                    </p>
                </div>
            </div>
        </div>
        <?php
    }
}
