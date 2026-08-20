<?php
defined('ABSPATH') || exit;

/**
 * THE profile view. One renderer, one markup, used by every entry point that shows what
 * happened during one request.
 *
 * There used to be two: the admin bar's "Analyse this page" popover built its own markup in
 * PHP-shaped JSON plus JS, and the Pages tab drill-down built a different subset with
 * `html +=` string concatenation in sspa-admin.js. Same stored capture underneath, two
 * presentations, each missing something the other had - nobody ever decided they should
 * differ, they simply drifted. Hence a PHP partial: one template called from both AJAX
 * handlers cannot drift.
 *
 * Everything is scoped to the popover shell (#sspa-adhoc-pop) that sspa-adhoc.js owns, so the
 * Pages tab now opens the same branded panel the admin bar does rather than an inline table.
 */
class SSPA_Profile_Panel {

    /** Queries shown, and the ceiling on how many get an EXPLAIN. */
    const MAX_QUERIES = 10;

    /** Blocking HTTP calls shown. */
    const MAX_HTTP = 10;

    /** Fallback seconds per measurement when no completed run exists to learn from. */
    const FALLBACK_SECONDS_PER_JOB = 8;

    public static function register() {
        add_action('wp_ajax_sspa_profile_panel', array(__CLASS__, 'ajax_panel'));
        add_action('wp_ajax_sspa_profile_export', array(__CLASS__, 'ajax_export'));
        add_action('wp_ajax_sspa_impact_plan', array(__CLASS__, 'ajax_impact_plan'));
    }

    private static function guard() {
        check_ajax_referer('sspa_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('forbidden', 403);
        }
    }

    /**
     * The panel for one stored profile. Used by the Pages tab row click and by the popover
     * after a page-scoped impact run finishes.
     */
    public static function ajax_panel() {
        self::guard();
        $profile_id = isset($_POST['profile_id']) ? (int) $_POST['profile_id'] : 0;
        $html = self::render($profile_id, array('cached' => true));
        if (is_wp_error($html)) {
            wp_send_json_error($html->get_error_message());
        }
        wp_send_json_success(array('profile_id' => $profile_id, 'html' => $html));
    }

    /** Download one page measurement as a self-contained diagnostic JSON document. */
    public static function ajax_export() {
        self::guard();
        $profile_id = isset($_POST['profile_id']) ? (int) $_POST['profile_id'] : 0;
        $payload = self::export_data($profile_id);
        if (is_wp_error($payload)) {
            wp_send_json_error($payload->get_error_message());
        }
        $page_key = sanitize_file_name($payload['profile']['page_key']);
        wp_send_json_success(array(
            'filename' => sspa_download_filename('sspa-page-' . ($page_key ? $page_key : $profile_id) . '-' . gmdate('Ymd-His') . '.json'),
            'payload' => $payload,
        ));
    }

    // ---------------- data ----------------

