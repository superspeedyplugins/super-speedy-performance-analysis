<?php
defined('ABSPATH') || exit;

/**
 * Records a bounded, privacy-safe set of plugin changes and offers it to the next
 * administrator request. Recording is deliberately separate from running analysis:
 * updater requests stay fast and a person decides when the final update is complete.
 */
class SSPA_Change_Set {

    const OPTION = 'sspa_pending_change_set';
    const SCHEMA = 1;
    const MAX_PLUGINS = 50;
    const MAX_EVENTS_PER_PLUGIN = 10;

    private static $registered = false;
    private static $before_versions = array();

    public static function register() {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        add_action('activated_plugin', array(__CLASS__, 'activated'), 10, 1);
        add_action('deactivated_plugin', array(__CLASS__, 'deactivated'), 10, 1);
        add_filter('upgrader_pre_install', array(__CLASS__, 'before_update'), 10, 2);
        add_action('upgrader_process_complete', array(__CLASS__, 'after_update'), 10, 2);

        self::migrate_legacy_toggle();
    }

    public static function activated($plugin) {
        self::record($plugin, 'activated', '', self::plugin_version($plugin));
    }

    public static function deactivated($plugin) {
        self::record($plugin, 'deactivated', self::plugin_version($plugin), '');
    }

    /** Preserve the filter response exactly; this hook only snapshots old versions. */
    public static function before_update($response, $hook_extra) {
        if (!self::enabled()) {
            return $response;
        }
        foreach (self::plugins_from_hook($hook_extra) as $plugin) {
            self::$before_versions[$plugin] = self::plugin_version($plugin);
        }
        return $response;
    }

    public static function after_update($upgrader, $hook_extra) {
        if (!self::enabled() || !is_array($hook_extra)
            || 'plugin' !== (isset($hook_extra['type']) ? $hook_extra['type'] : '')
            || 'update' !== (isset($hook_extra['action']) ? $hook_extra['action'] : '')) {
            return;
        }
        foreach (self::plugins_from_hook($hook_extra) as $plugin) {
            $from = isset(self::$before_versions[$plugin]) ? self::$before_versions[$plugin] : '';
            self::record($plugin, 'updated', $from, self::plugin_version($plugin));
            unset(self::$before_versions[$plugin]);
        }
    }

    public static function enabled() {
        return (bool) sspa_get_option('plugin_update_detection');
    }

    /**
     * Add one event, coalescing repeated changes to the same plugin.
     *
     * @return array|null Current change set, or null when recording is disabled/ignored.
     */
    public static function record($plugin, $action, $from_version = '', $to_version = '') {
        if (!self::enabled() || (defined('SSPA_PROFILED_REQUEST') && SSPA_PROFILED_REQUEST)) {
            return null;
        }

        $identity = self::plugin_identity($plugin);
        if (!$identity || 'super-speedy-performance-analysis' === $identity['slug']) {
            return null;
        }
        $action = sanitize_key($action);
        if (!in_array($action, array('activated', 'deactivated', 'updated'), true)) {
            return null;
        }

        $now = gmdate('c');
        $state = get_option(self::OPTION, array());
        if (!is_array($state) || self::SCHEMA !== (isset($state['schema']) ? (int) $state['schema'] : 0)) {
            $state = array(
                'schema' => self::SCHEMA,
                'id' => wp_generate_uuid4(),
                'first_detected_at' => $now,
                'last_detected_at' => $now,
                'snoozed_until' => 0,
                'changes' => array(),
            );
        }

        $slug = $identity['slug'];
        $existing = isset($state['changes'][$slug]) && is_array($state['changes'][$slug])
            ? $state['changes'][$slug] : array();
        $events = isset($existing['events']) && is_array($existing['events']) ? $existing['events'] : array();
        $event = array(
            'action' => $action,
            'from_version' => self::safe_version($from_version),
            'to_version' => self::safe_version($to_version),
            'detected_at' => $now,
        );
        $events[] = $event;
        $events = array_slice($events, -self::MAX_EVENTS_PER_PLUGIN);

        $first_from = isset($existing['from_version']) ? $existing['from_version'] : $event['from_version'];
        if ('' === $first_from && '' !== $event['from_version']) {
            $first_from = $event['from_version'];
        }
        $state['changes'][$slug] = array(
            'slug' => $slug,
            'plugin_file' => $identity['file'],
            'action' => $action,
            'from_version' => $first_from,
            'to_version' => $event['to_version'],
            'detected_at' => $now,
            'events' => $events,
        );

        // Keep the oldest entries if a pathological updater reports more than fifty
        // plugins. The visible record remains bounded and does not grow an autoload row.
        $state['changes'] = array_slice($state['changes'], 0, self::MAX_PLUGINS, true);
        $state['last_detected_at'] = $now;
        $state['snoozed_until'] = 0;
        update_option(self::OPTION, $state, false);
        return $state;
    }

