<?php
// Instantiated by the sspa db.php shim for token-bearing requests only. Records what core
// SAVEQUERIES cannot: returned row counts, per-query errors, and real backtrace frames for
// component attribution. Normal traffic never sees this class.

if (!class_exists('SSPA_Profiling_WPDB') && class_exists('wpdb')) {
    class SSPA_Profiling_WPDB extends wpdb {

        const MAX_LOGGED_QUERIES = 20000;
        const MAX_FRAMES = 14;

        public $sspa_log = array();
        public $sspa_truncated = false;

        public function query($query) {
            $start = microtime(true);
            $result = parent::query($query);
            $ms = (microtime(true) - $start) * 1000;

            if (count($this->sspa_log) >= self::MAX_LOGGED_QUERIES) {
                $this->sspa_truncated = true;
                return $result;
            }

            $frames = array();
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, self::MAX_FRAMES + 2) as $f) {
                if (!isset($f['file'])) {
                    continue;
                }
                $frames[] = array(
                    $f['file'],
                    isset($f['line']) ? $f['line'] : 0,
                    (isset($f['class']) ? $f['class'] . '::' : '') . $f['function'],
                );
                if (count($frames) >= self::MAX_FRAMES) {
                    break;
                }
            }

            $is_write = (bool) preg_match('/^\s*(insert|update|delete|replace|create|alter|drop|truncate)\b/i', (string) $query);
            $this->sspa_log[] = array(
                'sql' => (string) $query,
                'ms' => $ms,
                'rows' => $is_write ? (int) $this->rows_affected : (int) $this->num_rows,
                'err' => ($this->last_error !== '') ? $this->last_error : null,
                'frames' => $frames,
            );

            return $result;
        }
    }
}
