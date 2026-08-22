<?php
defined('ABSPATH') || exit;

/**
 * Admin-bar "Analyse this page" runner: profiles the URL the admin is looking at (front
 * end or wp-admin) and shows the results in a popover styled like the floating run
 * monitor. Results are stored as ordinary profiles under a URL-derived page key on a run
 * of type 'adhoc', which the Overview/Pages "latest analysis" queries deliberately
 * ignore (they filter to baseline/spot), so a one-page check never masquerades as a
 * site-wide analysis. Re-opening the popover on the same URL shows the stored result;
 * Re-run profiles it again.
 */
class SSPA_Adhoc {

    public static function register() {
        add_action('admin_bar_menu', array(__CLASS__, 'admin_bar_node'), 90);
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue'));
        add_action('wp_ajax_sspa_adhoc_start', array(__CLASS__, 'ajax_start'));
        add_action('wp_ajax_sspa_adhoc_result', array(__CLASS__, 'ajax_result'));
    }

    private static function available() {
        return is_admin_bar_showing() && current_user_can('manage_options');
    }

    /** Our own settings page, where the Pages tab opens the same panel from a row click. */
    private static function on_settings_page() {
        return is_admin() && current_user_can('manage_options')
            && isset($_GET['page']) && 'sspa' === $_GET['page']; // phpcs:ignore WordPress.Security.NonceVerification
    }

    /**
     * The panel's assets are needed wherever it can be opened: the admin bar's button, and the
     * Pages tab. Keyed on the admin bar alone, a site that hides the bar inside wp-admin lost
     * the Pages drill-down entirely once it became this panel.
     */
    private static function panel_context() {
        return self::available() || self::on_settings_page();
    }

    public static function admin_bar_node($bar) {
        if (!self::available()) {
            return;
        }
        $bar->add_node(array(
            'id' => 'sspa-adhoc',
            'parent' => 'sspa-measure', // the PA menu, see class-sspa-admin-bar.php
            'title' => esc_html__('Analyse this page', 'super-speedy-performance-analysis'),
            'href' => '#',
            'meta' => array('title' => __('Profile this URL with Super Speedy Performance Analysis', 'super-speedy-performance-analysis')),
        ));

        // Second node, only where a purchase is the thing in front of you. Clicking it
        // shows the disclosure; it never starts a purchase on its own.
        if (self::checkout_flow_available()) {
            $bar->add_node(array(
                'id' => 'sspa-checkout',
                'parent' => 'sspa-measure',
                'title' => esc_html__('Analyse checkout & order flow', 'super-speedy-performance-analysis'),
                'href' => '#',
                'meta' => array('title' => __('Measure a real purchase and handling the order afterwards - viewing it and marking it completed', 'super-speedy-performance-analysis')),
            ));
        }
    }

    /**
     * Shown on the shop-facing pages a purchase starts from - the same conditional family
     * the capture already snapshots. Cheap: these are WooCommerce's own conditionals.
     */
    private static function checkout_flow_available() {
        if (!class_exists('WooCommerce') || is_admin()) {
            return false;
        }
        foreach (array('is_cart', 'is_checkout', 'is_product') as $fn) {
            if (function_exists($fn) && call_user_func($fn)) {
                return true;
            }
        }
        return false;
    }

