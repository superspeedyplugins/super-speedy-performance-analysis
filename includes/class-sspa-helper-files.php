<?php
defined('ABSPATH') || exit;

/**
 * Installs, verifies and removes the two helper files that must live outside the plugin
 * folder: the mu-loader and the conditional db.php drop-in. Both are generated from
 * templates with the secret and plugin path stamped in, and carry a signature line so we
 * only ever delete files we wrote. Also owns the temporary-swap ("hold") mechanics for
 * foreign db.php drop-ins, with crash-safe restoration.
 */
class SSPA_Helper_Files {

    const SIGNATURE = 'Super Speedy Performance Analysis';
    const HOLD_FILE = 'db.php.sspa-hold';

    public static function mu_path() {
        return WPMU_PLUGIN_DIR . '/sspa-loader.php';
    }

    public static function dropin_path() {
        return WP_CONTENT_DIR . '/db.php';
    }

    private static function generate($template) {
        $content = file_get_contents(SSPA_PLUGIN_DIR . $template);
        if ($content === false) {
            return false;
        }
        return str_replace(
            array('%%SSPA_VERSION%%', '%%SSPA_SECRET%%', '%%SSPA_PLUGIN_DIR%%'),
            array(SSPA_VERSION, SSPA_Token::secret(), addslashes(trailingslashit(SSPA_PLUGIN_DIR))),
            $content
        );
    }

    private static function is_ours($path) {
        if (!file_exists($path)) {
            return false;
        }
        $head = file_get_contents($path, false, null, 0, 512);
        return $head !== false && strpos($head, self::SIGNATURE) !== false;
    }

    /**
     * @return string ours | absent | qm | foreign
     */
    public static function dropin_status() {
        $path = self::dropin_path();
        if (!file_exists($path)) {
            return 'absent';
        }
        if (self::is_ours($path)) {
            return 'ours';
        }
        $head = (string) file_get_contents($path, false, null, 0, 2048);
        if (stripos($head, 'query monitor') !== false) {
            return 'qm';
        }
        return 'foreign';
    }

