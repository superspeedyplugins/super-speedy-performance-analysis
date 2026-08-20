<?php
defined('ABSPATH') || exit;
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
            // Render phase. the_content is a FILTER - the wrap-on-entry callback passes
            // the value through untouched.
            'wp_head', 'wp_footer', 'the_content',
            // WooCommerce's template layout hooks: where a shop page's render work
            // actually lives (breadcrumbs, loop items, summaries, sidebar). Harmless
            // no-ops on sites without WooCommerce - the hooks simply never fire.
            'woocommerce_before_main_content', 'woocommerce_archive_description',
            'woocommerce_before_shop_loop', 'woocommerce_shop_loop',
            'woocommerce_before_shop_loop_item', 'woocommerce_shop_loop_item_title',
            'woocommerce_after_shop_loop_item', 'woocommerce_after_shop_loop',
            'woocommerce_after_main_content', 'woocommerce_before_single_product',
            'woocommerce_single_product_summary', 'woocommerce_after_single_product_summary',
            'woocommerce_after_single_product', 'woocommerce_sidebar',
        );

        /**
         * Pseudo-hooks + real hooks whose callback time belongs to the RENDER phase, so
         * the "Template render + output" segment can be decomposed per component.
         * Anything starting woocommerce_ in $hooks is render-phase too (checked by
         * prefix in is_render_hook()).
         */
        private static $render_hooks = array('wp_head', 'wp_footer', 'the_content', 'shortcode', 'widget', 'block');

        private static function is_render_hook($hook) {
            return in_array($hook, self::$render_hooks, true) || 0 === strpos($hook, 'woocommerce_');
        }

        private $milestones = array();   // name => seconds since $timestart
        private $plugin_ms = array();    // plugin file (dir/file.php) => include ms
        private $callbacks = array();    // [hook, callable, ms, label?]
        private $wrapped_hooks = array(); // hook => true once its queue is wrapped
        private $block_stack = array();  // in-flight dynamic block renders
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
                // Filter-safe: returns the first argument untouched, so wrapping works
                // for the_content and any future filter as well as for actions.
                add_filter($hook, function ($value = null) use ($hook) {
                    $this->wrap_pending($hook);
                    return $value;
                }, PHP_INT_MIN);
            }

            // Shortcodes and widgets are the render phase's named units of work; both
            // registries are plain arrays, fully populated by wp_loaded, and safe to
            // wrap in place.
            add_action('wp_loaded', function () {
                $this->wrap_shortcodes();
                $this->wrap_widgets();
            }, PHP_INT_MAX);

            // Dynamic blocks: pre_render_block/render_block bracket every block render,
            // so pairing them times each one. Only blocks WITH a render_callback are
            // tracked - static blocks are stored HTML with no PHP cost worth naming.
            add_filter('pre_render_block', function ($pre, $parsed = null) {
                $name = is_array($parsed) && !empty($parsed['blockName']) ? $parsed['blockName'] : null;
                if ($name && $this->block_is_dynamic($name)) {
                    $this->block_stack[] = array($name, microtime(true));
                }
                return $pre;
            }, PHP_INT_MIN, 2);
            add_filter('render_block', function ($content, $parsed = null) {
                $name = is_array($parsed) && !empty($parsed['blockName']) ? $parsed['blockName'] : null;
                $depth = count($this->block_stack);
                if ($name && $depth && $this->block_stack[$depth - 1][0] === $name) {
                    $entry = array_pop($this->block_stack);
                    // Only OUTERMOST dynamic blocks are recorded: an inner block's time
                    // is already inside its parent's, and double counting would push
                    // the render ledger past the segment total.
                    if (0 === count($this->block_stack)) {
                        $this->callbacks[] = array('block', $this->block_callback($name), (microtime(true) - $entry[1]) * 1000, $name);
                    }
                }
                return $content;
            }, PHP_INT_MAX, 2);
        }

        private function block_is_dynamic($name) {
            if (!class_exists('WP_Block_Type_Registry')) {
                return false;
            }
            $type = WP_Block_Type_Registry::get_instance()->get_registered($name);
            return $type && !empty($type->render_callback);
        }

        /** The block's render callback, so attribution resolves to the providing plugin. */
        private function block_callback($name) {
            $type = WP_Block_Type_Registry::get_instance()->get_registered($name);
            return ($type && !empty($type->render_callback)) ? $type->render_callback : null;
        }

        private function wrap_shortcodes() {
            global $shortcode_tags;
            if (!is_array($shortcode_tags)) {
                return;
            }
            foreach ($shortcode_tags as $tag => $callback) {
                $shortcode_tags[$tag] = function (...$args) use ($callback, $tag) {
                    $t0 = microtime(true);
                    $result = call_user_func_array($callback, $args);
                    $this->callbacks[] = array('shortcode', $callback, (microtime(true) - $t0) * 1000, '[' . $tag . ']');
                    return $result;
                };
            }
        }

        private function wrap_widgets() {
            global $wp_registered_widgets;
            if (!is_array($wp_registered_widgets)) {
                return;
            }
            foreach ($wp_registered_widgets as $id => $widget) {
                if (!isset($widget['callback']) || !is_callable($widget['callback'])) {
                    continue;
                }
                $callback = $widget['callback'];
                $label = !empty($widget['name']) ? $widget['name'] : $id;
                $wp_registered_widgets[$id]['callback'] = function (...$args) use ($callback, $label) {
                    $t0 = microtime(true);
                    $result = call_user_func_array($callback, $args);
                    $this->callbacks[] = array('widget', $callback, (microtime(true) - $t0) * 1000, $label);
                    return $result;
                };
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
            // Once per hook: the_content and the WooCommerce loop hooks fire once PER
            // POST/ITEM, and re-wrapping already-wrapped callbacks nests the timers and
            // counts the same work twice. The wrappers persist, so every later firing
            // is still timed - only the wrapping happens once.
            if (isset($this->wrapped_hooks[$hook])) {
                return;
            }
            $this->wrapped_hooks[$hook] = true;
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

        /** Raw milestones in ms since request start, for cross-referencing other collectors. */
        public function milestones_ms() {
            $out = array();
            foreach ($this->milestones as $k => $v) {
                $out[$k] = $v * 1000;
            }
            return $out;
        }

        /**
         * @param SSPA_Component_Map $map
         * @return array Capture-ready summary.
         */
        public function report($map) {
            // Called from the capture's shutdown handler, i.e. after output: this mark
            // closes the ledger so the segment table accounts for the WHOLE request.
            $this->mark('request_end');
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
            // individually so "init cost 90ms" comes with named offenders. Render-phase
            // work (wp_head/wp_footer/the_content callbacks, shortcodes, widgets) also
            // feeds a render sub-report so the render segment stops being one number.
            $hooks = array();
            $components = $includes; // combined ranking starts from include cost
            $top = array();
            $render = array('timed_ms' => 0, 'untimed_ms' => null, 'components' => array(), 'top' => array());
            foreach ($this->callbacks as $c) {
                $hook = $c[0];
                $callable = $c[1];
                $ms = $c[2];
                $label = isset($c[3]) ? $c[3] : $this->callable_label($callable);
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
                $entry = array('hook' => $hook, 'label' => $label, 'component' => $component, 'ms' => round($ms, 2));
                $top[] = $entry;

                if (self::is_render_hook($hook)) {
                    $render['timed_ms'] += $ms;
                    $render['components'][$component] =
                        round((isset($render['components'][$component]) ? $render['components'][$component] : 0) + $ms, 2);
                    $render['top'][] = $entry;
                }
            }
            foreach ($hooks as &$h) {
                $h['ms'] = round($h['ms'], 2);
                arsort($h['components']);
            }
            unset($h);
            arsort($components);
            $by_ms = function ($a, $b) {
                return $b['ms'] <=> $a['ms'];
            };
            usort($top, $by_ms);
            usort($render['top'], $by_ms);
            $render['top'] = array_slice($render['top'], 0, 10);
            arsort($render['components']);
            $render['timed_ms'] = round($render['timed_ms'], 1);
            if (isset($segments['render_and_output'])) {
                // The residual is code no wrapped surface covers: the theme's template
                // files themselves and direct output. Named so the UI can say so.
                $render['untimed_ms'] = round(max(0, $segments['render_and_output'] - $render['timed_ms']), 1);
            }

            return array(
                'segments' => $segments,
                'includes' => array_slice($includes, 0, 100, true),
                'hooks' => $hooks,
                'components' => array_slice($components, 0, 100, true),
                'top_callbacks' => array_slice($top, 0, 15),
                'render' => $render,
                'assets' => $this->assets(),
            );
        }

        /**
         * Which components put scripts/styles on this response. A plugin whose only
         * front-end job is enqueuing an asset does near-zero PHP work and runs no
         * queries, so every other instrument reads it as idle - this is the signal
         * that stops such a plugin being called safe to unload.
         *
         * Counted from the queues at shutdown: `done` holds handles actually printed,
         * `queue` catches anything enqueued but not yet printed when the request died.
         * Attribution is by the registered src URL's /plugins/<slug>/ segment; themes
         * fold into 'theme', core bundles and external URLs into 'core'.
         *
         * @return array component => {scripts:int, styles:int}
         */
        private function assets() {
            $out = array();
            foreach (array('scripts' => 'wp_scripts', 'styles' => 'wp_styles') as $kind => $global_key) {
                if (empty($GLOBALS[$global_key]) || !is_object($GLOBALS[$global_key])) {
                    continue;
                }
                $registry = $GLOBALS[$global_key];
                $handles = array_unique(array_merge(
                    isset($registry->done) ? (array) $registry->done : array(),
                    isset($registry->queue) ? (array) $registry->queue : array()
                ));
                foreach ($handles as $handle) {
                    if (empty($registry->registered[$handle]) || empty($registry->registered[$handle]->src)) {
                        continue; // alias handles (dependency bundles) carry no src
                    }
                    $src = str_replace('\\', '/', (string) $registry->registered[$handle]->src);
                    if (preg_match('#/wp-content/plugins/([^/]+)/#', $src, $m)) {
                        $component = $m[1];
                    } elseif (false !== strpos($src, '/wp-content/themes/')) {
                        $component = 'theme';
                    } else {
                        $component = 'core';
                    }
                    if (!isset($out[$component])) {
                        $out[$component] = array('scripts' => 0, 'styles' => 0);
                    }
                    $out[$component][$kind]++;
                }
            }
            return $out;
        }

        /**
         * Named phases in ms, derived from the milestone ledger. Missing marks yield no
         * row. The phases are contiguous and end at request_end, so they sum to the
         * whole request - a table that only accounted for part of the generation time
         * made the remainder look unmeasurable when it was simply the render phase.
         */
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
                'post_init_boot' => array('init_done', 'wp_loaded'),
                'routing_and_query' => array('wp_loaded', 'template_redirect'),
            );
            $accounted_to = null;
            foreach ($pairs as $name => $ends) {
                list($from, $to) = $ends;
                if (!isset($m[$to]) || (null !== $from && !isset($m[$from]))) {
                    continue;
                }
                $seg[$name] = round($m[$to] - (null === $from ? 0 : $m[$from]), 1);
                $accounted_to = max($accounted_to, $m[$to]);
            }
            // Everything after the last milestone: template render + output on front-end
            // pages, screen render on wp-admin (where template_redirect never fires).
            if (isset($m['request_end']) && null !== $accounted_to && $m['request_end'] > $accounted_to) {
                $seg['render_and_output'] = round($m['request_end'] - $accounted_to, 1);
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
                    $file = $ref->getFileName() ?: null;
                    // A plugin subclass calling through a core base method (every widget:
                    // [WC_Widget_X, 'display_callback'] resolves to WP_Widget in core)
                    // should be attributed to the SUBCLASS's file, or all widgets read
                    // as "core".
                    if ($file && is_object($callable[0]) && false !== strpos(str_replace('\\', '/', $file), '/wp-includes/')) {
                        $cref = new ReflectionClass($callable[0]);
                        $cfile = $cref->getFileName() ?: null;
                        if ($cfile && false === strpos(str_replace('\\', '/', $cfile), '/wp-includes/')) {
                            $file = $cfile;
                        }
                    }
                    return $file;
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
