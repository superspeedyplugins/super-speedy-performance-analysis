<?php
defined('ABSPATH') || exit;

/**
 * Installs, verifies and removes the two helper files that must live outside the plugin
 * folder: the mu-loader and the conditional db.php drop-in. Both are generated from
 * templates with the secret and plugin path stamped in, and carry a signature line so we
 * only ever delete files we wrote. Retains restoration only for files held by versions before 0.43.4.
 */
class SSPA_Helper_Files {

    const SIGNATURE = 'Super Speedy Performance Analysis';
    const HOLD_FILE = 'db.php.sspa-hold';

    public static function mu_path() {
        return SSPA_MU_DIR . '/sspa-loader.php';
    }

    public static function dropin_path() {
        return SSPA_CONTENT_DIR . '/db.php';
    }

    private static function generate($template) {
        $content = SSPA_Filesystem::get_contents(SSPA_PLUGIN_DIR . $template);
        if ($content === false) {
            return false;
        }
        // The installed files carry THEIR OWN version, not the plugin's: a patch release
        // that touches neither of them must not rewrite them. The byte comparison in
        // health() still forces a rewrite when the secret or the plugin path changes,
        // which is a different thing and must keep working.
        return str_replace(
            array('%%SSPA_VERSION%%', '%%SSPA_MU_VERSION%%', '%%SSPA_DROPIN_VERSION%%', '%%SSPA_SECRET%%', '%%SSPA_PLUGIN_DIR%%'),
            array(SSPA_VERSION, SSPA_MU_VERSION, SSPA_DROPIN_VERSION, SSPA_Token::secret(), addslashes(trailingslashit(SSPA_PLUGIN_DIR))),
            $content
        );
    }

    private static function is_ours($path) {
        if (!file_exists($path)) {
            return false;
        }
        $head = SSPA_Filesystem::get_contents($path, false, null, 0, 512);
        return $head !== false && strpos($head, self::SIGNATURE) !== false;
    }

    /**
     * @return string ours | absent | qm | foreign
     */
    public static function dropin_status() {
        $path = self::dropin_path();
        if (!file_exists($path) && !is_link($path)) {
            return 'absent';
        }
        if (self::is_ours($path)) {
            return 'ours';
        }
        $head = is_readable($path) ? (string) SSPA_Filesystem::get_contents($path, false, null, 0, 2048) : '';
        if (stripos($head, 'query monitor') !== false) {
            return 'qm';
        }
        return 'foreign';
    }

    public static function dropin_owner_label() {
        $path = self::dropin_path();
        $head = is_readable($path) ? (string) SSPA_Filesystem::get_contents($path, false, null, 0, 2048) : '';
        if (preg_match('/Plugin Name:\s*(.+)/i', $head, $m)) {
            return trim($m[1]);
        }
        foreach (array('ludicrousdb' => 'LudicrousDB', 'w3 total cache' => 'W3 Total Cache', 'hyperdb' => 'HyperDB') as $needle => $label) {
            if (stripos($head, $needle) !== false) {
                return $label;
            }
        }
        return __('an unknown plugin', 'super-speedy-performance-analysis');
    }

    /**
     * Has the site forbidden plugins from writing files?
     *
     * DISALLOW_FILE_MODS is how an owner says "nothing may modify files here" - it is what
     * managed hosts set to stop plugin/theme installs and edits. Writing wp-content/db.php
     * and an mu-plugin is exactly the kind of modification it means to prevent, so this
     * plugin honours it, the same way Query Monitor gates its db.php symlink on it.
     *
     * CREATION only. Removal is deliberately NOT gated: taking our own files back out
     * restores the site to how it was, and refusing to do that would strand a drop-in the
     * owner can no longer get rid of from the UI.
     */
    public static function file_mods_blocked() {
        return defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS;
    }

