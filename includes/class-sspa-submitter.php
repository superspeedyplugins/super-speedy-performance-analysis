<?php
defined('ABSPATH') || exit;

/**
 * Talks to the hub (superspeedy.org; overridable for dev). First contact registers the
 * install UUID and receives a per-install secret; every submission is HMAC-signed with it
 * so one actor cannot trivially impersonate many installs. Nothing is ever sent unless
 * the site owner opted in on the Share tab.
 */
class SSPA_Submitter {

    public static function hub_url() {
        $url = get_option('sspa_hub_url');
        return $url ? untrailingslashit($url) : 'https://superspeedy.org';
    }

    /**
     * Hub endpoint URL. Uses the ?rest_route= form, which works on every WordPress
     * regardless of the hub's permalink settings or trailing-slash redirect rules
     * (pretty /wp-json/ URLs 301 into oblivion on some setups, breaking POSTs).
     */
    public static function endpoint($path) {
        return self::hub_url() . '/?rest_route=/ssph/v1/' . ltrim($path, '/');
    }

    public static function opted_in() {
        return (bool) get_option('sspa_share_optin');
    }

    /**
     * Per-install secrets are issued BY a hub, so they are stored per hub URL - switching
     * hubs (dev vs production) must not present one hub's secret to another.
     */
    private static function secret_key() {
        return 'sspa_install_secret_' . md5(self::hub_url());
    }

    private static function secret() {
        $secret = get_option(self::secret_key());
        if (!$secret) {
            // Pre-0.7.1 installs stored a single global secret; adopt it for this hub.
            $legacy = get_option('sspa_install_secret');
            if ($legacy) {
                add_option(self::secret_key(), $legacy, '', false);
                delete_option('sspa_install_secret');
                $secret = $legacy;
            }
        }
        return $secret;
    }

    public static function register() {
        if (self::secret()) {
            return true;
        }
        $response = wp_remote_post(self::endpoint('register'), array(
            'timeout' => 30,
            'sslverify' => false,
            'headers' => array('Content-Type' => 'application/json'),
            'body' => wp_json_encode(array(
                'install' => SSPA_Anonymiser::install_uuid(),
                'wp' => get_bloginfo('version'),
                'php' => PHP_VERSION,
            )),
        ));
        if (is_wp_error($response)) {
            return $response;
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ((int) wp_remote_retrieve_response_code($response) !== 200 || empty($body['secret'])) {
            return new WP_Error('sspa_register_failed', __('The community hub did not accept the registration.', 'super-speedy-performance-analysis'));
        }
        add_option(self::secret_key(), $body['secret'], '', false);
        return true;
    }

    /**
     * @return true|WP_Error
     */
    public static function submit() {
        if (!self::opted_in()) {
            return new WP_Error('sspa_not_opted_in', __('Sharing is not enabled - opt in on the Share tab first.', 'super-speedy-performance-analysis'));
        }
        $registered = self::register();
        if (is_wp_error($registered)) {
            self::log(false, $registered->get_error_message());
            return $registered;
        }

        $payload = SSPA_Anonymiser::build();
        if (is_wp_error($payload)) {
            return $payload;
        }
        $body = wp_json_encode($payload);

        $response = wp_remote_post(self::endpoint('submissions'), array(
            'timeout' => 60,
            'sslverify' => false,
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-SSPA-Install' => SSPA_Anonymiser::install_uuid(),
                'X-SSPA-Signature' => hash_hmac('sha256', $body, self::secret()),
            ),
            'body' => $body,
        ));

        if (is_wp_error($response)) {
            self::log(false, $response->get_error_code());
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $msg = wp_remote_retrieve_body($response);
            self::log(false, 'HTTP ' . $code . ' ' . substr((string) $msg, 0, 200));
            return new WP_Error('sspa_submit_failed', sprintf(__('The hub rejected the submission (HTTP %d).', 'super-speedy-performance-analysis'), $code));
        }
        self::log(true, sprintf(__('%s submitted', 'super-speedy-performance-analysis'), size_format(strlen($body))));
        return true;
    }

    private static function log($ok, $message) {
        $history = get_option('sspa_submission_log', array());
        array_unshift($history, array('time' => gmdate('Y-m-d H:i:s'), 'ok' => (bool) $ok, 'message' => $message));
        update_option('sspa_submission_log', array_slice($history, 0, 10), false);
    }

    public static function history() {
        return get_option('sspa_submission_log', array());
    }
}
