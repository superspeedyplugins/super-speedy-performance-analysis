<?php
defined('ABSPATH') || exit;

/**
 * Classifies blocked loopback responses and identifies which security layer did the
 * blocking, so the run can continue and tell the user exactly what to whitelist.
 */
class SSPA_Security_Detect {

    private static $security_plugins = array(
        'wordfence/wordfence.php' => 'Wordfence',
        'better-wp-security/better-wp-security.php' => 'Solid Security',
        'all-in-one-wp-security-and-firewall/wp-security.php' => 'All-In-One Security',
        'wp-simple-firewall/wp-simple-firewall.php' => 'Shield Security',
        'sucuri-scanner/sucuri.php' => 'Sucuri Security',
        'jetpack-protect/jetpack-protect.php' => 'Jetpack Protect',
        'wp-cerber/wp-cerber.php' => 'WP Cerber',
    );

    /**
     * @param int $code HTTP status.
     * @param array $headers Response headers (lowercase keys).
     * @param string $body Response body (may be truncated).
     * @param bool $had_auth_cookie Whether the request carried valid auth cookies.
     * @return string|null Blocking layer slug/label, or null if not blocked.
     */
    public static function classify($code, $headers, $body, $had_auth_cookie) {
        $detail = self::classify_detail($code, $headers, $body, $had_auth_cookie);
        return $detail ? self::display_label($detail) : null;
    }

    public static function display_label($detail) {
        if (!is_array($detail) || empty($detail['label'])) {
            return null;
        }
        return ('probable' === (isset($detail['confidence']) ? $detail['confidence'] : ''))
            ? sprintf(__('%s (probable)', 'super-speedy-performance-analysis'), $detail['label'])
            : $detail['label'];
    }

    /**
     * @return array|null {label:string,confidence:identified|probable|unknown}
     */
    public static function classify_detail($code, $headers, $body, $had_auth_cookie) {
        $blocked = in_array($code, array(401, 403, 406, 418, 429, 503), true);

        // Login bounce despite valid cookies = an auth/security layer rejected our session.
        if (!$blocked && in_array($code, array(301, 302), true) && $had_auth_cookie) {
            $location = isset($headers['location']) ? (is_array($headers['location']) ? end($headers['location']) : $headers['location']) : '';
            if (strpos($location, 'wp-login.php') !== false) {
                $blocked = true;
            }
        }
        if (!$blocked && is_string($body) && (stripos($body, 'cf-challenge') !== false || stripos($body, 'checking your browser') !== false)) {
            $blocked = true;
        }
        if (!$blocked) {
            return null;
        }

        // Edge layers first (headers are the strongest signal).
        $server = isset($headers['server']) ? strtolower((string) (is_array($headers['server']) ? end($headers['server']) : $headers['server'])) : '';
        if (isset($headers['cf-ray']) || strpos($server, 'cloudflare') !== false) {
            return array('label' => 'Cloudflare', 'confidence' => 'identified');
        }
        if (isset($headers['x-sucuri-id']) || isset($headers['x-sucuri-block'])) {
            return array('label' => 'Sucuri WAF', 'confidence' => 'identified');
        }
        if (is_string($body) && stripos($body, 'wordfence') !== false) {
            return array('label' => 'Wordfence', 'confidence' => 'identified');
        }

        // Fall back to the active security plugin.
        $active = (array) get_option('active_plugins', array());
        foreach (self::$security_plugins as $file => $label) {
            if (in_array($file, $active, true)) {
                return array('label' => $label, 'confidence' => 'probable');
            }
        }
        return array('label' => 'unknown security layer', 'confidence' => 'unknown');
    }

    public static function whitelist_advice($layer) {
        $generic = sprintf(
            /* translators: %s: security layer name */
            __('Loopback profiling requests from this site to itself were blocked by %s. Whitelist your own server IP (requests originate from this server) and re-run the analysis. Blocked pages were skipped; all other results are unaffected.', 'super-speedy-performance-analysis'),
            $layer
        );
        return $generic;
    }
}
