<?php
defined('ABSPATH') || exit;

/**
 * Share-safe projections and a final forbidden-data scanner.
 */
class SSPA_Community_Privacy {

    public static function component($name, $type, $submission_uuid) {
        $name = strtolower(trim((string) $name));
        $type = strtolower(trim((string) $type));
        if ('core' === $type || 'core' === $name) {
            return array('slug' => 'wordpress-core', 'type' => 'core');
        }
        if (in_array($type, array('plugin', 'theme'), true) && preg_match('/^[a-z0-9][a-z0-9._-]{0,190}$/', $name)) {
            return array('slug' => $name, 'type' => $type);
        }
        return array(
            'slug' => 'private-' . substr(hash('sha256', $submission_uuid . "\n" . $type . "\n" . $name), 0, 16),
            'type' => 'private',
        );
    }

    public static function page_class($page_key, $variant = '') {
        $key = strtolower((string) $page_key);
        if (0 === strpos($key, 'url-')) {
            return ('admin' === $variant) ? 'custom-admin' : 'custom-frontend';
        }
        if (0 === strpos($key, 'cpt-')) {
            return (false !== strpos($key, '-archive')) ? 'custom-post-type-archive' : 'custom-post-type-single';
        }
        if (0 === strpos($key, 'tax-')) {
            return 'custom-taxonomy-archive';
        }
        $known = array(
            'baseline', 'mail-probe', 'home', 'blog', 'post-single', 'post-cat', 'post-tag',
            'search-many', 'search-zero', '404', 'feed', 'rest-posts', 'shop', 'product-cat',
            'product-single', 'wc-cart', 'wc-checkout', 'wc-myaccount', 'admin-dashboard',
            'admin-plugins', 'admin-media', 'admin-posts', 'admin-edit-post', 'admin-new-post',
            'admin-products', 'admin-edit-product', 'admin-new-product', 'admin-orders',
            'admin-orders-search', 'admin-edit-order', 'write-save-post', 'write-save-product',
            'write-order-processing', 'flow-preflight', 'flow-view-product', 'flow-add-to-cart',
            'flow-view-cart', 'flow-cart-api', 'flow-update-customer', 'flow-update-order-review',
            'flow-select-shipping', 'flow-view-checkout', 'flow-checkout-draft', 'flow-place-order',
            'flow-order-received', 'flow-delete-order', 'flow',
        );
        return in_array($key, $known, true) ? $key : (('admin' === $variant) ? 'custom-admin' : 'custom-frontend');
    }

    public static function function_name($name, $component_type) {
        if ('private' === $component_type) {
            return null;
        }
        $name = trim((string) $name);
        if ('' === $name || false !== strpos($name, '/') || false !== strpos($name, "\0")) {
            return null;
        }
        $name = preg_replace('/\s+/', ' ', $name);
        return substr($name, 0, 191);
    }

    public static function sql_fingerprint($sql) {
        require_once SSPA_PLUGIN_DIR . 'profiler/fingerprint.php';
        global $wpdb;
        $fingerprint = sspa_sql_fingerprint((string) $sql);
        if (!empty($wpdb->prefix)) {
            $fingerprint = str_ireplace($wpdb->prefix, 'wp_', $fingerprint);
        }
        if (defined('DB_NAME') && DB_NAME) {
            $fingerprint = str_ireplace(DB_NAME, 'database', $fingerprint);
        }
        return substr($fingerprint, 0, 1000);
    }

    public static function finding_evidence($evidence) {
        $safe = array();
        foreach (array('ms', 'rows', 'query_count', 'sql_ms', 'count', 'saved_pct', 'queries_on', 'queries_off', 'construct_ms', 'autoload_bytes') as $key) {
            if (isset($evidence[$key]) && is_numeric($evidence[$key])) {
                $safe[$key] = $evidence[$key] + 0;
            }
        }
        if (isset($evidence['shape']) && preg_match('/^[a-z0-9_-]{1,64}$/', (string) $evidence['shape'])) {
            $safe['shape'] = (string) $evidence['shape'];
        }
        if (isset($evidence['fp'])) {
            $safe['fingerprint'] = self::sql_fingerprint($evidence['fp']);
        } elseif (isset($evidence['sql'])) {
            $safe['fingerprint'] = self::sql_fingerprint($evidence['sql']);
        }
        return $safe;
    }

    public static function validate($payload) {
        $blocked_keys = '/(^|_)(url|domain|domain_hash|email|ip|user_id|order_id|product_id|session|cookie|nonce|authorization|request_body|response_body|filesystem_path)(_|$)/i';
        $host = strtolower((string) parse_url(home_url('/'), PHP_URL_HOST));
        // Bare Docker/intranet hostnames commonly collide with plugin slugs. Complete URLs
        // are rejected separately; only use a host as a raw-string needle when it is an FQDN.
        $host_needle = (false !== strpos($host, '.')) ? $host : '';
        $needles = array_filter(array(
            $host_needle,
            defined('ABSPATH') ? str_replace('\\', '/', ABSPATH) : '',
            defined('WP_CONTENT_DIR') ? str_replace('\\', '/', WP_CONTENT_DIR) : '',
            defined('DB_NAME') ? DB_NAME : '',
        ));

        $scan = function ($value, $path = '$') use (&$scan, $blocked_keys, $needles) {
            if (is_array($value)) {
                foreach ($value as $key => $child) {
                    $child_path = $path . '.' . $key;
                    if (is_string($key) && preg_match($blocked_keys, $key)) {
                        return new WP_Error('sspa_privacy_forbidden_key', sprintf(__('Forbidden community field at %s.', 'super-speedy-performance-analysis'), $child_path));
                    }
                    $error = $scan($child, $child_path);
                    if (is_wp_error($error)) {
                        return $error;
                    }
                }
                return true;
            }
            if (!is_string($value) || '' === $value) {
                return true;
            }
            $normal = strtolower(str_replace('\\', '/', $value));
            if (preg_match('#https?://#i', $value)
                || preg_match('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', $value)
                || preg_match('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', $value)
                || preg_match('#(?:^|[\s\'\"])/(?:users|home|var|srv|opt|etc|tmp)/#i', $normal)) {
                return new WP_Error('sspa_privacy_forbidden_value', sprintf(__('Potential private data at %s.', 'super-speedy-performance-analysis'), $path));
            }
            foreach ($needles as $needle) {
                if (strlen($needle) >= 3 && false !== strpos($normal, strtolower(str_replace('\\', '/', $needle)))) {
                    return new WP_Error('sspa_privacy_site_identifier', sprintf(__('Site-identifying data at %s.', 'super-speedy-performance-analysis'), $path));
                }
            }
            return true;
        };

        return $scan($payload);
    }
}
