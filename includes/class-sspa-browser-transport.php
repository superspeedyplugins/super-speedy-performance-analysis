<?php
defined('ABSPATH') || exit;

/**
 * Browser transport: the run-driving admin's browser fetches the pages instead of the
 * server looping back to itself.
 *
 * Design: .docs/2026-08-13-browser-driven-transport.md. Chosen per run by the preflight
 * in SSPA_Run_Controller::decide_transport() - this class only ever drives a queue whose
 * transport is 'browser', and process_batch() no-ops for those queues.
 *
 * Why: the browser's requests carry what a loopback cannot on a protected site - HTTP
 * basic auth, CDN clearance cookies, a real browser UA from the admin's IP. Nothing
 * measured depends on who fetches: the mu-loader arms off the signed X-SSPA-Token header
 * inside the target request and stores everything server-side. This class inverts
 * SSPA_Crawler::profile_job()'s internal loop into a request-granular state machine:
 * `next` hands the browser ONE prepared request, `record` evaluates ONE report through
 * the same SSPA_Crawler::evaluate_sample() the loopback path uses.
 *
 * Trust model: both endpoints are manage_options + nonce. The reporter is the admin
 * driving the run - a dishonest report is not a threat (they could write the profiles
 * table directly). Tokens are minted server-side as always: HMAC, URL-bound, 120s,
 * single use.
 */
class SSPA_Browser_Transport {

    /** Response headers the driver JS echoes back for evaluate_sample()/looks_cached(). */
    const ECHO_HEADERS = array(
        'x-sspa-profiled', 'x-sspa-ps', 'x-sspa-replay',
        'x-cache', 'cf-cache-status', 'x-litespeed-cache', 'x-cache-status',
        'x-proxy-cache', 'x-srcache-fetch-status', 'x-fastcgi-cache', 'x-vercel-cache',
        'x-qc-cache', 'age', 'cf-ray', 'x-sucuri-id', 'x-sucuri-block', 'server', 'location',
    );

    public static function init() {
        add_action('wp_ajax_sspa_browser_next', array(__CLASS__, 'ajax_next'));
        add_action('wp_ajax_sspa_browser_record', array(__CLASS__, 'ajax_record'));
        // Registered (not enqueued) everywhere a run can be driven from: the SSPA admin
        // screen, the adhoc popover (front end too), and Scalability Pro's popover, which
        // enqueues the handle when its bridge sees this version of SSPA.
        add_action('admin_enqueue_scripts', array(__CLASS__, 'register_script'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'register_script'));
    }

    public static function register_script() {
        wp_register_script(
            'sspa-transport',
            SSPA_PLUGIN_URL . 'includes/admin/js/sspa-transport.js',
            array(),
            sspa_asset_version('includes/admin/js/sspa-transport.js'),
            true
        );
    }

    private static function guard() {
        check_ajax_referer('sspa_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('forbidden', 403);
        }
    }

    /** Fresh per-job state. seq is GLOBAL to the run (never resets per job) so a
     *  duplicate record can always be told apart from a desync. */
    private static function fresh_bt($seq = 0, $last_seq = -1, $last_response = null) {
        return array(
            'seq' => (int) $seq,
            'last_seq' => (int) $last_seq,
            'last_response' => $last_response,
            'pending' => null,
            'warmups_done' => 0,
            'attempts' => 0,
            'samples' => array(),
            'current_url' => null,
            'hops' => 0,
            'temp_id' => 0,
            'temp_is_order' => false,
            'write_flags' => null,
        );
    }

    private static function load($run_id) {
        $run = SSPA_Run_Controller::run_row($run_id);
        if (!$run || 'crawling' !== $run['status']) {
            return new WP_Error('sspa_not_crawling', __('This analysis is not running.', 'super-speedy-performance-analysis'));
        }
        $queue = get_option('sspa_queue_' . $run_id);
        if (!is_array($queue) || empty($queue['jobs'])) {
            return new WP_Error('sspa_no_queue', __('The analysis queue is missing.', 'super-speedy-performance-analysis'));
        }
        if (empty($queue['transport']) || 'browser' !== $queue['transport']) {
            return new WP_Error('sspa_wrong_transport', __('This analysis is server-driven; there is nothing for the browser to fetch.', 'super-speedy-performance-analysis'));
        }
        if (!isset($queue['bt']) || !is_array($queue['bt'])) {
            $queue['bt'] = self::fresh_bt();
        }
        return array($run, $queue);
    }

    private static function save($run_id, $queue) {
        $queue['last_progress'] = time();
        update_option('sspa_queue_' . $run_id, $queue, false);
    }

