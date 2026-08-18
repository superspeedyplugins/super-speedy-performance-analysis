<?php
defined('ABSPATH') || exit;
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
            // Isolation cells: refuse destructive statements outright. Hook neutering in the
            // mu-loader stops a reacting plugin's registered (de)activation routine, but a
            // reaction coded INLINE - "dependency missing, drop my indexes" right in the
            // bootstrap - arrives here with no hook to remove. Whatever the route, the
            // statement is not executed; the caller is told it succeeded so it does not
            // retry or error-cascade, and the attempt is recorded so the cell is reported
            // as a reaction rather than trusted as a measurement.
            if (!empty($GLOBALS['sspa_isolation_cell']) && self::sspa_is_destructive($query)) {
                if (!isset($GLOBALS['sspa_plugin_reactions'])) {
                    $GLOBALS['sspa_plugin_reactions'] = array();
                }
                $GLOBALS['sspa_plugin_reactions'][] = array(
                    'op' => 'sql',
                    'sql' => substr((string) $query, 0, 300),
                    'frames' => $this->sspa_frames(),
                );
                $this->last_error = '';
                return true;
            }

            $start = microtime(true);
            $result = parent::query($query);
            $ms = (microtime(true) - $start) * 1000;

            if (count($this->sspa_log) >= self::MAX_LOGGED_QUERIES) {
                $this->sspa_truncated = true;
                return $result;
            }

            $frames = $this->sspa_frames();

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

        /** Backtrace frames in the capture's shape, shared by query logging and the guard. */
        private function sspa_frames() {
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
            return $frames;
        }

        /**
         * Statements that destroy data or schema. Deliberately NOT all writes: pages
         * legitimately write during a measurement (transients, sessions, carts), and a
         * bounded DELETE is routine cleanup. What no measurement may ever cause:
         *  - DROP TABLE / INDEX / VIEW / TRIGGER / DATABASE
         *  - TRUNCATE, RENAME TABLE
         *  - ALTER TABLE carrying a DROP (columns, indexes)
         *  - a whole-table DELETE (no WHERE)
         * DROP TEMPORARY TABLE stays allowed - temp tables die with the connection anyway.
         */
        public static function sspa_is_destructive($sql) {
            $sql = ltrim((string) $sql);
            if (preg_match('/^(DROP\s+(TABLE|INDEX|VIEW|TRIGGER|DATABASE)\b|TRUNCATE\b|RENAME\s+TABLE\b)/i', $sql)) {
                return true;
            }
            if (preg_match('/^ALTER\s+TABLE\b/i', $sql) && preg_match('/\bDROP\b/i', $sql)) {
                return true;
            }
            if (preg_match('/^DELETE\s+FROM\s+\S+\s*;?\s*$/is', $sql)) {
                return true;
            }
            return false;
        }
    }
}
