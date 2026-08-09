<?php
defined('ABSPATH') || exit;

/**
 * Fetches the community rules feed from the hub and verifies its RSA signature before
 * anything trusts it - a spoofed feed that names plugins to disable would be an attack
 * vector. Verified rules are cached for 24h and merged over the bundled snapshot by
 * SSPA_Rules; on any failure the bundled snapshot alone applies.
 */
class SSPA_Rules_Feed {

    const CACHE_KEY = 'sspa_rules_feed';

    /**
     * Failure backoff. Only a SUCCESSFUL fetch writes CACHE_KEY, so without this the
     * hourly cleanup cron re-requests the feed every hour forever whenever the hub is
     * not serving it - which is the case until superspeedy.org publishes the route.
     * One dead endpoint must not cost every install 24 outbound requests a day.
     */
    const BACKOFF_KEY = 'sspa_rules_feed_backoff';
    const BACKOFF_SECONDS = 12 * HOUR_IN_SECONDS;

    /**
     * Public key for feed verification. Ships with the plugin once superspeedy.org is
     * live; the option override exists for development and key rotation.
     */
    public static function public_key() {
        $key = get_option('sspa_rules_pubkey');
        if ($key) {
            return $key;
        }
        $bundled = SSPA_PLUGIN_DIR . 'rules/feed-pubkey.pem';
        return file_exists($bundled) ? file_get_contents($bundled) : null;
    }

    /**
     * @return array|null Verified rules (or null when unavailable/invalid).
     */
    public static function get() {
        $cached = get_transient(self::CACHE_KEY);
        if (is_array($cached)) {
            return self::verify($cached) ? $cached['rules'] : null;
        }
        return null;
    }

    /**
     * @return true|WP_Error
     */
    public static function refresh() {
        if (get_transient(self::BACKOFF_KEY)) {
            return new WP_Error('sspa_feed_backoff', __('The rules feed is inside its failure backoff window.', 'super-speedy-performance-analysis'));
        }
        $response = wp_remote_get(SSPA_Submitter::endpoint('rules'), array(
            'timeout' => 30,
        ));
        if (is_wp_error($response)) {
            return self::back_off($response);
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['rules']) || empty($body['signature'])) {
            return self::back_off(new WP_Error('sspa_feed_invalid', __('The rules feed response was malformed.', 'super-speedy-performance-analysis')));
        }
        if (!self::verify($body)) {
            return self::back_off(new WP_Error('sspa_feed_unverified', __('The rules feed signature did not verify - feed ignored.', 'super-speedy-performance-analysis')));
        }
        delete_transient(self::BACKOFF_KEY);
        set_transient(self::CACHE_KEY, $body, DAY_IN_SECONDS);
        return true;
    }

    /**
     * Record a failed fetch so the next cleanup pass does not immediately repeat it, and
     * return the error unchanged for the caller to act on.
     */
    private static function back_off($error) {
        set_transient(self::BACKOFF_KEY, 1, self::BACKOFF_SECONDS);
        return $error;
    }

    /**
     * Signature covers the canonical JSON of version + rules.
     */
    public static function verify($body) {
        $pubkey = self::public_key();
        if (!$pubkey || empty($body['signature']) || !isset($body['rules'], $body['version']) || !function_exists('openssl_verify')) {
            return false;
        }
        $signed_data = wp_json_encode(array('version' => $body['version'], 'rules' => $body['rules']));
        $signature = base64_decode((string) $body['signature'], true);
        if (false === $signature) {
            return false;
        }
        return 1 === openssl_verify($signed_data, $signature, $pubkey, OPENSSL_ALGO_SHA256);
    }
}
