<?php
defined('ABSPATH') || exit;

/**
 * Pro: tests the server's DNS resolvers with real lookups.
 *
 * A resolver that drops lookups makes every outbound request a plugin sends (licence checks,
 * update checks, API calls) wait the resolver timeout before PHP retries, which shows up as
 * seconds added to admin pages. This sends 12 queries straight to each nameserver PHP uses,
 * over UDP, and counts the ones that fail or get no answer. It changes nothing on the server.
 *
 * Deleted from the wordpress.org build. Plan: .docs/2026-11-23-pro-dns-health-check.md
 */
class SSPA_Dns_Check {

    const OPTION = 'sspa_dns_check';
    const QUERIES = 12;
    const WAIT = 1.5;
    const BUDGET = 25.0;
    const SLOW_MS = 500;

    /** The last saved result, or null. */
    public static function last() {
        $last = get_option(self::OPTION, null);
        return is_array($last) ? $last : null;
    }

    /** The resolver configuration PHP uses: nameservers plus the timeout and attempts options. */
    public static function config() {
        $config = array('servers' => array(), 'timeout' => 5, 'attempts' => 2, 'source' => '');
        $file = '/etc/resolv.conf';
        $lines = @is_readable($file) ? @file($file, FILE_IGNORE_NEW_LINES) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir or a missing file means "cannot read", which the screen reports.
        if (is_array($lines)) {
            $config['source'] = $file;
            foreach ($lines as $line) {
                $line = trim(preg_replace('/[#;].*$/', '', $line));
                if (preg_match('/^nameserver\s+(\S+)/', $line, $m)) {
                    $config['servers'][] = $m[1];
                } elseif (preg_match('/^options\s+(.+)$/', $line, $m)) {
                    if (preg_match('/\btimeout:(\d+)/', $m[1], $t)) { $config['timeout'] = max(1, (int) $t[1]); }
                    if (preg_match('/\battempts:(\d+)/', $m[1], $a)) { $config['attempts'] = max(1, (int) $a[1]); }
                }
            }
            // systemd-resolved's stub forwards to the real upstream servers: test those too.
            if (array('127.0.0.53') === $config['servers'] && @is_readable('/run/systemd/resolve/resolv.conf')) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.
                foreach ((array) @file('/run/systemd/resolve/resolv.conf', FILE_IGNORE_NEW_LINES) as $line) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.
                    if (preg_match('/^\s*nameserver\s+(\S+)/', $line, $m)) { $config['servers'][] = $m[1]; }
                }
            }
        }
        /**
         * The nameservers to test, as "host" or "host:port". Set them when the resolver
         * configuration cannot be read on this server.
         */
        $config['servers'] = array_values(array_unique(array_filter(array_map('strval', (array) apply_filters('sspa_dns_check_nameservers', $config['servers'])))));
        return $config;
    }

    /** Run the check, save it and return it. */
    public static function run() {
        $config = self::config();
        $deadline = hrtime(true) + (int) (self::BUDGET * 1e9);
        $names = self::names();
        $servers = array();
        $udp = function_exists('stream_socket_client');
        foreach ($config['servers'] as $address) {
            $servers[] = $udp ? self::test_server($address, $names, $deadline) : null;
        }
        $servers = array_values(array_filter($servers));
        $fallback = false;
        if (!$servers) {
            $fallback = true;
            $servers[] = self::test_php_resolver($names, $deadline);
        }
        $result = array(
            'time' => time(),
            'source' => $config['source'],
            'timeout' => $config['timeout'],
            'attempts' => $config['attempts'],
            'fallback' => $fallback,
            'servers' => $servers,
        );
        update_option(self::OPTION, $result, false);
        return $result;
    }

    /** 4 names WordPress needs and 8 that no resolver can have cached. */
    private static function names() {
        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        $public = $host && false !== strpos($host, '.') && !filter_var($host, FILTER_VALIDATE_IP)
            && !preg_match('/\.(localhost|local|test|internal|lan|home|invalid|example)$/i', $host);
        $names = array(
            array('name' => 'api.wordpress.org', 'fresh' => false),
            array('name' => 'downloads.wordpress.org', 'fresh' => false),
            array('name' => 'wordpress.org', 'fresh' => false),
            array('name' => $public ? $host : 'www.google.com', 'fresh' => false),
        );
        $parent = $public ? $host : 'wordpress.org';
        for ($i = 0; $i < 8; $i++) {
            $names[] = array('name' => 'sspa-dns-check-' . strtolower(wp_generate_password(10, false)) . '.' . $parent, 'fresh' => true);
        }
        return $names;
    }

    private static function test_server($address, $names, $deadline) {
        $host = $address;
        $port = 53;
        if (preg_match('/^\[(.+)\]:(\d+)$/', $address, $m) || preg_match('/^([^:]+):(\d+)$/', $address, $m)) {
            $host = $m[1];
            $port = (int) $m[2];
        }
        $target = 'udp://' . (false !== strpos($host, ':') ? '[' . $host . ']' : $host) . ':' . $port;
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client($target, $errno, $errstr, self::WAIT); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the error is reported on the screen.
        $row = self::empty_row($address);
        if (!$socket) {
            $row['error'] = $errstr ? $errstr : 'could not open a UDP socket';
            $row['skipped'] = count($names);
            $row['verdict'] = 'unreachable';
            return $row;
        }
        foreach ($names as $entry) {
            if (hrtime(true) >= $deadline) { $row['skipped']++; continue; }
            $id = wp_rand(0, 65535);
            $started = hrtime(true);
            fwrite($socket, self::packet($id, $entry['name'])); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- a UDP socket, not a file.
            $row['sent']++;
            $outcome = self::await($socket, $id, $started);
            self::count($row, $outcome, $started, $entry['fresh']);
        }
        fclose($socket); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- a UDP socket, not a file.
        return self::finish($row);
    }

    /** Wait up to WAIT seconds for the reply with this id. Returns 'answered', 'failed' or 'unanswered'. */
    private static function await($socket, $id, $started) {
        $until = $started + (int) (self::WAIT * 1e9);
        while (($left = $until - hrtime(true)) > 0) {
            $read = array($socket);
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, 0, (int) ($left / 1000)); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an interrupted select is retried by the loop.
            if (!$ready) { continue; }
            $reply = fread($socket, 4096); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- a UDP socket, not a file.
            if (!is_string($reply) || strlen($reply) < 12) { continue; }
            $head = unpack('nid/nflags', substr($reply, 0, 4));
            if ((int) $head['id'] !== $id || !($head['flags'] & 0x8000)) { continue; } // a late reply to an earlier query
            $rcode = $head['flags'] & 0x0F;
            return (0 === $rcode || 3 === $rcode) ? 'answered' : 'failed';
        }
        return 'unanswered';
    }

    private static function packet($id, $name) {
        $qname = '';
        foreach (explode('.', rtrim($name, '.')) as $label) {
            $qname .= chr(strlen($label)) . $label;
        }
        return pack('nnnnnn', $id, 0x0100, 1, 0, 0, 0) . $qname . "\0" . pack('nn', 1, 1);
    }

    /** No readable configuration or no UDP: time lookups through PHP's own resolver. */
    private static function test_php_resolver($names, $deadline) {
        $row = self::empty_row(__('PHP resolver', 'super-speedy-performance-analysis'));
        foreach ($names as $entry) {
            if (hrtime(true) >= $deadline) { $row['skipped']++; continue; }
            $started = hrtime(true);
            $row['sent']++;
            $records = @dns_get_record($entry['name'], DNS_A); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a resolver failure is the result being measured.
            $slow = (hrtime(true) - $started) / 1e9 > self::WAIT;
            // An unknown random name comes back as an empty array; false is a resolver failure.
            $outcome = (false === $records) ? 'failed' : ($slow ? 'unanswered' : 'answered');
            self::count($row, $outcome, $started, $entry['fresh']);
        }
        return self::finish($row);
    }

    private static function empty_row($address) {
        return array('address' => (string) $address, 'sent' => 0, 'answered' => 0, 'failed' => 0, 'unanswered' => 0, 'skipped' => 0,
            'median_ms' => null, 'slowest_ms' => null, 'fresh_ms' => array(), 'all_ms' => array(), 'error' => '', 'verdict' => '');
    }

    private static function count(&$row, $outcome, $started, $fresh) {
        $row[$outcome]++;
        if ('answered' === $outcome) {
            $ms = (int) round((hrtime(true) - $started) / 1e6);
            $row['all_ms'][] = $ms;
            if ($fresh) { $row['fresh_ms'][] = $ms; }
        }
    }

    private static function finish($row) {
        $row['median_ms'] = self::median($row['fresh_ms'] ? $row['fresh_ms'] : $row['all_ms']);
        $row['slowest_ms'] = $row['all_ms'] ? max($row['all_ms']) : null;
        unset($row['fresh_ms'], $row['all_ms']);
        $row['verdict'] = self::verdict($row);
        return $row;
    }

    public static function verdict($row) {
        $lost = (int) $row['failed'] + (int) $row['unanswered'];
        if ($lost >= 2) { return 'dropping'; }
        if (1 === $lost) { return 'one_lost'; }
        if ((int) $row['skipped'] > 0) { return 'incomplete'; }
        if (null !== $row['median_ms'] && $row['median_ms'] >= self::SLOW_MS) { return 'slow'; }
        return 'healthy';
    }

    public static function verdict_label($verdict) {
        $labels = array(
            'healthy' => __('Healthy', 'super-speedy-performance-analysis'),
            'dropping' => __('Dropping lookups', 'super-speedy-performance-analysis'),
            'one_lost' => __('One lookup lost, check again', 'super-speedy-performance-analysis'),
            'slow' => __('Slow', 'super-speedy-performance-analysis'),
            'incomplete' => __('Not finished in time', 'super-speedy-performance-analysis'),
            'unreachable' => __('Could not be reached', 'super-speedy-performance-analysis'),
        );
        return isset($labels[$verdict]) ? $labels[$verdict] : $verdict;
    }

    private static function median($values) {
        if (!$values) { return null; }
        sort($values);
        $n = count($values);
        return (int) ($n % 2 ? $values[intdiv($n, 2)] : round(($values[$n / 2 - 1] + $values[$n / 2]) / 2));
    }
}
