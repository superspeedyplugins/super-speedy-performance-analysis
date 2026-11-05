<?php
defined('ABSPATH') || exit;

/**
 * Site-local collection authority. The state (generation, enabled) is a non-autoloaded
 * option; MySQL named locks serialise lifecycle changes against in-flight observer writes.
 *
 * A reader-writer lock built from GET_LOCK(), which is exclusive only: a reader takes one of N
 * slot locks at random, a writer takes the writer lock and then every slot in a fixed order, so
 * it waits for in-flight readers and blocks new ones. Lock names are server-wide, so they carry
 * a hash of this site's database and table prefix. Locks belong to the database connection and
 * are released if the request dies. (Until 0.61.6 this was flock() on an empty file in uploads,
 * which the wordpress.org Plugin Check refuses: WP_Filesystem cannot lock.)
 */
class SSPA_Traffic_Authority {
    /** The empty lock file used before 0.61.6, removed by migrate(). */
    const FILE = 'sspa-traffic.lock';
    const OPTION = 'sspa_traffic_authority';
    /** The state file used before 0.53.1, read once by migrate(). */
    const LEGACY_FILE = 'sspa-traffic-authority.php';
    const LEGACY_GUARD = "<?php exit; ?>\n";
    const SLOTS = 8;
    /** A reader waits this long for its slot, then skips observing that request (counted). */
    const READER_TIMEOUT = 1;
    const WRITER_TIMEOUT = 30;
    const SKIPS_PREFIX = 'sspa_traffic_lock_skips_';
    /** @var string[] Lock names held by this request, in acquisition order. */
    private static $held = array();
    private static $exclusive = false;

    public static function path() {
        $uploads = wp_upload_dir(null, false);
        return $uploads['basedir'] . '/super-speedy-performance-analysis/' . self::FILE;
    }

    /** True once Traffic has ever taken authority on this site. */
    public static function exists() {
        return null !== self::stored();
    }

    public static function migrate() {
        if (is_file(self::path())) {
            // The pre-0.61.6 flock file. An observer still running old code may hold it for the
            // length of one request; deleting the path does not disturb an open handle.
            SSPA_Filesystem::direct()->delete(self::path());
        }
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
        if (self::$held) {
            if ($exclusive && !self::$exclusive) {
                throw new RuntimeException('Cannot upgrade a traffic reader lock.');
            }
            return call_user_func($callback);
        }
        $names = $exclusive ? self::writer_names() : array(self::reader_name());
        $acquired = array();
        foreach ($names as $name) {
            if (!self::get_lock($name, $exclusive ? self::WRITER_TIMEOUT : self::READER_TIMEOUT)) {
                self::release($acquired);
                if (!$exclusive) {
                    throw new SSPA_Traffic_Lock_Busy('The traffic reader slot is busy.');
                }
                throw new RuntimeException('Cannot lock the traffic authority.');
            }
            $acquired[] = $name;
        }
        self::$held = $acquired;
        self::$exclusive = $exclusive;
        try {
            return call_user_func($callback);
        } finally {
            self::$held = array();
            self::$exclusive = false;
            self::release($acquired);
        }
    }

    /** Server-wide lock names, scoped to this site's database and table prefix (64-character limit). */
    private static function base() {
        global $wpdb;
        return 'sspa_traffic_' . substr(md5(DB_NAME . '|' . $wpdb->prefix), 0, 24);
    }

    private static function slots() {
        return max(1, min(64, (int) apply_filters('sspa_traffic_lock_slots', self::SLOTS)));
    }

    private static function reader_name() {
        return self::base() . ':r' . random_int(0, self::slots() - 1);
    }

    /** The writer lock, then every reader slot in a fixed order, so writers cannot deadlock. */
    private static function writer_names() {
        $names = array(self::base() . ':w');
        for ($slot = 0, $count = self::slots(); $slot < $count; $slot++) {
            $names[] = self::base() . ':r' . $slot;
        }
        return $names;
    }

    private static function get_lock($name, $timeout) {
        global $wpdb;
        // 1 = acquired, 0 = timed out, NULL = error; only 1 is success.
        return '1' === (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, $timeout));
    }

    private static function release($names) {
        global $wpdb;
        foreach (array_reverse($names) as $name) {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
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

    /**
     * The reader lock remains held through both event and endpoint writes. A request that cannot
     * get a reader slot within READER_TIMEOUT is not observed, and is counted for its collection
     * so the loss is reported (skips()), never silent.
     */
    public static function observe($config, $callback) {
        if (empty($config['generation'])) {
            return false;
        }
        try {
            return self::synchronized(false, static function() use ($config, $callback) {
                if (!self::permits($config['generation'])) {
                    return false;
                }
                return call_user_func($callback);
            });
        } catch (SSPA_Traffic_Lock_Busy $busy) {
            self::count_skip($config['generation']);
            return false;
        }
    }

    private static function skips_option($generation) {
        return self::SKIPS_PREFIX . substr(md5((string) $generation), 0, 24);
    }

    /** One atomic statement, so concurrent skipped requests are all counted. */
    private static function count_skip($generation) {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'off')
             ON DUPLICATE KEY UPDATE option_value = option_value + 1",
            self::skips_option($generation)
        ));
    }

    /** Requests a collection could not observe because every attempt timed out on its reader slot. */
    public static function skips($generation) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::skips_option($generation)));
    }

    public static function option($collection_id) {
        return 'sspa_traffic_generation_' . (int) $collection_id;
    }
}

/** A reader could not get its slot in time: skip observing this request. */
class SSPA_Traffic_Lock_Busy extends RuntimeException {}
