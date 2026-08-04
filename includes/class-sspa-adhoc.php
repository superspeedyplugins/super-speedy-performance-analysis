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

    public static function admin_bar_node($bar) {
        if (!self::available()) {
            return;
        }
        $bar->add_node(array(
            'id' => 'sspa-adhoc',
            'title' => '<span class="ab-icon dashicons dashicons-performance" style="top:2px"></span>' . esc_html__('Analyse this page', 'super-speedy-performance-analysis'),
            'href' => '#',
            'meta' => array('title' => __('Profile this URL with Super Speedy Performance Analysis', 'super-speedy-performance-analysis')),
        ));
    }

    public static function enqueue() {
        if (!self::available()) {
            return;
        }
        wp_enqueue_style('sspa-adhoc', SSPA_PLUGIN_URL . 'includes/admin/css/sspa-adhoc.css', array(), SSPA_VERSION);
        wp_enqueue_script('sspa-adhoc', SSPA_PLUGIN_URL . 'includes/admin/js/sspa-adhoc.js', array('jquery'), SSPA_VERSION, true);
        // The wordmark ships in the shared settings submodule; zips built without
        // submodules simply get a text-only header.
        $logo = file_exists(SSPA_PLUGIN_DIR . 'super-speedy-settings/super-speedy-plugins-white.svg')
            ? SSPA_PLUGIN_URL . 'super-speedy-settings/super-speedy-plugins-white.svg'
            : '';
        wp_localize_script('sspa-adhoc', 'sspa_adhoc', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sspa_admin'),
            'results_url' => admin_url('admin.php?page=sspa#pages'),
            'logo_url' => $logo,
            'i18n' => array(
                'running' => __('Profiling this page…', 'super-speedy-performance-analysis'),
                'running_detail' => __('Warm-up + 3 measured samples, then analysis. Usually under a minute.', 'super-speedy-performance-analysis'),
                'failed' => __('The analysis failed - see the plugin page for details.', 'super-speedy-performance-analysis'),
                'rerun' => __('Re-run', 'super-speedy-performance-analysis'),
                'cached' => __('Cached result', 'super-speedy-performance-analysis'),
                'fresh' => __('Fresh result', 'super-speedy-performance-analysis'),
                'full' => __('Open in Performance Analysis', 'super-speedy-performance-analysis'),
                'close' => __('Close', 'super-speedy-performance-analysis'),
                'copied' => __('Copied', 'super-speedy-performance-analysis'),
                'copy_hint' => __('Click to copy the full query', 'super-speedy-performance-analysis'),
                'as_visitor' => __('profiled as a logged-out visitor', 'super-speedy-performance-analysis'),
                'as_admin' => __('profiled as admin', 'super-speedy-performance-analysis'),
                'just_now' => __('just now', 'super-speedy-performance-analysis'),
                'ago' => __('%s ago', 'super-speedy-performance-analysis'),
            ),
        ));
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
        $parts = parse_url($url);
        $home = parse_url(home_url('/'));
        if (!$url || empty($parts['host']) || 0 !== strcasecmp($parts['host'], $home['host'])) {
            return new WP_Error('sspa_bad_url', __('Only URLs on this site can be profiled.', 'super-speedy-performance-analysis'));
        }
        $path = isset($parts['path']) ? $parts['path'] : '/';
        return array(
            'url' => $url,
            'page_key' => 'url-' . substr(md5($url), 0, 12),
            'variant' => (false !== strpos($path, '/wp-admin')) ? 'admin' : 'anon',
        );
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
     */
    public static function ajax_result() {
        global $wpdb;
        self::guard();
        $url = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';
        $job = self::job_for($url);
        if (is_wp_error($job)) {
            wp_send_json_error($job->get_error_message());
        }

        // An active adhoc run for this same URL? Tell the popover to reattach.
        $active = SSPA_Run_Controller::active_run_id();
        if ($active) {
            $queue = get_option('sspa_queue_' . $active);
            if (is_array($queue) && isset($queue['jobs'][0]['page_key']) && $queue['jobs'][0]['page_key'] === $job['page_key']) {
                wp_send_json_success(array('running' => (int) $active));
            }
        }

        $profiles = SSPA_Schema::table('profiles');
        $runs = SSPA_Schema::table('runs');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT p.* FROM $profiles p INNER JOIN $runs r ON r.id = p.run_id
             WHERE p.page_key = %s AND r.status = 'done'
             ORDER BY p.id DESC LIMIT 1",
            $job['page_key']
        ), ARRAY_A);
        if (!$row) {
            wp_send_json_success(array('found' => false));
        }

        $capture = $row['profile_blob'] ? json_decode((string) @gzuncompress($row['profile_blob']), true) : null;
        $queries = array();
        if (is_array($capture) && !empty($capture['sql']['queries'])) {
            $queries = $capture['sql']['queries'];
            usort($queries, function ($a, $b) {
                return $b['ms'] <=> $a['ms'];
            });
            $queries = array_map(function ($q) {
                return array(
                    'sql' => null !== $q['sql'] ? $q['sql'] : $q['fp'],
                    'ms' => round((float) $q['ms'], 1),
                    'rows' => $q['rows'],
                    'component' => $q['component'],
                );
            }, array_slice($queries, 0, 5));
        }

        wp_send_json_success(array(
            'found' => true,
            'created' => get_date_from_gmt($row['created'], get_option('date_format') . ' ' . get_option('time_format')),
            // Server-side age so the popover can say "5m ago" without the viewer doing
            // timezone arithmetic against the site's clock.
            'age_seconds' => max(0, time() - (int) strtotime($row['created'] . ' UTC')),
            'variant' => $row['variant'],
            'gen_ms' => null !== $row['page_gen_ms'] ? round((float) $row['page_gen_ms'], 1) : null,
            'sql_ms' => null !== $row['sql_ms'] ? round((float) $row['sql_ms'], 1) : null,
            'sql_count' => null !== $row['sql_count'] ? (int) $row['sql_count'] : null,
            'http_ms' => null !== $row['http_ms'] ? round((float) $row['http_ms'], 1) : null,
            'peak_mem' => $row['peak_mem_bytes'] ? size_format((int) $row['peak_mem_bytes']) : null,
            'blocked_by' => $row['blocked_by'],
            'boot' => (is_array($capture) && isset($capture['boot'])) ? $capture['boot'] : null,
            'queries' => $queries,
        ));
    }
}
