<?php
defined('ABSPATH') || exit;

/**
 * Server-side probe handlers for profiled requests. These only ever run when the
 * mu-loader armed the profiler (a valid signed token was present) AND the token's flags
 * request the probe - an unsigned request to the same URL renders the normal page.
 *
 * - mail probe (mp): sends one test wp_mail so the mail stack's construction cost is
 *   measured. The capture's construct mode guarantees nothing is delivered.
 * - write probes (wp): perform the real save/transition cascade on a TEMPORARY object
 *   created (and deleted) by the run controller - never on real content. We deliberately
 *   run the cascade server-side rather than simulating an admin form POST: the hook
 *   cascade is where the cost lives, and it needs no nonce gymnastics.
 */
class SSPA_Probes {

    public static function register() {
        add_action('template_redirect', array(__CLASS__, 'maybe_handle'), 0);
    }

    private static function flags() {
        return isset($GLOBALS['sspa_flags']) && is_array($GLOBALS['sspa_flags']) ? $GLOBALS['sspa_flags'] : null;
    }

    public static function maybe_handle() {
        $flags = self::flags();
        if ($flags === null || !isset($GLOBALS['sspa_capture'])) {
            return;
        }

        if (!empty($flags['mp'])) {
            // Hostname-only site URLs (docker, some intranets) make the default From
            // invalid, which would abort construction at setFrom(). Not what we measure.
            add_filter('wp_mail_from', function ($from) {
                return (strpos((string) $from, '.') === false || !is_email($from)) ? 'wordpress@example.com' : $from;
            }, PHP_INT_MAX);
            wp_mail(
                'sspa-probe@blackhole.invalid',
                'SSPA mail probe',
                'Timing probe from Super Speedy Performance Analysis. This message is never delivered.'
            );
            self::done('mail-probe');
        }

        if (!empty($flags['wp']) && !empty($flags['tid'])) {
            $target = (int) $flags['tid'];
            switch ($flags['wp']) {
                case 'save_post':
                case 'save_product':
                    $post = get_post($target);
                    if ($post && get_post_meta($target, '_sspa_temp', true)) {
                        // Full save cascade: save_post_{type}, save_post, wp_insert_post etc.
                        wp_update_post(array('ID' => $target, 'post_title' => $post->post_title));
                    }
                    self::done('write-' . $flags['wp']);
                    break;
                case 'order_processing':
                    if (function_exists('wc_get_order')) {
                        $order = wc_get_order($target);
                        if ($order && $order->get_meta('_sspa_temp')) {
                            // The full status cascade both shops and plugins hook into,
                            // including the emails each transition generates (intercepted).
                            $order->update_status('processing');
                            $order->update_status('completed');
                        }
                    }
                    self::done('write-order-processing');
                    break;
            }
        }
    }

    private static function done($label) {
        header('Content-Type: text/plain');
        echo 'sspa-probe-ok:' . $label; // phpcs:ignore WordPress.Security.EscapeOutput
        exit; // profiler capture still writes - it runs on PHP shutdown
    }

    // ---------------- temp objects (created/deleted by the run controller) ----------------

    public static function create_temp_copy($post_type) {
        $source = get_posts(array('numberposts' => 1, 'post_type' => $post_type, 'post_status' => 'publish'));
        if (!$source) {
            return 0;
        }
        $src = $source[0];
        $temp_id = wp_insert_post(array(
            'post_type' => $post_type,
            'post_status' => 'draft',
            'post_title' => '[SSPA temp] ' . $src->post_title,
            'post_content' => $src->post_content,
            'post_excerpt' => $src->post_excerpt,
        ));
        if (is_wp_error($temp_id) || !$temp_id) {
            return 0;
        }
        foreach (get_post_meta($src->ID) as $key => $values) {
            if ('_sspa_temp' === $key) {
                continue;
            }
            foreach ($values as $value) {
                add_post_meta($temp_id, $key, maybe_unserialize($value));
            }
        }
        update_post_meta($temp_id, '_sspa_temp', '1');
        return (int) $temp_id;
    }

    public static function create_temp_order() {
        if (!function_exists('wc_create_order')) {
            return 0;
        }
        $product = get_posts(array('numberposts' => 1, 'post_type' => 'product', 'post_status' => 'publish'));
        $order = wc_create_order();
        if (is_wp_error($order)) {
            return 0;
        }
        if ($product) {
            $wc_product = wc_get_product($product[0]->ID);
            if ($wc_product) {
                $order->add_product($wc_product, 1);
            }
        }
        $order->set_address(array('first_name' => 'SSPA', 'last_name' => 'Temp', 'email' => 'sspa-temp@blackhole.invalid'), 'billing');
        $order->calculate_totals();
        $order->update_meta_data('_sspa_temp', '1');
        $order->set_status('pending');
        $order->save();
        return (int) $order->get_id();
    }

    public static function delete_temp($id, $is_order = false) {
        if (!$id) {
            return;
        }
        if ($is_order && function_exists('wc_get_order')) {
            $order = wc_get_order($id);
            if ($order && $order->get_meta('_sspa_temp')) {
                $order->delete(true);
            }
            return;
        }
        if (get_post_meta($id, '_sspa_temp', true)) {
            wp_delete_post($id, true);
        }
    }
}