    public static function enqueue() {
        if (!self::panel_context()) {
            return;
        }
        wp_enqueue_style('sspa-adhoc', SSPA_PLUGIN_URL . 'includes/admin/css/sspa-adhoc.css', array(), sspa_asset_version('includes/admin/css/sspa-adhoc.css'));
        wp_enqueue_script('sspa-adhoc', SSPA_PLUGIN_URL . 'includes/admin/js/sspa-adhoc.js', array('jquery', 'sspa-transport'), sspa_asset_version('includes/admin/js/sspa-adhoc.js'), true);
        // The CURRENT white wordmark (text only, per Dave - no icon), bundled with the
        // plugin - the settings submodule's copy is an older mark and submodules are
        // absent from some zips anyway. Source of truth:
        // marketing/site/company-icons-and-logos/super-speedy-plugins-logo-white-text-only.svg
        $logo = SSPA_PLUGIN_URL . 'includes/admin/img/super-speedy-plugins-white.svg';
        wp_localize_script('sspa-adhoc', 'sspa_adhoc', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sspa_admin'),
            'logo_url' => $logo,
            'version' => SSPA_VERSION,
            'download_prefix' => sspa_download_prefix(),
            'i18n' => array(
                'running' => __('Profiling this page…', 'super-speedy-performance-analysis'),
                'running_detail' => __('Warm-up + 3 measured samples, then analysis. Usually under a minute.', 'super-speedy-performance-analysis'),
                'failed' => __('The analysis failed - see the plugin page for details.', 'super-speedy-performance-analysis'),
                'rerun' => __('Re-run', 'super-speedy-performance-analysis'),
                'close' => __('Close', 'super-speedy-performance-analysis'),
                'copied' => __('Copied', 'super-speedy-performance-analysis'),
                'loading' => __('Loading…', 'super-speedy-performance-analysis'),
                'exporting' => __('Preparing export…', 'super-speedy-performance-analysis'),
                'export_failed' => __('The page diagnostic could not be exported.', 'super-speedy-performance-analysis'),
                'markdown_failed' => __('The Markdown report could not be exported.', 'super-speedy-performance-analysis'),
                // The plugin-impact picker. Built client-side from the plan endpoint so the
                // estimate can update as boxes are ticked, rather than after a round trip.
                // NOTHING is preselected: choosing which plugins get excluded from test
                // requests is the site owner's decision, made knowing what it involves.
                'plan_title' => __('Which plugins should I measure on this page?', 'super-speedy-performance-analysis'),
                'plan_title_site' => __('Which plugins should I measure the impact of?', 'super-speedy-performance-analysis'),
                'plan_hint' => __('Each chosen plugin is left out of our test requests only - it is never deactivated, no activation or deactivation hook fires, and your visitors always get the full site. A measurement is a warm-up plus three samples per page.', 'super-speedy-performance-analysis'),
                'plan_risk' => __('One caution: a plugin that depends on one you choose may notice it missing during those test requests and react. Reactions we can reach are stopped - your plugin list cannot change, activation and deactivation routines are silenced, and destructive database statements are refused - and any reaction is recorded, with the pair measured together from then on. Even so, choose plugins you understand.', 'super-speedy-performance-analysis'),
                'plan_pick' => __('Nothing is ticked until you tick it. Plugins are listed by the time attribution charges them, most expensive first.', 'super-speedy-performance-analysis'),
                'select_blamed' => __('Select blamed', 'super-speedy-performance-analysis'),
                'select_all' => __('Select every eligible plugin', 'super-speedy-performance-analysis'),
                'select_none' => __('Clear', 'super-speedy-performance-analysis'),
                'cache_modes' => __('Also measure with the object cache off and take a first sample in the site\'s ambient cache state (three times the measurements, for plugins that show an impact)', 'super-speedy-performance-analysis'),
                /* translators: 1: number of plugins, 2: number of measurements, 3: estimated duration */
                'estimate' => __('%1$s plugins × 1 page = %2$s measurements, about %3$s.', 'super-speedy-performance-analysis'),
                /* translators: 1: number of plugins, 2: number of pages, 3: number of measurements, 4: estimated duration */
                'estimate_site' => __('%1$s plugins × %2$s pages = %3$s measurements, about %4$s.', 'super-speedy-performance-analysis'),
                /* translators: 1: number of plugins, 2: number of measurements, 3: estimated duration */
                'estimate_screen' => __('All %1$s plugins, screened: each is first measured on its busiest pages (about %2$s measurements, %3$s), then only the plugins that show an impact get the full treatment.', 'super-speedy-performance-analysis'),
                /* translators: 1: number of additional measurements, 2: estimated duration */
                'estimate_phase2' => __('Plugins that show an impact are then re-measured with the object cache off and priming - up to %1$s more measurements, about %2$s, if every one of them does.', 'super-speedy-performance-analysis'),
                'estimate_none' => __('Tick at least one plugin.', 'super-speedy-performance-analysis'),
                'start_measuring' => __('Start measuring', 'super-speedy-performance-analysis'),
                'cancel' => __('Cancel', 'super-speedy-performance-analysis'),
                /* translators: %s: number of minutes */
                'minutes' => __('%s minutes', 'super-speedy-performance-analysis'),
                /* translators: %s: number of seconds */
                'seconds' => __('%s seconds', 'super-speedy-performance-analysis'),
                'no_cost' => __('nothing attributed', 'super-speedy-performance-analysis'),
                /* translators: %s: milliseconds attributed to this component on this page */
                'attributed_here' => __('%sms attributed here', 'super-speedy-performance-analysis'),
                /* translators: %s: milliseconds attributed to this component across the site */
                'attributed_site' => __('%sms attributed across the site', 'super-speedy-performance-analysis'),
                /* translators: %s: comma-separated plugins that must be measured together with this one */
                'with_group' => __('· measured together with %s, which cannot run without it', 'super-speedy-performance-analysis'),
            ),
        ));

