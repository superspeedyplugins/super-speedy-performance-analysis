<?php
/**
 * The Performance Analysis admin-bar menu.
 *
 * One small node, present on every screen, carrying three things:
 *
 *   1. the analysis actions, which used to be separate top-level admin-bar buttons and now
 *      hang underneath this one, reclaiming the bar width they took;
 *   2. the caches you have to clear to get a fair measurement, each shown only when the
 *      thing it clears actually exists on this site;
 *   3. one line of state, including a permanent nudge when excimer is missing.
 *
 * Deliberately NOT a request inspector. A per-request query list belongs in the page panel,
 * which already does it properly and only when asked. This node must stay cheap enough to
 * render on every admin page, so the only work it does is `SSPA_Report::summary()` - two
 * queries reading the last completed run - plus capability checks that are all in-process.
 *
 * Nothing here analyses anything on page load.
 */

defined('ABSPATH') || exit;

class SSPA_Admin_Bar {

    const ACTION = 'sspa_bar';
    const BYPASS_COOKIE = 'sspa_bypass_cache';

    public static function register() {
        add_action('admin_bar_menu', array(__CLASS__, 'nodes'), 80);
        add_action('admin_post_' . self::ACTION, array(__CLASS__, 'handle'));
        add_action('admin_head', array(__CLASS__, 'styles'));
        add_action('wp_head', array(__CLASS__, 'styles'));
        add_action('admin_notices', array(__CLASS__, 'result_notice'));
    }

    private static function allowed() {
        return is_admin_bar_showing() && current_user_can('manage_options');
    }

    /** The latest score, or null when nothing has been analysed yet. */
    private static function summary() {
        if (!class_exists('SSPA_Report') || !method_exists('SSPA_Report', 'summary')) {
            return null;
        }
        return SSPA_Report::summary();
    }

    private static function action_url($do, $extra = array()) {
        $args = array_merge(array(
            'action' => self::ACTION,
            'do' => $do,
            'sspa_return' => rawurlencode(self::current_url()),
        ), $extra);
        return wp_nonce_url(add_query_arg($args, admin_url('admin-post.php')), self::ACTION . '_' . $do);
    }

    private static function current_url() {
        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
        $uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '/';
        if ('' === $host) {
            return home_url('/');
        }
        return (is_ssl() ? 'https://' : 'http://') . $host . $uri;
    }

    // ---------------------------------------------------------------- the node

    public static function nodes($bar) {
        if (!self::allowed()) {
            return;
        }

        $summary = self::summary();
        $score = (is_array($summary) && null !== $summary['score']) ? (int) $summary['score'] : null;

        $label = '<span class="ab-icon sspa-bolt" aria-hidden="true">&#9889;</span>'
            . '<span class="ab-label">PA'
            . (null === $score ? '' : ' <span class="sspa-bar-score">' . esc_html($score) . '</span>')
            . '</span>';

        $bar->add_node(array(
            'id' => 'sspa-menu',
            'title' => $label,
            'href' => admin_url('admin.php?page=sspa'),
            'meta' => array(
                'title' => null === $score
                    ? __('Super Speedy Performance Analysis - nothing analysed yet', 'super-speedy-performance-analysis')
                    : sprintf(__('Super Speedy Performance Analysis - last score %d/100', 'super-speedy-performance-analysis'), $score),
            ),
        ));

        self::group_measure($bar, $summary);
        self::group_clear($bar);
        self::group_state($bar, $summary);
        self::group_share($bar, $summary);
    }