    /**
     * The token flags for the current job, mirroring profile_job() exactly.
     */
    private static function job_flags($job, $bt, $purpose) {
        $flags = array('v' => $job['variant']);
        $flags['bt'] = '1'; // response body is a 20KB prefix and Set-Cookie is browser-hidden
        if ('baseline' === $job['page_key']) {
            $flags['bl'] = '1';
        }
        if (!empty($job['ps'])) {
            $flags['ps'] = $job['ps'];
        }
        if (!empty($job['oc_off'])) {
            $flags['oc'] = '0';
        }
        if (!empty($job['flags']) && is_array($job['flags'])) {
            $flags = array_merge($flags, $job['flags']);
        }
        if (!empty($bt['write_flags']) && is_array($bt['write_flags'])) {
            $flags = array_merge($flags, $bt['write_flags']);
        }
        if ('sample' === $purpose && empty($bt['samples']) && SSPA_Cache_Recon::eligible_job($job)) {
            $flags['cr'] = '1';
        }
        return $flags;
    }

    private static function warmups_for($job) {
        if ('baseline' === $job['page_key'] || !empty($job['skip_warmup'])) {
            return 0;
        }
        return SSPA_Crawler::WARMUPS;
    }

    private static function samples_wanted($job) {
        return isset($job['samples']) ? max(1, (int) $job['samples']) : SSPA_Crawler::SAMPLES;
    }

    /**
     * Hand the browser the next request. Never advances state - a lost response simply
     * re-issues the same logical request with a fresh token (they live 120s and are
     * single use, so re-minting per issue is mandatory anyway); the superseded token's
     * capture is discarded so an orphaned first fetch cannot linger as evidence.
     */
    public static function ajax_next() {
        self::guard();
        $run_id = isset($_POST['run_id']) ? (int) $_POST['run_id'] : 0;
        $loaded = self::load($run_id);
        if (is_wp_error($loaded)) {
            wp_send_json_error(array('message' => $loaded->get_error_message(), 'code' => $loaded->get_error_code()));
        }
        list($run, $queue) = $loaded;

        // One driver, site-wide. The page-level singleton cannot see across tabs, and
        // two tabs both driving (the SSPA screen auto-resumes active runs) re-mint each
        // other's pending tokens: the slower tab's report then evaluates against the
        // newer token and records a bogus no_canary sample. First claimant owns the
        // run; others hold off and take over only when the owner has gone quiet.
        $driver = isset($_POST['driver']) ? sanitize_text_field(wp_unslash($_POST['driver'])) : '';
        if ($driver && !empty($queue['bt_driver']) && $queue['bt_driver'] !== $driver
            && (time() - (int) $queue['bt_driver_seen']) < 30) {
            wp_send_json_success(array('status' => 'busy', 'retry_in' => 15));
        }
        $queue['bt_driver'] = $driver;
        $queue['bt_driver_seen'] = time();

        // Skip jobs the browser cannot or need not run. Checkout jobs cannot exist in a
        // browser-transport queue (the transport gate refuses that run type) - this is a
        // belt-and-braces skip, not a path.
        while ($queue['idx'] < count($queue['jobs'])) {
            $job = $queue['jobs'][$queue['idx']];
            if (!empty($job['checkout'])) {
                $queue['idx']++;
                $queue['bt'] = self::fresh_bt();
                continue;
            }
            // Write probes profile a request that saves a temp duplicate; create it on
            // the job's first issue, exactly as process_batch() does.
            if (!empty($job['write']) && empty($queue['bt']['temp_id'])) {
                $temp_is_order = ('order_processing' === $job['write']);
                $temp_id = $temp_is_order
                    ? SSPA_Probes::create_temp_order()
                    : SSPA_Probes::create_temp_copy($job['post_type']);
                if (!$temp_id) {
                    $queue['idx']++; // nothing to duplicate on this site - skip quietly
                    $queue['bt'] = self::fresh_bt();
                    continue;
                }
                $queue['bt']['temp_id'] = (int) $temp_id;
                $queue['bt']['temp_is_order'] = $temp_is_order;
                $queue['bt']['write_flags'] = array('wp' => $job['write'], 'tid' => (string) $temp_id, 'mail' => 'c');
            }
            break;
        }

        if ($queue['idx'] >= count($queue['jobs'])) {
            self::save($run_id, $queue);
            // Two tabs can both arrive at exhaustion; only complete once.
            $fresh = SSPA_Run_Controller::run_row($run_id);
            if ($fresh && 'crawling' === $fresh['status']) {
                SSPA_Run_Controller::complete_run($run_id, $run['run_type']);
            }
            wp_send_json_success(array('status' => 'done'));
        }

        $job = $queue['jobs'][$queue['idx']];
        $bt = $queue['bt'];
        $purpose = ($bt['warmups_done'] < self::warmups_for($job)) ? 'warmup' : 'sample';
        $flags = self::job_flags($job, $bt, $purpose);

        // Re-issue of a request whose record never arrived: the previous token may have
        // been spent by a fetch whose report was lost - discard whatever it captured.
        if (!empty($bt['pending']['token_id'])) {
            SSPA_Crawler::discard_capture($bt['pending']['token_id']);
        }

        $url = SSPA_Crawler::bust_url($bt['current_url'] ? $bt['current_url'] : $job['url']);
        $token = SSPA_Token::mint($url, $flags);
        $bt['pending'] = array(
            'seq' => (int) $bt['seq'],
            'token_id' => $token['id'],
            'url' => $url,
            'flags' => $flags,
            'purpose' => $purpose,
        );
        $queue['bt'] = $bt;
        self::save($run_id, $queue);

        wp_send_json_success(array(
            'status' => 'request',
            'seq' => (int) $bt['seq'],
            'url' => $url,
            'header_name' => SSPA_Token::HEADER,
            'header_value' => $token['header'],
            'purpose' => $purpose,
            'echo_headers' => self::ECHO_HEADERS,
            'label' => SSPA_Run_Controller::job_label($job),
            'done' => (int) $queue['idx'],
            'total' => count($queue['jobs']),
        ));
    }

