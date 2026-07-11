<?php
defined('ABSPATH') || exit;

/**
 * Public API. MVP uses normal WP REST; the fast-ajax mu-plugin acceleration for these hot
 * routes comes with the superspeedy.org deployment (companion brainstorm section 2).
 */
class SSPH_Rest {

    const MAX_PAYLOAD_BYTES = 2097152; // 2MB
    const RATE_LIMIT_PER_HOUR = 30;

    public static function register_routes() {
        register_rest_route('ssph/v1', '/register', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'register_install'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route('ssph/v1', '/submissions', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'receive_submission'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route('ssph/v1', '/rules', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'rules'),
            'permission_callback' => '__return_true',
        ));
    }

    public static function register_install($request) {
        global $wpdb;
        $params = $request->get_json_params();
        $uuid = isset($params['install']) ? (string) $params['install'] : '';
        if (!wp_is_uuid($uuid)) {
            return new WP_Error('ssph_bad_uuid', 'Invalid install id.', array('status' => 400));
        }

        $table = SSPH_Schema::table('installs');
        $existing = $wpdb->get_var($wpdb->prepare("SELECT install_uuid FROM $table WHERE install_uuid = %s", $uuid));
        if ($existing) {
            // Never re-issue a secret: an attacker knowing a UUID must not be able to
            // hijack the install's identity.
            return new WP_Error('ssph_already_registered', 'Install already registered.', array('status' => 409));
        }

        $secret = bin2hex(random_bytes(32));
        $wpdb->insert($table, array(
            'install_uuid' => $uuid,
            'secret' => $secret,
            'first_seen' => gmdate('Y-m-d H:i:s'),
            'last_seen' => gmdate('Y-m-d H:i:s'),
            'wp' => isset($params['wp']) ? substr((string) $params['wp'], 0, 20) : null,
            'php' => isset($params['php']) ? substr((string) $params['php'], 0, 20) : null,
        ));

        return rest_ensure_response(array('registered' => true, 'secret' => $secret));
    }

    public static function receive_submission($request) {
        global $wpdb;

        $body = $request->get_body();
        if (strlen($body) > self::MAX_PAYLOAD_BYTES) {
            return new WP_Error('ssph_too_large', 'Payload too large.', array('status' => 413));
        }

        $uuid = (string) $request->get_header('X-SSPA-Install');
        $signature = (string) $request->get_header('X-SSPA-Signature');
        if (!wp_is_uuid($uuid) || !$signature) {
            return new WP_Error('ssph_unauthenticated', 'Missing install identity.', array('status' => 401));
        }

        $installs = SSPH_Schema::table('installs');
        $install = $wpdb->get_row($wpdb->prepare("SELECT * FROM $installs WHERE install_uuid = %s", $uuid), ARRAY_A);
        if (!$install) {
            return new WP_Error('ssph_unknown_install', 'Register first.', array('status' => 401));
        }
        if (!hash_equals(hash_hmac('sha256', $body, $install['secret']), $signature)) {
            return new WP_Error('ssph_bad_signature', 'Signature mismatch.', array('status' => 401));
        }

        $submissions = SSPH_Schema::table('submissions');
        $recent = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $submissions WHERE install_uuid = %s AND received_at > %s",
            $uuid,
            gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS)
        ));
        if ($recent >= self::RATE_LIMIT_PER_HOUR) {
            return new WP_Error('ssph_rate_limited', 'Too many submissions.', array('status' => 429));
        }

        $payload = json_decode($body, true);
        $error = self::validate_payload($payload);
        if ($error) {
            return new WP_Error('ssph_invalid_payload', $error, array('status' => 422));
        }

        $wpdb->insert($submissions, array(
            'install_uuid' => $uuid,
            'schema_version' => (int) $payload['schema'],
            'status' => 'quarantined', // aggregation cron promotes consistent installs later
            'payload' => gzcompress($body, 6),
            'received_at' => gmdate('Y-m-d H:i:s'),
        ));
        $submission_id = (int) $wpdb->insert_id;

        // Flatten measured impacts for the future rollups (the crown-jewel data).
        $impacts_table = SSPH_Schema::table('plugin_impacts');
        foreach (array_slice((array) $payload['impacts'], 0, 200) as $impact) {
            if (empty($impact['plugin'])) {
                continue;
            }
            $wpdb->insert($impacts_table, array(
                'submission_id' => $submission_id,
                'install_uuid' => $uuid,
                'plugin' => substr((string) $impact['plugin'], 0, 191),
                'page_key' => isset($impact['page_key']) ? substr((string) $impact['page_key'], 0, 64) : null,
                'method' => isset($impact['method']) ? substr((string) $impact['method'], 0, 16) : null,
                'delta_ttfb_ms' => isset($impact['delta_ttfb_ms']) ? (float) $impact['delta_ttfb_ms'] : null,
                'delta_sql_ms' => isset($impact['delta_sql_ms']) ? (float) $impact['delta_sql_ms'] : null,
                'delta_mem_bytes' => isset($impact['delta_mem_bytes']) ? (int) $impact['delta_mem_bytes'] : null,
                'delta_queries' => isset($impact['delta_queries']) ? (int) $impact['delta_queries'] : null,
                'confidence' => isset($impact['confidence']) ? substr((string) $impact['confidence'], 0, 10) : null,
                'received_at' => gmdate('Y-m-d H:i:s'),
            ));
        }

        $wpdb->update($installs, array('last_seen' => gmdate('Y-m-d H:i:s')), array('install_uuid' => $uuid));

        return rest_ensure_response(array('received' => true, 'submission_id' => $submission_id));
    }

    /**
     * @return string|null Error message, or null when valid.
     */
    private static function validate_payload($payload) {
        if (!is_array($payload)) {
            return 'Not JSON.';
        }
        if (!isset($payload['schema']) || 1 !== (int) $payload['schema']) {
            return 'Unknown schema version.';
        }
        foreach (array('install', 'site', 'plugins', 'profiles', 'findings', 'impacts') as $key) {
            if (!array_key_exists($key, $payload)) {
                return "Missing $key.";
            }
        }
        foreach ((array) $payload['profiles'] as $p) {
            foreach (array('page_gen_ms', 'sql_ms', 'peak_mem_bytes') as $metric) {
                if (isset($p[$metric]) && ($p[$metric] < 0 || $p[$metric] > 1e10)) {
                    return 'Implausible profile metrics.';
                }
            }
        }
        return null;
    }

    public static function rules() {
        $rules = get_option('ssph_rules', array());
        $version = (int) get_option('ssph_rules_version', 1);
        $signed_data = wp_json_encode(array('version' => $version, 'rules' => $rules));
        return rest_ensure_response(array(
            'version' => $version,
            'rules' => $rules,
            'signature' => SSPH_Keys::sign($signed_data),
        ));
    }
}
