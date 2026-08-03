<?php
// Standalone. Decomposes the per-request PHP "floor" - the cost every page pays before
// rendering - which single-plugin exclusion can never see when it is spread across many
// plugins in slices smaller than the noise gate.
//
// Two instruments, both attribution-grade and needing NOTHING installed on the server:
//
//   1. Plugin include timing. The mu-loader arms the capture before any regular plugin
//      loads, so hooking core's per-plugin `plugin_loaded` action and taking deltas
//      times every plugin's file include + top-level code.
//
//   2. Hook callback timing. For each profiled hook, a PHP_INT_MIN callback wraps every
//      later-priority callback in a timing closure as the hook starts to run. WP_Hook
//      reads each priority bucket live, so mutating buckets it has not reached yet is
//      safe, and wrapping from INSIDE the hook catches callbacks whenever they were
//      registered. Overhead per callback is two microtime() calls on profiled requests
//      only; real traffic never loads this file.
//
// Costs overlap by design: a plugin's include time, its plugins_loaded boot, and its
// init work are separate rows of the same story, not double counting - but hook totals
// must not be summed with segment totals blindly.

if (!class_exists('SSPA_Boot_Timer')) {
    class SSPA_Boot_Timer {

        /** Hooks whose callbacks get individually timed. */
        private static $hooks = array(
            'plugins_loaded', 'after_setup_theme', 'init', 'widgets_init', 'wp_loaded',
            'wp_enqueue_scripts', 'admin_init', 'admin_menu', 'admin_enqueue_scripts',
            'template_redirect', 'rest_api_init',
        );

        private $milestones = array();   // name => seconds since $timestart
        private $plugin_ms = array();    // plugin file (dir/file.php) => include ms
        private $callbacks = array();    // [hook, callable, ms]
        private $last_mark;

        public function install() {
            $this->last_mark = microtime(true);
            $this->mark('profiler_armed');

            add_action('muplugins_loaded', function () {
                $this->mark('plugins_start');
                $this->last_mark = microtime(true);
            }, PHP_INT_MAX);

            // Core fires this after each plugin file is included; the delta since the
            // previous event is that plugin's include + top-level cost (approximate for
            // the first plugin, which also absorbs core's small l10n setup).
            add_action('plugin_loaded', function ($plugin) {
                $now = microtime(true);
                $file = str_replace('\\', '/', (string) $plugin);
                $pos = strpos($file, '/wp-content/plugins/');
                $key = ($pos !== false) ? substr($file, $pos + strlen('/wp-content/plugins/')) : basename($file);
                $this->plugin_ms[$key] = round(($now - $this->last_mark) * 1000, 2);
                $this->last_mark = $now;
            });

            add_action('plugins_loaded', function () {
                $this->mark('includes_done');
            }, PHP_INT_MIN);
            add_action('plugins_loaded', function () {
                $this->mark('plugins_loaded_done');
            }, PHP_INT_MAX);
            add_action('after_setup_theme', function () {
                $this->mark('theme_loaded');
            }, PHP_INT_MIN);
            add_action('init', function () {
                $this->mark('init_start');
            }, PHP_INT_MIN);
            add_action('init', function () {
                $this->mark('init_done');
            }, PHP_INT_MAX);
            add_action('wp_loaded', function () {
                $this->mark('wp_loaded');
            }, PHP_INT_MAX);
            add_action('template_redirect', function () {
                $this->mark('template_redirect');
            }, PHP_INT_MIN);

            foreach (self::$hooks as $hook) {
                add_action($hook, function () use ($hook) {
                    $this->wrap_pending($hook);
                }, PHP_INT_MIN);
            }
        }

        private function mark($name) {
            if (!isset($this->milestones[$name])) {
                $start = isset($GLOBALS['timestart']) ? (float) $GLOBALS['timestart'] : $this->last_mark;
                $this->milestones[$name] = microtime(true) - $start;
            }
        }

        /**
         * Replace every not-yet-run callback on $hook with a timing wrapper. Runs as the
         * hook's first (PHP_INT_MIN) callback; only buckets WP_Hook has not reached yet
         * are touched, and each entry keeps its accepted_args, so behaviour is identical.
         */
        private function wrap_pending($hook) {
            global $wp_filter;
            if (!isset($wp_filter[$hook]) || !($wp_filter[$hook] instanceof WP_Hook)) {
                return;
            }
            foreach ($wp_filter[$hook]->callbacks as $priority => $bucket) {
                if (PHP_INT_MIN === $priority) {
                    continue; // ourselves (and any coincident callback already running)
                }
                foreach ($bucket as $idx => $entry) {
                    $original = $entry['function'];
                    $wp_filter[$hook]->callbacks[$priority][$idx]['function'] = function (...$args) use ($original, $hook) {
                        $t0 = microtime(true);
                        $result = call_user_func_array($original, $args);
                        $this->callbacks[] = array($hook, $original, (microtime(true) - $t0) * 1000);
                        return $result;
                    };
                }
            }
        }

        /**
         * @param SSPA_Component_Map $map
         * @return array Capture-ready summary.
         */
        public function report($map) {
            $segments = $this->segments();

            // Per-plugin include cost, slugged, largest first.
            arsort($this->plugin_ms);
            $includes = array();
            foreach ($this->plugin_ms as $file => $ms) {
                $dir = dirname($file);
                $slug = ('.' !== $dir) ? $dir : basename($file, '.php');
                $includes[$slug] = round((isset($includes[$slug]) ? $includes[$slug] : 0) + $ms, 2);
            }

            // Attribute each timed callback to its component; keep the slowest few
            // individually so "init cost 90ms" comes with named offenders.
            $hooks = array();
            $components = $includes; // combined ranking starts from include cost
            $top = array();
            foreach ($this->callbacks as $c) {
                list($hook, $callable, $ms) = $c;
                $label = $this->callable_label($callable);
                $file = $this->callable_file($callable);
                $cls = $file ? $map->classify_file($file) : array('component' => 'core', 'type' => 'core');
                $component = $cls['component'];

                if (!isset($hooks[$hook])) {
                    $hooks[$hook] = array('ms' => 0, 'components' => array());
                }
                $hooks[$hook]['ms'] += $ms;
                $hooks[$hook]['components'][$component] =
                    round((isset($hooks[$hook]['components'][$component]) ? $hooks[$hook]['components'][$component] : 0) + $ms, 2);

                if ('core' !== $component) {
                    $components[$component] = round((isset($components[$component]) ? $components[$component] : 0) + $ms, 2);
                }
                $top[] = array('hook' => $hook, 'label' => $label, 'component' => $component, 'ms' => round($ms, 2));
            }
            foreach ($hooks as &$h) {
                $h['ms'] = round($h['ms'], 2);
                arsort($h['components']);
            }
            unset($h);
            arsort($components);
            usort($top, function ($a, $b) {
                return $b['ms'] <=> $a['ms'];
            });

            return array(
                'segments' => $segments,
                'includes' => array_slice($includes, 0, 100, true),
                'hooks' => $hooks,
                'components' => array_slice($components, 0, 100, true),
                'top_callbacks' => array_slice($top, 0, 15),
            );
        }

        /** Named phases in ms, derived from the milestone ledger. Missing marks yield no row. */
        private function segments() {
            $m = array();
            foreach ($this->milestones as $k => $v) {
                $m[$k] = $v * 1000;
            }
            $seg = array();
            $pairs = array(
                'core_before_plugins' => array(null, 'plugins_start'),
                'plugin_includes' => array('plugins_start', 'includes_done'),
                'plugins_loaded_callbacks' => array('includes_done', 'plugins_loaded_done'),
                'theme_load_and_setup' => array('plugins_loaded_done', 'init_start'),
                'init_callbacks' => array('init_start', 'init_done'),
                'routing_and_query' => array('wp_loaded', 'template_redirect'),
            );
            foreach ($pairs as $name => $ends) {
                list($from, $to) = $ends;
                if (!isset($m[$to]) || (null !== $from && !isset($m[$from]))) {
                    continue;
                }
                $seg[$name] = round($m[$to] - (null === $from ? 0 : $m[$from]), 1);
            }
            return $seg;
        }

        private function callable_label($callable) {
            if (is_string($callable)) {
                return $callable;
            }
            if (is_array($callable) && 2 === count($callable)) {
                return (is_object($callable[0]) ? get_class($callable[0]) : (string) $callable[0]) . '::' . $callable[1];
            }
            if ($callable instanceof Closure) {
                $file = $this->callable_file($callable);
                return 'closure (' . ($file ? basename($file) : 'unknown') . ')';
            }
            return is_object($callable) ? get_class($callable) . '::__invoke' : 'unknown';
        }

        private function callable_file($callable) {
            try {
                if ($callable instanceof Closure || (is_string($callable) && function_exists($callable))) {
                    $ref = new ReflectionFunction($callable);
                    return $ref->getFileName() ?: null;
                }
                if (is_array($callable) && 2 === count($callable) && method_exists($callable[0], $callable[1])) {
                    $ref = new ReflectionMethod($callable[0], $callable[1]);
                    return $ref->getFileName() ?: null;
                }
                if (is_object($callable) && method_exists($callable, '__invoke')) {
                    $ref = new ReflectionMethod($callable, '__invoke');
                    return $ref->getFileName() ?: null;
                }
            } catch (Throwable $e) {
                return null;
            }
            return null;
        }
    }
}