    public static function profile_row($profile_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM %i WHERE id = %d',
            SSPA_Schema::table('profiles'),
            (int) $profile_id
        ), ARRAY_A);
    }

    /**
     * The newest stored profile for a page key, whatever kind of run produced it.
     *
     * Deliberately not restricted to baseline/spot: the whole point of the ad-hoc runner is
     * that the freshest measurement of a page is often a one-page check.
     */
    public static function newest_profile_id_for_page($page_key) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT p.id FROM %i p
             INNER JOIN %i r ON r.id = p.run_id
             WHERE p.page_key = %s AND r.status = 'done' AND p.plugin_set_hash = ''
             ORDER BY p.id DESC LIMIT 1",
            SSPA_Schema::table('profiles'),
            SSPA_Schema::table('runs'),
            $page_key
        ));
    }

    public static function capture($row) {
        if (empty($row['profile_blob'])) {
            return null;
        }
        $json = @gzuncompress($row['profile_blob']);
        if (false === $json) {
            return null;
        }
        $capture = json_decode((string) $json, true);
        return is_array($capture) ? $capture : null;
    }

    /**
     * Everything needed to hand one measured page to a performance or cache-optimisation
     * job. This is deliberately the LOCAL diagnostic capture, not the anonymised community
     * payload: retained SQL and the exact URL are useful when fixing the site, and the
     * document says plainly that they may contain private values before it is shared.
     *
     * @return array|WP_Error
     */
    public static function export_data($profile_id) {
        global $wpdb;

        $row = self::profile_row($profile_id);
        if (!$row) {
            return new WP_Error('sspa_no_profile', __('That page profile no longer exists.', 'super-speedy-performance-analysis'));
        }
        $capture = self::capture($row);
        $run = SSPA_Run_Controller::run_row((int) $row['run_id']);

        $profile = $row;
        unset($profile['profile_blob']);
        $samples = json_decode((string) $profile['samples'], true);
        $profile['samples'] = is_array($samples) ? $samples : array();

        $run_data = null;
        if ($run) {
            $plugin_set = json_decode((string) $run['plugin_set'], true);
            $notes = json_decode((string) $run['notes'], true);
            $share_context = json_decode((string) $run['share_context'], true);
            $run_data = array(
                'id' => (int) $run['id'],
                'uuid' => (string) $run['run_uuid'],
                'type' => (string) $run['run_type'],
                'status' => (string) $run['status'],
                'trigger' => (string) $run['trigger_source'],
                'measurement_version' => (int) $run['measurement_version'],
                'started' => $run['started'],
                'finished' => $run['finished'],
                'components' => is_array($plugin_set) && isset($plugin_set['components']) ? $plugin_set['components'] : array(),
                'configuration' => is_array($share_context) ? $share_context : array(),
                'notes' => is_array($notes) ? $notes : array(),
            );
        }

        $findings = array_values(array_filter(SSPA_Report::findings((int) $row['run_id']), function ($finding) use ($row) {
            return empty($finding['page_key']) || $finding['page_key'] === $row['page_key'];
        }));
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- latest_impacts_sql() returns a string already run through $wpdb->prepare().
        $impacts = $wpdb->get_results(SSPA_Plugins_Table::latest_impacts_sql('', (string) $row['page_key']), ARRAY_A);

        return array(
            'schema' => 'sspa/page-diagnostic-export@1',
            'generated_at' => gmdate('c'),
            'generated_by' => array(
                'plugin' => 'super-speedy-performance-analysis',
                'version' => SSPA_VERSION,
                'wordpress' => get_bloginfo('version'),
                'php' => PHP_VERSION,
                'site_url' => home_url('/'),
            ),
            'sensitivity' => array(
                'classification' => 'private-site-diagnostic',
                'warning' => __('This file contains the exact measured URL and may contain retained SQL literals, option names, callback names and outbound hosts. Review it before sharing outside a trusted performance or cache-optimisation job.', 'super-speedy-performance-analysis'),
            ),
            'run' => $run_data,
            'profile' => $profile,
            'capture' => $capture,
            'findings' => $findings,
            'measured_plugin_impacts' => $impacts,
        );
    }

    /**
     * Pages that can be re-profiled and swept: a real GET of a URL. Write probes step a
     * temporary post/order through save, the mail probe answers with a stub and a checkout
     * flow is a journey, so none of them can be re-run from a panel.
     */
    public static function is_reprofilable($row) {
        $key = (string) $row['page_key'];
        if (in_array($key, array('baseline', 'mail-probe'), true) || 0 === strpos($key, 'write-') || 0 === strpos($key, 'flow')) {
            return false;
        }
        return 'GET' === strtoupper((string) $row['method']) && '' !== (string) $row['url'];
    }

    // ---------------- the panel ----------------

    /**
     * @param int   $profile_id
     * @param array $args {cached: bool - whether this is a stored result rather than one
     *                     just measured}
     * @return string|WP_Error panel body HTML
     */
    public static function render($profile_id, $args = array()) {
        $row = self::profile_row($profile_id);
        if (!$row) {
            return new WP_Error('sspa_no_profile', __('That page profile no longer exists.', 'super-speedy-performance-analysis'));
        }
        $capture = self::capture($row);
        $cached = !empty($args['cached']);

        $left = self::stats_html($row, $capture) . self::phases_html($capture);
        $right = self::boot_components_html($capture) . self::render_breakdown_html($capture) . self::callbacks_html($capture);

        $html = '<div class="sspa-adhoc-grid">';
        $html .= self::topbar_html($row, $cached);
        $html .= self::notes_html($row, $capture);
        $html .= '<div>' . $left . '</div><div>' . $right . '</div>';
        $html .= self::impact_html($row);
        $html .= self::components_html($capture);
        $html .= self::functions_html($capture);
        $html .= self::queries_html($capture);
        $html .= self::http_html($capture);
        if (null === $capture) {
            if (!self::transport_errors($row)) {
                $html .= '<p class="sspa-adhoc-note sspa-adhoc-span">'
                    . esc_html__('No detailed data is stored for this measurement because it was pruned.', 'super-speedy-performance-analysis')
                    . '</p>';
            }
        }
        $html .= '</div>';
        return $html;
    }

    /**
     * Provenance and the two actions, at the TOP: whether you are looking at a stored result
     * has to be unmissable, and neither re-running nor measuring impact should need scrolling.
     *
     * No "open in Performance Analysis" link any more - this IS that view now.
     */
    private static function topbar_html($row, $cached) {
        $created_gmt = strtotime($row['created'] . ' UTC');
        $when = get_date_from_gmt($row['created'], get_option('date_format') . ' ' . get_option('time_format'));
        $age = max(0, time() - (int) $created_gmt);
        $run = SSPA_Run_Controller::run_row((int) $row['run_id']);
        $run_type = $run ? $run['run_type'] : '';

        $html = '<div class="sspa-adhoc-topbar sspa-adhoc-span">';
        if (self::is_reprofilable($row)) {
            $html .= '<button type="button" class="sspa-adhoc-btn sspa-adhoc-btn-primary sspa-adhoc-rerun" data-url="' . esc_attr($row['url']) . '">'
                . esc_html__('Re-run', 'super-speedy-performance-analysis') . '</button>';
        }
        $html .= '<button type="button" class="sspa-adhoc-btn sspa-adhoc-export" data-profile-id="' . (int) $row['id'] . '" title="'
            . esc_attr__('Downloads the exact local diagnostic capture. It may contain URLs and SQL literals.', 'super-speedy-performance-analysis') . '">'
            . esc_html__('Export JSON', 'super-speedy-performance-analysis') . '</button>';
        $html .= '<button type="button" class="sspa-adhoc-btn sspa-markdown-download" data-kind="page" data-id="' . (int) $row['id'] . '">'
            . esc_html__('Download Markdown', 'super-speedy-performance-analysis') . '</button>';
        $html .= '<button type="button" class="sspa-adhoc-btn sspa-markdown-copy" data-kind="page" data-id="' . (int) $row['id'] . '">'
            . esc_html__('Copy Markdown', 'super-speedy-performance-analysis') . '</button>';
        $html .= '<span class="sspa-markdown-status" aria-live="polite"></span>';
        $html .= '<span class="sspa-adhoc-badge ' . ($cached ? 'is-cached' : 'is-fresh') . '">'
            . esc_html($cached ? __('Stored result', 'super-speedy-performance-analysis') : __('Fresh result', 'super-speedy-performance-analysis'))
            . '</span>';
        $html .= '<span class="sspa-adhoc-note"><strong>' . esc_html($cached ? self::ago($age) : __('just now', 'super-speedy-performance-analysis')) . '</strong> · '
            . esc_html($when) . ' · <code>' . esc_html($row['page_key']) . '</code> · '
            . esc_html('admin' === $row['variant']
                ? __('profiled as admin', 'super-speedy-performance-analysis')
                : __('profiled as a logged-out visitor', 'super-speedy-performance-analysis'));
        if ('adhoc' === $run_type) {
            $html .= ' · ' . esc_html__('one-page analysis', 'super-speedy-performance-analysis');
        } elseif (in_array($run_type, array('baseline', 'spot'), true)) {
            $html .= ' · ' . esc_html__('from a full analysis', 'super-speedy-performance-analysis');
        }
        $html .= '</span>';
        $html .= '</div>';
        return $html;
    }

    /** "5m ago" from a server-computed age, so nobody reconciles two clocks. */
    private static function ago($seconds) {
        if ($seconds < 45) {
            return __('just now', 'super-speedy-performance-analysis');
        }
        if ($seconds < HOUR_IN_SECONDS) {
            $value = round($seconds / MINUTE_IN_SECONDS) . 'm';
        } elseif ($seconds < DAY_IN_SECONDS) {
            $value = round($seconds / HOUR_IN_SECONDS) . 'h';
        } else {
            $value = round($seconds / DAY_IN_SECONDS) . 'd';
        }
        /* translators: %s: a duration such as "5m" */
        return sprintf(__('%s ago', 'super-speedy-performance-analysis'), $value);
    }

    /**
     * Measurement-path honesty. A loopback that bypassed the CDN was measured WITHOUT the
     * headers a CDN adds, so behaviour keyed on them (WooCommerce's MaxMind lookup, for one)
     * differs between the measured path and the visitor path.
     */
    private static function notes_html($row, $capture) {
        $html = '';
        if (!empty($row['blocked_by'])) {
            /* translators: %s: the plugin or service that blocked the request */
            $html .= '<p class="sspa-adhoc-error sspa-adhoc-span">' . esc_html(sprintf(__('Blocked by %s', 'super-speedy-performance-analysis'), $row['blocked_by'])) . '</p>';
        }
        foreach (self::transport_errors($row) as $error) {
            /* translators: %s: the HTTP transport's own error message */
            $html .= '<p class="sspa-adhoc-error sspa-adhoc-span">'
                . esc_html(sprintf(__('Measurement transport error: %s', 'super-speedy-performance-analysis'), $error))
                . '</p>';
        }
        if (!is_array($capture) || !isset($capture['overview'])) {
            return $html;
        }
        $via = array_key_exists('via_cloudflare', $capture['overview']) ? $capture['overview']['via_cloudflare'] : null;
        if (false === $via) {
            $html .= '<p class="sspa-adhoc-note sspa-adhoc-span sspa-adhoc-pathnote">&#9432; '
                . esc_html__('The profiling request went directly to the origin server, not through Cloudflare - CDN-added headers (visitor country, WAF marks) were absent on this measurement. Costs that only occur without those headers (such as WooCommerce\'s MaxMind lookup) may not apply to real visitors.', 'super-speedy-performance-analysis')
                . '</p>';
        } elseif (true === $via) {
            $country = isset($capture['overview']['cf_country']) ? $capture['overview']['cf_country'] : null;
            $html .= '<p class="sspa-adhoc-note sspa-adhoc-span sspa-adhoc-pathnote">&#9432; '
                . esc_html($country
                    /* translators: %s: two-letter country code */
                    ? sprintf(__('Profiled through Cloudflare (visitor country header: %s).', 'super-speedy-performance-analysis'), $country)
                    : __('Profiled through Cloudflare - no visitor country header; IP Geolocation is off in Cloudflare.', 'super-speedy-performance-analysis'))
                . '</p>';
        }
        return $html;
    }

    /** Unique transport failures retained in this profile's sample summaries. */
    private static function transport_errors($row) {
        $samples = json_decode((string) $row['samples'], true);
        $errors = array();
        foreach ((array) $samples as $sample) {
            if (empty($sample['error'])) {
                continue;
            }
            $message = !empty($sample['error_message']) ? $sample['error_message'] : $sample['error'];
            $message = sanitize_text_field($message);
            if ('' !== $message) {
                $errors[$message] = true;
            }
        }
        return array_keys($errors);
    }

    private static function stat($value, $label) {
        return '<div class="sspa-adhoc-stat"><span class="sspa-adhoc-stat-value">' . esc_html($value)
            . '</span><span class="sspa-adhoc-stat-label">' . esc_html($label) . '</span></div>';
    }

    private static function ms($value, $decimals = 1) {
        return (null === $value || '' === $value) ? '?' : number_format((float) $value, $decimals) . 'ms';
    }

    private static function stats_html($row, $capture) {
        $html = '<div class="sspa-adhoc-stats">';
        $html .= self::stat(self::ms($row['page_gen_ms']), __('Generation', 'super-speedy-performance-analysis'));
        $html .= self::stat(
            null !== $row['sql_ms'] ? number_format((float) $row['sql_ms'], 1) . 'ms / ' . (int) $row['sql_count'] : '?',
            __('SQL / queries', 'super-speedy-performance-analysis')
        );
        $html .= self::stat(null !== $row['http_ms'] ? self::ms($row['http_ms']) : '?', __('HTTP', 'super-speedy-performance-analysis'));
        $html .= self::stat($row['peak_mem_bytes'] ? size_format((int) $row['peak_mem_bytes']) : '?', __('Peak RAM', 'super-speedy-performance-analysis'));

        // The object cache figures for this page. Hit rate is the number that matters: a cache
        // present but missing everything costs a round trip per lookup and saves nothing.
        $hits = (null !== $row['cache_hits']) ? (int) $row['cache_hits'] : null;
        $misses = (null !== $row['cache_misses']) ? (int) $row['cache_misses'] : null;
        if (null !== $hits || null !== $misses) {
            $total = (int) $hits + (int) $misses;
            $rate = $total > 0 ? round(100 * $hits / $total) : 0;
            $html .= self::stat(
                $total > 0 ? $rate . '% of ' . number_format($total) : '-',
                __('Object cache hits', 'super-speedy-performance-analysis')
            );
        }
        if (is_array($capture) && isset($capture['cache']['alloptions_bytes']) && $capture['cache']['alloptions_bytes']) {
            $html .= self::stat(size_format((int) $capture['cache']['alloptions_bytes']), __('Autoloaded options', 'super-speedy-performance-analysis'));
        }
        $html .= '</div>';

        if (is_array($capture) && isset($capture['cache']['persistent']) && false === $capture['cache']['persistent']) {
            $html .= '<p class="sspa-adhoc-note">' . esc_html__('No persistent object cache was in use for this measurement, so every lookup above was served from PHP memory and thrown away at the end of the request.', 'super-speedy-performance-analysis') . '</p>';
        }
        return $html;
    }

    /** Human names for the request phases the boot timer records. */
    private static function segment_names() {
        return array(
            'core_before_plugins' => __('Core (before plugins)', 'super-speedy-performance-analysis'),
            'plugin_includes' => __('Plugin file loading', 'super-speedy-performance-analysis'),
            'plugins_loaded_callbacks' => __('Plugin boot (plugins_loaded)', 'super-speedy-performance-analysis'),
            'theme_load_and_setup' => __('Theme load + setup', 'super-speedy-performance-analysis'),
            'init_callbacks' => __('init callbacks', 'super-speedy-performance-analysis'),
            'post_init_boot' => __('Post-init boot (widgets, REST)', 'super-speedy-performance-analysis'),
            'routing_and_query' => __('Routing + main query', 'super-speedy-performance-analysis'),
            'render_and_output' => __('Template render + output', 'super-speedy-performance-analysis'),
            'endpoint_work' => __('Endpoint work', 'super-speedy-performance-analysis'),
        );
    }

    /** Small, consistent route to the server-specific Excimer instructions. */
    private static function excimer_prompt_html($extra_class = '') {
        $class = trim('sspa-excimer-prompt ' . $extra_class);
        $label = extension_loaded('excimer')
            ? __('Re-run with Excimer to improve this data', 'super-speedy-performance-analysis')
            : __('Install Excimer to improve this data', 'super-speedy-performance-analysis');
        return '<a class="' . esc_attr($class) . '" href="' . esc_url(admin_url('admin.php?page=sspa#tools')) . '">'
            . esc_html($label) . '</a>';
    }

    /** What a request phase can expand into, from data the capture already stores. */
    private static function phase_detail($boot, $key) {
        $from_hooks = function ($names) use ($boot) {
            $out = array();
            foreach ($names as $name) {
                if (empty($boot['hooks'][$name]['components'])) {
                    continue;
                }
                foreach ($boot['hooks'][$name]['components'] as $component => $ms) {
                    $out[$component] = (isset($out[$component]) ? $out[$component] : 0) + (float) $ms;
                }
            }
            return $out;
        };
        switch ($key) {
            case 'plugin_includes':
                return isset($boot['includes']) ? $boot['includes'] : null;
            case 'plugins_loaded_callbacks':
                return $from_hooks(array('plugins_loaded'));
            case 'theme_load_and_setup':
                return $from_hooks(array('after_setup_theme'));
            case 'init_callbacks':
                return $from_hooks(array('init', 'widgets_init'));
            case 'post_init_boot':
                return $from_hooks(array('wp_loaded', 'rest_api_init'));
            case 'render_and_output':
                return isset($boot['render']['components']) ? $boot['render']['components'] : null;
        }
        return null;
    }

    /**
     * Request phases, each expanding to the per-component work our wrappers saw inside it,
     * and each remainder expanding to the functions the sampling profiler caught during that
     * phase. This is the PHP floor decomposed: where the time went before a single query ran.
     */
    private static function phases_html($capture) {
        if (!is_array($capture) || empty($capture['boot']['segments'])) {
            return '';
        }
        $boot = $capture['boot'];
        $names = self::segment_names();
        $profile = isset($capture['profile']) ? $capture['profile'] : null;
        $has_global_fns = !empty($profile['functions']);

        $html = '<h4>' . esc_html__('Where the PHP time went', 'super-speedy-performance-analysis')
            . ' <small>' . esc_html__('Click a phase to expand it', 'super-speedy-performance-analysis');
        if (empty($profile)) {
            $html .= ' · ' . self::excimer_prompt_html('sspa-excimer-phases-prompt');
        }
        $html .= '</small></h4>';
        $html .= '<table class="sspa-adhoc-table">';
        foreach ($boot['segments'] as $key => $ms) {
            $label = isset($names[$key]) ? $names[$key] : $key;
            $detail = self::phase_detail($boot, $key);
            $detail = is_array($detail) ? array_filter($detail, function ($v) {
                return (float) $v >= 0.5;
            }) : array();
            $phase_fns = !empty($profile['phases'][$key]['functions']);
            if (!$detail) {
                if ($phase_fns) {
                    $html .= '<tr class="sspa-adhoc-phase" data-phase="' . esc_attr($key) . '"><td><span class="sspa-adhoc-caret">&#9656;</span>'
                        . esc_html($label) . '</td><td>' . esc_html(number_format((float) $ms, 1)) . 'ms</td></tr>';
                    $html .= self::phase_function_rows($profile, $key, true);
                } else {
                    $html .= '<tr><td class="sspa-adhoc-phase-plain">' . esc_html($label) . '</td><td>' . esc_html(number_format((float) $ms, 1)) . 'ms</td></tr>';
                }
                continue;
            }
            arsort($detail);
            $html .= '<tr class="sspa-adhoc-phase" data-phase="' . esc_attr($key) . '"><td><span class="sspa-adhoc-caret">&#9656;</span>'
                . esc_html($label) . '</td><td>' . esc_html(number_format((float) $ms, 1)) . 'ms</td></tr>';
            $shown = 0;
            foreach (array_slice($detail, 0, 12, true) as $component => $cms) {
                $html .= '<tr class="sspa-adhoc-sub" data-parent="' . esc_attr($key) . '" style="display:none"><td><code>'
                    . esc_html($component) . '</code></td><td>' . esc_html(number_format((float) $cms, 1)) . 'ms</td></tr>';
                $shown += (float) $cms;
            }
            // Phases are wall-clock; the detail is only the work our wrappers saw.
            $gap = (float) $ms - $shown;
            if ($gap > 1) {
                $gap_label = ('render_and_output' === $key)
                    ? __('theme templates + direct output (untimed)', 'super-speedy-performance-analysis')
                    : __('untimed / core framework', 'super-speedy-performance-analysis');
                $classes = 'sspa-adhoc-sub' . ($phase_fns ? ' sspa-adhoc-untimed' : (($has_global_fns) ? ' sspa-adhoc-tobyfn' : ''));
                $title = $phase_fns
                    ? __('Click: the functions the profiler sampled during this phase', 'super-speedy-performance-analysis')
                    : ($has_global_fns ? __('See the By function table - the sampling profiler names this time', 'super-speedy-performance-analysis') : '');
                $html .= '<tr class="' . esc_attr($classes) . '" data-parent="' . esc_attr($key) . '" data-fns="' . esc_attr($key) . '"'
                    . ($title ? ' title="' . esc_attr($title) . '"' : '') . ' style="display:none"><td><small>'
                    . esc_html($gap_label) . (($phase_fns || $has_global_fns) ? ' &darr;' : '')
                    . '</small></td><td><small>' . esc_html(number_format($gap, 1)) . 'ms</small></td></tr>';
                $html .= self::phase_function_rows($profile, $key);
            }
        }
        $html .= '</table>';
        return $html;
    }

    /** Hidden rows listing what the sampling profiler caught DURING one phase. */
    private static function phase_function_rows($profile, $phase_key, $direct_children = false) {
        if (empty($profile['phases'][$phase_key]['functions'])) {
            return '';
        }
        $html = '';
        foreach ($profile['phases'][$phase_key]['functions'] as $fn) {
            $class = $direct_children ? 'sspa-adhoc-sub sspa-adhoc-fnsub' : 'sspa-adhoc-fnsub';
            $html .= '<tr class="' . esc_attr($class) . '"'
                . ($direct_children ? ' data-parent="' . esc_attr($phase_key) . '"' : '')
                . ' data-fnparent="' . esc_attr($phase_key) . '" style="display:none"><td><small><code>'
                . esc_html($fn['fn']) . '</code> · ' . esc_html($fn['component']) . '</small></td><td><small>'
                . esc_html(number_format((float) $fn['self_ms'], 1)) . 'ms</small></td></tr>';
        }
        return $html;
    }

    private static function boot_components_html($capture) {
        if (!is_array($capture) || empty($capture['boot']['components'])) {
            return '';
        }
        $includes = isset($capture['boot']['includes']) ? $capture['boot']['includes'] : array();
        $rows = array_filter($capture['boot']['components'], function ($ms) {
            return (float) $ms >= 1;
        });
        if (!$rows) {
            return '';
        }
        arsort($rows);
        $html = '<h4>' . esc_html__('PHP cost per plugin', 'super-speedy-performance-analysis')
            . ' <small>' . esc_html__('file loading + hook callbacks', 'super-speedy-performance-analysis') . '</small></h4>';
        $html .= '<table class="sspa-adhoc-table sspa-adhoc-num3">';
        $html .= '<tr class="sspa-adhoc-hrow"><td>' . esc_html__('Plugin', 'super-speedy-performance-analysis') . '</td><td>'
            . esc_html__('Load', 'super-speedy-performance-analysis') . '</td><td>' . esc_html__('Hooks', 'super-speedy-performance-analysis')
            . '</td><td>' . esc_html__('Total', 'super-speedy-performance-analysis') . '</td></tr>';
        foreach (array_slice($rows, 0, 15, true) as $component => $ms) {
            $load = isset($includes[$component]) ? (float) $includes[$component] : 0;
            $html .= '<tr><td><code>' . esc_html($component) . '</code></td>'
                . '<td>' . esc_html(number_format($load, 1)) . '</td>'
                . '<td>' . esc_html(number_format(max(0, (float) $ms - $load), 1)) . '</td>'
                . '<td>' . esc_html(number_format((float) $ms, 1)) . 'ms</td></tr>';
        }
        $html .= '</table>';
        return $html;
    }

    private static function render_breakdown_html($capture) {
        if (!is_array($capture) || empty($capture['boot']['render'])) {
            return '';
        }
        $render = $capture['boot']['render'];
        $untimed = isset($render['untimed_ms']) ? $render['untimed_ms'] : null;
        if (empty($render['timed_ms']) && (null === $untimed || $untimed <= 0)) {
            return '';
        }
        $profile = isset($capture['profile']) ? $capture['profile'] : null;
        $html = '<h4>' . esc_html__('Render breakdown', 'super-speedy-performance-analysis')
            . ' <small>' . esc_html__('wp_head/wp_footer, content filters, shortcodes, widgets', 'super-speedy-performance-analysis') . '</small></h4>';
        $html .= '<table class="sspa-adhoc-table">';
        foreach (array_slice((array) (isset($render['top']) ? $render['top'] : array()), 0, 8) as $item) {
            $html .= '<tr><td>' . esc_html($item['label']) . ' <small>' . esc_html($item['hook']) . ' · ' . esc_html($item['component'])
                . '</small></td><td>' . esc_html(number_format((float) $item['ms'], 1)) . 'ms</td></tr>';
        }
        if (null !== $untimed && $untimed > 0) {
            $phase_fns = !empty($profile['phases']['render_and_output']['functions']);
            $linkable = !$phase_fns && !empty($profile['functions']);
            $classes = $phase_fns ? 'sspa-adhoc-untimed' : ($linkable ? 'sspa-adhoc-tobyfn' : '');
            $html .= '<tr' . ($classes ? ' class="' . esc_attr($classes) . '" data-fns="render_and_output"' : '') . '><td>'
                . esc_html__('Theme templates + direct output', 'super-speedy-performance-analysis') . ' <small>('
                . esc_html__('untimed remainder', 'super-speedy-performance-analysis')
                . (($phase_fns || $linkable) ? ' - ' . esc_html__('click for the function view', 'super-speedy-performance-analysis') : '')
                . ')' . (empty($profile) ? ' · ' . self::excimer_prompt_html('sspa-excimer-render-prompt') : '')
                . '</small></td><td>' . esc_html(number_format((float) $untimed, 1)) . 'ms</td></tr>';
            if ($phase_fns) {
                $html .= self::phase_function_rows($profile, 'render_and_output');
            }
        }
        $html .= '</table>';
        return $html;
    }

    private static function callbacks_html($capture) {
        if (!is_array($capture) || empty($capture['boot']['top_callbacks'])) {
            return '';
        }
        $html = '<h4>' . esc_html__('Slowest hook callbacks', 'super-speedy-performance-analysis') . '</h4>';
        $html .= '<table class="sspa-adhoc-table">';
        foreach (array_slice($capture['boot']['top_callbacks'], 0, 10) as $callback) {
            $html .= '<tr><td>' . esc_html($callback['label']) . ' <small>' . esc_html($callback['hook']) . ' · '
                . esc_html($callback['component']) . '</small></td><td>' . esc_html(number_format((float) $callback['ms'], 1)) . 'ms</td></tr>';
        }
        $html .= '</table>';
        return $html;
    }

    /**
     * Per-component attribution for this one page, in BOTH modes.
     *
     * Both tables are rendered up front and toggled client-side: caller mode is recomputed
     * from the capture we have already decoded, so the second table costs no query and no
     * round trip, and the switch is instant.
     */
    private static function components_html($capture) {
        if (!is_array($capture) || empty($capture['components'])) {
            return '';
        }

        $code_owner = array();
        foreach ($capture['components'] as $component => $stats) {
            $code_owner[$component] = array(
                'queries' => (int) $stats['query_count'],
                'sql_ms' => (float) $stats['sql_ms'],
                'http_ms' => (float) $stats['http_ms'],
                'rows' => (int) $stats['rows'],
            );
        }
        $caller = array();
        foreach (SSPA_Attribution::caller_aggregate($capture) as $component => $stats) {
            $caller[$component] = array(
                'queries' => (int) $stats['query_count'],
                'sql_ms' => (float) $stats['sql_ms'],
                'http_ms' => (float) $stats['http_ms'],
                'rows' => (int) $stats['rows'],
            );
        }

        $table = function ($rows, $mode, $active) {
            uasort($rows, function ($a, $b) {
                return ($b['sql_ms'] + $b['http_ms']) <=> ($a['sql_ms'] + $a['http_ms']);
            });
            $html = '<table class="sspa-adhoc-table sspa-adhoc-num3 sspa-adhoc-attrib-table" data-mode="' . esc_attr($mode) . '"'
                . ($active ? '' : ' style="display:none"') . '>';
            $html .= '<tr class="sspa-adhoc-hrow"><td>' . esc_html__('Component', 'super-speedy-performance-analysis')
                . '</td><td>' . esc_html__('Queries', 'super-speedy-performance-analysis')
                . '</td><td>' . esc_html__('SQL', 'super-speedy-performance-analysis')
                . '</td><td>' . esc_html__('HTTP', 'super-speedy-performance-analysis') . '</td></tr>';
            foreach (array_slice($rows, 0, 15, true) as $component => $stats) {
                $html .= '<tr><td><code>' . esc_html($component) . '</code>'
                    . ($stats['rows'] ? ' <small>' . esc_html(sprintf(
                        /* translators: %s: number of database rows */
                        __('%s rows', 'super-speedy-performance-analysis'),
                        number_format($stats['rows'])
                    )) . '</small>' : '')
                    . '</td><td>' . (int) $stats['queries'] . '</td><td>'
                    . esc_html(number_format($stats['sql_ms'], 1)) . '</td><td>'
                    . esc_html(number_format($stats['http_ms'], 1)) . 'ms</td></tr>';
            }
            $html .= '</table>';
            return $html;
        };

        $html = '<div class="sspa-adhoc-span" id="sspa-adhoc-attrib">';
        $html .= '<h4>' . esc_html__('By component, on this page', 'super-speedy-performance-analysis') . '</h4>';
        $html .= '<p class="sspa-adhoc-modes">';
        foreach (SSPA_Attribution::modes() as $mode => $label) {
            $active = (SSPA_Attribution::MODE_CODE_OWNER === $mode);
            $html .= '<button type="button" class="sspa-adhoc-btn sspa-adhoc-attrib-btn' . ($active ? ' sspa-adhoc-btn-primary' : '')
                . '" data-mode="' . esc_attr($mode) . '" aria-pressed="' . ($active ? 'true' : 'false') . '">' . esc_html($label) . '</button> ';
        }
        $html .= '<span class="sspa-adhoc-note sspa-adhoc-attrib-desc"'
            . ' data-code_owner="' . esc_attr(SSPA_Attribution::describe(SSPA_Attribution::MODE_CODE_OWNER)) . '"'
            . ' data-caller="' . esc_attr(SSPA_Attribution::describe(SSPA_Attribution::MODE_CALLER)) . '">'
            . esc_html(SSPA_Attribution::describe(SSPA_Attribution::MODE_CODE_OWNER)) . '</span>';
        $html .= '</p>';
        $html .= $table($code_owner, SSPA_Attribution::MODE_CODE_OWNER, true);
        $html .= $table($caller, SSPA_Attribution::MODE_CALLER, false);
        $html .= '</div>';
        return $html;
    }

    private static function functions_html($capture) {
        if (!is_array($capture)) {
            return '';
        }
        if (empty($capture['profile'])) {
            return '<div class="sspa-adhoc-span sspa-excimer-missing" id="sspa-adhoc-byfn"><h4>'
                . esc_html__('By function', 'super-speedy-performance-analysis') . '</h4><p class="sspa-adhoc-note">'
                . esc_html__('Function-level sampling was not available for this measurement.', 'super-speedy-performance-analysis') . ' '
                . self::excimer_prompt_html('sspa-excimer-functions-prompt') . '.</p></div>';
        }
        if (empty($capture['profile']['functions'])) {
            return '';
        }
        $profile = $capture['profile'];
        $functions = $profile['functions'];
        // SELF time first: sorted by inclusive time the top rows are WordPress's own bootstrap
        // include chain - plumbing, not culprits.
        usort($functions, function ($a, $b) {
            return $b['self_ms'] <=> $a['self_ms'];
        });

        $html = '<div class="sspa-adhoc-span" id="sspa-adhoc-byfn"><h4>'
            . esc_html__('By function, self time first', 'super-speedy-performance-analysis') . ' <small>'
            . esc_html(sprintf(
                /* translators: 1: sample count, 2: sampling period in ms */
                __('Excimer sampling, %1$s samples at %2$sms - statistical, sees inside theme templates', 'super-speedy-performance-analysis'),
                number_format((int) $profile['samples']),
                $profile['period_ms']
            )) . '</small></h4>';
        $html .= '<table class="sspa-adhoc-table sspa-adhoc-fn-table">';
        foreach (array_slice($functions, 0, 12) as $fn) {
            $by = '';
            $by_keys = !empty($fn['by']) ? array_keys($fn['by']) : array();
            // Worth a line when the time is driven by someone other than the function's owner,
            // or split across several drivers.
            if (count($by_keys) > 1 || (1 === count($by_keys) && $by_keys[0] !== $fn['component'])) {
                $parts = array();
                foreach ($fn['by'] as $driver => $ms) {
                    $parts[] = esc_html($driver) . ' ' . esc_html(number_format((float) $ms)) . 'ms';
                }
                $by = '<br><small>' . esc_html__('driven by:', 'super-speedy-performance-analysis') . ' ' . implode(' · ', $parts) . '</small>';
            }
            $html .= '<tr><td><code>' . esc_html($fn['fn']) . '</code>'
                . (!empty($fn['file']) ? ' <small>' . esc_html($fn['file'] . (!empty($fn['line']) ? ':' . $fn['line'] : '')) . '</small>' : '')
                . $by . '</td><td><code>' . esc_html($fn['component']) . '</code></td><td>'
                . esc_html(number_format((float) $fn['self_ms'])) . 'ms self</td><td>'
                . esc_html(number_format((float) $fn['incl_ms'])) . 'ms incl</td></tr>';
        }
        $html .= '</table></div>';
        return $html;
    }

    /**
     * The slowest queries, each with what EXPLAIN says about its plan.
     *
     * EXPLAIN runs here, in an admin request, over the stored SQL - never inside a profiled
     * request, so it cannot contaminate a measurement. Only queries whose full SQL was
     * retained are eligible; a fingerprint's literals are gone and any plan would be fiction.
     */
    private static function queries_html($capture) {
        if (!is_array($capture) || empty($capture['sql']['queries'])) {
            return '';
        }
        $queries = $capture['sql']['queries'];
        usort($queries, function ($a, $b) {
            return $b['ms'] <=> $a['ms'];
        });
        $queries = array_slice($queries, 0, self::MAX_QUERIES);

        $html = '<div class="sspa-adhoc-span"><h4>' . esc_html__('Slowest queries', 'super-speedy-performance-analysis')
            . ' <small>' . esc_html__('Click a row to copy the full query', 'super-speedy-performance-analysis') . '</small></h4>';
        $html .= '<table class="sspa-adhoc-table">';
        foreach ($queries as $query) {
            $sql = (null !== $query['sql']) ? (string) $query['sql'] : (string) $query['fp'];
            $shown = (strlen($sql) > 200) ? substr($sql, 0, 200) . '…' : $sql;
            $meta = array($query['component']);
            if (!empty($query['caller'])) {
                $meta[] = $query['caller'];
            }
            if (isset($query['rows']) && null !== $query['rows']) {
                /* translators: %s: number of rows the query returned */
                $meta[] = sprintf(__('%s rows', 'super-speedy-performance-analysis'), number_format((int) $query['rows']));
            }
            $note = SSPA_Explain::summarise(SSPA_Explain::explain($sql));
            $html .= '<tr class="sspa-adhoc-qrow" data-sql="' . esc_attr($sql) . '" title="'
                . esc_attr__('Click to copy the full query', 'super-speedy-performance-analysis') . '"><td class="sspa-adhoc-sql"><code>'
                . esc_html($shown) . '</code><br><small>' . esc_html(implode(' · ', $meta)) . '</small>'
                . ($note ? '<br><small class="sspa-adhoc-explain">' . esc_html__('EXPLAIN:', 'super-speedy-performance-analysis') . ' '
                    . esc_html($note) . '</small>' : '')
                . '</td><td>' . esc_html(number_format((float) $query['ms'], 1)) . 'ms</td></tr>';
        }
        $html .= '</table></div>';
        return $html;
    }

    private static function http_html($capture) {
        if (!is_array($capture) || empty($capture['http']['calls'])) {
            return '';
        }
        $calls = $capture['http']['calls'];
        usort($calls, function ($a, $b) {
            return (float) $b['ms'] <=> (float) $a['ms'];
        });
        $html = '<div class="sspa-adhoc-span"><h4>' . esc_html__('Outbound HTTP calls', 'super-speedy-performance-analysis')
            . ' <small>' . esc_html__('a blocking call holds the page until it answers', 'super-speedy-performance-analysis') . '</small></h4>';
        $html .= '<table class="sspa-adhoc-table">';
        foreach (array_slice($calls, 0, self::MAX_HTTP) as $call) {
            $meta = array($call['component']);
            if (!empty($call['trace'])) {
                $meta[] = $call['trace'];
            }
            if (isset($call['code']) && $call['code']) {
                $meta[] = 'HTTP ' . (int) $call['code'];
            }
            if (isset($call['blocking']) && !$call['blocking']) {
                $meta[] = __('non-blocking', 'super-speedy-performance-analysis');
            }
            $url = (string) $call['url'] . (!empty($call['q']) ? '?' . $call['q'] : '');
            $html .= '<tr><td><code>' . esc_html((!empty($call['method']) ? $call['method'] . ' ' : '') . $url) . '</code>'
                . '<br><small>' . esc_html(implode(' · ', $meta)) . '</small></td><td>'
                . (null === $call['ms'] ? '-' : esc_html(number_format((float) $call['ms'], 1)) . 'ms') . '</td></tr>';
        }
        $html .= '</table></div>';
        return $html;
    }

    // ---------------- measured plugin impact, for this page ----------------

    /**
     * What has actually been measured on this page by disabling plugins, plus the control that
     * measures more.
     *
     * Site-wide sweeps answer "what does this plugin cost across the site". The question
     * somebody looking at one page's results has is narrower and cheaper to answer, and since
     * 0.12.7 a sweep can be scoped to one page - so it belongs here, next to the numbers that
     * prompted the question.
     */
    private static function impact_html($row) {
        global $wpdb;

        $page_key = (string) $row['page_key'];
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- latest_impacts_sql() returns a string already run through $wpdb->prepare().
        $rows = $wpdb->get_results(SSPA_Plugins_Table::latest_impacts_sql('', $page_key), ARRAY_A);

        $html = '<div class="sspa-adhoc-span" id="sspa-adhoc-impact"><h4>'
            . esc_html__('Measured plugin impact on this page', 'super-speedy-performance-analysis')
            . ' <small>' . esc_html__('measured by disabling the plugin for test requests only - never for your visitors', 'super-speedy-performance-analysis')
            . '</small></h4>';

        if ($rows) {
            $modes = array();
            $by_plugin = array();
            foreach ($rows as $impact) {
                $modes[$impact['object_cache_mode']] = true;
                $by_plugin[$impact['plugin']][$impact['object_cache_mode']] = $impact;
            }
            $mode_order = array('normal', 'disabled', 'prime', 'warm');
            $mode_labels = array(
                'normal' => __('Standard (cache warm)', 'super-speedy-performance-analysis'),
                'disabled' => __('No object cache', 'super-speedy-performance-analysis'),
                'prime' => __('Cache priming', 'super-speedy-performance-analysis'),
                'warm' => __('Warm cache', 'super-speedy-performance-analysis'),
            );
            $modes = array_values(array_filter($mode_order, function ($mode) use ($modes) {
                return isset($modes[$mode]);
            }));

            // Biggest measured cost first: that is the row somebody is looking for.
            uasort($by_plugin, function ($a, $b) {
                $worst = function ($cells) {
                    $out = 0;
                    foreach ($cells as $cell) {
                        if ('measured' === $cell['confidence']) {
                            $out = max($out, abs((float) $cell['delta_ttfb_ms']));
                        }
                    }
                    return $out;
                };
                return $worst($b) <=> $worst($a);
            });

            $html .= '<table class="sspa-adhoc-table sspa-adhoc-impact-table"><tr class="sspa-adhoc-hrow"><td>'
                . esc_html__('Plugin', 'super-speedy-performance-analysis') . '</td>';
            foreach ($modes as $mode) {
                $html .= '<td>' . esc_html($mode_labels[$mode]) . '</td>';
            }
            $html .= '</tr>';
            foreach ($by_plugin as $plugin => $cells) {
                $html .= '<tr><td><code>' . esc_html($plugin) . '</code>';
                $first = reset($cells);
                if (!empty($first['plugin_version'])) {
                    $html .= ' <small>' . esc_html($first['plugin_version']) . '</small>';
                }
                if (!empty($first['created'])) {
                    $html .= '<br><small>' . esc_html(sprintf(
                        /* translators: %s: date the measurement was taken */
                        __('measured %s', 'super-speedy-performance-analysis'),
                        mysql2date(get_option('date_format'), $first['created'])
                    )) . '</small>';
                }
                // A grouped verdict is the cost of the plugin AND whatever cannot run without
                // it, which is not the same claim as the cost of the plugin.
                if (!empty($first['group_members'])) {
                    $html .= '<br><small>' . esc_html(sprintf(
                        /* translators: %s: comma-separated plugin slugs */
                        __('with %s, which cannot run without it', 'super-speedy-performance-analysis'),
                        str_replace(',', ', ', (string) $first['group_members'])
                    )) . '</small>';
                }
                $html .= '</td>';
                foreach ($modes as $mode) {
                    $html .= '<td>' . (isset($cells[$mode]) ? self::impact_cell($cells[$mode]) : '-') . '</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</table>';
        } else {
            $html .= '<p class="sspa-adhoc-note">' . esc_html__('Nothing has been measured on this page yet. Attribution above says which component ran the work; measuring says what actually changes when the plugin is not there.', 'super-speedy-performance-analysis') . '</p>';
        }

        if (self::is_reprofilable($row)) {
            $html .= '<p><button type="button" class="sspa-adhoc-btn sspa-adhoc-measure" data-profile-id="' . (int) $row['id'] . '">'
                . esc_html__('Measure plugin impact on this page', 'super-speedy-performance-analysis') . '</button></p>';
            $html .= '<div class="sspa-adhoc-plan"></div>';
        }
        $html .= '</div>';
        return $html;
    }

    private static function impact_cell($impact) {
        if ('measured' !== $impact['confidence']) {
            return '<span class="sspa-adhoc-noise">' . esc_html(sprintf(
                /* translators: %s: noise floor in ms */
                __('within ±%sms noise', 'super-speedy-performance-analysis'),
                number_format((float) $impact['noise_floor_ms'])
            )) . '</span>';
        }
        $delta = (float) $impact['delta_ttfb_ms'];
        $sql = (float) $impact['delta_sql_ms'];
        $queries = (int) $impact['delta_queries'];
        $html = '<strong class="' . ($delta < 0 ? 'sspa-adhoc-saves' : 'sspa-adhoc-adds') . '">' . esc_html(sprintf(
            $delta < 0
                /* translators: %s: milliseconds */
                ? __('saves %sms', 'super-speedy-performance-analysis')
                /* translators: %s: milliseconds */
                : __('adds %sms', 'super-speedy-performance-analysis'),
            number_format(abs($delta))
        )) . '</strong><br><small>' . esc_html(sprintf(
            /* translators: 1: signed SQL milliseconds, 2: signed query count */
            __('SQL %1$sms · %2$s queries', 'super-speedy-performance-analysis'),
            ($sql >= 0 ? '+' : '−') . number_format(abs($sql)),
            ($queries >= 0 ? '+' : '−') . number_format(abs($queries))
        )) . '</small>';
        return $html;
    }

    // ---------------- the plan: what a page-scoped sweep would cost ----------------

    /**
     * The plugin picker and the estimate, before anything runs.
     *
     * The estimate is the point. A sweep whose total jumped from 72 to 216 part way through
     * read as a bug when it was simply phase 2 starting; the fix is to say the number, and the
     * time, before the user commits to it.
     */
    public static function ajax_impact_plan() {
        global $wpdb;
        self::guard();

        // Two scopes, one picker. With a profile id it plans "measure plugins on this page";
        // without one it plans the site-wide sweep - which is now reached the same way, by
        // choosing plugins one by one, rather than by a button that swept everything. Nothing
        // this endpoint returns is preselected: measuring a plugin means excluding it from
        // test requests, another plugin can react to that, and "we think it is safe" is not a
        // decision to make on somebody else's site.
        $profile_id = isset($_POST['profile_id']) ? (int) $_POST['profile_id'] : 0;
        $row = null;
        if ($profile_id) {
            $row = self::profile_row($profile_id);
            if (!$row) {
                wp_send_json_error(__('That page profile no longer exists.', 'super-speedy-performance-analysis'));
            }
            if (!self::is_reprofilable($row)) {
                wp_send_json_error(__('This measurement is not a page request, so it cannot be re-measured plugin by plugin.', 'super-speedy-performance-analysis'));
            }
        }

        $eligible = SSPA_Dependency_Map::isolation_candidates();
        if (!$eligible) {
            wp_send_json_error(__('No plugins can be safely excluded on this site - every active plugin is either a dependency of another or on the fragile list.', 'super-speedy-performance-analysis'));
        }

        // What attribution blames, so the list is ordered by who is worth suspecting: SQL +
        // HTTP, plus the PHP the boot timer charged to each plugin (a plugin can cost 200ms
        // of hook time without running a single query). Ordering only, never preselection.
        $blamed = array();
        $pages = 1;
        if ($row) {
            $capture = self::capture($row);
            if (is_array($capture)) {
                foreach ((array) (isset($capture['components']) ? $capture['components'] : array()) as $component => $stats) {
                    $blamed[$component] = (isset($blamed[$component]) ? $blamed[$component] : 0)
                        + (float) $stats['sql_ms'] + (float) $stats['http_ms'];
                }
                foreach ((array) (isset($capture['boot']['components']) ? $capture['boot']['components'] : array()) as $component => $ms) {
                    $blamed[$component] = (isset($blamed[$component]) ? $blamed[$component] : 0) + (float) $ms;
                }
            }
        } else {
            $run_id = SSPA_Plugins_Table::latest_run_id();
            if (!$run_id) {
                wp_send_json_error(__('Run a normal analysis first - plugin impact analysis re-measures the pages it profiled.', 'super-speedy-performance-analysis'));
            }
            foreach ($wpdb->get_results($wpdb->prepare(
                'SELECT component, SUM(sql_ms + http_ms) cost FROM %i
                 WHERE run_id = %d GROUP BY component',
                SSPA_Schema::table('component_stats'),
                $run_id
            ), ARRAY_A) as $component_row) {
                $blamed[$component_row['component']] = (float) $component_row['cost'];
            }
            $pages = max(1, (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT page_key) FROM %i
                 WHERE run_id = %d AND blocked_by IS NULL AND page_gen_ms IS NOT NULL
                 AND page_key NOT IN ('baseline', 'mail-probe') AND page_key NOT LIKE %s",
                SSPA_Schema::table('profiles'),
                $run_id,
                $wpdb->esc_like('write-') . '%'
            )));
        }

        // Plugins that come out in the same cell as this one, so the picker can say so before
        // anybody presses the button rather than after the verdict arrives naming two plugins.
        $together = SSPA_Dependency_Map::must_exclude_together();

        $plugins = array();
        foreach ($eligible as $slug) {
            $group = array_values(array_intersect(
                isset($together[$slug]) ? (array) $together[$slug] : array(),
                $eligible
            ));
            $plugins[] = array(
                'slug' => $slug,
                'cost_ms' => isset($blamed[$slug]) ? round((float) $blamed[$slug], 1) : 0.0,
                'group' => $group,
            );
        }
        usort($plugins, function ($a, $b) {
            return $b['cost_ms'] <=> $a['cost_ms'];
        });

        $oc_capable = (wp_using_ext_object_cache() || file_exists(WP_CONTENT_DIR . '/object-cache.php'))
            && 'ours' === SSPA_Helper_Files::dropin_status();

        wp_send_json_success(array(
            'scope' => $row ? 'page' : 'site',
            'profile_id' => $row ? (int) $row['id'] : 0,
            'page_key' => $row ? $row['page_key'] : '',
            'url' => $row ? $row['url'] : '',
            'plugins' => $plugins,
            'pages' => $pages,
            'screen_pages' => SSPA_Run_Controller::SWEEP_SCREEN_PAGES,
            'oc_capable' => $oc_capable,
            'seconds_per_job' => self::seconds_per_job(),
            'rebaseline_every' => SSPA_Run_Controller::SWEEP_REBASELINE_EVERY,
        ));
    }

    /**
     * Seconds per measurement, learned from this site's own completed runs rather than
     * assumed. A measurement is a warm-up plus three samples of one page, so it varies by an
     * order of magnitude between a static home page and a slow admin screen - a constant here
     * would make the estimate fiction on exactly the sites that need it most.
     */
    public static function seconds_per_job() {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT r.started, r.finished, COUNT(p.id) jobs
             FROM %i r
             INNER JOIN %i p ON p.run_id = r.id
             WHERE r.status = 'done' AND r.finished IS NOT NULL AND r.started IS NOT NULL
             GROUP BY r.id HAVING jobs >= 3 ORDER BY r.id DESC LIMIT 5",
            SSPA_Schema::table('runs'),
            SSPA_Schema::table('profiles')
        ), ARRAY_A);
        $rates = array();
        foreach ((array) $rows as $run) {
            $seconds = strtotime($run['finished'] . ' UTC') - strtotime($run['started'] . ' UTC');
            if ($seconds > 0 && (int) $run['jobs'] > 0) {
                $rates[] = $seconds / (int) $run['jobs'];
            }
        }
        if (!$rates) {
            return self::FALLBACK_SECONDS_PER_JOB;
        }
        sort($rates);
        return max(2, round($rates[(int) floor((count($rates) - 1) / 2)], 1));
    }
}