    public static function dropin_owner_label() {
        $head = (string) file_get_contents(self::dropin_path(), false, null, 0, 2048);
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

    /**
     * Idempotent: (re)writes helper files when missing or stale. Called on activation and
     * on admin_init (cheap: two filemtime-free content compares only when files exist).
     *
     * Returns both false when DISALLOW_FILE_MODS is set. health() reports that separately
     * from "not writable" so the reason the user is shown is the real one.
     */
    public static function ensure_installed() {
        $results = array('mu' => false, 'dropin' => false);

        if (self::file_mods_blocked()) {
            return $results;
        }

        $mu_content = self::generate('mu/sspa-loader.php');
        if ($mu_content !== false) {
            if (!is_dir(WPMU_PLUGIN_DIR)) {
                wp_mkdir_p(WPMU_PLUGIN_DIR);
            }
            $current = file_exists(self::mu_path()) ? file_get_contents(self::mu_path()) : null;
            if ($current === $mu_content) {
                $results['mu'] = true;
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- a pre-flight check, not a write; initialising WP_Filesystem just to answer it can prompt for FTP credentials.
            } elseif (is_writable(WPMU_PLUGIN_DIR) || (file_exists(self::mu_path()) && is_writable(self::mu_path()))) {
                $results['mu'] = (bool) file_put_contents(self::mu_path(), $mu_content);
            }
        }

        $status = self::dropin_status();
        if ($status === 'absent' || $status === 'ours') {
            $dropin_content = self::generate('dropins/db.php');
            if ($dropin_content !== false) {
                $current = file_exists(self::dropin_path()) ? file_get_contents(self::dropin_path()) : null;
                if ($current === $dropin_content) {
                    $results['dropin'] = true;
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- a pre-flight check, not a write; initialising WP_Filesystem just to answer it can prompt for FTP credentials.
                } elseif (is_writable(WP_CONTENT_DIR) || (file_exists(self::dropin_path()) && is_writable(self::dropin_path()))) {
                    $results['dropin'] = (bool) file_put_contents(self::dropin_path(), $dropin_content);
                }
            }
        }

        return $results;
    }

    /**
     * Temporarily displace a foreign db.php for the duration of a run (user opted in with
     * the caching-off warning). Restored by restore_held_dropin().
     */
    public static function hold_foreign_dropin() {
        $path = self::dropin_path();
        if (self::file_mods_blocked()) {
            return false;
        }
        if (self::dropin_status() !== 'foreign' && self::dropin_status() !== 'qm') {
            return false;
        }
        $hold = WP_CONTENT_DIR . '/' . self::HOLD_FILE;
        if (file_exists($hold)) {
            return false; // never overwrite an existing hold
        }
        $owner = self::dropin_owner_label(); // read before the rename
        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- atomic displacement of a foreign db.php drop-in. rename() is atomic; WP_Filesystem::move() is not, and a half-swapped db.php takes the site down. Same approach as Query Monitor's drop-in handling.
        if (!rename($path, $hold)) {
            return false;
        }
        update_option('sspa_dropin_hold', array('owner' => $owner, 'time' => time()), false);
        $content = self::generate('dropins/db.php');
        if ($content === false || !file_put_contents($path, $content)) {
            // Roll straight back if we could not install ours.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- crash-safe rollback of the swap above; must not depend on WP_Filesystem being initialisable.
            rename($hold, $path);
            delete_option('sspa_dropin_hold');
            return false;
        }
        return true;
    }

    public static function restore_held_dropin() {
        $hold = WP_CONTENT_DIR . '/' . self::HOLD_FILE;
        if (!file_exists($hold)) {
            delete_option('sspa_dropin_hold');
            return true;
        }
        $path = self::dropin_path();
        if (file_exists($path)) {
            if (!self::is_ours($path)) {
                return false; // something else owns db.php now - do not touch it
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removes only a drop-in is_ours() has confirmed we wrote, mirroring Query Monitor's class_exists('QM_DB') ownership check.
            unlink($path);
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic restore of the held foreign drop-in.
        $ok = rename($hold, $path);
        if ($ok) {
            delete_option('sspa_dropin_hold');
        }
        return $ok;
    }

    /**
     * Crash safety: if a hold exists but no run is active, restore and notify. Hooked on
     * plugins_loaded (cheap option check on admin requests only).
     */
    public static function stale_hold_check() {
        $active = class_exists('SSPA_Run_Controller') ? SSPA_Run_Controller::active_run_id() : 0;
        if (get_option('sspa_oc_hold') && !$active) {
            self::restore_object_cache();
        }
        if (!get_option('sspa_dropin_hold')) {
            return;
        }
        if (!$active) {
            $restored = self::restore_held_dropin();
            add_action('admin_notices', function () use ($restored) {
                $msg = $restored
                    ? __('Super Speedy Performance Analysis: a previous analysis ended unexpectedly; your original db.php drop-in has been restored.', 'super-speedy-performance-analysis')
                    : __('Super Speedy Performance Analysis: could not restore your original db.php drop-in (db.php.sspa-hold still exists in wp-content). Please restore it manually.', 'super-speedy-performance-analysis');
                echo '<div class="notice notice-' . ($restored ? 'warning' : 'error') . '"><p>' . esc_html($msg) . '</p></div>';
            });
        }
    }

    /**
     * Site-wide object-cache toggle fallback for cache-impact runs on hosts where our
     * db.php shim (and its per-request filter) is unavailable. Same hold pattern as the
     * db.php swap: crash-safe, restored on finish/fail and by the stale check.
     */
    public static function hold_object_cache() {
        $path = WP_CONTENT_DIR . '/object-cache.php';
        $hold = WP_CONTENT_DIR . '/object-cache.php.sspa-hold';
        if (self::file_mods_blocked()) {
            return false;
        }
        if (!file_exists($path) || file_exists($hold)) {
            return false;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- atomic displacement of the drop-in; see hold_foreign_dropin().
        if (!rename($path, $hold)) {
            return false;
        }
        update_option('sspa_oc_hold', time(), false);
        return true;
    }

    public static function restore_object_cache() {
        $path = WP_CONTENT_DIR . '/object-cache.php';
        $hold = WP_CONTENT_DIR . '/object-cache.php.sspa-hold';
        if (!file_exists($hold)) {
            delete_option('sspa_oc_hold');
            return true;
        }
        if (file_exists($path)) {
            return false; // something recreated object-cache.php; do not clobber it
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- atomic restore of the held foreign drop-in.
        $ok = rename($hold, $path);
        if ($ok) {
            delete_option('sspa_oc_hold');
        }
        return $ok;
    }

    public static function remove_all() {
        if (self::is_ours(self::mu_path())) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removes only the mu-loader this plugin wrote, verified by its signature line.
            unlink(self::mu_path());
        }
        if (self::is_ours(self::dropin_path())) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removes only a drop-in is_ours() has confirmed we wrote.
            unlink(self::dropin_path());
        }
        self::restore_held_dropin();
        self::restore_object_cache();
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

    /**
     * Replace an orphaned Query Monitor db.php with our conditional drop-in. The old file
     * is renamed, never deleted, and Query Monitor reinstates its own copy if reactivated.
     *
     * @return true|WP_Error
     */
    public static function replace_stale_qm_dropin() {
        if (self::file_mods_blocked()) {
            return new WP_Error('sspa_file_mods_disallowed', __('This site has DISALLOW_FILE_MODS enabled, so plugins may not write files. Remove wp-content/db.php by hand, or unset the constant.', 'super-speedy-performance-analysis'));
        }
        if (!self::dropin_is_stale_qm()) {
            return new WP_Error('sspa_not_stale', __('wp-content/db.php is not a leftover Query Monitor drop-in.', 'super-speedy-performance-analysis'));
        }
        $path = self::dropin_path();
        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- moves a stale Query Monitor drop-in aside atomically rather than deleting another plugin's file.
        if (!rename($path, WP_CONTENT_DIR . '/db.php.qm-stale-' . gmdate('Ymd-His'))) {
            return new WP_Error('sspa_rename_failed', __('Could not rename wp-content/db.php - check file permissions.', 'super-speedy-performance-analysis'));
        }
        $results = self::ensure_installed();
        if (empty($results['dropin'])) {
            return new WP_Error('sspa_install_failed', __('Renamed the old drop-in but could not install ours - wp-content is not writable.', 'super-speedy-performance-analysis'));
        }
        return true;
    }

    public static function health() {
        $mu_content = self::generate('mu/sspa-loader.php');
        $mu_ok = file_exists(self::mu_path()) && file_get_contents(self::mu_path()) === $mu_content;
        return array(
            'mu' => $mu_ok,
            // Distinct from "not writable": the owner has forbidden file writes, which is a
            // configuration choice, not a permissions fault. The UI must say which it is.
            'file_mods_blocked' => self::file_mods_blocked(),
            'dropin' => self::dropin_status(),
            'dropin_owner' => in_array(self::dropin_status(), array('qm', 'foreign'), true) ? self::dropin_owner_label() : null,
            'hold' => (bool) get_option('sspa_dropin_hold'),
            'stale_qm' => self::dropin_is_stale_qm(),
            'qm_active' => self::qm_plugin_active(),
        );
    }
}
