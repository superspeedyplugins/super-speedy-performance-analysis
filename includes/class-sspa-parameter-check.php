<?php
defined('ABSPATH') || exit;

/**
 * Ad-click and campaign URL parameters: what they make the site do, and how its page cache keys
 * them. Started only by an administrator (its own button on the Cache performance tab, or the
 * run-parameter-check ability), so an ordinary analysis and cache check cost nothing extra.
 *
 * Cookie pass: one profiled cookie-less request per representative page without a parameter
 * (the baseline), then each click ID on every representative page and each campaign parameter
 * on home only, each with a random value. A cookie absent from that page's baseline is the
 * parameter's, and the boot timer of the same profiled request names the code that set it.
 *
 * Cache-key pass: a page the cache is shown to serve (a cookie-less request, then two carrying
 * only the cookies it set), then per parameter value A twice and value B once with those same
 * cookies. ignored: B is still served from cache. separate_key: the repeated A is, B is not, so
 * every real click (unique IDs) misses. bypass: never served from cache. unknown: no cache layer
 * reported a status, or no page could be shown cacheable.
 *
 * Bounded: 4 + 10 x 4 + 8 = 52 profiled requests and at most 12 + 18 x 3 = 66 server requests.
 * Values, cookie values and response bodies are never stored.
 */
class SSPA_Parameter_Check {

    const SCHEMA = 'sspa/parameter-check@1';
    const PENDING_OPTION = 'sspa_parameter_check_pending';
    const REPORT_OPTION = 'sspa_parameter_check_report';
    /** Requests one advance makes at most, and its time budget in seconds. */
    const STEP_REQUESTS = 12;
    const STEP_SECONDS = 20;

