<?php
defined('ABSPATH') || exit;

/**
 * Transport and evidence version registry plus immutable encoding helpers.
 */
class SSPA_Community_Schema {

    const TRANSPORT_VERSION = 1;
    const PAYLOAD_SCHEMA_MAJOR = 1;
    // Minor 2 adds site cohort dimensions to the top-level site snapshot. Purely additive:
    // every field a 1.1 receiver reads is still present, with the same name and meaning.
    // Minor 3 adds `run.change_cycle` and sspa/component-state evidence.
    // Minor 4 adds privacy-normalised sspa/http-call evidence. Also additive.
    const PAYLOAD_SCHEMA_MINOR = 4;
    const ANONYMISATION_VERSION = 1;
    const MEASUREMENT_VERSION = 1;
    // Version 2 is the first to submit another plugin's settings, and only for plugins that have
    // opted in by registering the `sspa_component_state` filter. That is a genuine widening of
    // what leaves the site, so it is a new consent version rather than a silent addition: a site
    // that agreed to version 1 agreed to a payload with no third-party settings in it at all, and
    // every payload records which version was in force when it was built.
    // Version 3 adds normalised external HTTP endpoints and call-site evidence. It contains no
    // query values or site URLs, but it is still a wider disclosure than version 2.
    // Version 4 adds a coarse admin-save classification: post/page/product/order/custom post
    // type, classic/REST, real editor update/no-change workflow and mail handling mode. IDs,
    // content and custom post type slugs remain forbidden.
    const CONSENT_VERSION = 4;
    const MAX_COMPRESSED_BYTES = 33554432;
    const MAX_UNCOMPRESSED_BYTES = 268435456;

    public static function evidence_versions() {
        return array(
            // 2: site cohort dimensions (classification, banded sizes, environment). A
            // superset of 1, so a receiver that only understands 1 can still read every
            // field it knew about; one that understands 2 gets the cohort dimensions.
            'sspa/site-snapshot' => 2,
            'sspa/page-profile' => 2,
            'sspa/component-observation' => 1,
            'sspa/excimer-profile' => 1,
            'sspa/finding' => 1,
            'sspa/plugin-impact' => 1,
            'sspa/cache-impact' => 1,
            'sspa/checkout-flow' => 2,
            // The shop owner's post-sale work, kept as its own type rather than more steps on
            // the checkout flow: it measures a different person's time and must never be
            // added to what a customer waited through.
            // 2 adds the measured full-refund and move-to-Trash steps. Version 1 payloads
            // remain immutable and readable by the collector.
            'sspa/order-management-flow' => 2,
            // What the site's archives filter and order by, and the composite indexes that
            // would serve them. Shapes only - column names, index shapes, plan verdicts,
            // banded row counts. Meta key names are allowlisted; meta values never travel.
            'sspa/archive-profile' => 1,
            'sspa/plugin-toggle-spot' => 1,
            'sspa/adhoc-page-profile' => 1,
            'sspa/admin-save' => 1,
            // Another plugin's own account of how it was configured when the run was measured,
            // published by that plugin through the `sspa_component_state` filter. Never read out
            // of another plugin's options by this one.
            'sspa/component-state' => 1,
            'sspa/http-call' => 1,
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
