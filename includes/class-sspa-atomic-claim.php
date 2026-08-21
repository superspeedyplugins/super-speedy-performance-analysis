<?php
defined('ABSPATH') || exit;

/** Database-backed owner leases for work that must have exactly one executor. */
class SSPA_Atomic_Claim {

    /**
     * Acquire or renew a lease. The unique option_name index is the arbitration point;
     * unlike add_option(), this never uses an upsert that can report two winners.
     *
     * @return string|false Owner token on success.
     */
    public static function acquire($key, $ttl, $owner = '') {
        global $wpdb;
        $key = sanitize_key($key);
        $owner = $owner ? preg_replace('/[^A-Za-z0-9_.:-]/', '', sanitize_text_field($owner)) : wp_generate_uuid4();
        if ('' === $owner) {
            return false;
        }
        $expires = time() + max(5, (int) $ttl);
        $value = $owner . '|' . $expires;
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            $key,
            $value
        ));
        if (1 === (int) $inserted) {
            wp_cache_delete($key, 'options');
            return $owner;
        }

        $current = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            $key
        ));
        list($current_owner, $current_expires) = array_pad(explode('|', $current, 2), 2, '0');
        if ($current_owner === $owner || (int) $current_expires < time()) {
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                $value,
                $key,
                $current
            ));
            if (1 === (int) $updated) {
                wp_cache_delete($key, 'options');
                return $owner;
            }
        }
        return false;
    }

    public static function release($key, $owner) {
        global $wpdb;
        $key = sanitize_key($key);
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s",
            $key,
            $wpdb->esc_like(sanitize_text_field($owner) . '|') . '%'
        ));
        wp_cache_delete($key, 'options');
        return 1 === (int) $deleted;
    }

    public static function force_release($key) {
        delete_option(sanitize_key($key));
    }
}
