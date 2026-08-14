<?php
defined('ABSPATH') || exit;

/** Privacy assertions shared by experimental exports and the regression suite. */
class SSPA_Traffic_Privacy {

    const SCHEMA = 'sspa/traffic-collector-observations@1';

    private static $forbidden_keys = array(
        'name', 'first_name', 'last_name', 'email', 'phone', 'address', 'postcode', 'ip',
        'ip_address', 'user_id', 'customer_id', 'session_id', 'order_id', 'product_id',
        'product_name', 'sku', 'coupon', 'coupon_code', 'cookie', 'cookie_value', 'user_agent',
        'query_string', 'query_value', 'form_body', 'response_html', 'actor_key',
        'related_actor_key', 'commerce_key', 'collection_key', 'cloudflare_api_token',
    );

    public static function validate_export($payload) {
        $problems = array();
        self::walk($payload, '', $problems);
        return array_values(array_unique($problems));
    }

    private static function walk($value, $path, &$problems) {
        if (!is_array($value)) {
            return;
        }
        foreach ($value as $key => $child) {
            $key_string = strtolower((string) $key);
            $child_path = $path === '' ? $key_string : $path . '.' . $key_string;
            if (in_array($key_string, self::$forbidden_keys, true)) {
                $problems[] = $child_path;
            }
            self::walk($child, $child_path, $problems);
        }
    }
}
