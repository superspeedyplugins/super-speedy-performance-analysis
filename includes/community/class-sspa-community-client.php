<?php
defined('ABSPATH') || exit;

/**
 * Idempotent register, reserve, direct-upload and complete client.
 */
class SSPA_Community_Client {

    public static function register($force = false) {
        $marker = self::registration_marker();
        if (!$force && get_option($marker)) {
            return true;
        }
        $collector = SSPA_Community_Identity::collector_url();
        if (!self::allowed_collector($collector)) {
            return self::error('sspa_collector_tls_required', __('The collector must use verified HTTPS.', 'super-speedy-performance-analysis'), true);
        }
        $secret = SSPA_Community_Identity::secret();
        if (is_wp_error($secret)) {
            return $secret;
        }
        $response = self::json_request('POST', SSPA_Community_Identity::endpoint('installations'), array(
            'install_uuid' => SSPA_Community_Identity::install_uuid(),
            'install_secret' => $secret,
            'client_version' => SSPA_VERSION,
            'wordpress_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
        ), array(), 30);
        if (is_wp_error($response)) {
            return $response;
        }
        if (!in_array($response['status'], array(200, 201), true)
            || empty($response['body']['registered'])
            || SSPA_Community_Identity::install_uuid() !== (isset($response['body']['install_uuid']) ? $response['body']['install_uuid'] : '')) {
            return self::response_error($response, 'sspa_registration_failed');
        }
        update_option($marker, gmdate('Y-m-d H:i:s'), false);
        return true;
    }

    public static function submit($row) {
        $registered = self::register();
        if (is_wp_error($registered)) {
            return $registered;
        }
        if (empty($row['payload_gzip'])
            || strlen($row['payload_gzip']) !== (int) $row['compressed_bytes']
            || !hash_equals($row['payload_sha256'], hash('sha256', $row['payload_gzip']))) {
            return self::error('sspa_outbox_integrity_failed', __('The local outbox bytes failed their integrity check.', 'super-speedy-performance-analysis'), true);
        }

        $run = SSPA_Run_Controller::run_row((int) $row['run_id']);
        if (!$run || $run['run_uuid'] !== $row['run_uuid']) {
            return self::error('sspa_outbox_run_missing', __('The queued payload no longer matches its local run.', 'super-speedy-performance-analysis'), true);
        }
        $manifest = array(
            'transport_version' => (int) $row['transport_version'],
            'submission_uuid' => $row['submission_uuid'],
            'payload_sha256' => $row['payload_sha256'],
            'compressed_bytes' => (int) $row['compressed_bytes'],
            'uncompressed_bytes' => (int) $row['uncompressed_bytes'],
            'payload_schema_major' => (int) $row['payload_schema_major'],
            'payload_schema_minor' => (int) $row['payload_schema_minor'],
            'client_version' => $row['client_version'],
            'anonymisation_version' => (int) $row['anonymisation_version'],
            'run_uuid' => $row['run_uuid'],
            'run_type' => sanitize_key($run['run_type']),
            'payload_created_at' => $row['payload_created_at'],
        );
        $signature = self::sign(self::reservation_canonical($manifest));
        if (is_wp_error($signature)) {
            return $signature;
        }
        $headers = array(
            'X-SSPA-Install' => SSPA_Community_Identity::install_uuid(),
            'X-SSPA-Signature' => $signature,
        );
        $reserve = self::json_request('POST', SSPA_Community_Identity::endpoint('submissions'), $manifest, $headers, 30);
        if (is_wp_error($reserve)) {
            return $reserve;
        }
        if (401 === $reserve['status']) {
            $registration = self::register(true);
            if (is_wp_error($registration)) {
                return $registration;
            }
            return self::error('sspa_registration_refreshed', __('Collector registration was refreshed; the queued item will retry.', 'super-speedy-performance-analysis'), false, 401);
        }
        if (!in_array($reserve['status'], array(200, 201), true)) {
            return self::response_error($reserve, 'sspa_reservation_failed');
        }
        $remote = $reserve['body'];
        if (($remote['submission_uuid'] ?? '') !== $row['submission_uuid']) {
            return self::error('sspa_reservation_identity_mismatch', __('The collector returned a different submission UUID.', 'super-speedy-performance-analysis'), true, $reserve['status']);
        }
        $storage_status = isset($remote['storage_status']) ? $remote['storage_status'] : '';
        if ('complete' === $storage_status) {
            return self::verify_receipt($row, $remote, $reserve['status']);
        }
        $reservation_uuid = isset($remote['reservation_uuid']) ? $remote['reservation_uuid'] : '';
        if (!wp_is_uuid($reservation_uuid, 4)) {
            return self::error('sspa_invalid_reservation', __('The collector returned an invalid reservation UUID.', 'super-speedy-performance-analysis'), true, $reserve['status']);
        }
        $expires = null;
        if (!empty($remote['upload_expires_at'])) {
            $timestamp = strtotime($remote['upload_expires_at']);
            $expires = $timestamp ? gmdate('Y-m-d H:i:s', $timestamp) : null;
        }
        SSPA_Community_Outbox::phase($row['id'], ('uploaded' === $storage_status) ? 'completing' : 'uploading', array(
            'reservation_uuid' => $reservation_uuid,
            'upload_expires_at' => $expires,
        ));

        if ('awaiting_upload' === $storage_status) {
            $uploaded = self::upload($row, $remote);
            if (is_wp_error($uploaded)) {
                return $uploaded;
            }
        } elseif ('uploaded' !== $storage_status) {
            return self::error('sspa_unknown_storage_state', __('The collector returned an unknown storage state.', 'super-speedy-performance-analysis'), true, $reserve['status']);
        }

        SSPA_Community_Outbox::phase($row['id'], 'completing', array('reservation_uuid' => $reservation_uuid));
        $completion = array(
            'transport_version' => (int) $row['transport_version'],
            'reservation_uuid' => $reservation_uuid,
            'payload_sha256' => $row['payload_sha256'],
        );
        $completion_signature = self::sign(self::completion_canonical($row, $reservation_uuid));
        if (is_wp_error($completion_signature)) {
            return $completion_signature;
        }
        $complete = self::json_request(
            'POST',
            SSPA_Community_Identity::endpoint('submissions/' . rawurlencode($row['submission_uuid']) . '/complete'),
            $completion,
            array(
                'X-SSPA-Install' => SSPA_Community_Identity::install_uuid(),
                'X-SSPA-Signature' => $completion_signature,
            ),
            120
        );
        if (is_wp_error($complete)) {
            return $complete;
        }
        if (!in_array($complete['status'], array(200, 201), true)) {
            return self::response_error($complete, 'sspa_completion_failed');
        }
        return self::verify_receipt($row, $complete['body'], $complete['status']);
    }

