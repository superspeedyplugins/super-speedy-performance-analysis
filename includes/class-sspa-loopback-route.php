<?php
defined('ABSPATH') || exit;

/**
 * How Performance Analysis reaches its own site.
 *
 * Every profiling loopback goes through SSPA_Crawler::request(), which hands the request to
 * send() here. Two routes exist:
 *
 * - public: the URL as it is, through whatever DNS, CDN or firewall sits in front of the site.
 * - direct: the same URL, Host header, TLS SNI and certificate check, but the TCP connection
 *   goes to an origin address (cURL's CURLOPT_CONNECT_TO). The URL is never rewritten to an
 *   IP, so https keeps verifying against the real hostname.
 *
 * SSPA_Run_Controller::loopback_preflight() calls select(): public first, then each direct
 * candidate. The first healthy route is stored and used by every later loopback. Stored
 * basic-auth credentials are sent on own-origin requests only. Nothing is ever sent to an
 * origin address, or given credentials, for a URL on another host.
 */
class SSPA_Loopback_Route {

    const ROUTE_OPTION = 'sspa_loopback_route';
    const AUTH_OPTION = 'sspa_loopback_auth';

    /** Route forced while select() probes one candidate: array{route,address}|null. */
    private static $forced = null;

    /** CONNECT_TO entry for the request being sent, and whether cURL accepted it. */
    private static $connect_to = null;
    private static $applied = false;

    /* ---------------- settings ---------------- */

    /**
     * Validate an origin address: an IPv4 or bracketed IPv6 address, optionally with a port.
     *
     * @return string|WP_Error Normalised "address" or "address:port", '' for none.
     */
    public static function normalise_origin($value) {
        $value = trim((string) $value);
        if ('' === $value) {
            return '';
        }
        if (preg_match('/^\[([0-9a-f:.]+)\](?::(\d{1,5}))?$/i', $value, $m)) {
            $ip = filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
            $address = $ip ? '[' . strtolower($ip) . ']' : '';
            $port = isset($m[2]) ? (int) $m[2] : 0;
        } elseif (preg_match('/^([0-9.]+)(?::(\d{1,5}))?$/', $value, $m)) {
            $address = filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $m[1] : '';
            $port = isset($m[2]) ? (int) $m[2] : 0;
        } else {
            $address = '';
            $port = 0;
        }
        if ('' === $address || $port > 65535 || (isset($m[2]) && $port < 1)) {
            return new WP_Error('sspa_loopback_origin', __('The origin address must be an IP address such as 127.0.0.1, 10.0.0.5:8080 or [::1], with an optional port.', 'super-speedy-performance-analysis'));
        }
        return $address . ($port ? ':' . $port : '');
    }

    public static function configured_origin() {
        $origin = self::normalise_origin((string) sspa_get_option('loopback_origin'));
        return is_wp_error($origin) ? '' : $origin;
    }

    /** @return true|WP_Error */
    public static function set_origin($value) {
        $origin = self::normalise_origin($value);
        if (is_wp_error($origin)) {
            return $origin;
        }
        sspa_update_option('loopback_origin', $origin);
        delete_option(self::ROUTE_OPTION); // chosen again at the next run start
        return true;
    }

    /** @return array{user:string,password:string}|null */
    public static function credentials() {
        $stored = get_option(self::AUTH_OPTION, null);
        if (!is_array($stored) || !isset($stored['password']) || '' === (string) $stored['password']) {
            return null;
        }
        return array('user' => (string) ($stored['user'] ?? ''), 'password' => (string) $stored['password']);
    }

    /** @return true|WP_Error */
    public static function validate_credentials($user, $password) {
        $user = (string) $user;
        $password = (string) $password;
        if ('' === $password) {
            return new WP_Error('sspa_loopback_credentials', __('Loopback credentials need a password. Pass clear:true to remove stored credentials.', 'super-speedy-performance-analysis'));
        }
        if (false !== strpos($user, ':') || preg_match('/[\r\n]/', $user . $password)) {
            return new WP_Error('sspa_loopback_credentials', __('A basic-auth user name cannot contain a colon, and neither field can contain a line break.', 'super-speedy-performance-analysis'));
        }
        return true;
    }

