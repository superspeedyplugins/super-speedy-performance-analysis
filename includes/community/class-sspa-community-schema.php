<?php
defined('ABSPATH') || exit;

/**
 * Transport and evidence version registry plus immutable encoding helpers.
 */
class SSPA_Community_Schema {

    const TRANSPORT_VERSION = 1;
    const PAYLOAD_SCHEMA_MAJOR = 1;
    const PAYLOAD_SCHEMA_MINOR = 1;
    const ANONYMISATION_VERSION = 1;
    const MEASUREMENT_VERSION = 1;
    const CONSENT_VERSION = 1;
    const MAX_COMPRESSED_BYTES = 33554432;
    const MAX_UNCOMPRESSED_BYTES = 268435456;

    public static function evidence_versions() {
        return array(
            'sspa/site-snapshot' => 1,
            'sspa/page-profile' => 2,
            'sspa/component-observation' => 1,
            'sspa/excimer-profile' => 1,
            'sspa/finding' => 1,
            'sspa/plugin-impact' => 1,
            'sspa/cache-impact' => 1,
            'sspa/checkout-flow' => 2,
            'sspa/plugin-toggle-spot' => 1,
            'sspa/adhoc-page-profile' => 1,
        );
    }

    public static function evidence_version($type) {
        $versions = self::evidence_versions();
        return isset($versions[$type]) ? $versions[$type] : null;
    }

    public static function valid_evidence_type($type) {
        return (bool) preg_match('#^[a-z0-9][a-z0-9.-]*/[a-z0-9][a-z0-9-]{0,63}$#', (string) $type);
    }

    public static function canonical_time($mysql_time = null) {
        if (!$mysql_time) {
            return gmdate('Y-m-d\TH:i:s\Z');
        }
        $timestamp = strtotime($mysql_time . ' UTC');
        return $timestamp ? gmdate('Y-m-d\TH:i:s\Z', $timestamp) : null;
    }

    public static function canonicalise($value) {
        if (!is_array($value)) {
            return $value;
        }
        $is_list = array_keys($value) === range(0, count($value) - 1);
        if (!$is_list) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalise($item);
        }
        return $value;
    }

    public static function encode($payload) {
        $json = wp_json_encode(self::canonicalise($payload), JSON_UNESCAPED_SLASHES);
        if (false === $json) {
            return new WP_Error('sspa_payload_encode_failed', __('The community payload could not be encoded.', 'super-speedy-performance-analysis'));
        }
        return $json;
    }

    public static function compress($json) {
        $gzip = gzencode($json, 6, ZLIB_ENCODING_GZIP);
        if (false === $gzip) {
            return new WP_Error('sspa_payload_compress_failed', __('The community payload could not be compressed.', 'super-speedy-performance-analysis'));
        }
        return $gzip;
    }
}