    public static function reservation_canonical($manifest) {
        return implode("\n", array(
            'SSPA-SUBMISSION-RESERVE-V1',
            (string) (int) $manifest['transport_version'],
            SSPA_Community_Identity::install_uuid(),
            $manifest['submission_uuid'],
            $manifest['payload_sha256'],
            (string) (int) $manifest['compressed_bytes'],
            (string) (int) $manifest['uncompressed_bytes'],
            (string) (int) $manifest['payload_schema_major'],
            (string) (int) $manifest['payload_schema_minor'],
            $manifest['client_version'],
            (string) (int) $manifest['anonymisation_version'],
            $manifest['run_uuid'],
            $manifest['run_type'],
            $manifest['payload_created_at'],
        )) . "\n";
    }

    public static function completion_canonical($row, $reservation_uuid) {
        return implode("\n", array(
            'SSPA-SUBMISSION-COMPLETE-V1',
            (string) (int) $row['transport_version'],
            SSPA_Community_Identity::install_uuid(),
            $row['submission_uuid'],
            $reservation_uuid,
            $row['payload_sha256'],
        )) . "\n";
    }

    private static function upload($row, $remote) {
        $url = isset($remote['upload_url']) ? $remote['upload_url'] : '';
        $required = isset($remote['required_headers']) && is_array($remote['required_headers']) ? $remote['required_headers'] : array();
        if (!wp_http_validate_url($url)
            || 1 !== count($required)
            || !isset($required['Content-Type'])
            || 'application/gzip' !== strtolower($required['Content-Type'])) {
            return self::error('sspa_invalid_upload_authorisation', __('The collector returned an invalid upload authorisation.', 'super-speedy-performance-analysis'), true);
        }
        if (strlen($row['payload_gzip']) !== (int) $row['compressed_bytes']) {
            return self::error('sspa_upload_size_changed', __('The queued payload changed before upload.', 'super-speedy-performance-analysis'), true);
        }
        $response = wp_remote_request($url, array(
            'method' => 'PUT',
            'timeout' => 120,
            'redirection' => 0,
            'headers' => array('Content-Type' => 'application/gzip'),
            'body' => $row['payload_gzip'],
        ));
        if (is_wp_error($response)) {
            return self::error('sspa_upload_network_error', __('The direct object upload could not reach storage.', 'super-speedy-performance-analysis'), false);
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            return self::error('sspa_upload_http_' . $status, sprintf(__('Object storage rejected the upload (HTTP %d).', 'super-speedy-performance-analysis'), $status), self::permanent_status($status), $status, self::retry_after($response));
        }
        return true;
    }

