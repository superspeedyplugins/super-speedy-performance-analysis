<?php
defined('ABSPATH') || exit;

/**
 * The AJAX tab's observed-endpoints table and its "Profile this endpoint" action.
 *
 * The table shows what an AJAX window already stores per endpoint (SSPA_Report::endpoint_evidence).
 * Profiling an endpoint runs an ordinary ad-hoc analysis of a literal URL the administrator
 * confirms, through the real loopback and the site's real MU plugins, so a Fast trim applies.
 * Windows never store literal dynamic REST paths, which is why the URL is confirmed, not replayed.
 */
class SSPA_Endpoint_Profile {

    const OPTION = 'sspa_endpoint_profiles';
    const MAX_SAMPLES = 10;
    const DEFAULT_SAMPLES = 5;

    /** Only idempotent requests on the two transports the profiler can address by URL. */
    public static function profilable($identity) {
        return in_array($identity['transport'] ?? '', array('rest', 'admin_ajax'), true)
            && in_array(strtoupper((string) ($identity['method'] ?? '')), array('GET', 'HEAD'), true);
    }

    public static function name($identity) {
        return 'rest' === $identity['transport'] ? (string) $identity['route_pattern'] : (string) $identity['action'];
    }

    /**
     * A starting URL for the administrator to complete: optional groups dropped, each named
     * group shown as {name}. It is never requested as it stands.
     */
    public static function url_template($identity) {
        if ('admin_ajax' === $identity['transport']) {
            return add_query_arg('action', rawurlencode((string) $identity['action']), admin_url('admin-ajax.php'));
        }
        $pattern = (string) $identity['route_pattern'];
        $out = '';
        $length = strlen($pattern);
        for ($i = 0; $i < $length; $i++) {
            if ('(' === $pattern[$i] && '(?:' === substr($pattern, $i, 3)) {
                $end = self::group_end($pattern, $i);
                if ($end !== null && isset($pattern[$end + 1]) && '?' === $pattern[$end + 1]) {
                    $i = $end + 1; // an optional group: leave it out
                    continue;
                }
            }
            if ('(' === $pattern[$i] && preg_match('/\G\(\?P?<([a-zA-Z0-9_]+)>/', $pattern, $m, 0, $i)) {
                $end = self::group_end($pattern, $i);
                if (null !== $end) {
                    $out .= '{' . $m[1] . '}';
                    $i = $end;
                    continue;
                }
            }
            $out .= $pattern[$i];
        }
        $out = trim($out, '^$');
        return rest_url(ltrim($out, '/'));
    }

    private static function group_end($pattern, $start) {
        $depth = 0;
        $length = strlen($pattern);
        for ($i = $start; $i < $length; $i++) {
            if ('\\' === $pattern[$i]) {
                $i++;
                continue;
            }
            if ('(' === $pattern[$i]) {
                $depth++;
            } elseif (')' === $pattern[$i]) {
                $depth--;
                if (0 === $depth) {
                    return $i;
                }
            }
        }
        return null;
    }

