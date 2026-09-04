<?php
defined('ABSPATH') || exit;

/** Creates and removes the active-only generated MU traffic observer. */
class SSPA_Traffic_Helper {

    const SIGNATURE = 'Super Speedy Performance Analysis experimental traffic observer';
    const FILE = 'sspa-traffic-observer.php';
    const STOPPED_FILE = 'sspa-traffic-observer.stopped';
    const LOCK_OPTION = 'sspa_traffic_helper_lock';
    private static $lock_owner = '';

    public static function path() {
        return WPMU_PLUGIN_DIR . '/' . self::FILE;
    }

    public static function stopped_path() {
        return WPMU_PLUGIN_DIR . '/' . self::STOPPED_FILE;
    }

    public static function is_ours($path = null) {
        $path = $path ?: self::path();
        if (!is_file($path)) {
            return false;
        }
        $head = file_get_contents($path, false, null, 0, 512);
        return is_string($head) && strpos($head, self::SIGNATURE) !== false;
    }

    public static function install($config) {
        if (!self::lock()) {
            return new WP_Error('sspa_traffic_helper_busy', __('The traffic observer is being changed by another request. Try again.', 'super-speedy-performance-analysis'));
        }
        try {
            return self::install_unlocked($config);
        } finally {
            self::unlock();
        }
    }

    private static function install_unlocked($config) {
        if (is_multisite()) {
            return new WP_Error('sspa_traffic_multisite', __('The experimental traffic collector is not available on multisite yet because its MU observer must remain absent for inactive sites.', 'super-speedy-performance-analysis'));
        }
        // The site owner has forbidden plugins from writing files. Installing an mu-plugin is
        // exactly that, so refuse - and say so, rather than reporting it as a write failure.
        if (SSPA_Helper_Files::file_mods_blocked()) {
            return new WP_Error('sspa_file_mods_disallowed', __('This site sets DISALLOW_FILE_MODS, which forbids plugins from writing files, so the traffic observer cannot be installed.', 'super-speedy-performance-analysis'));
        }
        if (!is_dir(WPMU_PLUGIN_DIR) && !wp_mkdir_p(WPMU_PLUGIN_DIR)) {
            return new WP_Error('sspa_traffic_mu_dir', __('Could not create the mu-plugins directory.', 'super-speedy-performance-analysis'));
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- a pre-flight check, not a write; see class-sspa-helper-files.php.
        if (!is_writable(WPMU_PLUGIN_DIR) && !(is_file(self::path()) && is_writable(self::path()))) {
            return new WP_Error('sspa_traffic_mu_write', __('The mu-plugins directory is not writable, so the active-only traffic observer cannot be installed.', 'super-speedy-performance-analysis'));
        }
        if (is_file(self::path()) && !self::is_ours(self::path())) {
            return new WP_Error('sspa_traffic_mu_foreign', __('A different file already uses the traffic observer filename. It was not replaced.', 'super-speedy-performance-analysis'));
        }

        $template = file_get_contents(SSPA_PLUGIN_DIR . 'mu/sspa-traffic-observer.php');
        if (!is_string($template)) {
            return new WP_Error('sspa_traffic_template', __('The traffic observer template is missing.', 'super-speedy-performance-analysis'));
        }
        $safe = array(
            'blog_id' => (int) get_current_blog_id(),
            'collection_id' => (int) $config['collection_id'],
            'collect_until' => (int) $config['collect_until'],
            'outcomes_until' => (int) $config['outcomes_until'],
            'event_id_stop' => (int) $config['event_id_stop'],
            'origin_sample_modulus' => max(1, (int) $config['origin_sample_modulus']),
            'observer_version' => SSPA_Traffic_Codes::OBSERVER_VERSION,
            'key_option' => sanitize_key($config['key_option']),
            'table' => SSPA_Schema::table('traffic_events'),
			'endpoint_table' => SSPA_Schema::table('traffic_endpoint_observations'),
			'endpoint_id_stop' => max(1, isset($config['endpoint_id_stop']) ? (int) $config['endpoint_id_stop'] : 4294967295),
            'observer_path' => self::path(),
            'stopped_path' => self::stopped_path(),
        );
        $content = str_replace(
            array('/* %%SSPA_TRAFFIC_CONFIG_ASSIGNMENT%% */', '%%SSPA_TRAFFIC_PLUGIN_DIR%%'),
            array('$sspa_traffic_config = ' . var_export($safe, true) . ';', addslashes(trailingslashit(SSPA_PLUGIN_DIR))),
            $template
        );

        if (is_file(self::stopped_path())) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removes only this plugin's own observer stop-flag.
            unlink(self::stopped_path());
        }
        $temporary = self::path() . '.tmp-' . wp_generate_password(8, false, false);
        if (file_put_contents($temporary, $content, LOCK_EX) !== strlen($content)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- cleans up our own temporary file after a failed write.
            @unlink($temporary);
            return new WP_Error('sspa_traffic_mu_write', __('Could not write the traffic observer.', 'super-speedy-performance-analysis'));
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- sets 0644 on our own temporary file before it is renamed into place.
        @chmod($temporary, 0644);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic install of the observer mu-plugin: write to a temporary file, then rename, so no request ever sees a half-written observer.
        if (!rename($temporary, self::path())) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- cleans up our own temporary file after a failed rename.
            @unlink($temporary);
            return new WP_Error('sspa_traffic_mu_rename', __('Could not activate the traffic observer atomically.', 'super-speedy-performance-analysis'));
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate(self::path(), true);
        }
        return true;
    }

    public static function remove($force = false) {
        if ($force) {
            self::unlock();
            return self::remove_unlocked();
        }
        if (!self::lock()) {
            return false;
        }
        try {
            return self::remove_unlocked();
        } finally {
            self::unlock();
        }
    }

    private static function remove_unlocked() {
        $removed = true;
        if (self::is_ours(self::path())) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removes only the observer mu-plugin this plugin wrote.
            $removed = @unlink(self::path());
        }
        if (is_file(self::stopped_path())) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removes only this plugin's own observer stop-flag.
            @unlink(self::stopped_path());
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate(self::path(), true);
        }
        return $removed;
    }

    private static function lock() {
        self::$lock_owner = SSPA_Atomic_Claim::acquire(self::LOCK_OPTION, 30);
        return (bool) self::$lock_owner;
    }

    private static function unlock() {
        if (self::$lock_owner) {
            SSPA_Atomic_Claim::release(self::LOCK_OPTION, self::$lock_owner);
            self::$lock_owner = '';
        }
    }

    public static function state() {
        if (self::is_ours(self::path())) {
            return 'active';
        }
        if (is_file(self::stopped_path())) {
            $reason = sanitize_key((string) file_get_contents(self::stopped_path()));
            return in_array($reason, array('event_limit', 'database_error'), true) ? $reason : 'stopped';
        }
        return 'absent';
    }
}
