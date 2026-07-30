<?php
// Instantiated by the sspa db.php shim for token-bearing requests only. Records what core
// SAVEQUERIES cannot: returned row counts, per-query errors, and real backtrace frames for
// component attribution. Normal traffic never sees this class.

if (!class_exists('SSPA_Profiling_WPDB') && class_exists('wpdb')) {
    class SSPA_Profiling_WPDB extends wpdb {

        const MAX_LOGGED_QUERIES = 20000;
        const MAX_FRAMES = 32;

        public $sspa_log = array();
        public $sspa_truncated = false;
        /**
         * How many queries had their backtrace cut off at MAX_FRAMES. A deep stack that hits
         * the ceiling can hide the plugin-to-plugin boundary, which is what caller-mode
         * attribution needs, so a high number here means the limit is costing us chains
         * rather than there being none to find. Measured rather than assumed.
         */
        public $sspa_frames_truncated = 0;

        public function query($query) {
            $start = microtime(true);
            $result = parent::query($query);
            $ms = (microtime(true) - $start) * 1000;

            if (count($this->sspa_log) >= self::MAX_LOGGED_QUERIES) {
                $this->sspa_truncated = true;
                return $result;
            }

            $frames = array();
            // Ask for one more than we keep: if the extra frame exists, the stack was deeper
            // than MAX_FRAMES and we know we truncated rather than having to guess.
            $raw = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, self::MAX_FRAMES + 3);
            foreach ($raw as $f) {
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
            if (count($frames) >= self::MAX_FRAMES && count($raw) > self::MAX_FRAMES) {
                $this->sspa_frames_truncated++;
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
