<?php
// The per-request capture orchestrator. Armed by the mu-loader for token requests; collects
// SQL / HTTP / cache / overview data and writes a gzcompressed JSON capture to the
// sspa_captures table at PHP shutdown (after WordPress's own shutdown hooks).

if (!class_exists('SSPA_Capture')) {
    class SSPA_Capture {

        const SCHEMA_VERSION = 1;
        const FULL_SQL_TOP_N = 20;
        const FULL_SQL_MS = 50;
        const FULL_SQL_ROWS = 200;

        private $token_id;
        private $flags;
        private $http_pending = array();
        private $http_calls = array();
        private $mail_mode = 'suppress';
        private $mail_calls = array();
        private $mail_pending = null;
        private $conditionals = array();

        public function __construct($token_id, $flags) {
            $this->token_id = $token_id;
            $this->flags = $flags;
            if (isset($flags['mail']) && 'c' === $flags['mail']) {
                $this->mail_mode = 'construct';
            }
        }

        public function arm() {
            add_filter('pre_http_request', array($this, 'http_start'), 9999, 3);
            add_action('http_api_debug', array($this, 'http_end'), 10, 5);
            // Safety rail: no profiled request may ever send real mail.
            // - suppress (default): short-circuit wp_mail entirely, count + attribute.
            // - construct: let the mail stack BUILD the message (measuring template/SMTP
            //   plugin setup cost), then strip every recipient at phpmailer_init so
            //   PHPMailer::preSend() aborts before any transport I/O.
            if ('construct' === $this->mail_mode) {
                add_filter('wp_mail', array($this, 'mail_construct_start'), 1);
                add_action('phpmailer_init', array($this, 'mail_construct_end'), PHP_INT_MAX);
                // Construction can abort before phpmailer_init (e.g. an invalid From
                // address makes setFrom() throw). Record those too or they vanish.
                add_action('wp_mail_failed', array($this, 'mail_construct_failed'));
            } else {
                add_filter('pre_wp_mail', array($this, 'intercept_mail'), 9999, 2);
            }
            add_action('wp', array($this, 'snapshot_conditionals'));
            register_shutdown_function(array($this, 'finalize'));
        }

        public function http_start($pre, $args, $url) {
            $this->http_pending[] = array('url' => $url, 'start' => microtime(true));
            return $pre;
        }

        public function http_end($response, $context, $class, $args, $url) {
            $start = null;
            for ($i = count($this->http_pending) - 1; $i >= 0; $i--) {
                if ($this->http_pending[$i]['url'] === $url) {
                    $start = $this->http_pending[$i]['start'];
                    array_splice($this->http_pending, $i, 1);
                    break;
                }
            }
            $frames = array();
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 16) as $f) {
                if (isset($f['file'])) {
                    $frames[] = array($f['file'], isset($f['line']) ? $f['line'] : 0, (isset($f['class']) ? $f['class'] . '::' : '') . $f['function']);
                }
            }
            $parts = parse_url((string) $url);
            $code = null;
            if (is_wp_error($response)) {
                $code = 'error:' . $response->get_error_code();
            } elseif (is_array($response) && isset($response['response']['code'])) {
                $code = $response['response']['code'];
            }
            $this->http_calls[] = array(
                'url' => (isset($parts['host']) ? $parts['host'] : '') . (isset($parts['path']) ? $parts['path'] : ''),
                'method' => isset($args['method']) ? $args['method'] : 'GET',
                'ms' => ($start !== null) ? (microtime(true) - $start) * 1000 : null,
                'code' => $code,
                'blocking' => !isset($args['blocking']) || $args['blocking'],
                'frames' => $frames,
            );
        }

        public function intercept_mail($short_circuit, $atts) {
            $this->mail_calls[] = array('frames' => $this->trigger_frames(), 'construct_ms' => null);
            return true; // report success to the caller, send nothing
        }

        public function mail_construct_start($atts) {
            $this->mail_pending = array('start' => microtime(true), 'frames' => $this->trigger_frames());
            return $atts;
        }

        public function mail_construct_end($phpmailer) {
            if ($this->mail_pending) {
                $this->mail_calls[] = array(
                    'frames' => $this->mail_pending['frames'],
                    'construct_ms' => (microtime(true) - $this->mail_pending['start']) * 1000,
                );
                $this->mail_pending = null;
            }
            // The no-send guarantee: preSend() throws before any transport work when
            // there are no recipients; wp_mail() catches it and returns false.
            $phpmailer->clearAllRecipients();
        }

        public function mail_construct_failed($error) {
            if ($this->mail_pending) {
                $this->mail_calls[] = array(
                    'frames' => $this->mail_pending['frames'],
                    'construct_ms' => (microtime(true) - $this->mail_pending['start']) * 1000,
                );
                $this->mail_pending = null;
            }
        }

        private function trigger_frames() {
            $frames = array();
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 16) as $f) {
                if (isset($f['file'])) {
                    $frames[] = array($f['file'], isset($f['line']) ? $f['line'] : 0, (isset($f['class']) ? $f['class'] . '::' : '') . $f['function']);
                }
            }
            return $frames;
        }

        public function snapshot_conditionals() {
            $checks = array('is_front_page', 'is_home', 'is_singular', 'is_archive', 'is_category', 'is_tag', 'is_tax', 'is_search', 'is_404', 'is_feed');
            foreach ($checks as $fn) {
                if (function_exists($fn) && call_user_func($fn)) {
                    $this->conditionals[] = $fn;
                }
            }
            foreach (array('is_shop', 'is_product', 'is_product_category', 'is_cart', 'is_checkout', 'is_account_page') as $fn) {
                if (function_exists($fn) && call_user_func($fn)) {
                    $this->conditionals[] = $fn;
                }
            }
        }

        public function finalize() {
            global $wpdb, $timestart, $wp_object_cache;

            if (!isset($wpdb)) {
                return;
            }

            require_once __DIR__ . '/class-sspa-component-map.php';
            require_once __DIR__ . '/fingerprint.php';
            $map = new SSPA_Component_Map();

            $sql = $this->collect_sql($wpdb, $map);
            $http = $this->collect_http($map);
            $mail = $this->collect_mail($map);

            $overview = array(
                'gen_ms' => isset($timestart) ? (microtime(true) - $timestart) * 1000 : null,
                'peak_mem' => memory_get_peak_usage(true),
                'code' => function_exists('http_response_code') ? http_response_code() : null,
                'included_files' => count(get_included_files()),
                'is_admin' => function_exists('is_admin') ? is_admin() : null,
                'php' => PHP_VERSION,
                'wp' => isset($GLOBALS['wp_version']) ? $GLOBALS['wp_version'] : null,
                'capture_mode' => $sql['mode'],
            );

            $cache = array(
                'hits' => (is_object($wp_object_cache) && property_exists($wp_object_cache, 'cache_hits')) ? (int) $wp_object_cache->cache_hits : null,
                'misses' => (is_object($wp_object_cache) && property_exists($wp_object_cache, 'cache_misses')) ? (int) $wp_object_cache->cache_misses : null,
                'persistent' => function_exists('wp_using_ext_object_cache') ? wp_using_ext_object_cache() : null,
                'alloptions_bytes' => function_exists('wp_load_alloptions') ? strlen(serialize(wp_load_alloptions())) : null,
            );

            $payload = array(
                'schema' => self::SCHEMA_VERSION,
                'token' => $this->token_id,
                'ts' => time(),
                'flags' => $this->flags,
                'overview' => $overview,
                'sql' => $sql,
                'http' => $http,
                'mail' => $mail,
                'cache' => $cache,
                'conditionals' => $this->conditionals,
                'components' => $this->aggregate_components($sql, $http, $mail),
            );

            $json = function_exists('wp_json_encode') ? wp_json_encode($payload) : json_encode($payload);
            if ($json === false) {
                return;
            }

            $table = $wpdb->prefix . 'sspa_captures';
            // Suppress errors so a missing table (plugin deleted mid-run) can't break the page.
            $suppress = $wpdb->suppress_errors(true);
            $wpdb->query($wpdb->prepare(
                "INSERT INTO $table (token_id, capture, created) VALUES (%s, %s, %s)
                 ON DUPLICATE KEY UPDATE capture = VALUES(capture), created = VALUES(created)",
                $this->token_id,
                gzcompress($json, 6),
                gmdate('Y-m-d H:i:s')
            ));
            $wpdb->suppress_errors($suppress);
        }

        private function collect_sql($wpdb, $map) {
            $entries = array();
            $mode = 'none';

            if (isset($wpdb->sspa_log) && is_array($wpdb->sspa_log)) {
                $mode = 'full';
                foreach ($wpdb->sspa_log as $q) {
                    $attr = $map->attribute($q['frames']);
                    $entries[] = array(
                        'sql' => $q['sql'],
                        'ms' => $q['ms'],
                        'rows' => $q['rows'],
                        'err' => $q['err'],
                        'component' => $attr['component'],
                        'ctype' => $attr['type'],
                        'caller' => $attr['caller'],
                        'via' => $attr['via'],
                        'chain' => (count($attr['chain']) > 1) ? $attr['chain'] : null,
                    );
                }
            } elseif (!empty($wpdb->queries) && is_array($wpdb->queries)) {
                foreach ($wpdb->queries as $q) {
                    if (isset($q[0], $q[1], $q[2])) {
                        // Standard SAVEQUERIES triple; QM's drop-in appends result/trace.
                        $rows = null;
                        if (isset($q['result']) && is_int($q['result'])) {
                            $rows = $q['result'];
                            $mode = 'qm';
                        } elseif ($mode === 'none') {
                            $mode = 'degraded';
                        }
                        $attr = $map->attribute_from_summary($q[2]);
                        $entries[] = array(
                            'sql' => $q[0],
                            'ms' => ((float) $q[1]) * 1000,
                            'rows' => $rows,
                            'err' => (isset($q['result']) && is_object($q['result'])) ? 'error' : null,
                            'component' => $attr['component'],
                            'ctype' => $attr['type'],
                            'caller' => $attr['caller'],
                            'via' => $attr['via'],
                            'chain' => (count($attr['chain']) > 1) ? $attr['chain'] : null,
                        );
                    }
                }
            }

            // Decide which entries keep full SQL: top N slowest, slow, big, or errored.
            $by_ms = array();
            foreach ($entries as $i => $e) {
                $by_ms[$i] = $e['ms'];
            }
            arsort($by_ms);
            $keep_full = array_slice(array_keys($by_ms), 0, self::FULL_SQL_TOP_N, true);
            $keep_full = array_flip($keep_full);

            $total_ms = 0;
            $total_rows = 0;
            $dupes = array();
            $queries = array();
            foreach ($entries as $i => $e) {
                $total_ms += $e['ms'];
                $total_rows += (int) $e['rows'];
                // True duplicates = byte-identical SQL. Fingerprint-identical queries with
                // different literals are N+1, not dupes - the query-hog heuristic's job.
                $fp = md5($e['sql']);
                if (!isset($dupes[$fp])) {
                    $dupes[$fp] = array('count' => 0, 'sql' => $e['sql'], 'component' => $e['component'], 'ms' => 0);
                }
                $dupes[$fp]['count']++;
                $dupes[$fp]['ms'] += $e['ms'];
                $full = isset($keep_full[$i]) || $e['ms'] >= self::FULL_SQL_MS || $e['rows'] >= self::FULL_SQL_ROWS || $e['err'];
                $queries[] = array(
                    'sql' => $full ? $e['sql'] : null,
                    'fp' => sspa_sql_fingerprint($e['sql']),
                    'ms' => round($e['ms'], 3),
                    'rows' => $e['rows'],
                    'err' => $e['err'],
                    'component' => $e['component'],
                    'ctype' => $e['ctype'],
                    'caller' => $e['caller'],
                    'via' => $e['via'],
                    'chain' => $e['chain'],
                );
            }

            $dupe_count = 0;
            $dupe_details = array();
            foreach ($dupes as $d) {
                if ($d['count'] > 1) {
                    $dupe_count += $d['count'] - 1;
                    $dupe_details[] = array(
                        'count' => $d['count'],
                        'ms' => round($d['ms'], 3),
                        'sql' => $d['sql'],
                        'component' => $d['component'],
                    );
                }
            }
            usort($dupe_details, function ($a, $b) {
                return $b['count'] <=> $a['count'];
            });
            $dupe_details = array_slice($dupe_details, 0, 10);

            return array(
                'mode' => $mode,
                'count' => ($mode === 'none' && isset($wpdb->num_queries)) ? (int) $wpdb->num_queries : count($entries),
                // With no per-query capture these are UNKNOWN, not zero. Storing 0 made a
                // blind run (e.g. riding an active Query Monitor on an anonymous request)
                // indistinguishable from a page that genuinely spent no time in SQL.
                'total_ms' => ($mode === 'none') ? null : round($total_ms, 3),
                'rows_total' => ($mode === 'none') ? null : $total_rows,
                'dupe_count' => ($mode === 'none') ? null : $dupe_count,
                'dupe_details' => $dupe_details,
                'truncated' => !empty($wpdb->sspa_truncated),
                // Diagnostic for attribution coverage, not for the user: how many queries had
                // their stack cut at MAX_FRAMES, potentially hiding the calling component.
                'frames_truncated' => isset($wpdb->sspa_frames_truncated) ? (int) $wpdb->sspa_frames_truncated : 0,
                'queries' => $queries,
            );
        }

        private function collect_http($map) {
            $calls = array();
            $total_ms = 0;
            foreach ($this->http_calls as $call) {
                $attr = $map->attribute($call['frames']);
                unset($call['frames']);
                $call['component'] = $attr['component'];
                $call['ctype'] = $attr['type'];
                $call['caller'] = $attr['caller'];
                $call['via'] = $attr['via'];
                $call['chain'] = (count($attr['chain']) > 1) ? $attr['chain'] : null;
                $call['ms'] = ($call['ms'] !== null) ? round($call['ms'], 1) : null;
                $total_ms += (float) $call['ms'];
                $calls[] = $call;
            }
            return array('count' => count($calls), 'total_ms' => round($total_ms, 1), 'calls' => $calls);
        }

        private function collect_mail($map) {
            $components = array();
            $calls = array();
            $total_construct_ms = 0;
            foreach ($this->mail_calls as $call) {
                $attr = $map->attribute($call['frames']);
                $key = $attr['component'];
                $components[$key] = isset($components[$key]) ? $components[$key] + 1 : 1;
                $ms = ($call['construct_ms'] !== null) ? round($call['construct_ms'], 2) : null;
                $total_construct_ms += (float) $ms;
                $calls[] = array('component' => $key, 'construct_ms' => $ms);
            }
            return array(
                'count' => count($this->mail_calls),
                'mode' => $this->mail_mode,
                'total_construct_ms' => round($total_construct_ms, 2),
                'by_component' => $components,
                'calls' => $calls,
            );
        }

        private function aggregate_components($sql, $http, $mail) {
            $agg = array();
            foreach ($sql['queries'] as $q) {
                $key = $q['component'];
                if (!isset($agg[$key])) {
                    $agg[$key] = array('type' => $q['ctype'], 'query_count' => 0, 'sql_ms' => 0, 'rows' => 0, 'slowest_ms' => 0, 'http_ms' => 0, 'mail_count' => 0);
                }
                $agg[$key]['query_count']++;
                $agg[$key]['sql_ms'] += $q['ms'];
                $agg[$key]['rows'] += (int) $q['rows'];
                $agg[$key]['slowest_ms'] = max($agg[$key]['slowest_ms'], $q['ms']);
            }
            foreach ($http['calls'] as $call) {
                $key = $call['component'];
                if (!isset($agg[$key])) {
                    $agg[$key] = array('type' => $call['ctype'], 'query_count' => 0, 'sql_ms' => 0, 'rows' => 0, 'slowest_ms' => 0, 'http_ms' => 0, 'mail_count' => 0);
                }
                $agg[$key]['http_ms'] += (float) $call['ms'];
            }
            foreach ($mail['by_component'] as $key => $count) {
                if (!isset($agg[$key])) {
                    $agg[$key] = array('type' => 'plugin', 'query_count' => 0, 'sql_ms' => 0, 'rows' => 0, 'slowest_ms' => 0, 'http_ms' => 0, 'mail_count' => 0);
                }
                $agg[$key]['mail_count'] += $count;
            }
            foreach ($agg as &$a) {
                $a['sql_ms'] = round($a['sql_ms'], 3);
                $a['http_ms'] = round($a['http_ms'], 1);
                $a['slowest_ms'] = round($a['slowest_ms'], 3);
            }
            return $agg;
        }
    }
}