    /** @return true|WP_Error */
    public static function set_credentials($user, $password) {
        $user = (string) $user;
        $password = (string) $password;
        $valid = self::validate_credentials($user, $password);
        if (is_wp_error($valid)) {
            return $valid;
        }
        delete_option(self::AUTH_OPTION);
        add_option(self::AUTH_OPTION, array('user' => $user, 'password' => $password), '', false);
        delete_option(self::ROUTE_OPTION);
        return true;
    }

    public static function clear_credentials() {
        delete_option(self::AUTH_OPTION);
        delete_option(self::ROUTE_OPTION);
    }

    /** What settings may report: whether credentials exist, never the user or password. */
    public static function credentials_state() {
        $stored = self::credentials();
        return array('set' => null !== $stored, 'user_set' => null !== $stored && '' !== $stored['user']);
    }

    /* ---------------- scope ---------------- */

    private static function origin_parts($url) {
        $parts = wp_parse_url((string) $url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        return array(
            'scheme' => $scheme,
            'host' => strtolower($parts['host']),
            'port' => isset($parts['port']) ? (int) $parts['port'] : ('https' === $scheme ? 443 : 80),
        );
    }

    /** Same scheme, host and port as home_url(). */
    public static function is_own_origin($url) {
        $a = self::origin_parts($url);
        $b = self::origin_parts(home_url('/'));
        return $a && $b && $a === $b;
    }

    /** Add the stored basic-auth credentials to an own-origin request's headers. */
    public static function with_credentials($url, $headers) {
        $headers = is_array($headers) ? $headers : array();
        $credentials = self::credentials();
        if (!$credentials || !self::is_own_origin($url)) {
            return $headers;
        }
        foreach (array_keys($headers) as $name) {
            if ('authorization' === strtolower((string) $name)) {
                return $headers; // the caller's own Authorization wins
            }
        }
        $headers['Authorization'] = 'Basic ' . base64_encode($credentials['user'] . ':' . $credentials['password']); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP basic authentication.
        return $headers;
    }

    /* ---------------- sending ---------------- */

    /** The route the next own-origin loopback uses: array{route,address}. */
    public static function current() {
        if (null !== self::$forced) {
            return self::$forced;
        }
        $stored = get_option(self::ROUTE_OPTION, array());
        if (is_array($stored) && 'direct' === ($stored['route'] ?? '') && !empty($stored['address'])) {
            return array('route' => 'direct', 'address' => (string) $stored['address']);
        }
        return array('route' => 'public', 'address' => '');
    }

    /**
     * Add Super Speedy Filters' own-server token to an own-origin loopback, so its public rate limiter
     * does not count profiling requests as a visitor's (Filters 6.67.4 and later exempt only requests
     * carrying it). Never sent to another host. With an older Filters, or none, nothing is added and
     * the loopbacks are counted like any visitor's.
     */
    public static function with_own_server_token($url, $headers) {
        $headers = is_array($headers) ? $headers : array();
        if (!self::is_own_origin($url)
            || !function_exists('sspssf_rate_limit_own_server_token')
            || !function_exists('sspssf_rate_limit_own_server_header')) {
            return $headers;
        }
        $token = (string) sspssf_rate_limit_own_server_token();
        if ('' !== $token) {
            $headers[sspssf_rate_limit_own_server_header()] = $token;
        }
        return $headers;
    }

    /** Send one loopback over the current route. Called only by SSPA_Crawler::request(). */
    public static function send($url, $args) {
        $args['headers'] = self::with_credentials($url, isset($args['headers']) ? $args['headers'] : array());
        $args['headers'] = self::with_own_server_token($url, $args['headers']);
        $route = self::current();
        if ('direct' !== $route['route'] || !self::is_own_origin($url)) {
            return wp_remote_request($url, $args);
        }

        $origin = self::origin_parts($url);
        list($address, $port) = self::split_address($route['address']);
        self::$connect_to = $origin['host'] . ':' . $origin['port'] . ':' . $address . ':' . ($port ? $port : $origin['port']);
        self::$applied = false;
        add_action('http_api_curl', array(__CLASS__, 'apply_connect_to'), 10, 1);
        try {
            $response = wp_remote_request($url, $args);
        } finally {
            remove_action('http_api_curl', array(__CLASS__, 'apply_connect_to'), 10);
            self::$connect_to = null;
        }
        if (!self::$applied && !is_wp_error($response)) {
            // The request went out without the direct route (no cURL, or a cURL without
            // CURLOPT_CONNECT_TO). Its answer came down the public route, so it is not used.
            return new WP_Error('sspa_direct_route_unavailable', __('the direct route is unavailable: this server\'s HTTP transport is not cURL with CURLOPT_CONNECT_TO support', 'super-speedy-performance-analysis'));
        }
        return $response;
    }

    /** @internal http_api_curl callback; sets CONNECT_TO on the request send() is making. */
    public static function apply_connect_to($handle) {
        if (null === self::$connect_to || !defined('CURLOPT_CONNECT_TO')) {
            return;
        }
        if (curl_setopt($handle, CURLOPT_CONNECT_TO, array(self::$connect_to))) { // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- the WordPress HTTP API exposes its own cURL handle for exactly this.
            self::$applied = true;
        }
    }

    /** @return array{0:string,1:int} address (IPv6 bracketed) and port (0 when none). */
    private static function split_address($address) {
        if (preg_match('/^(\[[^\]]+\])(?::(\d+))?$/', $address, $m) || preg_match('/^([^:]+)(?::(\d+))?$/', $address, $m)) {
            return array($m[1], isset($m[2]) ? (int) $m[2] : 0);
        }
        return array($address, 0);
    }

    /* ---------------- selection ---------------- */

    /** Direct-route addresses to try, in order, duplicates dropped. */
    public static function candidates() {
        $list = array();
        $configured = self::configured_origin();
        if ('' !== $configured) {
            $list[] = $configured;
        }
        $list[] = '127.0.0.1';
        $server = isset($_SERVER['SERVER_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_ADDR'])) : '';
        if ('' !== $server) {
            $server = self::normalise_origin(false !== strpos($server, ':') && '[' !== $server[0] ? '[' . $server . ']' : $server);
            if (!is_wp_error($server) && '' !== $server) {
                $list[] = $server;
            }
        }
        return array_values(array_unique($list));
    }