    /**
     * A. The analysis actions, in the order you reach for them.
     *
     * The group is declared EMPTY here and filled at priority 90/91 by SSPA_Adhoc (analyse
     * this page, analyse checkout) and SSPA_Admin_Save (analyse update/save), so those land
     * first. The report entry goes in a group of its own, declared afterwards, because
     * "run your first site analysis" is the least urgent thing in the menu and should not
     * sit above the button you actually came for.
     */
    private static function group_measure($bar, $summary) {
        $bar->add_group(array('id' => 'sspa-measure', 'parent' => 'sspa-menu'));
        $bar->add_group(array('id' => 'sspa-report', 'parent' => 'sspa-menu'));

        if (is_array($summary)) {
            $bar->add_node(array(
                'id' => 'sspa-open-report',
                'parent' => 'sspa-report',
                'title' => __('Open the full report', 'super-speedy-performance-analysis'),
                'href' => admin_url('admin.php?page=sspa'),
            ));
            return;
        }

        // No site-wide run. Page analyses are stored separately and deliberately do not
        // produce a site score, so say which one is missing rather than "nothing yet".
        $pages = class_exists('SSPA_Report') && method_exists('SSPA_Report', 'page_analysis_count')
            ? SSPA_Report::page_analysis_count()
            : 0;
        $bar->add_node(array(
            'id' => 'sspa-first-run',
            'parent' => 'sspa-report',
            'title' => $pages
                ? __('Run a site-wide analysis for a score', 'super-speedy-performance-analysis')
                : __('Run your first site analysis', 'super-speedy-performance-analysis'),
            'href' => admin_url('admin.php?page=sspa'),
        ));
    }

    /**
     * B. Clear before measuring.
     *
     * Every entry is conditional on the thing existing. A menu item that silently does
     * nothing is worse than no menu item, so nothing here is shown "just in case".
     */
    private static function group_clear($bar) {
        $bar->add_group(array('id' => 'sspa-clear', 'parent' => 'sspa-menu', 'meta' => array('class' => 'ab-sub-secondary')));

        $bar->add_node(array(
            'id' => 'sspa-clear-heading',
            'parent' => 'sspa-clear',
            'title' => '<span class="sspa-bar-heading">' . esc_html__('Clear before measuring', 'super-speedy-performance-analysis') . '</span>',
            'meta' => array('tabindex' => -1),
        ));

        $bar->add_node(array(
            'id' => 'sspa-clear-ours',
            'parent' => 'sspa-clear',
            'title' => __('Super Speedy caches', 'super-speedy-performance-analysis'),
            'href' => self::action_url('clear_ours'),
            'meta' => array('title' => __('Our own transients only. Small and safe: nothing else on the site is touched.', 'super-speedy-performance-analysis')),
        ));

        $bar->add_node(array(
            'id' => 'sspa-clear-transients',
            'parent' => 'sspa-clear',
            'title' => __('All transients', 'super-speedy-performance-analysis'),
            'href' => self::action_url('clear_transients'),
            'meta' => array('title' => __('Every plugin\'s cached data. The site will be slower until they refill, which is the point when you are measuring.', 'super-speedy-performance-analysis')),
        ));

        if (wp_using_ext_object_cache()) {
            $bar->add_node(array(
                'id' => 'sspa-flush-object-cache',
                'parent' => 'sspa-clear',
                'title' => __('Object cache', 'super-speedy-performance-analysis'),
                'href' => self::action_url('flush_object_cache'),
                'meta' => array('title' => __('Flushes the persistent object cache. On a shared backend this affects every site using it.', 'super-speedy-performance-analysis')),
            ));
        }

        $page_cache = self::page_cache_integration();
        if ($page_cache) {
            if (!empty($page_cache['url'])) {
                $bar->add_node(array(
                    'id' => 'sspa-purge-url',
                    'parent' => 'sspa-clear',
                    'title' => sprintf(__('Page cache: this URL (%s)', 'super-speedy-performance-analysis'), $page_cache['name']),
                    'href' => self::action_url('purge_url'),
                ));
            }
            $bar->add_node(array(
                'id' => 'sspa-purge-all',
                'parent' => 'sspa-clear',
                'title' => sprintf(__('Page cache: everything (%s)', 'super-speedy-performance-analysis'), $page_cache['name']),
                'href' => self::action_url('purge_all'),
                'meta' => array('title' => __('Expensive on a busy site: every cached page has to be rebuilt.', 'super-speedy-performance-analysis')),
            ));
        }

        if (function_exists('opcache_reset')) {
            $bar->add_node(array(
                'id' => 'sspa-opcache',
                'parent' => 'sspa-clear',
                'title' => __('OPcache', 'super-speedy-performance-analysis'),
                'href' => self::action_url('reset_opcache'),
                'meta' => array('title' => __('Matters after deploying code, which is exactly when you re-measure.', 'super-speedy-performance-analysis')),
            ));
        }

        $bar->add_node(array(
            'id' => 'sspa-forget-page',
            'parent' => 'sspa-clear',
            'title' => __('This page\'s stored result', 'super-speedy-performance-analysis'),
            'href' => self::action_url('forget_page'),
            'meta' => array('title' => __('So "Analyse this page" measures again instead of reopening the stored panel.', 'super-speedy-performance-analysis')),
        ));

        // D. The bypass cookie, shown only where a page cache exists to bypass.
        //
        // Honest about what it is: setting a cookie is all WE can do. No page cache reads a
        // cookie it was not told about, so the tooltip names the cookie and the host or the
        // cache plugin has to be given one rule that honours it. Without that rule this
        // would be a control that quietly does nothing, which is worse than no control.
        if ($page_cache) {
            $bypassing = !empty($_COOKIE[self::BYPASS_COOKIE]);
            $bar->add_node(array(
                'id' => 'sspa-bypass',
                'parent' => 'sspa-clear',
                'title' => $bypassing
                    ? '<span class="sspa-bar-on">' . esc_html__('Cache bypass cookie: SET', 'super-speedy-performance-analysis') . '</span>'
                    : esc_html__('Set the cache-bypass cookie for me', 'super-speedy-performance-analysis'),
                'href' => self::action_url($bypassing ? 'bypass_off' : 'bypass_on'),
                'meta' => array('title' => sprintf(
                    /* translators: %s: the cookie name. */
                    __('Sets %s for this browser only. Your page cache must be configured to skip requests carrying it - most already skip logged-in users anyway.', 'super-speedy-performance-analysis'),
                    self::BYPASS_COOKIE
                )),
            ));
        }
    }

