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
        $response = wp_remote_get(SSPA_Submitter::endpoint('rules'), array(
            'timeout' => 30,
        ));
        if (is_wp_error($response)) {
            return $response;
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['rules']) || empty($body['signature'])) {
            return new WP_Error('sspa_feed_invalid', __('The rules feed response was malformed.', 'super-speedy-performance-analysis'));
        }
        if (!self::verify($body)) {
            return new WP_Error('sspa_feed_unverified', __('The rules feed signature did not verify - feed ignored.', 'super-speedy-performance-analysis'));
        }
        set_transient(self::CACHE_KEY, $body, DAY_IN_SECONDS);
        return true;
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
