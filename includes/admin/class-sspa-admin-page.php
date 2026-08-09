<?php
defined('ABSPATH') || exit;

/**
 * The Performance Analysis admin page. All tabs render in one page load and switch
 * client-side (the house JS tab pattern) - no page reloads, no lost state.
 */
class SSPA_Admin_Page {

    /**
     * Offer a quick before/after profile whenever an admin toggles a plugin normally -
     * over time these spot profiles build the site's own per-plugin cost ledger.
     */
    public static function register_toggle_prompt() {
        $note = function ($plugin, $action) {
            $slug = dirname($plugin) !== '.' ? dirname($plugin) : basename($plugin, '.php');
            if ('super-speedy-performance-analysis' === $slug) {
                return;
            }
            set_transient('sspa_plugin_toggled', array('slug' => $slug, 'action' => $action), 10 * MINUTE_IN_SECONDS);
        };
        add_action('activated_plugin', function ($plugin) use ($note) {
            $note($plugin, 'activated');
        });
        add_action('deactivated_plugin', function ($plugin) use ($note) {
            $note($plugin, 'deactivated');
        });
        add_action('admin_notices', array(__CLASS__, 'toggle_prompt_notice'));
    }

    public static function toggle_prompt_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }
        if (isset($_GET['sspa_dismiss_toggle'])) {
            delete_transient('sspa_plugin_toggled');
            return;
        }
        // Keep the context until the AJAX request has created the spot-check run.
        if (isset($_GET['sspa_autospot'])) {
            return;
        }
        $toggled = get_transient('sspa_plugin_toggled');
        if (!is_array($toggled) || SSPA_Run_Controller::active_run_id()) {
            return;
        }
        $url = admin_url('admin.php?page=sspa&sspa_autospot=1#overview');
        $dismiss = add_query_arg('sspa_dismiss_toggle', '1');
        echo '<div class="notice notice-info is-dismissible"><p>';
        printf(
            /* translators: 1: plugin slug, 2: action */
            esc_html__('You just %2$s %1$s - want a 30-second performance spot-check to see what it changed? Compare runs on the History tab afterwards.', 'super-speedy-performance-analysis'),
            '<strong>' . esc_html($toggled['slug']) . '</strong>',
            esc_html($toggled['action'])
        );
        echo ' <a class="button button-small" href="' . esc_url($url) . '">' . esc_html__('Run spot-check', 'super-speedy-performance-analysis') . '</a>';
        echo ' <a href="' . esc_url($dismiss) . '">' . esc_html__('Dismiss', 'super-speedy-performance-analysis') . '</a>';
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
        wp_enqueue_script('sspa-admin', SSPA_PLUGIN_URL . 'includes/admin/js/sspa-admin.js', array('jquery'), sspa_asset_version('includes/admin/js/sspa-admin.js'), true);
        wp_localize_script('sspa-admin', 'sspa_admin', array(
            'nonce' => wp_create_nonce('sspa_admin'),
        ));
        wp_localize_script('sspa-admin', 'sspa_tools_i18n', array(
            'show' => __('Show installation steps', 'super-speedy-performance-analysis'),
            'hide' => __('Hide installation steps', 'super-speedy-performance-analysis'),
            'copied' => __('Copied', 'super-speedy-performance-analysis'),
        ));
        wp_enqueue_style('sspa-admin', SSPA_PLUGIN_URL . 'includes/admin/css/sspa-admin.css', array(), sspa_asset_version('includes/admin/css/sspa-admin.css'));
    }

    private static function tabs() {
        return array(
            'overview' => __('Overview', 'super-speedy-performance-analysis'),
            'pages' => __('Pages', 'super-speedy-performance-analysis'),
            'plugins' => __('Plugins', 'super-speedy-performance-analysis'),
            'history' => __('History', 'super-speedy-performance-analysis'),
            'tools' => __('Tools', 'super-speedy-performance-analysis'),
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
                echo '<div class="tab-contents" data-tab="' . esc_attr($tab_id) . '"' . ('overview' === $tab_id ? '' : ' style="display:none"') . '>';
                $tab_file = SSPA_PLUGIN_DIR . 'includes/admin/tabs/' . $tab_id . '.php';
                if (file_exists($tab_file)) {
                    include $tab_file;
                }
                echo '</div>';
            }
            ?>

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
                    <p class="sspa-runner-actions">
                        <button type="button" class="button" id="sspa-runner-cancel"><?php esc_html_e('Cancel run', 'super-speedy-performance-analysis'); ?></button>
                    </p>
                </div>
            </div>
        </div>
        <?php
    }
}
