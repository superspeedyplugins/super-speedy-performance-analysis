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
     * Send a measurement request without imposing a client-side response deadline.
     *
     * WordPress defaults omitted timeouts to five seconds, and Requests clamps zero to
     * one second in its cURL transport. cURL therefore needs an explicit zero applied
     * after Requests configures the handle. The very large argument is the equivalent
     * fallback for the streams transport, whose zero means "return immediately" rather
     * than "wait indefinitely". Server, PHP, proxy and operating-system limits remain.
     *
     * @param string $url  Measurement URL.
     * @param array  $args WordPress HTTP request arguments.
     * @return array|WP_Error
     */
    public static function request($url, $args = array()) {
        $args['timeout'] = 2147483647;
        $disable_curl_timeout = static function ($handle, $request, $request_url) use ($url) {
            if ($request_url === $url && defined('CURLOPT_TIMEOUT')) {
                curl_setopt($handle, CURLOPT_TIMEOUT, 0);
            }
        };

        add_action('http_api_curl', $disable_curl_timeout, PHP_INT_MAX, 3);
        try {
            return wp_remote_request($url, $args);
        } finally {
            remove_action('http_api_curl', $disable_curl_timeout, PHP_INT_MAX);
        }
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
                $wurl = self::bust_url($job['url']);
                $token = SSPA_Token::mint($wurl, $wflags);
                $this->send($wurl, $cookies, $token['header']);
                self::discard_capture($token['id']);
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
            // Scan one successful response per shared-cache candidate. This happens in the
            // controller after the timed request, so it cannot inflate the page measurement.
            if (!$samples && SSPA_Cache_Recon::eligible_job($job)) {
                $flags['cr'] = '1';
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

    /**
     * Evaluate ONE profiled response into a sample, whichever transport made
     * the request. The loopback path builds $norm from wp_remote_*; the
     * browser transport (SSPA_Browser_Transport) builds it from what the
     * admin's fetch() observed. One classifier - both transports fail in the
     * same words.
     *
     * @param array  $norm {
     *     @type float       $wall_ms Wall time around the request.
     *     @type int         $code    HTTP status (0 on transport error).
     *     @type array       $headers Response headers, LOWERCASE keys.
     *     @type string      $body    Body, first 20KB is enough.
     *     @type string|null $error   Transport error code, or null.
     *     @type string|null $error_message Transport error explanation, or null.
     *     @type bool        $cookies_present Whether auth cookies rode along.
     * }
     * @param string $token_id The minted token id this request carried.
     * @param array  $flags    The token flags (ps echo is verified when set).
     * @return array Sample: {wall_ms, code, cached, blocked_by, error, error_message, capture}
     */
    public static function evaluate_sample($norm, $token_id, $flags) {
        $sample = array(
            'wall_ms' => round((float) $norm['wall_ms'], 1),
            'code' => 0,
            'cached' => false,
            'blocked_by' => null,
            'error' => null,
            'error_message' => null,
            'capture' => null,
        );

        if (!empty($norm['error'])) {
            $sample['error'] = $norm['error'];
            $sample['error_message'] = !empty($norm['error_message'])
                ? sanitize_text_field($norm['error_message'])
                : sanitize_text_field($norm['error']);
            return $sample;
        }

        $headers = $norm['headers'];
        $sample['code'] = (int) $norm['code'];

        $sample['blocked_by'] = SSPA_Security_Detect::classify(
            $sample['code'],
            $headers,
            substr((string) $norm['body'], 0, 20000),
            !empty($norm['cookies_present'])
        );
        if ($sample['blocked_by']) {
            return $sample;
        }

        // Canary: the mu-loader echoes our token id in a header. A missing or mismatched
        // canary on a 200 means a cache answered (or the mu-loader is not installed).
        $canary = isset($headers['x-sspa-profiled']) ? $headers['x-sspa-profiled'] : null;
        if ($canary !== $token_id) {
            if (self::looks_cached($headers)) {
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

        // Output identity: a hash of the response with per-request noise stripped, so the
        // sweep can say "excluding this plugin changed no bytes" - which timing deltas alone
        // cannot. Only hashed once the sample is known to be a genuine uncached profiled
        // response; comparing a cached body against a live one would be meaningless.
        // Headers are folded in because a plugin can change ONLY headers (security
        // headers, CSP, redirects-adjacent behaviour) while the body stays identical.
        $sample['body_hash'] = self::body_hash((string) $norm['body'], $headers);

        $capture = self::fetch_capture($token_id);
        if ($capture) {
            if (!empty($flags['cr'])) {
                $capture['cache_recon'] = SSPA_Cache_Recon::scan_response(
                    (string) $norm['body'],
                    $headers,
                    $capture,
                    !empty($flags['bt'])
                );
            }
            $sample['capture'] = $capture;
        } elseif (empty($flags['bl'])) {
            $sample['error'] = 'capture_missing';
        }
        return $sample;
    }

    /**
     * Hash a response body with legitimately-per-request noise removed, so two requests
     * for the SAME page content hash equal. Strips: nonces (all common shapes), session/
     * CSRF hidden fields, our own token echoes, and timestamps embedded by analytics
     * snippets. Deliberately conservative - anything NOT stripped that varies makes two
     * bodies hash differently, which reads as "output changed" and fails SAFE (the sweep
     * records output_identical=0, never a false "identical").
     *
     * $headers, when given, are folded into the hash (minus per-request volatile ones)
     * so header-only differences - a security plugin's CSP, an X-Redirect-By - also
     * count as "output changed". A plugin whose entire job is response headers must
     * never read as byte-identical.
     */
    public static function body_hash($body, $headers = null) {
        if ('' === $body) {
            return null;
        }
        $header_lines = '';
        if (is_array($headers) || $headers instanceof Traversable) {
            $volatile = array(
                'date', 'expires', 'age', 'set-cookie', 'etag', 'last-modified',
                'content-length', 'connection', 'keep-alive', 'transfer-encoding',
                'server-timing', 'x-request-id', 'cf-ray', 'x-runtime',
                'x-sspa-profiled', 'x-sspa-ps', 'x-spro-unloaded',
            );
            $kept = array();
            foreach ($headers as $name => $value) {
                $name = strtolower((string) $name);
                if (in_array($name, $volatile, true)) {
                    continue;
                }
                $kept[] = $name . ': ' . (is_array($value) ? implode(', ', $value) : (string) $value);
            }
            sort($kept);
            $header_lines = implode("\n", $kept) . "\n\n";
        }
        $normalised = preg_replace(
            array(
                // _wpnonce=abc123 in URLs and wp.apiFetch nonce embeds.
                '/([?&;]_wpnonce=)[a-f0-9]{6,12}/i',
                // Hidden nonce inputs: value="abc123" adjacent to a *nonce* name (both orders).
                '/(name=["\'][^"\']*nonce[^"\']*["\'][^>]{0,80}value=["\'])[a-f0-9]{6,12}(["\'])/i',
                '/(value=["\'])[a-f0-9]{6,12}(["\'][^>]{0,80}name=["\'][^"\']*nonce[^"\']*["\'])/i',
                // JSON-embedded nonces: "nonce":"abc123", 'rest_nonce':'...' etc.
                '/(["\'][a-z_]*nonce["\']\s*[:=]\s*["\'])[a-f0-9]{6,12}(["\'])/i',
                // Millisecond/second timestamps in query strings (cache busters, analytics).
                '/([?&;](?:t|ts|time|_)=)\d{10,13}/',
            ),
            array('${1}0', '${1}0${2}', '${1}0${2}', '${1}0${2}', '${1}0'),
            $body
        );
        if (null === $normalised) {
            $normalised = $body; // a PCRE failure must not lose the sample
        }
        return md5($header_lines . $normalised);
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
            $hop_url = self::bust_url($current_url);
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
            self::discard_capture($stale_id);
        }

        return self::evaluate_sample(array(
            'wall_ms' => $wall_ms,
            'code' => is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response),
            'headers' => is_wp_error($response) ? array() : $this->lower_headers($response),
            'body' => is_wp_error($response) ? '' : (string) wp_remote_retrieve_body($response),
            'error' => is_wp_error($response) ? $response->get_error_code() : null,
            'error_message' => is_wp_error($response) ? $response->get_error_message() : null,
            'cookies_present' => !empty($cookies),
        ), $token['id'], $flags);
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
     *               cached, blocked_by, error, error_message, capture) plus body, json
     *               and cookies.
     */
    public function send_profiled($url, $args = array()) {
        $method = isset($args['method']) ? strtoupper($args['method']) : 'GET';
        $flags = isset($args['flags']) ? $args['flags'] : array();
        // Guarantees a cache MISS; the token signature binds path+query so it cannot be
        // stripped in transit.
        $hop_url = self::bust_url($url);
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
        $response = self::request($hop_url, array(
            'method' => $method,
            'redirection' => 0,
            'sslverify' => false,
            'headers' => $headers,
            'body' => $body,
            'cookies' => isset($args['cookies']) ? $args['cookies'] : array(),
        ));
        $wall_ms = (microtime(true) - $start) * 1000;

        $body_out = is_wp_error($response) ? '' : (string) wp_remote_retrieve_body($response);
        $sample = self::evaluate_sample(array(
            'wall_ms' => $wall_ms,
            'code' => is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response),
            'headers' => is_wp_error($response) ? array() : $this->lower_headers($response),
            'body' => $body_out,
            'error' => is_wp_error($response) ? $response->get_error_code() : null,
            'error_message' => is_wp_error($response) ? $response->get_error_message() : null,
            'cookies_present' => !empty($args['cookies']),
        ), $token['id'], $flags);

        // No cached-retry concept here (a repeat would be another real order): a
        // cache-shaped canary miss is a failure, exactly as it always was.
        if ($sample['cached']) {
            $sample['cached'] = false;
            $sample['error'] = 'no_canary';
        }

        $decoded = json_decode($body_out, true);
        $sample['body'] = $body_out;
        $sample['json'] = is_array($decoded) ? $decoded : null;
        $sample['cookies'] = is_wp_error($response) ? array() : wp_remote_retrieve_cookies($response);

        if (is_wp_error($response) || $sample['blocked_by'] || 'no_canary' === $sample['error']) {
            self::discard_capture($token['id']);
        }
        return $sample;
    }

    private function send($url, $cookies, $token_header) {
        $headers = array('Cache-Control' => 'no-cache');
        if ($token_header) {
            $headers[SSPA_Token::HEADER] = $token_header;
        }
        $args = array(
            'redirection' => 0,
            'sslverify' => false,
            'headers' => $headers,
        );
        if ($cookies) {
            $args['cookies'] = $cookies;
        }
        return self::request($url, $args);
    }

    public static function discard_capture($token_id) {
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
    public static function bust_url($url) {
        $url = rtrim(preg_replace('/([?&])sspa_nc=[a-f0-9]*(&|$)/', '$1', $url), '?&');
        return $url . ((strpos($url, '?') === false) ? '?' : '&') . 'sspa_nc=' . bin2hex(random_bytes(6));
    }

    private static function looks_cached($headers) {
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

    private static function fetch_capture($token_id) {
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
