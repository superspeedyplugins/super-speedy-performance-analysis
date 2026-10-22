<?php
defined('ABSPATH') || exit;
// Environment evidence for a profiled request, measured in the PHP process that served it:
// the database connection and round trip, the object cache round trip and drop-in, and opcache.
// Run by SSPA_Capture at shutdown, after generation time is stamped and after the SQL, cache and
// sampler sections are assembled, so the probes appear in none of them. Only the PHP that
// served the request can read its own opcache: a WP-CLI process has its own.

if (!class_exists('SSPA_Environment_Probe')) {
    class SSPA_Environment_Probe {

        const DB_QUERIES = 5;
        const CACHE_MISSES = 10;
        /** Below this share of free opcache memory the cache is nearly full. */
        const LOW_FREE_SHARE = 0.05;
        /** At or above this share of the key table, new scripts are close to being refused. */
        const KEYS_HIGH_SHARE = 0.95;
        /** More than this share of the request's files missing from opcache is reported. */
        const UNCACHED_SHARE = 0.05;
        const HIT_RATE_LOW = 90.0;
        const HIT_RATE_MIN_LOOKUPS = 1000;

        /**
         * @param wpdb  $wpdb
         * @param array $cache The capture's cache section, already measured.
         */
        public static function collect($wpdb, $cache) {
            $start = microtime(true);
            $out = array(
                'schema' => 'sspa/environment@1',
                'sapi' => PHP_SAPI,
                'php' => PHP_VERSION,
                'opcache' => self::opcache(),
                'db' => self::db($wpdb),
                'object_cache' => self::object_cache($cache),
            );
            $out['probe_ms'] = round((microtime(true) - $start) * 1000, 3);
            return $out;
        }

        private static function distribution($values) {
            sort($values, SORT_NUMERIC);
            $n = count($values);
            if (!$n) {
                return array('n' => 0, 'median_ms' => null, 'p95_ms' => null, 'max_ms' => null);
            }
            return array(
                'n' => $n,
                'median_ms' => round($values[(int) ceil(0.5 * $n) - 1], 4),
                'p95_ms' => round($values[(int) ceil(0.95 * $n) - 1], 4),
                'max_ms' => round($values[$n - 1], 4),
            );
        }

        private static function elapsed_ms($start_ns) {
            return (hrtime(true) - $start_ns) / 1000000;
        }

        private static function db($wpdb) {
            $connect = (is_object($wpdb) && property_exists($wpdb, 'sspa_connect_ms') && null !== $wpdb->sspa_connect_ms)
                ? round((float) $wpdb->sspa_connect_ms, 3) : null;
            $times = array();
            if (is_object($wpdb) && !empty($wpdb->dbh)) {
                for ($i = 0; $i < self::DB_QUERIES; $i++) {
                    $t = hrtime(true);
                    $wpdb->get_var('SELECT 1');
                    $times[] = self::elapsed_ms($t);
                }
            }
            $transport = null;
            if (defined('DB_HOST') && is_object($wpdb) && method_exists($wpdb, 'parse_db_host')) {
                $parsed = $wpdb->parse_db_host(DB_HOST);
                if (is_array($parsed)) {
                    list($host, $port, $socket) = $parsed;
                    // mysqli connects to "localhost" without a port through the default socket.
                    $transport = ($socket || ('localhost' === strtolower((string) $host) && !$port)) ? 'socket' : 'tcp';
                }
            }
            return array(
                'connect_ms' => $connect,
                'connect_measured' => null !== $connect,
                // Only Performance Analysis's own db.php drop-in can time the connection.
                'connect_unmeasured_reason' => null !== $connect ? null : 'connection_not_made_by_performance_analysis_dropin',
                'trivial_query' => self::distribution($times),
                'server' => is_object($wpdb) && method_exists($wpdb, 'db_server_info') && !empty($wpdb->dbh) ? substr((string) $wpdb->db_server_info(), 0, 100) : null,
                'transport' => $transport,
            );
        }

        private static function object_cache($cache) {
            global $wp_object_cache;
            $persistent = function_exists('wp_using_ext_object_cache') && (bool) wp_using_ext_object_cache();
            $dropin_file = WP_CONTENT_DIR . '/object-cache.php';
            $dropin = array('present' => is_file($dropin_file), 'name' => null, 'version' => null, 'class' => is_object($wp_object_cache) ? get_class($wp_object_cache) : null);
            if ($dropin['present'] && function_exists('get_file_data')) {
                $header = get_file_data($dropin_file, array('name' => 'Plugin Name', 'version' => 'Version'));
                $dropin['name'] = '' !== $header['name'] ? substr($header['name'], 0, 120) : null;
                $dropin['version'] = '' !== $header['version'] ? substr($header['version'], 0, 40) : null;
            }
            $times = array();
            if ($persistent && function_exists('wp_cache_get')) {
                // Random keys are never stored, so each lookup is a miss that goes to the cache
                // server; force skips the in-request copy.
                for ($i = 0; $i < self::CACHE_MISSES; $i++) {
                    $key = 'sspa_probe_' . bin2hex(random_bytes(8));
                    $t = hrtime(true);
                    wp_cache_get($key, 'sspa_probe', true);
                    $times[] = self::elapsed_ms($t);
                }
            }
            return array(
                'persistent' => $persistent,
                'dropin' => $dropin,
                'round_trip' => $persistent ? self::distribution($times) : null,
                'misses_this_request' => isset($cache['misses']) ? $cache['misses'] : null,
                'hits_this_request' => isset($cache['hits']) ? $cache['hits'] : null,
            );
        }

        private static function ini_bool($name) {
            $value = ini_get($name);
            return false === $value ? null : in_array(strtolower((string) $value), array('1', 'on', 'yes', 'true'), true);
        }

        private static function ini_int($name) {
            $value = ini_get($name);
            return false === $value || '' === $value ? null : (int) $value;
        }

        private static function opcache() {
            $ini = array(
                'enable' => self::ini_bool('opcache.enable'),
                'enable_cli' => self::ini_bool('opcache.enable_cli'),
                'memory_consumption_mb' => self::ini_int('opcache.memory_consumption'),
                'interned_strings_buffer_mb' => self::ini_int('opcache.interned_strings_buffer'),
                'max_accelerated_files' => self::ini_int('opcache.max_accelerated_files'),
                'max_wasted_percentage' => self::ini_int('opcache.max_wasted_percentage'),
                'validate_timestamps' => self::ini_bool('opcache.validate_timestamps'),
                'revalidate_freq' => self::ini_int('opcache.revalidate_freq'),
                'file_cache' => false !== ini_get('opcache.file_cache') && '' !== (string) ini_get('opcache.file_cache'),
                'file_cache_only' => self::ini_bool('opcache.file_cache_only'),
                'jit' => false === ini_get('opcache.jit') ? null : substr((string) ini_get('opcache.jit'), 0, 20),
                'jit_buffer_size' => false === ini_get('opcache.jit_buffer_size') ? null : substr((string) ini_get('opcache.jit_buffer_size'), 0, 20),
                'restrict_api' => false !== ini_get('opcache.restrict_api') && '' !== (string) ini_get('opcache.restrict_api'),
            );
            $out = array(
                'loaded' => function_exists('opcache_get_status'),
                'enabled' => false,
                'status_readable' => false,
                'cache_full' => null,
                'restart_pending' => null,
                'memory' => null,
                'interned_strings' => null,
                'scripts' => null,
                'statistics' => null,
                'jit_enabled' => null,
                'request_files' => null,
                'ini' => $ini,
                'warnings' => array(),
            );
            $included = get_included_files();
            if (!$out['loaded']) {
                $out['warnings'][] = array('code' => 'opcache_unavailable', 'severity' => 'error');
                return $out;
            }
            // opcache.restrict_api makes this warn and return false for scripts outside its path.
            $status = @opcache_get_status(false); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a restricted API emits a warning; that state is reported below.
            if (!is_array($status)) {
                if ($ini['restrict_api'] && $ini['enable']) {
                    $out['enabled'] = null; // unknown: the host restricts reading it
                    $out['warnings'][] = array('code' => 'opcache_status_restricted', 'severity' => 'info');
                } else {
                    $out['warnings'][] = array('code' => 'opcache_disabled', 'severity' => 'error');
                }
                return $out;
            }
            $out['status_readable'] = true;
            $out['enabled'] = !empty($status['opcache_enabled']);
            if (!$out['enabled']) {
                $out['warnings'][] = array('code' => 'opcache_disabled', 'severity' => 'error');
                return $out;
            }
            $memory = isset($status['memory_usage']) ? $status['memory_usage'] : array();
            $stats = isset($status['opcache_statistics']) ? $status['opcache_statistics'] : array();
            $interned = isset($status['interned_strings_usage']) ? $status['interned_strings_usage'] : array();
            $out['cache_full'] = !empty($status['cache_full']);
            $out['restart_pending'] = !empty($status['restart_pending']) || !empty($status['restart_in_progress']);
            $out['memory'] = array(
                'used_bytes' => (int) ($memory['used_memory'] ?? 0),
                'free_bytes' => (int) ($memory['free_memory'] ?? 0),
                'wasted_bytes' => (int) ($memory['wasted_memory'] ?? 0),
                'wasted_percent' => round((float) ($memory['current_wasted_percentage'] ?? 0), 2),
            );
            $out['interned_strings'] = $interned ? array(
                'buffer_bytes' => (int) ($interned['buffer_size'] ?? 0),
                'used_bytes' => (int) ($interned['used_memory'] ?? 0),
                'free_bytes' => (int) ($interned['free_memory'] ?? 0),
                'strings' => (int) ($interned['number_of_strings'] ?? 0),
            ) : null;
            $out['scripts'] = array(
                'cached' => (int) ($stats['num_cached_scripts'] ?? 0),
                'cached_keys' => (int) ($stats['num_cached_keys'] ?? 0),
                'max_cached_keys' => (int) ($stats['max_cached_keys'] ?? 0),
                'max_accelerated_files' => $ini['max_accelerated_files'],
            );
            $out['statistics'] = array(
                'hits' => (int) ($stats['hits'] ?? 0),
                'misses' => (int) ($stats['misses'] ?? 0),
                'hit_rate' => round((float) ($stats['opcache_hit_rate'] ?? 0), 2),
                'oom_restarts' => (int) ($stats['oom_restarts'] ?? 0),
                'hash_restarts' => (int) ($stats['hash_restarts'] ?? 0),
                'manual_restarts' => (int) ($stats['manual_restarts'] ?? 0),
                'start_time' => (int) ($stats['start_time'] ?? 0),
                'last_restart_time' => (int) ($stats['last_restart_time'] ?? 0),
            );
            $out['jit_enabled'] = isset($status['jit']['enabled']) ? (bool) $status['jit']['enabled'] : null;

            $cached = 0;
            if (function_exists('opcache_is_script_cached')) {
                foreach ($included as $file) {
                    if (@opcache_is_script_cached($file)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- same restrict_api warning as above.
                        $cached++;
                    }
                }
            }
            $out['request_files'] = array('included' => count($included), 'cached' => $cached);

            $total = $out['memory']['used_bytes'] + $out['memory']['free_bytes'] + $out['memory']['wasted_bytes'];
            if ($out['cache_full']) {
                $out['warnings'][] = array('code' => 'opcache_full', 'severity' => 'error');
            } elseif ($total > 0 && $out['memory']['free_bytes'] < self::LOW_FREE_SHARE * $total) {
                $out['warnings'][] = array('code' => 'opcache_memory_low', 'severity' => 'warning');
            }
            if ($out['scripts']['max_cached_keys'] > 0 && $out['scripts']['cached_keys'] >= self::KEYS_HIGH_SHARE * $out['scripts']['max_cached_keys']) {
                $out['warnings'][] = array('code' => 'opcache_keys_high', 'severity' => 'warning');
            }
            if ($out['interned_strings'] && $out['interned_strings']['buffer_bytes'] > 0 && 0 === $out['interned_strings']['free_bytes']) {
                $out['warnings'][] = array('code' => 'opcache_interned_full', 'severity' => 'warning');
            }
            if ($out['statistics']['oom_restarts'] + $out['statistics']['hash_restarts'] > 0) {
                $out['warnings'][] = array('code' => 'opcache_restarts', 'severity' => 'error');
            }
            $lookups = $out['statistics']['hits'] + $out['statistics']['misses'];
            if ($lookups >= self::HIT_RATE_MIN_LOOKUPS && $out['statistics']['hit_rate'] < self::HIT_RATE_LOW) {
                $out['warnings'][] = array('code' => 'opcache_hit_rate_low', 'severity' => 'warning');
            }
            if (function_exists('opcache_is_script_cached') && count($included) > 0
                && (count($included) - $cached) > self::UNCACHED_SHARE * count($included)) {
                $out['warnings'][] = array('code' => 'opcache_request_files_uncached', 'severity' => 'error');
            }
            return $out;
        }
    }
}