    /** Refresh the database helper; the loader requires explicit consent. */
    public static function ensure_installed($enable_mu = false) {
        $results = array('mu' => false, 'dropin' => false);

        if (self::file_mods_blocked() || (is_multisite() && !current_user_can('manage_network_options'))) {
            return $results;
        }

        if ($enable_mu) {
            if ((file_exists(self::mu_path()) || is_link(self::mu_path())) && !self::is_ours(self::mu_path())) {
                return $results;
            }
            $mu_content = self::generate('mu/sspa-loader.php');
            if ($mu_content !== false) {
                if (!is_dir(SSPA_MU_DIR)) {
                    wp_mkdir_p(SSPA_MU_DIR);
                }
                $current = file_exists(self::mu_path()) ? SSPA_Filesystem::get_contents(self::mu_path()) : null;
                if ($current === $mu_content) {
                    $results['mu'] = true;
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- a pre-flight check, not a write; initialising WP_Filesystem just to answer it can prompt for FTP credentials.
                } elseif (SSPA_Filesystem::direct()->is_writable(SSPA_MU_DIR) || (file_exists(self::mu_path()) && SSPA_Filesystem::direct()->is_writable(self::mu_path()))) {
                    $results['mu'] = (bool) SSPA_Filesystem::put_contents(self::mu_path(), $mu_content);
                }
            }

        }

        $status = self::dropin_status();
        if ($status === 'absent' || $status === 'ours') {
            $dropin_content = self::generate('dropins/db.php');
            if ($dropin_content !== false) {
                $current = file_exists(self::dropin_path()) ? SSPA_Filesystem::get_contents(self::dropin_path()) : null;
                if ($current === $dropin_content) {
                    $results['dropin'] = true;
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- a pre-flight check, not a write; initialising WP_Filesystem just to answer it can prompt for FTP credentials.
                } elseif (SSPA_Filesystem::direct()->is_writable(SSPA_CONTENT_DIR) || (file_exists(self::dropin_path()) && SSPA_Filesystem::direct()->is_writable(self::dropin_path()))) {
                    $results['dropin'] = (bool) SSPA_Filesystem::put_contents(self::dropin_path(), $dropin_content);
                }
            }
        }

        return $results;
    }

