<?php
defined('ABSPATH') || exit;

/**
 * Site-local collection authority. The state (generation, enabled) is a non-autoloaded
 * option; an empty lock file in this plugin's uploads folder provides the flock that
 * serialises lifecycle changes against in-flight observer writes. Nothing is written to it.
 */
class SSPA_Traffic_Authority {
    const FILE = 'sspa-traffic.lock';
    const OPTION = 'sspa_traffic_authority';
    /** The state file used before 0.53.1, read once by migrate(). */
    const LEGACY_FILE = 'sspa-traffic-authority.php';
    const LEGACY_GUARD = "<?php exit; ?>\n";
    private static $handle = null;
    private static $exclusive = false;

    public static function path() {
        $uploads = wp_upload_dir(null, false);
        return $uploads['basedir'] . '/super-speedy-performance-analysis/' . self::FILE;
    }

    /** True once Traffic has ever taken authority on this site. */
    public static function exists() {
        return is_file(self::path()) || null !== self::stored();
    }

    public static function migrate() {
        $dir = dirname(self::path());
        $files = array(
            SSPA_MU_DIR . '/sspa-traffic-authority.json',
            $dir . '/sspa-traffic-authority.json',
            $dir . '/' . self::LEGACY_FILE,
        );
        if (!is_file($files[0]) && !is_file($files[1]) && !is_file($files[2])) { return; }
        self::synchronized(true, static function() use ($files) {
            $state = self::read();
            // Newest location first: the uploads authority supersedes the legacy MU authority.
            foreach (array_reverse($files) as $legacy) {
                if (!is_file($legacy)) { continue; }
                if (!$state) {
                    $raw = (string) SSPA_Filesystem::get_contents($legacy);
                    $legacy_state = json_decode(0 === strpos($raw, self::LEGACY_GUARD) ? substr($raw, strlen(self::LEGACY_GUARD)) : $raw, true);
                    if (!is_array($legacy_state) || !isset($legacy_state['generation'], $legacy_state['enabled']) || !is_string($legacy_state['generation'])) {
                        throw new RuntimeException('The legacy traffic authority file is invalid.');
                    }
                    self::write($legacy_state);
                    $state = $legacy_state;
                }
                if (!SSPA_Filesystem::direct()->delete($legacy)) { throw new RuntimeException('Cannot remove legacy traffic authority.'); }
            }
        });
    }

    public static function synchronized($exclusive, $callback) {
        if (self::$handle) {
            if ($exclusive && !self::$exclusive) {
                throw new RuntimeException('Cannot upgrade a traffic reader lock.');
            }
            return call_user_func($callback);
        }
        if ($exclusive && !SSPA_Traffic_Helper::data_dir()) {
            throw new RuntimeException('Cannot create the traffic authority directory.');
        }
        // An empty lock file: flock needs a PHP stream, which WP_Filesystem cannot provide. Mode
        // 'c' creates it without truncating and nothing is ever written through this handle.
        $handle = fopen(self::path(), $exclusive ? 'c' : 'r');
        if (!$handle) {
            throw new RuntimeException('Cannot open the traffic authority file.');
        }
        if (!flock($handle, $exclusive ? LOCK_EX : LOCK_SH)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the local authority flock stream; WP_Filesystem cannot manage its PHP resource.
            fclose($handle);
            throw new RuntimeException('Cannot lock the traffic authority file.');
        }
        self::$handle = $handle;
        self::$exclusive = $exclusive;
        try {
            return call_user_func($callback);
        } finally {
            self::$handle = null;
            self::$exclusive = false;
            flock($handle, LOCK_UN);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the local authority flock stream after unlocking; WP_Filesystem cannot manage its PHP resource.
            fclose($handle);
        }
    }

    /** The stored state, read past any object cache so a revocation is seen at once. */
    private static function stored() {
        wp_cache_delete(self::OPTION, 'options');
        $missing = wp_cache_get('notoptions', 'options');
        if (is_array($missing) && isset($missing[self::OPTION])) {
            unset($missing[self::OPTION]);
            wp_cache_set('notoptions', $missing, 'options');
        }
        $state = get_option(self::OPTION, null);
        return null === $state || false === $state ? null : $state;
    }

    private static function read() {
        $state = self::stored();
        if (null === $state) {
            return null;
        }
        if (!is_array($state) || !isset($state['generation'], $state['enabled']) || !is_string($state['generation'])) {
            throw new RuntimeException('The traffic authority state is invalid.');
        }
        return $state;
    }

    /** Called only while holding the exclusive lock. */
    private static function write($state) {
        $state = array('generation' => (string) $state['generation'], 'enabled' => (bool) $state['enabled']);
        if (self::stored() !== $state && !update_option(self::OPTION, $state, false)) {
            throw new RuntimeException('Cannot persist traffic authority.');
        }
    }

    public static function activate() {
        return self::synchronized(true, static function() {
            self::write(array('generation' => bin2hex(random_bytes(16)), 'enabled' => true));
        });
    }

    public static function revoke() {
        if (!self::exists()) { return; }
        return self::synchronized(true, static function() {
            self::write(array('generation' => bin2hex(random_bytes(16)), 'enabled' => false));
        });
    }

    /** Called under the start exclusive lock, before creating any collection row. */
    public static function generation() {
        return self::synchronized(true, static function() {
            $state = self::read();
            if (!$state) {
                // First start after upgrading an already-active plugin.
                if (!self::plugin_active()) {
                    throw new RuntimeException('Traffic collection requires an active plugin.');
                }
                self::write(array('generation' => bin2hex(random_bytes(16)), 'enabled' => true));
                $state = self::read();
            }
            if (!$state['enabled'] || !self::plugin_active()) {
                throw new RuntimeException('Traffic collection authority has been revoked.');
            }
            return $state['generation'];
        });
    }

    private static function plugin_active() {
        return !is_multisite() && in_array('super-speedy-performance-analysis/super-speedy-performance-analysis.php', (array) get_option('active_plugins', array()), true);
    }

    public static function permits($generation) {
        $state = self::read();
        return is_string($generation) && '' !== $generation && $state && $state['enabled']
            && hash_equals($state['generation'], $generation);
        // Effective active_plugins can exclude PA for an intentionally isolated AJAX
        // request. Real deactivation revokes this durable generation under the same lock.
    }

    /** The shared lock remains held through both event and endpoint writes. */
    public static function observe($config, $callback) {
        if (empty($config['generation']) || !is_file(self::path())) {
            return false;
        }
        return self::synchronized(false, static function() use ($config, $callback) {
            if (!self::permits($config['generation'])) {
                return false;
            }
            return call_user_func($callback);
        });
    }

    public static function option($collection_id) {
        return 'sspa_traffic_generation_' . (int) $collection_id;
    }
}