        // The checkout-flow panel reuses this popover's CSS wholesale; it only needs its
        // own script because the disclosure step has no equivalent here.
        if (self::checkout_flow_available() || self::on_settings_page()) {
            wp_enqueue_script('sspa-checkout', SSPA_PLUGIN_URL . 'includes/admin/js/sspa-checkout.js', array('jquery', 'sspa-adhoc'), sspa_asset_version('includes/admin/js/sspa-checkout.js'), true);
            wp_localize_script('sspa-checkout', 'sspa_checkout', array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('sspa_admin'),
                'results_url' => admin_url('admin.php?page=sspa#pages'),
                'tools_url' => admin_url('admin.php?page=sspa#tools'),
                'excimer_prompt' => extension_loaded('excimer')
                    ? __('Re-run with Excimer to improve this data', 'super-speedy-performance-analysis')
                    : __('Install Excimer to improve this data', 'super-speedy-performance-analysis'),
                'logo_url' => $logo,
                'version' => SSPA_VERSION,
            ));
        }
    }

    /**
     * Normalise a URL for ad-hoc profiling and derive its stable page key. Same-site
     * only; fragments and our own cache-buster are stripped.
     *
     * @return array|WP_Error {url, page_key, variant}
     */
    public static function job_for($url) {
        $url = trim((string) $url);
        if (($hash = strpos($url, '#')) !== false) {
            $url = substr($url, 0, $hash);
        }
        $url = rtrim(preg_replace('/([?&])sspa_nc=[a-f0-9]*(&|$)/', '$1', $url), '?&');
        $parts = wp_parse_url($url);
        $home = wp_parse_url(home_url('/'));
        if (!$url || empty($parts['host']) || 0 !== strcasecmp($parts['host'], $home['host'])) {
            return new WP_Error('sspa_bad_url', __('Only URLs on this site can be profiled.', 'super-speedy-performance-analysis'));
        }
        $path = isset($parts['path']) ? $parts['path'] : '/';

        // If this URL is one the catalogue already knows - the shop, a product, the cart -
        // reuse ITS page key and variant. Otherwise analysing the shop page from the admin bar
        // would file its result under url-<hash>, invisible beside the same page measured by a
        // full analysis, and comparable with nothing.
        $known = self::catalogue_match($url);
        if ($known) {
            return array(
                'url' => $known['url'],
                'page_key' => $known['page_key'],
                'variant' => $known['variant'],
            );
        }

        return array(
            'url' => $url,
            'page_key' => 'url-' . substr(md5($url), 0, 12),
            'variant' => (false !== strpos($path, '/wp-admin')) ? 'admin' : 'anon',
        );
    }

    /**
     * The catalogue job whose URL addresses the same page as $url, or null.
     *
     * Compared on path plus the query arguments that actually select content: the catalogue
     * builds its URLs from permalinks, so trailing slashes and our own cache-buster differ
     * without meaning anything.
     */
    private static function catalogue_match($url) {
        $wanted = self::compare_key($url);
        if ('' === $wanted) {
            return null;
        }
        foreach (SSPA_Catalogue::build() as $job) {
            if (empty($job['url']) || 0 === strpos($job['page_key'], 'write-')) {
                continue;
            }
            // Probe endpoints answer with a stub, so they are never a match for a real page.
            if (in_array($job['page_key'], array('baseline', 'mail-probe'), true)) {
                continue;
            }
            if (self::compare_key($job['url']) === $wanted) {
                return $job;
            }
        }
        return null;
    }

    private static function compare_key($url) {
        $parts = wp_parse_url((string) $url);
        if (!is_array($parts)) {
            return '';
        }
        $path = isset($parts['path']) ? rtrim($parts['path'], '/') : '';
        $args = array();
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $args);
            foreach (array_keys($args) as $key) {
                if (0 === strpos($key, 'sspa_')) {
                    unset($args[$key]);
                }
            }
            ksort($args);
        }
        return strtolower($path) . '?' . http_build_query($args);
    }

    private static function guard() {
        check_ajax_referer('sspa_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('forbidden', 403);
        }
    }

    public static function ajax_start() {
        self::guard();
        $url = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';
        $job = self::job_for($url);
        if (is_wp_error($job)) {
            wp_send_json_error($job->get_error_message());
        }
        $run_id = SSPA_Run_Controller::start(array('type' => 'adhoc', 'url' => $url, 'trigger' => 'adminbar'));
        if (is_wp_error($run_id)) {
            wp_send_json_error($run_id->get_error_message());
        }
        wp_send_json_success(array('run_id' => $run_id, 'page_key' => $job['page_key']));
    }

    /**
     * The popover's single source of truth for a URL: an in-flight run to reattach to,
     * a stored result, or nothing yet.
     *
     * Since 0.14.0 this only RESOLVES - a URL to the newest profile of the page it addresses.
     * The panel itself is rendered by SSPA_Profile_Panel, the same renderer the Pages tab
     * uses, so the two views cannot show different subsets of the same capture again.
     */
    public static function ajax_result() {
        self::guard();
        $url = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';
        $fresh = !empty($_POST['fresh']);
        $job = self::job_for($url);
        if (is_wp_error($job)) {
            wp_send_json_error($job->get_error_message());
        }

        // An active adhoc run for this same URL? Tell the popover to reattach.
        $active = SSPA_Run_Controller::active_run_id();
        if ($active) {
            $queue = SSPA_Run_Queue::get($active);
            if (is_array($queue) && isset($queue['jobs'][0]['page_key']) && $queue['jobs'][0]['page_key'] === $job['page_key']) {
                wp_send_json_success(array('running' => (int) $active));
            }
        }

        $profile_id = SSPA_Profile_Panel::newest_profile_id_for_page($job['page_key']);
        if (!$profile_id) {
            wp_send_json_success(array('found' => false));
        }
        $html = SSPA_Profile_Panel::render($profile_id, array('cached' => !$fresh));
        if (is_wp_error($html)) {
            wp_send_json_error($html->get_error_message());
        }
        wp_send_json_success(array(
            'found' => true,
            'profile_id' => $profile_id,
            'html' => $html,
        ));
    }
}