    /** Click identifiers ad platforms append: checked on every representative page. */
    const CLICK_IDS = array('fbclid', 'gclid', 'gbraid', 'wbraid', 'msclkid', 'ttclid', 'twclid', 'li_fat_id', 'epik', 'srsltid');
    /** Campaign and referral parameters: checked on home only. */
    const CAMPAIGN = array('utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'ref', 'mc_cid', 'mc_eid');

    public static function parameters() {
        return array_merge(self::CLICK_IDS, self::CAMPAIGN);
    }

    /** The request budget shown before the button and returned by run-parameter-check. */
    public static function request_plan($target_count) {
        $cookie = $target_count + count(self::CLICK_IDS) * $target_count + count(self::CAMPAIGN);
        $key = count(self::parameters()) * 3;
        return array(
            'profiled_requests' => $cookie,
            'cache_key_requests' => $key,
            'cache_key_page_requests_max' => 3 * $target_count,
            'request_count_max' => $cookie + $key + 3 * $target_count,
        );
    }

    public static function ajax_prepare() {
        check_ajax_referer('sspa_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You are not allowed to run this check.', 'super-speedy-performance-analysis')), 403);
        }
        $prepared = self::prepare();
        if (is_wp_error($prepared)) {
            wp_send_json_error(array('message' => $prepared->get_error_message(), 'code' => $prepared->get_error_code()));
        }
        wp_send_json_success($prepared);
    }

    public static function ajax_advance() {
        check_ajax_referer('sspa_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You are not allowed to run this check.', 'super-speedy-performance-analysis')), 403);
        }
        $check_id = isset($_POST['check_id']) ? sanitize_text_field(wp_unslash($_POST['check_id'])) : '';
        $result = self::advance($check_id);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message(), 'code' => $result->get_error_code()));
        }
        wp_send_json_success($result);
    }

    public static function prepare() {
        $targets = SSPA_Cache_Delivery::targets();
        if (!$targets || 'home' !== $targets[0]['page_key']) {
            return new WP_Error('sspa_parameter_check_targets', __('The check needs a public home page on this site.', 'super-speedy-performance-analysis'));
        }
        $targets = array_map(function ($t) {
            return array('page_key' => $t['page_key'], 'label' => $t['label'], 'url' => $t['url']);
        }, $targets);
        $queue = array();
        foreach ($targets as $target) {
            $queue[] = array('page_key' => $target['page_key'], 'parameter' => '');
        }
        foreach (self::CLICK_IDS as $parameter) {
            foreach ($targets as $target) {
                $queue[] = array('page_key' => $target['page_key'], 'parameter' => $parameter);
            }
        }
        foreach (self::CAMPAIGN as $parameter) {
            $queue[] = array('page_key' => 'home', 'parameter' => $parameter);
        }
        $check_id = wp_generate_uuid4();
        $plan = self::request_plan(count($targets));
        update_option(self::PENDING_OPTION, array(
            'check_id' => $check_id,
            'created_at' => time(),
            'run_id' => (int) SSPA_Report::latest_done_run_id(),
            'targets' => $targets,
            'cookie_queue' => $queue,
            'cookie_results' => array(),
            'key' => array('page_index' => 0, 'step' => 0, 'jar' => array(), 'page' => null, 'probes' => array(), 'pages_tried' => array()),
            'key_results' => array(),
            'requests_made' => 0,
            'requests_total' => $plan['profiled_requests'] + $plan['cache_key_requests'] + 3,
        ), false);
        return array_merge(array('check_id' => $check_id, 'targets' => $targets, 'parameters' => self::parameters()), $plan);
    }

    public static function latest() {
        $report = get_option(self::REPORT_OPTION, null);
        return is_array($report) && self::SCHEMA === ($report['schema'] ?? '') ? $report : null;
    }

    private static function pending($check_id) {
        $pending = get_option(self::PENDING_OPTION, null);
        if (!is_array($pending) || empty($pending['check_id']) || !hash_equals((string) $pending['check_id'], (string) $check_id)) {
            return new WP_Error('sspa_parameter_check_expired', __('This parameter check is missing or has been replaced. Start it again.', 'super-speedy-performance-analysis'));
        }
        if (time() - (int) $pending['created_at'] > HOUR_IN_SECONDS) {
            delete_option(self::PENDING_OPTION);
            return new WP_Error('sspa_parameter_check_expired', __('This parameter check expired. Start it again.', 'super-speedy-performance-analysis'));
        }
        return $pending;
    }

    /**
     * Make the next requests, within STEP_REQUESTS and STEP_SECONDS, and save progress. On the
     * last one the report is built and saved.
     *
     * @return array {done, requests_made, requests_total, phase} plus headline when done.
     */
    public static function advance($check_id) {
        $pending = self::pending($check_id);
        if (is_wp_error($pending)) {
            $report = self::latest();
            if ($report && hash_equals((string) $report['check_id'], (string) $check_id)) {
                return array('done' => true, 'requests_made' => (int) $report['requests']['total'], 'requests_total' => (int) $report['requests']['total'], 'phase' => 'done', 'headline' => $report['headline']);
            }
            return $pending;
        }
        $started = microtime(true);
        $made = 0;
        while ($made < self::STEP_REQUESTS && microtime(true) - $started < self::STEP_SECONDS) {
            if ($pending['cookie_queue']) {
                $step = array_shift($pending['cookie_queue']);
                $pending['cookie_results'][] = self::cookie_request($pending['targets'], $step);
            } elseif (!self::key_step($pending)) {
                break;
            }
            $made++;
            $pending['requests_made']++;
        }
        $phase = $pending['cookie_queue'] ? 'cookies' : (null === $pending['key']['page'] ? 'cache_key_page' : 'cache_key');
        $finished = !$pending['cookie_queue'] && self::key_finished($pending);
        if (!$finished) {
            update_option(self::PENDING_OPTION, $pending, false);
            return array('done' => false, 'requests_made' => (int) $pending['requests_made'], 'requests_total' => max((int) $pending['requests_total'], (int) $pending['requests_made'] + 1), 'phase' => $phase);
        }
        $report = self::build_report($pending);
        update_option(self::REPORT_OPTION, $report, false);
        delete_option(self::PENDING_OPTION);
        return array('done' => true, 'requests_made' => (int) $report['requests']['total'], 'requests_total' => (int) $report['requests']['total'], 'phase' => 'done', 'headline' => $report['headline']);
    }

    private static function target($targets, $page_key) {
        foreach ($targets as $target) {
            if ($target['page_key'] === $page_key) {
                return $target;
            }
        }
        return null;
    }

    private static function with_parameter($url, $parameter, $value) {
        return $url . (false === strpos($url, '?') ? '?' : '&') . rawurlencode($parameter) . '=' . rawurlencode($value);
    }

    /** One profiled cookie-less request; only cookie names and their attribution are kept. */
    private static function cookie_request($targets, $step) {
        $target = self::target($targets, $step['page_key']);
        $url = '' === $step['parameter'] ? $target['url'] : self::with_parameter($target['url'], $step['parameter'], bin2hex(random_bytes(8)));
        $crawler = new SSPA_Crawler();
        $sample = $crawler->send_profiled($url, array('flags' => array('v' => 'anon')));
        $names = array();
        foreach ((array) ($sample['cookies'] ?? array()) as $cookie) {
            if (is_object($cookie) && !empty($cookie->name) && !SSPA_Cache_Delivery::is_infrastructure_cookie($cookie->name)) {
                $names[(string) $cookie->name] = true;
            }
        }
        $attribution = array();
        foreach ((array) ($sample['capture']['boot']['set_cookies'] ?? array()) as $row) {
            if (is_array($row) && isset($names[(string) ($row['name'] ?? '')])) {
                $attribution[] = array_intersect_key($row, array_flip(array('name', 'component', 'type', 'component_name', 'version', 'hook', 'callback', 'attribution', 'detected_at')));
            }
        }
        return array(
            'page_key' => $step['page_key'],
            'parameter' => $step['parameter'],
            'http_status' => (int) ($sample['code'] ?? 0),
            'error' => !empty($sample['error']) ? sanitize_key((string) $sample['error']) : (!empty($sample['blocked_by']) ? 'blocked' : ''),
            'cookie_names' => array_keys($names),
            'attribution' => $attribution,
            'attribution_available' => isset($sample['capture']['boot']) && array_key_exists('set_cookies', (array) $sample['capture']['boot']),
        );
    }

    /**
     * One request of the cache-key pass. Finding a cacheable page first: a cookie-less request,
     * then two carrying only the cookies it set; a HIT on any proves the page. Then three requests
     * per parameter with the same cookies.
     *
     * @return bool False when there is nothing left to request.
     */
    private static function key_step(&$pending) {
        $key = &$pending['key'];
        if (null === $key['page']) {
            if ($key['page_index'] >= count($pending['targets'])) {
                return false;
            }
            $target = $pending['targets'][$key['page_index']];
            $probe = SSPA_Cache_Delivery::probe_url($target['page_key'], $target['url'], 0 === $key['step'] ? array() : $key['jar']);
            if (0 === $key['step']) {
                $key['jar'] = $probe['cookie_jar'];
                $key['probes'] = array();
            }
            unset($probe['cookie_jar']);
            $key['probes'][] = $probe;
            $key['step']++;
            if (in_array('HIT', $probe['statuses'], true)) {
                $key['page'] = array('page_key' => $target['page_key'], 'url' => $target['url'],
                    'audience' => $key['jar'] ? 'returning_visitor' : 'new_visitor', 'cookie_names' => array_keys($key['jar']),
                    'probes' => $key['probes']);
                $pending['key_queue'] = self::parameters();
            } elseif ($key['step'] >= 3) {
                $key['pages_tried'][] = array('page_key' => $target['page_key'], 'probes' => $key['probes']);
                $key['page_index']++;
                $key['step'] = 0;
                $key['jar'] = array();
            }
            return true;
        }
        if (empty($pending['key_queue'])) {
            return false;
        }
        $parameter = $pending['key_queue'][0];
        if (!isset($pending['key_results'][$parameter])) {
            $pending['key_results'][$parameter] = array('values' => array(bin2hex(random_bytes(8)), bin2hex(random_bytes(8))), 'requests' => array());
        }
        $entry = &$pending['key_results'][$parameter];
        $n = count($entry['requests']);
        $value = 2 === $n ? $entry['values'][1] : $entry['values'][0];
        $probe = SSPA_Cache_Delivery::probe_url($key['page']['page_key'], self::with_parameter($key['page']['url'], $parameter, $value), $key['jar']);
        unset($probe['cookie_jar']);
        $entry['requests'][] = $probe;
        if (3 === count($entry['requests'])) {
            unset($entry['values']);
            array_shift($pending['key_queue']);
        }
        return true;
    }

    private static function key_finished($pending) {
        $key = $pending['key'];
        if (null === $key['page']) {
            return $key['page_index'] >= count($pending['targets']);
        }
        return empty($pending['key_queue']);
    }

    /** ignored, separate_key, bypass or unknown, from the three statuses of one parameter. */
    public static function cache_outcome($requests) {
        if (3 !== count($requests) || array_filter($requests, function ($r) { return '' !== $r['error_class'] || 200 !== (int) $r['http_status']; })) {
            return 'unknown';
        }
        $hit = array_map(function ($r) { return in_array('HIT', $r['statuses'], true); }, $requests);
        $any_status = (bool) array_filter($requests, function ($r) { return (bool) $r['statuses']; });
        if (!$any_status) {
            return 'unknown';
        }
        if ($hit[2]) {
            return 'ignored';
        }
        if ($hit[1]) {
            return 'separate_key';
        }
        if (!$hit[0]) {
            return 'bypass';
        }
        return 'unknown';
    }

    private static function build_report($pending) {
        $baseline = array();
        foreach ($pending['cookie_results'] as $row) {
            if ('' === $row['parameter']) {
                $baseline[$row['page_key']] = $row['cookie_names'];
            }
        }
        $plain_key_cookies = $pending['key']['page'] ? (array) $pending['key']['page']['cookie_names'] : array();
        $rows = array();
        $index = 0;
        foreach (self::parameters() as $parameter) {
            $cookies = array();
            $pages = array();
            $errors = array();
            foreach ($pending['cookie_results'] as $row) {
                if ($row['parameter'] !== $parameter) {
                    continue;
                }
                $pages[] = $row['page_key'];
                if ('' !== $row['error']) {
                    $errors[] = $row['page_key'] . ':' . $row['error'];
                }
                $plain = isset($baseline[$row['page_key']]) ? $baseline[$row['page_key']] : array();
                foreach (array_diff($row['cookie_names'], $plain) as $name) {
                    if (!isset($cookies[$name])) {
                        $cookies[$name] = array('name' => $name, 'pages' => array(), 'set_by' => array());
                    }
                    $cookies[$name]['pages'][] = $row['page_key'];
                    $found = false;
                    foreach ($row['attribution'] as $who) {
                        if ($who['name'] === $name) {
                            $found = true;
                            $cookies[$name]['set_by'][md5(wp_json_encode($who))] = $who;
                        }
                    }
                    if (!$found) {
                        $who = array('name' => $name, 'component' => null, 'type' => null, 'component_name' => null, 'version' => null, 'hook' => null,
                            'callback' => null, 'attribution' => $row['attribution_available'] ? 'not_set_by_profiled_php' : 'not_attributed', 'detected_at' => null);
                        $cookies[$name]['set_by'][md5(wp_json_encode($who))] = $who;
                    }
                }
            }
            foreach ($cookies as &$cookie) {
                $cookie['set_by'] = array_values($cookie['set_by']);
            }
            unset($cookie);
            $cache = array('outcome' => 'unknown', 'statuses' => array(), 'response_cookies' => array());
            if (isset($pending['key_results'][$parameter])) {
                $requests = $pending['key_results'][$parameter]['requests'];
                $cache['outcome'] = self::cache_outcome($requests);
                $cache['statuses'] = array_map(function ($r) { return $r['statuses'] ? implode('/', $r['statuses']) : ''; }, $requests);
                $response = array();
                foreach ($requests as $r) {
                    foreach (array_diff((array) $r['cookie_names'], $plain_key_cookies) as $name) {
                        $response[$name] = true;
                    }
                }
                $cache['response_cookies'] = array_keys($response);
            } elseif (!$pending['key']['page']) {
                $cache['reason'] = 'no_cacheable_page_found';
            }
            $sets_cookie = (bool) $cookies;
            $misses = in_array($cache['outcome'], array('bypass', 'separate_key'), true);
            $rows[] = array(
                'parameter' => $parameter,
                'kind' => in_array($parameter, self::CLICK_IDS, true) ? 'click_id' : 'campaign',
                'pages_checked' => $pages,
                'errors' => $errors,
                'cookies' => array_values($cookies),
                'cache' => $cache,
                'worst' => $sets_cookie && $misses,
                'rank' => ($sets_cookie && $misses ? 0 : ($sets_cookie ? 1 : ($misses ? 2 : 3))) * 100 + $index++,
            );
        }
        usort($rows, function ($a, $b) { return $a['rank'] <=> $b['rank']; });
        foreach ($rows as &$row) {
            unset($row['rank']);
        }
        unset($row);

        $worst = array_values(array_filter($rows, function ($r) { return $r['worst']; }));
        $cookie_rows = array_values(array_filter($rows, function ($r) { return (bool) $r['cookies']; }));
        $miss_rows = array_values(array_filter($rows, function ($r) { return in_array($r['cache']['outcome'], array('bypass', 'separate_key'), true); }));
        if ($worst) {
            $first = $worst[0];
            $who = $first['cookies'][0]['set_by'][0];
            $name = !empty($who['component']) ? trim((!empty($who['component_name']) ? $who['component_name'] : $who['component']) . (!empty($who['version']) ? ' ' . $who['version'] : '')) : __('code the profiler could not name', 'super-speedy-performance-analysis');
            $headline = sprintf(
                /* translators: 1: parameter, 2: cookie name, 3: plugin name and version, 4: number of parameters */
                _n('%1$s makes %3$s set %2$s and is not served from cache; %4$d parameter does both', '%1$s makes %3$s set %2$s and is not served from cache; %4$d parameters do both', count($worst), 'super-speedy-performance-analysis'),
                $first['parameter'],
                $first['cookies'][0]['name'],
                $name,
                count($worst)
            );
        } elseif ($cookie_rows || $miss_rows) {
            $headline = sprintf(
                /* translators: 1: number of parameters that set a cookie, 2: number of parameters not served from cache */
                __('%1$d parameter(s) set a cookie and %2$d are not served from cache', 'super-speedy-performance-analysis'),
                count($cookie_rows),
                count($miss_rows)
            );
        } else {
            $headline = __('No checked parameter set a cookie or missed the cache', 'super-speedy-performance-analysis');
        }
        $cookie_requests = count($pending['cookie_results']);
        $page_requests = $pending['key']['page'] ? count($pending['key']['page']['probes']) : 0;
        foreach ((array) $pending['key']['pages_tried'] as $tried) {
            $page_requests += count($tried['probes']);
        }
        $key_requests = 0;
        foreach ($pending['key_results'] as $entry) {
            $key_requests += count($entry['requests']);
        }
        return array(
            'schema' => self::SCHEMA,
            'check_id' => $pending['check_id'],
            'generated_at' => gmdate('c'),
            'run_id' => (int) $pending['run_id'],
            'headline' => $headline,
            'targets' => $pending['targets'],
            'baseline_cookies' => $baseline,
            'cache_key_page' => $pending['key']['page'] ? array(
                'page_key' => $pending['key']['page']['page_key'],
                'url' => $pending['key']['page']['url'],
                'audience' => $pending['key']['page']['audience'],
                'cookie_names' => $pending['key']['page']['cookie_names'],
                'statuses' => array_map(function ($p) { return $p['statuses'] ? implode('/', $p['statuses']) : ''; }, $pending['key']['page']['probes']),
            ) : null,
            'pages_not_cacheable' => array_map(function ($tried) {
                return array('page_key' => $tried['page_key'], 'statuses' => array_map(function ($p) { return $p['statuses'] ? implode('/', $p['statuses']) : ''; }, $tried['probes']));
            }, (array) $pending['key']['pages_tried']),
            'parameters' => $rows,
            'requests' => array(
                'profiled' => $cookie_requests,
                'cache_key_page' => $page_requests,
                'cache_key' => $key_requests,
                'total' => $cookie_requests + $page_requests + $key_requests,
            ),
            'limitations' => array(
                'one_random_value_per_parameter_and_page_in_the_cookie_pass',
                'cache_key_pass_uses_one_page',
                'browser_side_scripts_and_edge_rules_not_executed',
            ),
        );
    }
}
