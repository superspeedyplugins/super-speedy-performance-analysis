<?php
defined('ABSPATH') || exit;

/** Administrator recipients for synthetic work and signed profiling requests. */
class SSPA_Mail {
    private static $depth = 0;

    public static function register() {
        add_filter('wp_mail', array(__CLASS__, 'recipients'), PHP_INT_MAX);
    }

    public static function owner_id() {
        $id = get_current_user_id();
        if ($id && user_can($id, 'manage_options')) {
            return $id;
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
                throw new RuntimeException($saved->get_error_message());
            }
        }
    }

    public static function address($tag) {
        $admin = (string) get_option('admin_email');
        if (!is_email($admin)) {
            throw new RuntimeException('Performance Analysis requires a valid site administrator email address.');
        }
        $at = strrpos($admin, '@');
        return substr($admin, 0, $at) . '+sspa-perf-' . sanitize_key((string) $tag) . substr($admin, $at);
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

    public static function recipients($atts) {
        if (!self::$depth && empty($GLOBALS['sspa_capture'])) {
            return $atts;
        }
        $atts['to'] = array(self::address('analysis'));
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
        if (realpath($file) === realpath(ABSPATH . WPINC . '/pluggable.php')) {
            return '';
        }
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $owner = basename(dirname($file)) . '/' . basename($file);
        foreach (get_plugins() as $path => $plugin) {
            $base = dirname($path);
            $root = realpath(WP_PLUGIN_DIR . '/' . ('.' === $base ? $path : $base));
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

    public static function notice() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $warning = self::warning();
        if ($warning) {
            echo '<div class="notice notice-warning"><p>' . esc_html($warning) . '</p></div>';
        }
    }
}
