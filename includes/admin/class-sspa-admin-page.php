<?php
defined('ABSPATH') || exit;

/**
 * The Performance Analysis admin page. All tabs render in one page load and switch
 * client-side (the house JS tab pattern) - no page reloads, no lost state.
 */
class SSPA_Admin_Page {

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
        wp_enqueue_script('sspa-admin', SSPA_PLUGIN_URL . 'includes/admin/js/sspa-admin.js', array('jquery'), SSPA_VERSION, true);
        wp_enqueue_style('sspa-admin', SSPA_PLUGIN_URL . 'includes/admin/css/sspa-admin.css', array(), SSPA_VERSION);
    }

    private static function tabs() {
        return array(
            'overview' => __('Overview', 'super-speedy-performance-analysis'),
            'pages' => __('Pages', 'super-speedy-performance-analysis'),
            'plugins' => __('Plugins', 'super-speedy-performance-analysis'),
            'history' => __('History', 'super-speedy-performance-analysis'),
            'share' => __('Share', 'super-speedy-performance-analysis'),
        );
    }

    public static function show() {
        ?>
        <div class="wrap" id="sspa_main">
            <h1>Super Speedy Performance Analysis</h1>
            <h2 class="nav-tab-wrapper">
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
        </div>
        <?php
    }
}
