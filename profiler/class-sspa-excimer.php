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
        const MAX_PHASE_FUNCTIONS = 10;

        private $profiler = null;
        private $started_at = null;

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
                $this->started_at = microtime(true);
            } catch (Throwable $e) {
                $this->profiler = null;
            }
        }

        /**
         * The request phases as ordered [key, start_ms, end_ms] windows on the same
         * clock the samples use (ms since WordPress's $timestart). Mirrors the boot
         * timer's segment derivation; the final window always runs to request_end so
         * render (or the admin screen render) is covered even when template_redirect
         * never fired.
         */
        private static function phase_windows($milestones) {
            $order = array(
                'core_before_plugins' => array(null, 'plugins_start'),
                'plugin_includes' => array('plugins_start', 'includes_done'),
                'plugins_loaded_callbacks' => array('includes_done', 'plugins_loaded_done'),
                'theme_load_and_setup' => array('plugins_loaded_done', 'init_start'),
                'init_callbacks' => array('init_start', 'init_done'),
                'post_init_boot' => array('init_done', 'wp_loaded'),
                'routing_and_query' => array('wp_loaded', 'template_redirect'),
            );
            $windows = array();
            $covered_to = 0.0;
            foreach ($order as $key => $ends) {
                list($from, $to) = $ends;
                if (!isset($milestones[$to]) || (null !== $from && !isset($milestones[$from]))) {
                    continue;
                }
                $start = (null === $from) ? 0.0 : (float) $milestones[$from];
                $windows[] = array($key, $start, (float) $milestones[$to]);
                $covered_to = max($covered_to, (float) $milestones[$to]);
            }
            if (isset($milestones['request_end']) && (float) $milestones['request_end'] > $covered_to) {
                // On a REST or ?wc-ajax= request there is no template to render and this
                // window holds nearly everything the endpoint did, so "render_and_output"
                // would be actively misleading - which matters most on exactly the
                // requests the checkout flow measures.
                $windows[] = array(self::is_endpoint_request() ? 'endpoint_work' : 'render_and_output', $covered_to, (float) $milestones['request_end']);
            }
            return $windows;
        }

        private static function is_endpoint_request() {
            if (defined('REST_REQUEST') && REST_REQUEST) {
                return true;
            }
            if (isset($_GET['wc-ajax']) || isset($_GET['rest_route'])) { // phpcs:ignore WordPress.Security.NonceVerification
                return true;
            }
            $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
            $prefix = function_exists('rest_get_url_prefix') ? rest_get_url_prefix() : 'wp-json';
            return false !== strpos($uri, '/' . $prefix . '/');
        }

        private static function phase_for($ms, $windows) {
            foreach ($windows as $w) {
                if ($ms >= $w[1] && $ms < $w[2]) {
                    return $w[0];
                }
            }
            return null;
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
        public function report($map, $milestones = array()) {
            if (!$this->profiler) {
                return null;
            }
            try {
                $this->profiler->stop();
                $log = $this->profiler->getLog();
            } catch (Throwable $e) {
                return null;
            }

            // Align the sample clock (seconds since profiler start) with the milestone
            // clock (ms since WordPress's $timestart).
            $offset_ms = 0.0;
            if (isset($GLOBALS['timestart']) && null !== $this->started_at) {
                $offset_ms = ($this->started_at - (float) $GLOBALS['timestart']) * 1000;
            }
            $windows = self::phase_windows($milestones);

            $functions = array();
            $components = array();
            $by_caller = array(); // leaf fn => [driving component => samples]
            $phase_fns = array(); // phase => [leaf fn => [samples, component]]
            $total = 0;

            foreach ($log as $entry) {
                $trace = $entry->getTrace(); // frames innermost-first
                if (!is_array($trace) || !$trace) {
                    continue;
                }
                $count = max(1, (int) $entry->getEventCount());
                $total += $count;
                $phase = $windows ? self::phase_for($offset_ms + $entry->getTimestamp() * 1000, $windows) : null;

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
                        // Per-phase leaf accounting: which functions were actually
                        // running DURING each request phase - the contextual answer to
                        // "what is inside this phase's untimed remainder".
                        if (null !== $phase) {
                            if (!isset($phase_fns[$phase][$name])) {
                                $file0 = isset($f['file']) ? (string) $f['file'] : '';
                                $cls0 = $file0 ? $map->classify_file($file0) : array('component' => 'core');
                                $phase_fns[$phase][$name] = array('samples' => 0, 'component' => $cls0['component']);
                            }
                            $phase_fns[$phase][$name]['samples'] += $count;
                        }
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

            $phases = array();
            foreach ($phase_fns as $phase => $fns) {
                uasort($fns, function ($a, $b) {
                    return $b['samples'] <=> $a['samples'];
                });
                $list = array();
                $phase_total = 0;
                foreach ($fns as $name => $info) {
                    $phase_total += $info['samples'];
                    if (count($list) < self::MAX_PHASE_FUNCTIONS) {
                        $list[] = array(
                            'fn' => $name,
                            'component' => $info['component'],
                            'self_ms' => round($info['samples'] * self::PERIOD_MS, 1),
                        );
                    }
                }
                $phases[$phase] = array(
                    'total_ms' => round($phase_total * self::PERIOD_MS, 1),
                    'functions' => $list,
                );
            }

            return array(
                'collector' => 'excimer',
                'period_ms' => self::PERIOD_MS,
                'samples' => $total,
                'wall_ms' => round($total * self::PERIOD_MS, 1),
                'functions' => $rows,
                'components' => $comp_ms,
                'phases' => $phases,
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
