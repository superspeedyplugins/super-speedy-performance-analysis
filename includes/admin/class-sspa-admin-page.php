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
        $run_url = add_query_arg(array(
            'page' => 'sspa',
            'sspa_autospot' => '1',
            'sspa_change_set' => $change_set['id'],
        ), admin_url('admin.php')) . '#overview';
        $base_action_url = add_query_arg(array(
            'sspa_change_set' => $change_set['id'],
        ));
        $snooze = wp_nonce_url(add_query_arg('sspa_change_action', 'snooze', $base_action_url), 'sspa_change_set_action_' . $change_set['id']);
        $dismiss = wp_nonce_url(add_query_arg('sspa_change_action', 'dismiss', $base_action_url), 'sspa_change_set_action_' . $change_set['id']);
        echo '<div class="notice notice-info"><p><strong>';
        /* translators: %d: number of changed plugins */
        printf(esc_html(_n('A plugin change was detected.', '%d plugin changes were detected.', $count, 'super-speedy-performance-analysis')), $count);
        echo '</strong> ';
        esc_html_e('Run a quick Performance Analysis after your final update to compare this site with its previous point in time. If you are making more updates, finish them first.', 'super-speedy-performance-analysis');
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
        wp_enqueue_script('sspa-admin', SSPA_PLUGIN_URL . 'includes/admin/js/sspa-admin.js', array('jquery', 'sspa-transport'), sspa_asset_version('includes/admin/js/sspa-admin.js'), true);
        wp_enqueue_script('sspa-history-chart', SSPA_PLUGIN_URL . 'includes/admin/js/sspa-history-chart.js', array('jquery', 'sspa-admin'), sspa_asset_version('includes/admin/js/sspa-history-chart.js'), true);
        wp_localize_script('sspa-admin', 'sspa_admin', array(
            'nonce' => wp_create_nonce('sspa_admin'),
            'download_prefix' => sspa_download_prefix(),
            'history_chart_asset' => SSPA_PLUGIN_URL . 'includes/admin/vendor/echarts-history.min.js',
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