    /** Recovery only: versions before 0.43.4 could displace a foreign db.php. */
    public static function restore_legacy_db_hold() {
        $hold = SSPA_CONTENT_DIR . '/' . self::HOLD_FILE;
        if (!file_exists($hold) && !is_link($hold)) {
            delete_option('sspa_dropin_hold');
            return true;
        }
        $path = self::dropin_path();
        if (file_exists($path) || is_link($path)) {
            if (!self::is_ours($path)) {
                return false; // something else owns db.php now - do not touch it
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removes only a drop-in is_ours() has confirmed we wrote, mirroring Query Monitor's class_exists('QM_DB') ownership check.
            SSPA_Filesystem::direct()->delete($path);
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic restore of the held foreign drop-in.
        $ok = SSPA_Filesystem::direct()->move($hold, $path, false);
        if ($ok) {
            delete_option('sspa_dropin_hold');
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($path, true); // discard the restored path from this SAPI's opcache
            }
        }
        return $ok;
    }

    /**
     * Crash safety: if a hold exists but no run is active, restore and notify. Hooked on
     * plugins_loaded (cheap option check on admin requests only).
     */
    public static function stale_hold_check() {
        $oc_hold = get_option('sspa_oc_hold');
        $db_hold = get_option('sspa_dropin_hold');
        if (!$oc_hold && !$db_hold) {
            // The cached runtime flags said a hold exists, but neither record does (it was removed
            // by another process). Refresh the flags so ordinary requests stop running this check.
            SSPA_Bootstrap::update_runtime_flag('sspa_dropin_hold');
            SSPA_Bootstrap::update_runtime_flag('sspa_oc_hold');
            return;
        }
        $active = class_exists('SSPA_Run_Controller') ? SSPA_Run_Controller::active_run_id() : 0;
        if ($oc_hold && !$active) {
            self::restore_legacy_cache_hold();
        }
        if (!$db_hold) {
            return;
        }
        if (!$active) {
            update_option('sspa_dropin_restore_notice', self::restore_legacy_db_hold() ? 'restored' : 'failed', false);
        }
    }

    /** The drop-in recovery result, shown once on the Plugins screen or Performance Analysis's own screen. */
    public static function restore_notice() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!current_user_can('manage_options') || !$screen || !('plugins' === $screen->id || preg_match('/(?:^|_page_)sspa$/', $screen->id))) {
            return;
        }
        $result = get_option('sspa_dropin_restore_notice');
        if (!$result) {
            return;
        }
        delete_option('sspa_dropin_restore_notice');
        $restored = 'restored' === $result;
        $msg = $restored
            ? __('Super Speedy Performance Analysis: a previous analysis ended unexpectedly; your original db.php drop-in has been restored.', 'super-speedy-performance-analysis')
            : __('Super Speedy Performance Analysis: could not restore your original db.php drop-in (db.php.sspa-hold still exists in wp-content). Please restore it manually.', 'super-speedy-performance-analysis');
        echo '<div class="notice notice-' . ($restored ? 'warning' : 'error') . ' is-dismissible"><p>' . esc_html($msg) . '</p></div>';
    }

    /** Recovery only: return object-cache.php held by versions before 0.43.4. */
    public static function restore_legacy_cache_hold() {
        $path = SSPA_CONTENT_DIR . '/object-cache.php';
        $hold = SSPA_CONTENT_DIR . '/object-cache.php.sspa-hold';
        if (!file_exists($hold) && !is_link($hold)) {
            delete_option('sspa_oc_hold');
            return true;
        }
        if (file_exists($path) || is_link($path)) {
            return false; // something recreated object-cache.php; do not clobber it
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- atomic restore of the held foreign drop-in.
        $ok = SSPA_Filesystem::direct()->move($hold, $path, false);
        if ($ok) {
            delete_option('sspa_oc_hold');
        }
        return $ok;
    }

    public static function enable_profiler() {
        if (is_multisite() && !current_user_can('manage_network_options')) {
            return new WP_Error('sspa_network_permission', __('Only a network administrator may install network-wide profiler files.', 'super-speedy-performance-analysis'));
        }
        if (self::file_mods_blocked()) {
            return new WP_Error('sspa_file_mods_disallowed', __('This site sets DISALLOW_FILE_MODS, which forbids installing the profiler loader.', 'super-speedy-performance-analysis'));
        }
        $result = self::ensure_installed(true);
        if (!$result['mu']) {
            return new WP_Error('sspa_loader_write', __('Could not install the profiler loader; check mu-plugins permissions and any existing file at that path.', 'super-speedy-performance-analysis'));
        }
        if ('ours' === self::dropin_status() && !$result['dropin']) {
            return new WP_Error('sspa_dropin_write', __('Could not refresh the profiler db.php; check wp-content permissions.', 'super-speedy-performance-analysis'));
        }
        update_option('sspa_profiler_enabled', true, false);
        return true;
    }

    public static function disable_profiler() {
        if (is_multisite() && !current_user_can('manage_network_options')) {
            return new WP_Error('sspa_network_permission', __('Only a network administrator may remove network-wide profiler files.', 'super-speedy-performance-analysis'));
        }
        if (self::is_ours(self::mu_path()) && !SSPA_Filesystem::direct()->delete(self::mu_path())) {
            return new WP_Error('sspa_loader_remove', __('Could not remove the profiler loader; check its file permissions.', 'super-speedy-performance-analysis'));
        }
        if (self::is_ours(self::dropin_path()) && !SSPA_Filesystem::direct()->delete(self::dropin_path())) {
            return new WP_Error('sspa_dropin_remove', __('Could not remove the profiler db.php; check its file permissions.', 'super-speedy-performance-analysis'));
        }
        update_option('sspa_profiler_enabled', false, false);
        return true;
    }

    /** Record existing pre-consent installs without writing or recreating a loader. */
    public static function migrate_profiler_consent() {
        if (false === get_option('sspa_profiler_enabled', null)) {
            return;
        }
        if (null === get_option('sspa_profiler_enabled', null)) {
            update_option('sspa_profiler_enabled', self::is_ours(self::mu_path()), false);
        }
    }

    /**
     * Versions before 0.55.1 installed db.php on activation. Where the administrator never
     * enabled the profiler, take that file back out on upgrade (only our own, signature-checked).
     */
    public static function remove_unconsented_dropin() {
        if (get_option('sspa_profiler_enabled') || !self::is_ours(self::dropin_path())) {
            return;
        }
        SSPA_Filesystem::direct()->delete(self::dropin_path());
    }

    public static function remove_all() {
        if (is_multisite() && !current_user_can('manage_network_options')) { return; }
        update_option('sspa_profiler_enabled', false, false);
        if (self::is_ours(self::mu_path())) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removes only the mu-loader this plugin wrote, verified by its signature line.
            SSPA_Filesystem::direct()->delete(self::mu_path());
        }
        if (self::is_ours(self::dropin_path())) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removes only a drop-in is_ours() has confirmed we wrote.
            SSPA_Filesystem::direct()->delete(self::dropin_path());
        }
        self::restore_legacy_db_hold();
        self::restore_legacy_cache_hold();
    }

    /** True when the Query Monitor PLUGIN is active. Its db.php can outlive deactivation. */
    public static function qm_plugin_active() {
        if (in_array('query-monitor/query-monitor.php', (array) get_option('active_plugins', array()), true)) {
            return true;
        }
        if (is_multisite()) {
            $network = (array) get_site_option('active_sitewide_plugins', array());
            if (isset($network['query-monitor/query-monitor.php'])) {
                return true;
            }
        }
        return false;
    }

    /** A Query Monitor db.php whose plugin is deactivated: an orphan that blocks our capture. */
    public static function dropin_is_stale_qm() {
        return self::dropin_status() === 'qm' && !self::qm_plugin_active();
    }

    public static function health() {
        $mu_content = self::generate('mu/sspa-loader.php');
        $mu_exists = file_exists(self::mu_path());
        $dropin_stale = 'ours' === self::dropin_status() && SSPA_Filesystem::get_contents(self::dropin_path()) !== self::generate('dropins/db.php');
        $mu_ok = $mu_exists && SSPA_Filesystem::get_contents(self::mu_path()) === $mu_content && !$dropin_stale;

        // Why it is not installed decides what the user is told to do about it. "Not
        // writable" sends someone to chmod a directory that may be fine.
        $mu_reason = 'ok';
        if (!$mu_ok) {
            if (self::file_mods_blocked()) {
                $mu_reason = 'file_mods_blocked';
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- a pre-flight check, not a write.
            } elseif (!SSPA_Filesystem::direct()->is_writable(SSPA_MU_DIR) && !($mu_exists && SSPA_Filesystem::direct()->is_writable(self::mu_path()))) {
                $mu_reason = 'not_writable';
            } else {
                $mu_reason = ($mu_exists || $dropin_stale) ? 'stale' : 'disabled';
            }
        }

        return array(
            'mu' => $mu_ok,
            'mu_reason' => $mu_reason,
            // Distinct from "not writable": the owner has forbidden file writes, which is a
            // configuration choice, not a permissions fault. The UI must say which it is.
            'file_mods_blocked' => self::file_mods_blocked(),
            'dropin' => self::dropin_status(),
            'dropin_stale' => $dropin_stale,
            'dropin_owner' => in_array(self::dropin_status(), array('qm', 'foreign'), true) ? self::dropin_owner_label() : null,
            'hold' => (bool) get_option('sspa_dropin_hold'),
            'stale_qm' => self::dropin_is_stale_qm(),
            'qm_active' => self::qm_plugin_active(),
        );
    }
}
