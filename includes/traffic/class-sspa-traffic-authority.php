<?php
defined('ABSPATH') || exit;

/** Site-local collection authority. The stable inode is also the publication/write lock. */
class SSPA_Traffic_Authority {
    const FILE = 'sspa-traffic-authority.json';
    private static $handle = null;
    private static $exclusive = false;

    public static function path() {
        return WPMU_PLUGIN_DIR . '/' . self::FILE;
    }

    public static function synchronized($exclusive, $callback) {
        if (self::$handle) {
            if ($exclusive && !self::$exclusive) {
                throw new RuntimeException('Cannot upgrade a traffic reader lock.');
            }
            return call_user_func($callback);
        }
        if ($exclusive && !is_dir(WPMU_PLUGIN_DIR) && !wp_mkdir_p(WPMU_PLUGIN_DIR)) {
            throw new RuntimeException('Cannot create the traffic authority directory.');
        }
        $handle = fopen(self::path(), $exclusive ? 'c+' : 'r');
        if (!$handle) {
            throw new RuntimeException('Cannot open the traffic authority file.');
        }
        if (!flock($handle, $exclusive ? LOCK_EX : LOCK_SH)) {
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
            fclose($handle);
        }
    }

    private static function read() {
        rewind(self::$handle);
        $raw = stream_get_contents(self::$handle);
        if ('' === $raw) {
            return null;
        }
        $state = json_decode($raw, true);
        if (!is_array($state) || !isset($state['generation'], $state['enabled']) || !is_string($state['generation'])) {
            throw new RuntimeException('The traffic authority file is invalid.');
        }
        return $state;
    }

    private static function write($state) {
        $json = wp_json_encode($state);
        rewind(self::$handle);
        if (!ftruncate(self::$handle, 0) || fwrite(self::$handle, $json) !== strlen($json) || !fflush(self::$handle)) {
            throw new RuntimeException('Cannot persist traffic authority.');
        }
    }

    public static function activate() {
        return self::synchronized(true, static function() {
            self::write(array('generation' => bin2hex(random_bytes(16)), 'enabled' => true));
        });
    }

    public static function revoke() {
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