    private static function verify_receipt($row, $remote, $http_status) {
        $receipt_uuid = isset($remote['receipt_uuid']) ? $remote['receipt_uuid'] : '';
        $remote_hash = isset($remote['payload_sha256']) ? $remote['payload_sha256'] : '';
        if (($remote['submission_uuid'] ?? '') !== $row['submission_uuid']
            || !wp_is_uuid($receipt_uuid, 4)
            || 'archived' !== ($remote['storage_status'] ?? '')
            || !is_string($remote_hash)
            || !hash_equals($row['payload_sha256'], $remote_hash)) {
            return self::error('sspa_receipt_mismatch', __('The collector receipt did not match the queued payload.', 'super-speedy-performance-analysis'), true, $http_status);
        }
        return array('receipt_uuid' => $receipt_uuid, 'http_status' => (int) $http_status);
    }

    private static function json_request($method, $url, $body, $headers = array(), $timeout = 30) {
        $headers['Content-Type'] = 'application/json';
        $response = wp_remote_request($url, array(
            'method' => $method,
            'timeout' => $timeout,
            'redirection' => 0,
            'headers' => $headers,
            'body' => wp_json_encode($body),
        ));
        if (is_wp_error($response)) {
            return self::error('sspa_collector_network_error', __('The collector is unavailable; the queued payload will retry.', 'super-speedy-performance-analysis'), false);
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        return array(
            'status' => $status,
            'body' => is_array($decoded) ? $decoded : array(),
            'retry_after' => self::retry_after($response),
        );
    }

    private static function response_error($response, $fallback_code) {
        $status = (int) $response['status'];
        $remote_code = isset($response['body']['error']['code']) ? sanitize_key($response['body']['error']['code']) : $fallback_code;
        return self::error(
            $remote_code ?: $fallback_code,
            sprintf(__('The collector rejected the request (HTTP %d).', 'super-speedy-performance-analysis'), $status),
            self::permanent_status($status),
            $status,
            isset($response['retry_after']) ? $response['retry_after'] : null
        );
    }

    private static function permanent_status($status) {
        return !in_array((int) $status, array(408, 425, 429, 500, 502, 503, 504), true);
    }

    private static function retry_after($response) {
        $value = wp_remote_retrieve_header($response, 'retry-after');
        if (is_numeric($value)) {
            return (int) $value;
        }
        $timestamp = $value ? strtotime($value) : false;
        return $timestamp ? max(0, $timestamp - time()) : null;
    }

    private static function sign($canonical) {
        $secret = SSPA_Community_Identity::secret_bytes();
        return is_wp_error($secret) ? $secret : hash_hmac('sha256', $canonical, $secret);
    }

    private static function allowed_collector($url) {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }
        if ('https' === strtolower($parts['scheme'])) {
            return true;
        }
        return 'http' === strtolower($parts['scheme'])
            && in_array(strtolower($parts['host']), array('localhost', '127.0.0.1', '::1', 'host.docker.internal'), true);
    }

    private static function registration_marker() {
        return 'sspa_collector_registered_' . md5(SSPA_Community_Identity::collector_url());
    }

    private static function error($code, $message, $permanent, $http_status = null, $retry_after = null) {
        return new WP_Error($code, $message, array(
            'permanent' => (bool) $permanent,
            'http_status' => $http_status ? (int) $http_status : null,
            'retry_after' => $retry_after ? (int) $retry_after : null,
        ));
    }
}