    /** @return array|null */
    public static function pending($include_snoozed = false) {
        if (!self::enabled()) {
            return null;
        }
        self::migrate_legacy_toggle();
        $state = get_option(self::OPTION, array());
        if (!is_array($state) || empty($state['id']) || empty($state['changes'])) {
            return null;
        }
        if (!$include_snoozed && !empty($state['snoozed_until']) && (int) $state['snoozed_until'] > time()) {
            return null;
        }
        return $state;
    }

    public static function snooze($change_set_id, $seconds = HOUR_IN_SECONDS) {
        $state = self::pending(true);
        if (!$state || !hash_equals((string) $state['id'], (string) $change_set_id)) {
            return false;
        }
        $state['snoozed_until'] = time() + max(60, (int) $seconds);
        update_option(self::OPTION, $state, false);
        return true;
    }

    public static function consume($change_set_id) {
        $state = get_option(self::OPTION, array());
        if (!is_array($state) || empty($state['id'])
            || !hash_equals((string) $state['id'], (string) $change_set_id)) {
            return false;
        }
        delete_option(self::OPTION);
        return true;
    }

    public static function dismiss($change_set_id = '') {
        $state = get_option(self::OPTION, array());
        if ($change_set_id && (!is_array($state) || empty($state['id'])
            || !hash_equals((string) $state['id'], (string) $change_set_id))) {
            return false;
        }
        delete_option(self::OPTION);
        delete_transient('sspa_plugin_toggled');
        return true;
    }

    /** Privacy-safe subset embedded in one run. */
    public static function context($state) {
        if (!is_array($state) || empty($state['id']) || empty($state['changes'])) {
            return array();
        }
        $changes = array();
        foreach ((array) $state['changes'] as $change) {
            if (!is_array($change) || empty($change['slug'])) {
                continue;
            }
            $changes[] = array(
                'slug' => sanitize_key($change['slug']),
                'action' => sanitize_key(isset($change['action']) ? $change['action'] : ''),
                'from_version' => self::safe_version(isset($change['from_version']) ? $change['from_version'] : ''),
                'to_version' => self::safe_version(isset($change['to_version']) ? $change['to_version'] : ''),
            );
        }
        return array(
            'id' => sanitize_text_field($state['id']),
            'first_detected_at' => sanitize_text_field(isset($state['first_detected_at']) ? $state['first_detected_at'] : ''),
            'last_detected_at' => sanitize_text_field(isset($state['last_detected_at']) ? $state['last_detected_at'] : ''),
            'changes' => $changes,
        );
    }

    private static function plugins_from_hook($hook_extra) {
        if (!is_array($hook_extra)) {
            return array();
        }
        $plugins = array();
        if (!empty($hook_extra['plugin'])) {
            $plugins[] = $hook_extra['plugin'];
        }
        if (!empty($hook_extra['plugins']) && is_array($hook_extra['plugins'])) {
            $plugins = array_merge($plugins, $hook_extra['plugins']);
        }
        $out = array();
        foreach ($plugins as $plugin) {
            $identity = self::plugin_identity($plugin);
            if ($identity) {
                $out[$identity['file']] = $identity['file'];
            }
        }
        return array_values($out);
    }

    private static function plugin_identity($plugin) {
        $plugin = wp_normalize_path((string) $plugin);
        if (0 === strpos($plugin, wp_normalize_path(WP_PLUGIN_DIR) . '/')) {
            $plugin = plugin_basename($plugin);
        }
        $plugin = ltrim($plugin, '/');
        if (false !== strpos($plugin, '..') || !preg_match('#^[A-Za-z0-9._/-]+\.php$#', $plugin)) {
            return null;
        }
        $dir = dirname($plugin);
        $slug = '.' !== $dir ? $dir : basename($plugin, '.php');
        $slug = sanitize_key(basename($slug));
        return $slug ? array('file' => $plugin, 'slug' => $slug) : null;
    }

    private static function plugin_version($plugin) {
        $identity = self::plugin_identity($plugin);
        if (!$identity) {
            return '';
        }
        $path = WP_PLUGIN_DIR . '/' . $identity['file'];
        if (!is_file($path)) {
            return '';
        }
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $data = get_plugin_data($path, false, false);
        return self::safe_version(isset($data['Version']) ? $data['Version'] : '');
    }

    private static function safe_version($version) {
        $version = trim(sanitize_text_field((string) $version));
        return preg_match('/^[0-9A-Za-z][0-9A-Za-z.+_-]{0,63}$/', $version) ? $version : '';
    }

    private static function migrate_legacy_toggle() {
        $legacy = get_transient('sspa_plugin_toggled');
        if (!is_array($legacy) || empty($legacy['slug']) || empty($legacy['action'])) {
            return;
        }
        delete_transient('sspa_plugin_toggled');
        $slug = sanitize_key($legacy['slug']);
        $action = sanitize_key($legacy['action']);
        if (!$slug || !in_array($action, array('activated', 'deactivated'), true)) {
            return;
        }
        self::record($slug . '/' . $slug . '.php', $action);
    }
}