    /** C. One line of state each, and a permanent nudge where a capability is missing. */
    private static function group_state($bar, $summary) {
        $bar->add_group(array('id' => 'sspa-state', 'parent' => 'sspa-menu', 'meta' => array('class' => 'ab-sub-secondary')));

        $bar->add_node(array(
            'id' => 'sspa-state-heading',
            'parent' => 'sspa-state',
            'title' => '<span class="sspa-bar-heading">' . esc_html__('This site', 'super-speedy-performance-analysis') . '</span>',
            'meta' => array('tabindex' => -1),
        ));

        if (is_array($summary)) {
            $when = !empty($summary['finished']) ? strtotime($summary['finished'] . ' UTC') : 0;
            $bar->add_node(array(
                'id' => 'sspa-state-score',
                'parent' => 'sspa-state',
                'title' => sprintf(
                    /* translators: 1: score out of 100, 2: human readable age. */
                    esc_html__('Last measured: %1$s/100, %2$s ago', 'super-speedy-performance-analysis'),
                    null === $summary['score'] ? '?' : (int) $summary['score'],
                    $when ? human_time_diff($when) : '?'
                ),
                'href' => admin_url('admin.php?page=sspa'),
            ));
        }

        $bar->add_node(array(
            'id' => 'sspa-state-object-cache',
            'parent' => 'sspa-state',
            'title' => wp_using_ext_object_cache()
                ? esc_html__('Object cache: persistent', 'super-speedy-performance-analysis')
                : '<span class="sspa-bar-warn">' . esc_html__('Object cache: none', 'super-speedy-performance-analysis') . '</span>',
            'href' => admin_url('admin.php?page=sspa&tab=tools'),
        ));

        $excimer = class_exists('SSPA_Excimer') && SSPA_Excimer::available();
        $bar->add_node(array(
            'id' => 'sspa-state-excimer',
            'parent' => 'sspa-state',
            'title' => $excimer
                ? esc_html__('Excimer: installed', 'super-speedy-performance-analysis')
                : '<span class="sspa-bar-warn">' . esc_html__('Excimer not installed - no function-level detail', 'super-speedy-performance-analysis') . '</span>',
            'href' => admin_url('admin.php?page=sspa&tab=tools'),
            'meta' => array('title' => $excimer
                ? __('Profiles carry a by-function breakdown.', 'super-speedy-performance-analysis')
                : __('Install the free excimer extension to see which PHP functions the time went into. The Tools tab generates the commands for this server.', 'super-speedy-performance-analysis')),
        ));

        $digests = class_exists('SSPA_Digests') && SSPA_Digests::readable();
        $bar->add_node(array(
            'id' => 'sspa-state-digests',
            'parent' => 'sspa-state',
            'title' => $digests
                ? esc_html__('MySQL digests: readable', 'super-speedy-performance-analysis')
                : '<span class="sspa-bar-warn">' . esc_html__('MySQL digests unavailable - no rows-examined', 'super-speedy-performance-analysis') . '</span>',
            'href' => admin_url('admin.php?page=sspa&tab=tools'),
            'meta' => array('title' => $digests
                ? __('Queries reading far more rows than they return can be detected.', 'super-speedy-performance-analysis')
                : __('performance_schema is off or unreadable, so hidden full scans cannot be seen. The Tools tab writes the one GRANT needed.', 'super-speedy-performance-analysis')),
        ));
    }