    /**
     * The URL must address exactly the observed endpoint.
     *
     * @return true|WP_Error
     */
    public static function validate_url($identity, $url) {
        $parts = wp_parse_url((string) $url);
        $home = wp_parse_url(home_url('/'));
        if (!is_array($parts) || empty($parts['host']) || empty($parts['scheme']) || !is_array($home)
            || 0 !== strcasecmp($parts['host'], (string) $home['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return new WP_Error('sspa_endpoint_url', __('Enter a full URL on this site.', 'super-speedy-performance-analysis'));
        }
        if (false !== strpos((string) $url, '{') || false !== strpos(rawurldecode((string) $url), '{')) {
            return new WP_Error('sspa_endpoint_placeholder', __('Replace each {placeholder} in the URL with a real value before profiling.', 'super-speedy-performance-analysis'));
        }
        $query = array();
        parse_str(isset($parts['query']) ? (string) $parts['query'] : '', $query);
        $path = isset($parts['path']) ? (string) $parts['path'] : '/';
        if ('admin_ajax' === $identity['transport']) {
            $ajax_path = (string) wp_parse_url(admin_url('admin-ajax.php'), PHP_URL_PATH);
            if (untrailingslashit($path) !== untrailingslashit($ajax_path) || !isset($query['action']) || (string) $query['action'] !== (string) $identity['action']) {
                /* translators: %s: the observed admin-ajax action */
                return new WP_Error('sspa_endpoint_mismatch', sprintf(__('The URL must be admin-ajax.php with action=%s.', 'super-speedy-performance-analysis'), $identity['action']));
            }
            return true;
        }
        if (isset($query['rest_route']) && is_string($query['rest_route'])) {
            $route = $query['rest_route'];
        } else {
            $rest_path = untrailingslashit((string) wp_parse_url(get_rest_url(), PHP_URL_PATH));
            if ('' === $rest_path || 0 !== strpos($path, $rest_path . '/')) {
                return new WP_Error('sspa_endpoint_mismatch', __('The URL is not a REST API URL on this site.', 'super-speedy-performance-analysis'));
            }
            $route = substr($path, strlen($rest_path));
        }
        $route = '/' . ltrim(rawurldecode((string) $route), '/');
        // The same match WP_REST_Server::match_request_to_handler() makes.
        if (1 !== @preg_match('@^' . $identity['route_pattern'] . '$@i', $route)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a route pattern from another plugin may not compile; a failed match is reported below.
            /* translators: %s: the observed REST route pattern */
            return new WP_Error('sspa_endpoint_mismatch', sprintf(__('The URL does not match the observed route %s.', 'super-speedy-performance-analysis'), $identity['route_pattern']));
        }
        return true;
    }

    /** The endpoint, by evidence key, in the window's own collection. */
    public static function find($uuid, $key) {
        $windows = SSPA_Ajax_Profile::windows();
        if (!isset($windows[$uuid])) {
            return new WP_Error('sspa_ajax_window', __('AJAX window not found.', 'super-speedy-performance-analysis'));
        }
        $evidence = SSPA_Report::endpoint_evidence((int) $windows[$uuid]['collection_id']);
        if (is_wp_error($evidence)) {
            return $evidence;
        }
        foreach ($evidence['endpoints'] as $endpoint) {
            if (hash_equals((string) $endpoint['key'], strtolower((string) $key))) {
                return $endpoint;
            }
        }
        return new WP_Error('sspa_endpoint_unknown', __('That endpoint was not observed in this window.', 'super-speedy-performance-analysis'));
    }

    /**
     * @param array $args {uuid, key, url, samples, confirmed}
     * @return array|WP_Error {run_id, page_key, url, samples, variant}
     */
    public static function start($args) {
        if (empty($args['confirmed'])) {
            return new WP_Error('sspa_endpoint_confirm', __('Confirm that this exact URL may be requested before profiling it.', 'super-speedy-performance-analysis'));
        }
        $samples = isset($args['samples']) ? (int) $args['samples'] : self::DEFAULT_SAMPLES;
        if ($samples < 1 || $samples > self::MAX_SAMPLES) {
            /* translators: %d: the maximum number of samples */
            return new WP_Error('sspa_endpoint_samples', sprintf(__('Choose between 1 and %d samples.', 'super-speedy-performance-analysis'), self::MAX_SAMPLES));
        }
        $endpoint = self::find(isset($args['uuid']) ? (string) $args['uuid'] : '', isset($args['key']) ? (string) $args['key'] : '');
        if (is_wp_error($endpoint)) {
            return $endpoint;
        }
        $identity = $endpoint['identity'];
        if (!self::profilable($identity)) {
            return new WP_Error('sspa_endpoint_method', __('Only GET and HEAD requests to REST or admin-ajax endpoints can be profiled. Other requests change data, and their bodies are not stored.', 'super-speedy-performance-analysis'));
        }
        $raw = trim((string) ($args['url'] ?? ''));
        // Before esc_url_raw(), which deletes braces: "startup/{term}" would otherwise become
        // the real, different request "startup/term".
        if (false !== strpos($raw, '{') || false !== strpos($raw, '}') || false !== stripos($raw, '%7B') || false !== stripos($raw, '%7D')) {
            return new WP_Error('sspa_endpoint_placeholder', __('Replace each {placeholder} in the URL with a real value before profiling.', 'super-speedy-performance-analysis'));
        }
        $url = esc_url_raw($raw, array('http', 'https'));
        if ($url !== $raw) {
            return new WP_Error('sspa_endpoint_url', __('The URL contains characters that cannot be requested as typed. Enter it exactly as the browser sends it.', 'super-speedy-performance-analysis'));
        }
        $valid = self::validate_url($identity, $url);
        if (is_wp_error($valid)) {
            return $valid;
        }
        $variant = 'logged_in' === $identity['auth_context'] ? 'admin' : 'anon';
        $run_id = SSPA_Run_Controller::start(array('type' => 'adhoc', 'url' => $url, 'samples' => $samples, 'variant' => $variant, 'trigger' => 'ajax-endpoint'));
        if (is_wp_error($run_id)) {
            return $run_id;
        }
        $job = SSPA_Adhoc::job_for($url, $variant);
        $record = array('url' => $url, 'page_key' => $job['page_key'], 'samples' => $samples, 'variant' => $variant, 'run_id' => (int) $run_id, 'started' => gmdate('c'));
        $all = self::records();
        $all[$endpoint['key']] = $record;
        update_option(self::OPTION, $all, false);
        return $record;
    }

    public static function records() {
        return (array) get_option(self::OPTION, array());
    }

    /** The newest stored profile of an endpoint profiled from this table, or 0. */
    public static function latest_profile_id($key) {
        $records = self::records();
        if (empty($records[$key]['page_key'])) {
            return 0;
        }
        return (int) SSPA_Profile_Panel::newest_profile_id_for_page($records[$key]['page_key']);
    }

    private static function ms($value) {
        return null === $value ? '—' : number_format_i18n((float) $value) . ' ms';
    }

    /** Plugin display names for recorded basenames, from the installed headers. */
    private static function plugin_label($file, $version, $installed) {
        $name = isset($installed[$file]['Name']) && '' !== $installed[$file]['Name'] ? $installed[$file]['Name'] : $file;
        return trim($name . ' ' . $version);
    }

    /** The observed-endpoints table for one window, as a string (the AJAX operation). */
    public static function table_html($uuid = '') {
        ob_start();
        self::render_table($uuid);
        return ob_get_clean();
    }

    /** Output the observed-endpoints table for one window: the latest one when $uuid is empty. */
    public static function render_table($uuid = '') {
        $windows = SSPA_Ajax_Profile::windows();
        if (!$windows) {
            echo '<p class="description">' . esc_html__('Run a window to see each endpoint\'s start-up, handler, CPU and loaded plugins here.', 'super-speedy-performance-analysis') . '</p>';
            return;
        }
        if (!isset($windows[$uuid])) {
            $uuids = array_keys($windows);
            $uuid = (string) end($uuids);
        }
        $window = $windows[$uuid];
        $evidence = SSPA_Report::endpoint_evidence((int) $window['collection_id']);
        if (!function_exists('get_plugins')) { require_once SSPA_CORE_DIR . 'wp-admin/includes/plugin.php'; }
        $installed = get_plugins();
        $active = count((array) get_option('active_plugins', array()));
        $records = self::records();
        ?>
        <div class="sspa-ajax-endpoints" data-uuid="<?php echo esc_attr($uuid); ?>">
        <p class="description"><?php esc_html_e('What this window recorded for each endpoint, unprofiled. Wall time runs from the MU observer to shutdown. Outside handler is wall minus handler: start-up before the handler plus shutdown after it. Before MU is core start to the observer; PHP start is the request reaching PHP to WordPress starting its timer. CPU well below wall means waiting: database, network, disk or sleeps.', 'super-speedy-performance-analysis'); ?></p>
        <?php if (is_wp_error($evidence) || empty($evidence['endpoints'])) : ?>
            <p><?php esc_html_e('No registered endpoint was observed in this window.', 'super-speedy-performance-analysis'); ?></p>
        <?php else : ?>
        <div class="sspa-table-scroll"><table class="widefat striped sspa-ajax-endpoints-table">
            <thead><tr>
                <th><?php esc_html_e('Endpoint', 'super-speedy-performance-analysis'); ?></th>
                <th><?php esc_html_e('Requests', 'super-speedy-performance-analysis'); ?></th>
                <th><?php esc_html_e('Wall (median / p95)', 'super-speedy-performance-analysis'); ?></th>
                <th><?php esc_html_e('Handler', 'super-speedy-performance-analysis'); ?></th>
                <th><?php esc_html_e('Outside handler', 'super-speedy-performance-analysis'); ?></th>
                <th><?php esc_html_e('Before MU / PHP start', 'super-speedy-performance-analysis'); ?></th>
                <th><?php esc_html_e('CPU', 'super-speedy-performance-analysis'); ?></th>
                <th><?php esc_html_e('Queries', 'super-speedy-performance-analysis'); ?></th>
                <th><?php esc_html_e('Loaded setup', 'super-speedy-performance-analysis'); ?></th>
                <th><?php esc_html_e('Profile', 'super-speedy-performance-analysis'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($evidence['endpoints'] as $endpoint) :
                $identity = $endpoint['identity'];
                $o = $endpoint['observations'];
                $wall = $o['whole_request_wall_ms']['median'];
                $cpu = $o['cpu_ms']['median'];
                $selector = $identity['transport'] . ':' . self::name($identity);
                ?>
                <tr data-endpoint="<?php echo esc_attr($selector); ?>" data-method="<?php echo esc_attr($identity['method']); ?>" data-key="<?php echo esc_attr($endpoint['key']); ?>">
                    <td><code><?php echo esc_html(self::name($identity)); ?></code><br><span class="description"><?php echo esc_html(implode(' · ', array(str_replace('_', '-', $identity['transport']), $identity['method'], str_replace('_', ' ', $identity['auth_context'])))); ?></span></td>
                    <td><?php echo esc_html(number_format_i18n((int) $o['count'])); ?></td>
                    <td><?php echo esc_html(self::ms($wall) . ' / ' . self::ms($o['whole_request_wall_ms']['p95'])); ?></td>
                    <td><?php echo esc_html(self::ms($o['handler_wall_ms']['median'])); ?></td>
                    <td><?php echo esc_html(self::ms($o['outside_handler_ms']['median'])); ?></td>
                    <td><?php echo esc_html(self::ms($o['pre_mu_ms']['median']) . ' / ' . self::ms($o['php_start_to_wordpress_ms']['median'])); ?></td>
                    <td><?php
                        echo esc_html(self::ms($cpu));
                        if (null !== $cpu && $wall) {
                            /* translators: %s: CPU median as a percentage of the wall median */
                            echo '<br><span class="description">' . esc_html(sprintf(__('%s%% of wall', 'super-speedy-performance-analysis'), number_format_i18n(100 * $cpu / $wall))) . '</span>';
                        }
                    ?></td>
                    <td><?php echo esc_html(null === $o['query_count']['median'] ? '—' : number_format_i18n((int) $o['query_count']['median'])); ?></td>
                    <td><?php
                        if (empty($endpoint['setups'])) {
                            esc_html_e('Not recorded', 'super-speedy-performance-analysis');
                        }
                        foreach ($endpoint['setups'] as $setup) {
                            $loaded = count($setup['plugins']);
                            echo '<div class="sspa-ajax-setup">';
                            if (count($endpoint['setups']) > 1) {
                                /* translators: %s: number of requests with this setup */
                                echo '<strong>' . esc_html(sprintf(_n('%s request:', '%s requests:', (int) $setup['requests'], 'super-speedy-performance-analysis'), number_format_i18n((int) $setup['requests']))) . '</strong> ';
                            }
                            if ($loaded < $active) {
                                /* translators: 1: plugins loaded on this endpoint, 2: plugins active on the site */
                                echo '<strong>' . esc_html(sprintf(__('%1$d of %2$d active plugins loaded', 'super-speedy-performance-analysis'), $loaded, $active)) . '</strong>: ';
                            } else {
                                /* translators: %d: plugins loaded */
                                echo esc_html(sprintf(_n('%d plugin loaded', '%d plugins loaded', $loaded, 'super-speedy-performance-analysis'), $loaded)) . ': ';
                            }
                            $labels = array();
                            foreach ($setup['plugins'] as $file => $version) {
                                $labels[] = self::plugin_label($file, $version, $installed);
                            }
                            echo esc_html(implode(', ', $labels));
                            if (!empty($setup['theme']['stylesheet'])) {
                                /* translators: %s: theme stylesheet slug */
                                echo '<br><span class="description">' . esc_html(sprintf(__('Theme: %s', 'super-speedy-performance-analysis'), $setup['theme']['stylesheet'])) . '</span>';
                            }
                            echo '</div>';
                        }
                    ?></td>
                    <td><?php
                        $profile_id = self::latest_profile_id($endpoint['key']);
                        if ($profile_id) {
                            echo '<button type="button" class="button-link sspa-endpoint-profile-view" data-profile-id="' . (int) $profile_id . '">' . esc_html__('Open latest profile', 'super-speedy-performance-analysis') . '</button><br>';
                        }
                        if (self::profilable($identity)) {
                            $url = isset($records[$endpoint['key']]['url']) ? $records[$endpoint['key']]['url'] : self::url_template($identity);
                            echo '<button type="button" class="button sspa-endpoint-profile-open" data-url="' . esc_attr($url) . '">' . esc_html__('Profile this endpoint', 'super-speedy-performance-analysis') . '</button>';
                        } else {
                            echo '<span class="description">' . esc_html__('Not profiled: only GET and HEAD REST or admin-ajax requests are replayed.', 'super-speedy-performance-analysis') . '</span>';
                        }
                    ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <form class="sspa-endpoint-profile-form" hidden>
            <input type="hidden" name="key" value="">
            <p><label><?php esc_html_e('Exact URL to request', 'super-speedy-performance-analysis'); ?> <input type="url" name="url" class="large-text" required></label></p>
            <p class="description"><?php esc_html_e('Windows do not store the literal URLs of requests, only the route. Replace each {placeholder} with a real value, such as a search term. The request is sent without your login for an anonymous endpoint, and as you for a logged-in one. A HEAD endpoint is profiled with GET, which WordPress routes to the same handler.', 'super-speedy-performance-analysis'); ?></p>
            <p><label><?php esc_html_e('Samples', 'super-speedy-performance-analysis'); ?> <input type="number" name="samples" min="1" max="<?php echo (int) self::MAX_SAMPLES; ?>" value="<?php echo (int) self::DEFAULT_SAMPLES; ?>"></label></p>
            <p><label><input type="checkbox" name="confirmed" value="1" required> <?php esc_html_e('I confirm this exact URL is safe to request this many times plus one warm-up.', 'super-speedy-performance-analysis'); ?></label></p>
            <p><button class="button button-primary"><?php esc_html_e('Start profiling', 'super-speedy-performance-analysis'); ?></button> <button type="button" class="button sspa-endpoint-profile-cancel"><?php esc_html_e('Cancel', 'super-speedy-performance-analysis'); ?></button></p>
        </form>
        <?php endif; ?>
        </div>
        <?php
    }
}