    /**
     * Choose the route: public first, then each direct candidate. $probe() runs one preflight
     * request over whichever route is forced and returns {healthy, reason}.
     *
     * @return array {healthy, reason, route, origin_address, attempts}
     */
    public static function select($probe, $target_url = '') {
        $attempts = array();
        self::$forced = array('route' => 'public', 'address' => '');
        try {
            $public = $probe();
        } finally {
            self::$forced = null;
        }
        $attempts[] = array('route' => 'public', 'address' => '', 'healthy' => (bool) $public['healthy'], 'reason' => (string) $public['reason']);
        if ($public['healthy']) {
            return self::store(array_merge($public, array('route' => 'public', 'origin_address' => null, 'attempts' => $attempts)));
        }

        $url = $target_url ? $target_url : home_url('/');
        if (self::is_own_origin($url)) {
            foreach (self::candidates() as $address) {
                self::$forced = array('route' => 'direct', 'address' => $address);
                try {
                    $direct = $probe();
                } finally {
                    self::$forced = null;
                }
                $attempts[] = array('route' => 'direct', 'address' => $address, 'healthy' => (bool) $direct['healthy'], 'reason' => (string) $direct['reason']);
                if ($direct['healthy']) {
                    return self::store(array_merge($direct, array('route' => 'direct', 'origin_address' => $address, 'attempts' => $attempts)));
                }
            }
        }

        $direct_reasons = array();
        foreach (array_slice($attempts, 1) as $attempt) {
            $direct_reasons[] = $attempt['address'] . ': ' . $attempt['reason'];
        }
        $reason = $public['reason'];
        if ($direct_reasons) {
            /* translators: 1: public route failure, 2: each direct attempt and its failure */
            $reason = sprintf(__('%1$s; direct to the origin server also failed (%2$s)', 'super-speedy-performance-analysis'), $public['reason'], implode('; ', $direct_reasons));
        }
        return self::store(array('healthy' => false, 'reason' => $reason, 'route' => null, 'origin_address' => null, 'attempts' => $attempts));
    }

    private static function store($result) {
        update_option(self::ROUTE_OPTION, array(
            'route' => $result['route'],
            'address' => $result['origin_address'],
            'healthy' => (bool) $result['healthy'],
            'reason' => (string) $result['reason'],
            'attempts' => $result['attempts'],
            'checked_at' => time(),
        ), false);
        return $result;
    }

    /** The last selection, as stored. */
    public static function last_selection() {
        $stored = get_option(self::ROUTE_OPTION, array());
        return is_array($stored) ? $stored : array();
    }
}