    /** E. Hand the result to a person or an LLM. */
    private static function group_share($bar, $summary) {
        if (!is_array($summary)) {
            return;
        }
        $bar->add_group(array('id' => 'sspa-share', 'parent' => 'sspa-menu', 'meta' => array('class' => 'ab-sub-secondary')));
        $bar->add_node(array(
            'id' => 'sspa-copy-markdown',
            'parent' => 'sspa-share',
            'title' => __('Download this analysis as Markdown', 'super-speedy-performance-analysis'),
            'href' => self::action_url('markdown', array('run' => (int) $summary['run_id'])),
            'meta' => array('title' => __('Privacy-safe: no SQL literals, no query values, no identifiers. For a developer, a host or an LLM.', 'super-speedy-performance-analysis')),
        ));
    }

    // ---------------------------------------------------------------- caches

    /**
     * The page cache in use, if we can recognise it.
     *
     * Recognition is by the plugin's own public API, never by poking its files. Where a
     * plugin offers no per-URL purge, only the everything entry is offered rather than a
     * per-URL button that quietly purges the lot.
     */
    private static function page_cache_integration() {
        if (defined('WP_ROCKET_VERSION') && function_exists('rocket_clean_domain')) {
            return array('key' => 'rocket', 'name' => 'WP Rocket', 'url' => function_exists('rocket_clean_files'));
        }
        if (class_exists('LiteSpeed\Purge') || defined('LSCWP_V')) {
            return array('key' => 'litespeed', 'name' => 'LiteSpeed Cache', 'url' => true);
        }
        if (function_exists('w3tc_flush_all')) {
            return array('key' => 'w3tc', 'name' => 'W3 Total Cache', 'url' => function_exists('w3tc_flush_url'));
        }
        if (function_exists('wp_cache_clear_cache')) {
            return array('key' => 'wpsc', 'name' => 'WP Super Cache', 'url' => function_exists('wpsc_delete_url_cache'));
        }
        if (class_exists('SiteGround_Optimizer\Supercacher\Supercacher')) {
            return array('key' => 'sgo', 'name' => 'SG Optimizer', 'url' => false);
        }
        if (defined('NGINX_HELPER_BASENAME') || class_exists('Nginx_Helper')) {
            return array('key' => 'nginx-helper', 'name' => 'Nginx Helper', 'url' => true);
        }
        if (class_exists('Cachify')) {
            return array('key' => 'cachify', 'name' => 'Cachify', 'url' => false);
        }
        return null;
    }

