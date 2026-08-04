<?php
// Standalone. Phase-5 sampling collector (design: .docs/implementation-plan-profilers-
// and-digests.md section B): Excimer records a COMPLETE call stack every sample period,
// so the same attribution walk used for SQL runs exactly over profiler data - no
// caller-graph apportioning, no shared-vendor blame bug. Sampling overhead is
// negligible (Wikipedia runs it on production traffic), which is why this collector is
// allowed to run DURING the normal measurement pass: it does not distort the timings
// being measured. The XHProf-family collector (phase 6, exact call counts) must never
// make that claim.
//
// Sees what no hook wrapper can: inside theme template files, closures, vendor
// internals. Statistical by nature - a function cheaper than the sample period is
// invisible, and "samples" only approximate wall time (ms = samples x period).
//
// Armed requests only; the extension's absence is normal, not an error.

if (!class_exists('SSPA_Excimer')) {
    class SSPA_Excimer {

        const PERIOD_MS = 1.0;   // 1ms: ~500 samples on a 500ms page - enough for
                                 // "where did the time go", useless below ~5ms cost.
        const MAX_FUNCTIONS = 40;

        private $profiler = null;

        public static function available() {
            return extension_loaded('excimer') && class_exists('ExcimerProfiler');
        }

        /** Start sampling. Called from the capture's arm(), i.e. before plugins load. */
        public function start() {
            if (!self::available()) {
                return;
            }
            try {
                $profiler = new ExcimerProfiler();
                $profiler->setPeriod(self::PERIOD_MS / 1000);
                $profiler->setEventType(EXCIMER_REAL);
                $profiler->start();
                $this->profiler = $profiler;
            } catch (Throwable $e) {
                $this->profiler = null;
            }
        }

        /**
         * Stop and aggregate. Two views of the same samples:
         * - components: every sample's full stack attributed via the component map's
         *   chain walk (executor, vendor-aware) - statistical wall ms per component.
         * - functions: per-function inclusive/self sample counts, the function itself
         *   classified to its owning component.
         *
         * @param SSPA_Component_Map $map
         * @return array|null Null when the extension is absent or anything failed.
         */
        public function report($map) {
            if (!$this->profiler) {
                return null;
            }
            try {
                $this->profiler->stop();
                $log = $this->profiler->getLog();
            } catch (Throwable $e) {
                return null;
            }

            $functions = array();
            $components = array();
            $by_caller = array(); // leaf fn => [driving component => samples]
            $total = 0;

            foreach ($log as $entry) {
                $trace = $entry->getTrace(); // frames innermost-first
                if (!is_array($trace) || !$trace) {
                    continue;
                }
                $count = max(1, (int) $entry->getEventCount());
                $total += $count;

                $frames = array();
                foreach ($trace as $f) {
                    $frames[] = array(
                        isset($f['file']) ? $f['file'] : '',
                        isset($f['line']) ? (int) $f['line'] : 0,
                        self::frame_name($f),
                    );
                }
                $attr = $map->attribute($frames);
                $comp = $attr['component'];
                $components[$comp] = (isset($components[$comp]) ? $components[$comp] : 0) + $count;

                $seen = array();
                foreach ($trace as $i => $f) {
                    $name = self::frame_name($f);
                    if ('' === $name) {
                        continue;
                    }
                    // Split the LEAF function's self time by the component the whole
                    // stack attributes to - "63ms in WP_Object_Cache::get" becomes
                    // "driven by woocommerce 30ms, rank-math 12ms". The map's chain walk
                    // already decided who owns each sample; this just files the leaf's
                    // time under it.
                    if (0 === $i) {
                        if (!isset($by_caller[$name])) {
                            $by_caller[$name] = array();
                        }
                        $by_caller[$name][$comp] = (isset($by_caller[$name][$comp]) ? $by_caller[$name][$comp] : 0) + $count;
                    }
                    if (!isset($functions[$name])) {
                        $file = isset($f['file']) ? (string) $f['file'] : '';
                        $cls = $file ? $map->classify_file($file) : array('component' => 'core', 'type' => 'core');
                        $functions[$name] = array(
                            'incl' => 0,
                            'self' => 0,
                            'component' => $cls['component'],
                            'ctype' => isset($cls['type']) ? $cls['type'] : 'core',
                            'file' => $file ? basename($file) : '',
                            'line' => isset($f['line']) ? (int) $f['line'] : 0,
                        );
                    }
                    // Once per stack, or recursion would multiply inclusive time.
                    if (!isset($seen[$name])) {
                        $functions[$name]['incl'] += $count;
                        $seen[$name] = true;
                    }
                    if (0 === $i) {
                        $functions[$name]['self'] += $count;
                    }
                }
            }

            if (!$total) {
                return null;
            }

            uasort($functions, function ($a, $b) {
                return $b['incl'] <=> $a['incl'];
            });
            $rows = array();
            foreach (array_slice($functions, 0, self::MAX_FUNCTIONS, true) as $name => $fn) {
                $drivers = array();
                if (isset($by_caller[$name])) {
                    arsort($by_caller[$name]);
                    foreach (array_slice($by_caller[$name], 0, 4, true) as $c => $samples) {
                        $drivers[$c] = round($samples * self::PERIOD_MS, 1);
                    }
                }
                $rows[] = array(
                    'fn' => $name,
                    'file' => $fn['file'],
                    'line' => $fn['line'],
                    'component' => $fn['component'],
                    'ctype' => $fn['ctype'],
                    'incl_ms' => round($fn['incl'] * self::PERIOD_MS, 1),
                    'self_ms' => round($fn['self'] * self::PERIOD_MS, 1),
                    'by' => $drivers,
                );
            }

            arsort($components);
            $comp_ms = array();
            foreach ($components as $c => $samples) {
                $comp_ms[$c] = round($samples * self::PERIOD_MS, 1);
            }

            return array(
                'collector' => 'excimer',
                'period_ms' => self::PERIOD_MS,
                'samples' => $total,
                'wall_ms' => round($total * self::PERIOD_MS, 1),
                'functions' => $rows,
                'components' => $comp_ms,
            );
        }

        private static function frame_name($f) {
            $fn = isset($f['function']) ? (string) $f['function'] : '';
            if ('' === $fn) {
                // The outermost frame of a file include has no function name.
                return isset($f['file']) ? 'include ' . basename((string) $f['file']) : '';
            }
            $name = (isset($f['class']) ? $f['class'] . '::' : '') . $fn;
            if (isset($f['closure_line']) && isset($f['file'])) {
                $name .= ' (' . basename((string) $f['file']) . ':' . (int) $f['closure_line'] . ')';
            }
            return $name;
        }
    }
}