    /**
     * Evaluate what the browser observed and advance the state machine. Idempotent on
     * `seq`: a retry of a record whose response was lost is acknowledged without being
     * processed twice, so job N's outcome can never stamp itself onto job N+1.
     */
    public static function ajax_record() {
        self::guard();
        $run_id = isset($_POST['run_id']) ? (int) $_POST['run_id'] : 0;
        $loaded = self::load($run_id);
        if (is_wp_error($loaded)) {
            wp_send_json_error(array('message' => $loaded->get_error_message(), 'code' => $loaded->get_error_code()));
        }
        list($run, $queue) = $loaded;
        $bt = $queue['bt'];

        // A report from a driver that no longer owns the run (another tab took over) is
        // acknowledged without being processed - its token was superseded, so evaluating
        // it would only record a bogus sample.
        $driver = isset($_POST['driver']) ? sanitize_text_field(wp_unslash($_POST['driver'])) : '';
        if ($driver && !empty($queue['bt_driver']) && $queue['bt_driver'] !== $driver) {
            wp_send_json_success(!empty($bt['last_response']) ? $bt['last_response'] : array('status' => 'progress', 'done' => (int) $queue['idx'], 'total' => count($queue['jobs']), 'label' => '', 'outcome' => null));
        }
        $queue['bt_driver_seen'] = time();

        $seq = isset($_POST['seq']) ? (int) $_POST['seq'] : -1;
        if ($seq === (int) $bt['last_seq'] && !empty($bt['last_response'])) {
            wp_send_json_success($bt['last_response']); // duplicate of an acknowledged record
        }
        if (empty($bt['pending']) || $seq !== (int) $bt['pending']['seq']) {
            wp_send_json_error(array(
                'message' => __('The analysis has moved on - another tab may be driving it.', 'super-speedy-performance-analysis'),
                'code' => 'sspa_desync',
            ));
        }

        $pending = $bt['pending'];
        $job = $queue['jobs'][$queue['idx']];

        // Normalise the report.
        $code = isset($_POST['code']) ? (int) $_POST['code'] : 0;
        $error = isset($_POST['error']) ? sanitize_text_field(wp_unslash($_POST['error'])) : '';
        $redirected = !empty($_POST['redirected']);
        $final_url = isset($_POST['final_url']) ? esc_url_raw(wp_unslash($_POST['final_url'])) : '';
        $duration = isset($_POST['duration_ms']) ? (float) $_POST['duration_ms'] : 0;
        $body = isset($_POST['body_snippet']) ? substr((string) wp_unslash($_POST['body_snippet']), 0, 20000) : '';
        $headers = array();
        if (isset($_POST['headers'])) {
            $decoded = json_decode((string) wp_unslash($_POST['headers']), true);
            if (is_array($decoded)) {
                foreach ($decoded as $k => $v) {
                    $headers[strtolower(sanitize_text_field($k))] = sanitize_text_field((string) $v);
                }
            }
        }
        // The admin/customer variants ride on real cookies in this transport.
        $cookies_present = in_array($job['variant'], array('admin', 'customer'), true);

        // fetch() follows redirects and exposes only the final response. The token was
        // bound to the first URL, so a redirected request proved nothing - mirror the
        // loopback path: discard the hop's capture and re-issue at the target, max 2
        // hops, same origin only; a login bounce goes to the blocked classifier.
        if ('' === $error && $redirected) {
            SSPA_Crawler::discard_capture($pending['token_id']);
            $is_login = $final_url && strpos($final_url, 'wp-login.php') !== false;
            $same_host = $final_url && strtolower((string) wp_parse_url($final_url, PHP_URL_HOST)) === strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
            if (!$is_login && $same_host && $bt['hops'] < 2) {
                $bt['hops']++;
                $bt['current_url'] = $final_url;
                $bt['seq']++;
                $bt['last_seq'] = $seq;
                $bt['pending'] = null;
                $bt['last_response'] = array(
                    'status' => 'progress',
                    'done' => (int) $queue['idx'],
                    'total' => count($queue['jobs']),
                    'label' => SSPA_Run_Controller::job_label($job),
                    'outcome' => array('code' => $code, 'note' => 'redirect-followed', 'final_url' => $final_url),
                );
                $queue['bt'] = $bt;
                self::save($run_id, $queue);
                wp_send_json_success($bt['last_response']);
            }
            // Hop limit, cross-origin target, or a login bounce: synthesise the redirect
            // response the loopback classifier would have seen.
            $code = 302;
            $headers['location'] = (string) $final_url;
        }

        if ('warmup' === $pending['purpose']) {
            // Warm-ups are never measured; their capture and single-use marker go.
            SSPA_Crawler::discard_capture($pending['token_id']);
            $bt['warmups_done']++;
            $bt['seq']++;
            $bt['last_seq'] = $seq;
            $bt['pending'] = null;
            $bt['last_response'] = array(
                'status' => 'progress',
                'done' => (int) $queue['idx'],
                'total' => count($queue['jobs']),
                'label' => SSPA_Run_Controller::job_label($job),
                'outcome' => array('code' => $code, 'note' => 'warmup'),
            );
            $queue['bt'] = $bt;
            self::save($run_id, $queue);
            wp_send_json_success($bt['last_response']);
        }

        $sample = SSPA_Crawler::evaluate_sample(array(
            'wall_ms' => $duration,
            'code' => $code,
            'headers' => $headers,
            'body' => $body,
            'error' => ('' !== $error) ? 'browser_fetch_failed' : null,
            'error_message' => ('' !== $error) ? $error : null,
            'cookies_present' => $cookies_present,
        ), $pending['token_id'], $pending['flags']);

        $bt['attempts']++;
        if (!$sample['cached']) {
            $bt['samples'][] = $sample;
        }

        $wanted = self::samples_wanted($job);
        $job_finished = $sample['blocked_by']
            || count($bt['samples']) >= $wanted
            || $bt['attempts'] >= $wanted + 2;

        if ($job_finished) {
            SSPA_Profile_Store::save($run_id, array(
                'page_key' => $job['page_key'],
                'url' => $job['url'],
                'variant' => $job['variant'],
                'samples' => $bt['samples'],
                'blocked_by' => $sample['blocked_by'],
                'plugin_set_hash' => !empty($job['ps']) ? $job['ps'] : '',
                'object_cache_mode' => !empty($job['oc_label'])
                    ? $job['oc_label']
                    : (!empty($job['oc_off']) ? 'disabled' : 'normal'),
            ));
            if (!empty($bt['temp_id'])) {
                SSPA_Probes::delete_temp((int) $bt['temp_id'], !empty($bt['temp_is_order']));
            }
            $queue['idx']++;
            $last = array(
                'status' => 'progress',
                'done' => (int) $queue['idx'],
                'total' => count($queue['jobs']),
                'label' => SSPA_Run_Controller::job_label($job),
                'outcome' => array(
                    'code' => $sample['code'],
                    'cached' => $sample['cached'],
                    'blocked_by' => $sample['blocked_by'],
                    'error' => $sample['error'],
                    'job_finished' => true,
                ),
            );
            // seq is run-global: the fresh job continues the counter, and this record
            // stays acknowledged as last_seq so a lost-response retry gets its answer.
            $queue['bt'] = self::fresh_bt((int) $bt['seq'] + 1, $seq, $last);
            self::save($run_id, $queue);
            wp_send_json_success($last);
        }

        // More requests needed for this job (next sample, or a cached retry). A followed
        // redirect target only applies within one logical request - back to the job URL.
        $bt['current_url'] = null;
        $bt['hops'] = 0;
        $bt['seq']++;
        $bt['last_seq'] = $seq;
        $bt['pending'] = null;
        $bt['last_response'] = array(
            'status' => 'progress',
            'done' => (int) $queue['idx'],
            'total' => count($queue['jobs']),
            'label' => SSPA_Run_Controller::job_label($job),
            'outcome' => array(
                'code' => $sample['code'],
                'cached' => $sample['cached'],
                'blocked_by' => $sample['blocked_by'],
                'error' => $sample['error'],
            ),
        );
        $queue['bt'] = $bt;
        self::save($run_id, $queue);
        wp_send_json_success($bt['last_response']);
    }
}