    private static function purge_page_cache($everything, $url = '') {
        $cache = self::page_cache_integration();
        if (!$cache) {
            return __('No page cache was detected, so nothing was purged.', 'super-speedy-performance-analysis');
        }
        switch ($cache['key']) {
            case 'rocket':
                if (!$everything && function_exists('rocket_clean_files')) {
                    rocket_clean_files(array($url));
                    break;
                }
                rocket_clean_domain();
                break;
            case 'litespeed':
                if ($everything) {
                    do_action('litespeed_purge_all');
                } else {
                    do_action('litespeed_purge_url', $url);
                }
                break;
            case 'w3tc':
                if (!$everything && function_exists('w3tc_flush_url')) {
                    w3tc_flush_url($url);
                    break;
                }
                w3tc_flush_all();
                break;
            case 'wpsc':
                if (!$everything && function_exists('wpsc_delete_url_cache')) {
                    wpsc_delete_url_cache($url);
                    break;
                }
                wp_cache_clear_cache();
                break;
            case 'sgo':
                do_action('siteground_optimizer_flush_cache');
                break;
            case 'nginx-helper':
                if ($everything) {
                    do_action('rt_nginx_helper_purge_all');
                } else {
                    do_action('rt_nginx_helper_purge_url', $url);
                }
                break;
            case 'cachify':
                do_action('cachify_flush_cache');
                break;
        }
        return $everything
            ? sprintf(__('Purged the whole %s page cache.', 'super-speedy-performance-analysis'), $cache['name'])
            : sprintf(__('Purged %1$s for %2$s.', 'super-speedy-performance-analysis'), $cache['name'], $url);
    }

