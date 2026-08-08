<?php
defined('ABSPATH') || exit;

/**
 * Stable installation identity and per-collector credentials.
 */
class SSPA_Community_Identity {

    public static function collector_url() {
        $url = trim((string) get_option('sspa_collector_url'));
        return untrailingslashit($url ?: 'https://collector.superspeedy.org');
    }

    public static function endpoint($path) {
        return self::collector_url() . '/v1/' . ltrim($path, '/');
    }

    public static function install_uuid() {
        return SSPA_Anonymiser::install_uuid();
    }

    private static function secret_option() {
        return 'sspa_collector_secret_' . md5(self::collector_url());
    }

    public static function secret($create = true) {
        $secret = strtolower((string) get_option(self::secret_option()));
        if (preg_match('/^[a-f0-9]{64}$/', $secret)) {
            return $secret;
        }
        if (!$create) {
            return '';
        }
        try {
            $secret = bin2hex(random_bytes(32));
        } catch (Exception $e) {
            return new WP_Error('sspa_secret_generation_failed', __('Could not create the collector credential.', 'super-speedy-performance-analysis'));
        }
        update_option(self::secret_option(), $secret, false);
        return $secret;
    }

    public static function secret_bytes() {
        $secret = self::secret();
        if (is_wp_error($secret)) {
            return $secret;
        }
        $bytes = hex2bin($secret);
        return (false === $bytes) ? new WP_Error('sspa_invalid_install_secret', __('The local collector credential is invalid.', 'super-speedy-performance-analysis')) : $bytes;
    }
}
