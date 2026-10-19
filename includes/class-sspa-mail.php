<?php
defined('ABSPATH') || exit;

/** Administrator recipients for synthetic work and signed profiling requests. */
class SSPA_Mail {
    private static $depth = 0;

    public static function register() {
        add_filter('wp_mail', array(__CLASS__, 'recipients'), PHP_INT_MAX);
        add_action('phpmailer_init', array(__CLASS__, 'mailer_recipients'), PHP_INT_MAX);
        add_filter('woocommerce_email_classes', array(__CLASS__, 'email_classes'), PHP_INT_MAX);
        add_filter('woocommerce_email_headers', array(__CLASS__, 'email_headers'), PHP_INT_MAX, 4);
    }

    public static function email_classes($emails) {
        foreach ($emails as $email) {
            if (!empty($email->id)) {
                add_filter('woocommerce_email_recipient_' . $email->id, array(__CLASS__, 'order_recipient'), PHP_INT_MAX, 3);
            }
        }
        return $emails;
    }

    private static function marked($object) {
        return is_object($object) && method_exists($object, 'get_meta') && $object->get_meta('_sspa_temp');
    }

    public static function order_recipient($recipient, $object = null, $email = null) {
        if (!self::marked($object)) { return $recipient; }
        $billing = method_exists($object, 'get_billing_email') ? $object->get_billing_email() : '';
        return self::safe_recipient(array($billing));
    }

    public static function email_headers($headers, $id, $object = null, $email = null) {
        if (!self::marked($object)) { return $headers; }
        $atts = self::with_admin(static function() use ($headers) {
            return self::recipients(array('to' => array(), 'headers' => $headers));
        });
        return implode("\r\n", $atts['headers']);
    }

    public static function owner_id() {
        $id = get_current_user_id();
        if ($id && user_can($id, 'manage_options')) {
            return $id;
        }
        if (is_multisite()) {
            foreach (get_super_admins() as $login) {
                $admin = get_user_by('login', $login);
                if ($admin) { return (int) $admin->ID; }
            }
        }
        $admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
        if (!$admins) {
            throw new RuntimeException('Performance Analysis requires an administrator to own its temporary objects.');
        }
        return (int) $admins[0];
    }

    public static function own_product($id) {
        if ((int) get_post_field('post_author', $id) !== self::owner_id()) {
            $saved = wp_update_post(array('ID' => $id, 'post_author' => self::owner_id()), true);
            if (is_wp_error($saved)) {
                throw new RuntimeException(esc_html($saved->get_error_message()));
            }
        }
    }

    public static function address($tag) {
        $admin = (string) get_option('admin_email');
        if (!is_email($admin)) {
            throw new RuntimeException('Performance Analysis requires a valid site administrator email address.');
        }
        $at = strrpos($admin, '@');
        $address = substr($admin, 0, $at) . '+sspa-perf-' . sanitize_key((string) $tag) . substr($admin, $at);
        return strlen(strstr($address, '@', true)) <= 64 && is_email($address) ? $address : $admin;
    }

    /** Cover creation/deletion callbacks outside the signed measurement request too. */
    public static function with_admin($callback) {
        self::$depth++;
        try {
            return $callback();
        } finally {
            self::$depth--;
        }
    }

    private static function rewrites() {
        $flags = $GLOBALS['sspa_flags'] ?? array();
        return self::$depth || (empty($flags['real_save']) && (!empty($flags['mp']) || (!empty($flags['wp']) && !empty($flags['tid'])) || !empty($flags['ck']) || !empty($flags['wf'])));
    }

    public static function mailer_recipients($mailer) {
        if (!self::rewrites()) { return; }
        $to = self::safe_recipient(array_column($mailer->getToAddresses(), 0));
        $mailer->clearAllRecipients();
        $mailer->addAddress($to);
    }

    private static function safe_recipient($recipients) {
        $admin = (string) get_option('admin_email');
        $at = strrpos($admin, '@');
        $prefix = substr($admin, 0, $at) . '+sspa-perf-';
        foreach ((array) $recipients as $recipient) {
            if (is_string($recipient) && is_email($recipient) && 0 === strpos($recipient, $prefix) && substr($recipient, strrpos($recipient, '@')) === substr($admin, $at)) { return $recipient; }
        }
        return self::address('analysis');
    }

    public static function recipients($atts) {
        if (!self::rewrites()) {
            return $atts;
        }
        $atts['to'] = array(self::safe_recipient($atts['to']));
        $headers = is_array($atts['headers']) ? $atts['headers'] : explode("\n", str_replace("\r\n", "\n", (string) $atts['headers']));
        $safe = array();
        foreach ($headers as $header) {
            foreach (preg_split('/\r?\n/', $header) as $line) {
                if (!preg_match('/^\s*(?:cc|bcc)\s*:/i', $line)) {
                    $safe[] = $line;
                }
            }
        }
        $atts['headers'] = $safe;
        return $atts;
    }

    /** A replacement can bypass filters even if an earlier send honoured them. */
    public static function warning() {
        if (!function_exists('wp_mail')) {
            return '';
        }
        $file = (new ReflectionFunction('wp_mail'))->getFileName();
        if (realpath($file) === realpath(SSPA_CORE_DIR . WPINC . '/pluggable.php')) {
            return '';
        }
        $owner = basename(dirname($file)) . '/' . basename($file);
        if (!function_exists('get_plugins')) {
            require_once SSPA_CORE_DIR . 'wp-admin/includes/plugin.php';
        }
        foreach (get_plugins() as $path => $plugin) {
            $base = dirname($path);
            $root = realpath(SSPA_PLUGINS_DIR . '/' . ('.' === $base ? $path : $base));
            if ($root && (realpath($file) === $root || 0 === strpos(realpath($file), $root . DIRECTORY_SEPARATOR))) {
                $owner = $plugin['Name'];
                break;
            }
        }
        return sprintf(
            /* translators: %s: plugin owning the replacement mail function. */
            __('%s replaces wp_mail(). Administrator recipient rewriting cannot be confirmed with this mailer. The analysis will proceed and emails may reach recipients chosen by its callbacks; send/queue time is unavailable when the mailer provides no completion hook.', 'super-speedy-performance-analysis'),
            $owner
        );
    }

    public static function is_pa_screen() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        return $screen && preg_match('/(?:^|_page_)sspa$/', $screen->id);
    }

    public static function notice() {
        if (!current_user_can('manage_options') || !self::is_pa_screen()) {
            return;
        }
        $warning = self::warning();
        if ($warning) {
            echo '<div class="notice notice-warning"><p>' . esc_html($warning) . '</p></div>';
        }
    }
}
