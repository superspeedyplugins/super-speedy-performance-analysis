<?php
defined('ABSPATH') || exit;

/**
 * Sends the loopback requests and assembles per-page results from the captures the
 * profiler writes. Sampling discipline: 1 warm-up + 3 measured samples, medians reported,
 * spread kept. Cached responses are detected via the token canary and discarded.
 */
class SSPA_Crawler {

    const WARMUPS = 1;
    const SAMPLES = 3;

    /**
     * Seconds each measurement request may wait for the page, from Settings.
     *
     * The old hardcoded 60 abandoned every sample on a site whose pages take minutes -
     * the exact site whose owner most needs the measurement, and who has already raised
     * their server timeouts to load the page in a browser.
     */
    public static function loopback_timeout() {
        return self::sanitise_loopback_timeout(sspa_get_option('loopback_timeout'));
    }

    /**
     * 10-900 seconds; anything non-numeric or absurd falls back to the default 60.
     * Shared by the crawler and the Settings save handler so the stored value and the
     * used value can never disagree.
     */
    public static function sanitise_loopback_timeout($value) {
        $value = (int) $value;
        if ($value <= 0) {
            return 60;
        }
        return max(10, min(900, $value));
    }

    /**
     * @return array Profile data ready for SSPA_Profile_Store::save().
     */
    public function profile_job($job, $user_id) {
        $cookies = SSPA_Auth::cookies_for($job['variant'], $user_id);
        $is_baseline = ('baseline' === $job['page_key']);

        // Sweep "prime" cells skip the warm-up on purpose: they measure the FIRST
        // cache-enabled request. Sample count is per-job overridable for the same reason.
        $warmups = !empty($job['skip_warmup']) ? 0 : self::WARMUPS;
        $samples_wanted = isset($job['samples']) ? max(1, (int) $job['samples']) : self::SAMPLES;

        for ($i = 0; $i < $warmups && !$is_baseline; $i++) {
            if (!empty($job['ps']) || !empty($job['oc_off'])) {
                // The warm-up must run the SAME configuration as the samples (reduced
                // plugin set and/or cache-bypassed) - only token requests see either
                // override, so it is signed; its capture and single-use marker are
                // discarded and it is never measured.
                $wflags = array('v' => $job['variant']);
                if (!empty($job['ps'])) {
                    $wflags['ps'] = $job['ps'];
                }
                if (!empty($job['oc_off'])) {
                    $wflags['oc'] = '0';
                }
                $wurl = $this->bust_url($job['url']);
                $token = SSPA_Token::mint($wurl, $wflags);
                $this->send($wurl, $cookies, $token['header']);
                $this->discard_capture($token['id']);
            } else {
                $this->send($job['url'], $cookies, null); // unsigned: warms caches, not profiled
            }
        }

        $samples = array();
        $blocked_by = null;
        $attempts = $samples_wanted + 2; // allow retries for cache-served responses
        for ($i = 0; count($samples) < $samples_wanted && $i < $attempts; $i++) {
            $flags = array('v' => $job['variant']);
            if ($is_baseline) {
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
            $sample = $this->profiled_request($job['url'], $cookies, $flags);
            if ($sample['cached']) {
                continue; // page cache served it - tells us nothing about PHP
            }
            $samples[] = $sample;
            if ($sample['blocked_by']) {
                $blocked_by = $sample['blocked_by'];
                break;
            }
        }

        return array(
            'page_key' => $job['page_key'],
            'url' => $job['url'],
            'variant' => $job['variant'],
            'samples' => $samples,
            'blocked_by' => $blocked_by,
            'plugin_set_hash' => !empty($job['ps']) ? $job['ps'] : '',
            'object_cache_mode' => !empty($job['oc_label'])
                ? $job['oc_label']
                : (!empty($job['oc_off']) ? 'disabled' : 'normal'),
        );
    }

    private function profiled_request($url, $cookies, $flags) {
        // Follow up to 2 non-login redirects, re-minting the token per hop (the signature
        // binds to the exact path). The redirecting response itself runs WordPress and
        // writes a capture, so superseded tokens are cleaned up below.
        $chain = array();
        $current_url = $url;
        for ($hop = 0; $hop <= 2; $hop++) {
            // Unique query arg per request: page caches and CDNs key on the URL, so a
            // fresh arg guarantees a cache MISS and the request actually reaches PHP.
            // The token signature binds to path+query, so the arg cannot be stripped.
            $hop_url = $this->bust_url($current_url);
            $token = SSPA_Token::mint($hop_url, $flags);
            $chain[] = $token['id'];
            $start = microtime(true);
            $response = $this->send($hop_url, $cookies, $token['header']);
            $wall_ms = (microtime(true) - $start) * 1000;

            if (is_wp_error($response) || $hop === 2) {
                break;
            }
            $redirect_code = (int) wp_remote_retrieve_response_code($response);
            if (!in_array($redirect_code, array(301, 302, 307, 308), true)) {
                break;
            }
            $location = wp_remote_retrieve_header($response, 'location');
            if (!$location || strpos($location, 'wp-login.php') !== false) {
                break; // login bounce: hand to the blocked classifier below
            }
            $current_url = (strpos($location, 'http') === 0) ? $location : home_url($location);
        }

        // Remove captures/markers written by redirecting hops - only the final hop counts.
        array_pop($chain);
        foreach ($chain as $stale_id) {
            $this->discard_capture($stale_id);
        }

        $sample = array(
            'wall_ms' => round($wall_ms, 1),
            'code' => 0,
            'cached' => false,
            'blocked_by' => null,
            'error' => null,
            'capture' => null,
        );

        if (is_wp_error($response)) {
            $sample['error'] = $response->get_error_code();
            return $sample;
        }

        $code = wp_remote_retrieve_response_code($response);
        $headers = $this->lower_headers($response);
        $sample['code'] = (int) $code;

        $sample['blocked_by'] = SSPA_Security_Detect::classify(
            (int) $code,
            $headers,
            substr((string) wp_remote_retrieve_body($response), 0, 20000),
            !empty($cookies)
        );
        if ($sample['blocked_by']) {
            return $sample;
        }

        // Canary: the mu-loader echoes our token id in a header. A missing or mismatched
        // canary on a 200 means a cache answered (or the mu-loader is not installed).
        $canary = isset($headers['x-sspa-profiled']) ? $headers['x-sspa-profiled'] : null;
        if ($canary !== $token['id']) {
            if ($this->looks_cached($headers)) {
                $sample['cached'] = true;
                return $sample;
            }
            $sample['error'] = 'no_canary';
            return $sample;
        }

        // Isolation runs must prove the override applied - a measurement of the wrong
        // plugin set is worse than no measurement.
        if (!empty($flags['ps'])) {
            $ps_header = isset($headers['x-sspa-ps']) ? $headers['x-sspa-ps'] : null;
            if ($ps_header !== $flags['ps']) {
                $sample['error'] = 'ps_not_applied';
                return $sample;
            }
        }

        $capture = $this->fetch_capture($token['id']);
        if ($capture) {
            $sample['capture'] = $capture;
        } elseif (empty($flags['bl'])) {
            $sample['error'] = 'capture_missing';
        }
        return $sample;
    }

    /**
     * Send ONE profiled request of any method and return the sample plus the parsed body.
     *
     * Deliberately not built on profiled_request(): that follows redirects, and a followed
     * 3xx becomes a GET which would silently measure the wrong thing. Here a 3xx is a
     * failure the caller must handle (see the rest_route fallback in SSPA_Checkout_Flow).
     * No warm-up and no repeat samples either - the checkout flow buys something, and every
     * repeat is another real order.
     *
     * @param string $url  Absolute URL on this site.
     * @param array  $args {
     *     @type string       $method  GET|POST. Default GET.
     *     @type array|string $body    Array for form encoding, array for JSON when json=true.
     *     @type bool         $json    Send the body as application/json. Default false.
     *     @type array        $headers Extra request headers, e.g. ['Cart-Token' => '...'].
     *     @type array        $cookies Cookie jar to send (name => value or WP_Http_Cookie[]).
     *     @type array        $flags   Token flags, e.g. ['v' => 'guest', 'ck' => 'flow'].
     * }
     * @return array The sample shape SSPA_Profile_Store::save() expects (wall_ms, code,
     *               cached, blocked_by, error, capture) plus body, json and cookies.
     */
    public function send_profiled($url, $args = array()) {
        $method = isset($args['method']) ? strtoupper($args['method']) : 'GET';
        $flags = isset($args['flags']) ? $args['flags'] : array();
        // Guarantees a cache MISS; the token signature binds path+query so it cannot be
        // stripped in transit.
        $hop_url = $this->bust_url($url);
        $token = SSPA_Token::mint($hop_url, $flags);

        $headers = array('Cache-Control' => 'no-cache', SSPA_Token::HEADER => $token['header']);
        if (!empty($args['headers'])) {
            $headers = array_merge($headers, $args['headers']);
        }
        $body = isset($args['body']) ? $args['body'] : null;
        if (!empty($args['json'])) {
            $headers['Content-Type'] = 'application/json';
            $body = wp_json_encode($body);
        }

        $start = microtime(true);
        $response = wp_remote_request($hop_url, array(
            'method' => $method,
            'timeout' => self::loopback_timeout(),
            'redirection' => 0,
            'sslverify' => false,
            'headers' => $headers,
            'body' => $body,
            'cookies' => isset($args['cookies']) ? $args['cookies'] : array(),
        ));
        $wall_ms = (microtime(true) - $start) * 1000;

        $sample = array(
            'wall_ms' => round($wall_ms, 1),
            'code' => 0,
            'cached' => false,
            'blocked_by' => null,
            'error' => null,
            'capture' => null,
            'body' => '',
            'json' => null,
            'cookies' => array(),
        );

        if (is_wp_error($response)) {
            $sample['error'] = $response->get_error_code();
            $this->discard_capture($token['id']);
            return $sample;
        }

        $sample['code'] = (int) wp_remote_retrieve_response_code($response);
        $sample['body'] = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($sample['body'], true);
        $sample['json'] = is_array($decoded) ? $decoded : null;
        $sample['cookies'] = wp_remote_retrieve_cookies($response);
        $headers_lc = $this->lower_headers($response);

        $sample['blocked_by'] = SSPA_Security_Detect::classify(
            $sample['code'],
            $headers_lc,
            substr($sample['body'], 0, 20000),
            !empty($args['cookies'])
        );
        if ($sample['blocked_by']) {
            $this->discard_capture($token['id']);
            return $sample;
        }

        // Canary: the mu-loader echoes our token id. Missing means a cache answered, the
        // mu-loader is not installed, or (on a POST) a redirect swallowed the request.
        $canary = isset($headers_lc['x-sspa-profiled']) ? $headers_lc['x-sspa-profiled'] : null;
        if ($canary !== $token['id']) {
            $sample['error'] = 'no_canary';
            $this->discard_capture($token['id']);
            return $sample;
        }

        $sample['capture'] = $this->fetch_capture($token['id']);
        if (!$sample['capture']) {
            $sample['error'] = 'capture_missing';
        }
        return $sample;
    }

    private function send($url, $cookies, $token_header) {
        $headers = array('Cache-Control' => 'no-cache');
        if ($token_header) {
            $headers[SSPA_Token::HEADER] = $token_header;
        }
        $args = array(
            'timeout' => self::loopback_timeout(),
            'redirection' => 0,
            'sslverify' => false,
            'headers' => $headers,
        );
        if ($cookies) {
            $args['cookies'] = $cookies;
        }
        return wp_remote_get($url, $args);
    }

    private function discard_capture($token_id) {
        global $wpdb;
        $table = SSPA_Schema::table('captures');
        $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE token_id = %s", $token_id));
        delete_option('sspa_used_' . $token_id);
    }

    private function lower_headers($response) {
        $raw = wp_remote_retrieve_headers($response);
        $all = is_object($raw) && method_exists($raw, 'getAll') ? $raw->getAll() : (array) $raw;
        $headers = array();
        foreach ($all as $k => $v) {
            $headers[strtolower($k)] = $v;
        }
        return $headers;
    }

    /**
     * A fresh sspa_nc value per request; replaces any value carried over from a
     * previous hop's redirect Location. String surgery rather than add_query_arg(),
     * which decodes and does NOT re-encode the existing query string (a %20 in the
     * catalogue's search URL would come back as a literal space).
     */
    private function bust_url($url) {
        $url = rtrim(preg_replace('/([?&])sspa_nc=[a-f0-9]*(&|$)/', '$1', $url), '?&');
        return $url . ((strpos($url, '?') === false) ? '?' : '&') . 'sspa_nc=' . bin2hex(random_bytes(6));
    }

    private function looks_cached($headers) {
        foreach (array('x-cache', 'cf-cache-status', 'x-litespeed-cache', 'x-cache-status', 'x-proxy-cache', 'x-srcache-fetch-status', 'x-fastcgi-cache', 'x-vercel-cache', 'x-qc-cache') as $h) {
            if (isset($headers[$h]) && stripos((string) (is_array($headers[$h]) ? end($headers[$h]) : $headers[$h]), 'hit') !== false) {
                return true;
            }
        }
        // Age > 0 is a shared-cache signal (Varnish, CDNs) even when no x-cache header names it.
        if (isset($headers['age']) && (int) (is_array($headers['age']) ? end($headers['age']) : $headers['age']) > 0) {
            return true;
        }
        return false;
    }

    private function fetch_capture($token_id) {
        global $wpdb;
        $table = SSPA_Schema::table('captures');
        $blob = $wpdb->get_var($wpdb->prepare("SELECT capture FROM $table WHERE token_id = %s", $token_id));
        if (!$blob) {
            return null;
        }
        $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE token_id = %s", $token_id));
        delete_option('sspa_used_' . $token_id);
        $json = @gzuncompress($blob);
        if ($json === false) {
            return null;
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }
}