    /**
     * Transient clearing, honest about where transients actually live.
     *
     * With a persistent object cache in play, WordPress keeps transients THERE and not in
     * the options table, so a DELETE finds nothing and reporting "cleared 0" would be a lie
     * about work that the object cache flush then did anyway. Each path therefore says what
     * it really did. Found the hard way on a Redis-backed test site, 22 August 2026.
     */
    private static function clear_our_caches() {
        global $wpdb;
        $rows = (int) $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name REGEXP '^_(site_)?transient(_timeout)?_(superspeedy_changes_|superspeedy_l_|ssp_|sspa_)'"
        );
        if (wp_using_ext_object_cache()) {
            wp_cache_flush();
            return $rows
                ? sprintf(
                    /* translators: %d: number of stale database rows also removed. */
                    __('This site keeps transients in its object cache, so that was flushed. %d stale database row(s) were removed as well.', 'super-speedy-performance-analysis'),
                    $rows
                )
                : __('This site keeps transients in its object cache, so that was flushed.', 'super-speedy-performance-analysis');
        }
        wp_cache_flush();
        return sprintf(
            /* translators: %d: number of database rows removed. */
            _n('Cleared %d Super Speedy cache row.', 'Cleared %d Super Speedy cache rows.', $rows, 'super-speedy-performance-analysis'),
            $rows
        );
    }

    private static function clear_all_transients() {
        global $wpdb;
        $rows = (int) $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name REGEXP '^_(site_)?transient(_timeout)?_'"
        );
        if (wp_using_ext_object_cache()) {
            wp_cache_flush();
            return sprintf(
                /* translators: %d: number of stale database rows also removed. */
                __('This site keeps transients in its object cache, so the whole object cache was flushed. %d stale database row(s) were removed as well. Plugins will refill as pages are hit.', 'super-speedy-performance-analysis'),
                $rows
            );
        }
        wp_cache_flush();
        return sprintf(
            /* translators: %d: number of database rows removed. */
            _n('Cleared %d transient row. Plugins will refill them as pages are hit.', 'Cleared %d transient rows. Plugins will refill them as pages are hit.', $rows, 'super-speedy-performance-analysis'),
            $rows
        );
    }

    // ---------------------------------------------------------------- handler

    public static function handle() {
        $do = isset($_GET['do']) ? sanitize_key(wp_unslash($_GET['do'])) : '';
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do that.', 'super-speedy-performance-analysis'), 403);
        }
        check_admin_referer(self::ACTION . '_' . $do);

        $return = isset($_GET['sspa_return']) ? esc_url_raw(urldecode(wp_unslash($_GET['sspa_return']))) : admin_url();
        $return = wp_validate_redirect($return, admin_url());
        $message = '';

        switch ($do) {
            case 'clear_ours':
                $message = self::clear_our_caches();
                break;
            case 'clear_transients':
                $message = self::clear_all_transients();
                break;
            case 'flush_object_cache':
                wp_cache_flush();
                $message = __('Flushed the object cache.', 'super-speedy-performance-analysis');
                break;
            case 'purge_url':
                $message = self::purge_page_cache(false, $return);
                break;
            case 'purge_all':
                $message = self::purge_page_cache(true);
                break;
            case 'reset_opcache':
                $message = function_exists('opcache_reset') && opcache_reset()
                    ? __('Reset OPcache.', 'super-speedy-performance-analysis')
                    : __('OPcache refused the reset. Some hosts disable it for web requests.', 'super-speedy-performance-analysis');
                break;
            case 'forget_page':
                $message = self::forget_stored_page($return);
                break;
            case 'bypass_on':
                setcookie(self::BYPASS_COOKIE, '1', time() + DAY_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true);
                $message = __('Page cache bypassed for this browser for 24 hours.', 'super-speedy-performance-analysis');
                break;
            case 'bypass_off':
                setcookie(self::BYPASS_COOKIE, '', time() - 3600, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true);
                $message = __('Page cache bypass turned off.', 'super-speedy-performance-analysis');
                break;
            case 'markdown':
                self::send_markdown();
                return; // sends a file and exits
            default:
                $message = __('Unknown action.', 'super-speedy-performance-analysis');
        }

        set_transient('sspa_bar_notice_' . get_current_user_id(), $message, 60);
        wp_safe_redirect($return);
        exit;
    }

    /** Drop any stored ad-hoc result for this URL so the next analysis measures. */
    private static function forget_stored_page($url) {
        if (!class_exists('SSPA_Adhoc')) {
            return __('Nothing stored for this page.', 'super-speedy-performance-analysis');
        }
        global $wpdb;
        $table = SSPA_Schema::table('runs');
        $rows = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = 'superseded' WHERE run_type = 'adhoc' AND notes LIKE %s AND status = 'done'",
            '%' . $wpdb->esc_like($url) . '%'
        ));
        return $rows
            ? __('Forgot the stored result for this page. The next analysis will measure it again.', 'super-speedy-performance-analysis')
            : __('Nothing stored for this page.', 'super-speedy-performance-analysis');
    }

    private static function send_markdown() {
        $run = isset($_GET['run']) ? (int) $_GET['run'] : 0;
        if (!$run || !class_exists('SSPA_Markdown_Export')) {
            wp_die(esc_html__('No analysis to export.', 'super-speedy-performance-analysis'));
        }
        // build() answers with {filename, markdown}, and the filename it chooses already
        // carries the site prefix, so it is used as given rather than rebuilt here.
        $built = SSPA_Markdown_Export::build('run', $run);
        if (is_wp_error($built) || !is_array($built) || empty($built['markdown'])) {
            wp_die(esc_html(
                is_wp_error($built)
                    ? $built->get_error_message()
                    : __('That analysis could not be exported.', 'super-speedy-performance-analysis')
            ));
        }
        $name = !empty($built['filename']) ? $built['filename'] : 'sspa-analysis-' . $run . '.md';
        header('Content-Type: text/markdown; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($name) . '"');
        echo $built['markdown']; // phpcs:ignore WordPress.Security.EscapeOutput -- a generated Markdown file, not HTML.
        exit;
    }

    public static function result_notice() {
        $key = 'sspa_bar_notice_' . get_current_user_id();
        $message = get_transient($key);
        if (!$message) {
            return;
        }
        delete_transient($key);
        printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html($message));
    }

    public static function styles() {
        if (!self::allowed()) {
            return;
        }
        ?>
        <style>
        #wpadminbar .sspa-bolt { font-size: 15px; line-height: 1; top: 2px; margin-right: 4px; }
        #wpadminbar #wp-admin-bar-sspa-menu .sspa-bar-score { font-weight: 600; }
        #wpadminbar .sspa-bar-heading { text-transform: uppercase; font-size: 10px; letter-spacing: .05em; opacity: .6; }
        #wpadminbar .sspa-bar-warn { color: #ffb900; }
        #wpadminbar .sspa-bar-on { color: #46b450; font-weight: 600; }
        </style>
        <?php
    }
}
