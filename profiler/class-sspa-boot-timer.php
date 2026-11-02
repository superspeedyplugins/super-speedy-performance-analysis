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
//   2. Selected or all-hook callback timing. Core invokes `all` before a hook
//      enters its callback queue. Wrappers retain registry IDs and accepted_args;
//      callback time uses a nesting stack. Reference callbacks remain untouched.
//      CPU and template boundaries complement this ledger. Real traffic never
//      loads this file.
//
// Callback rows use exclusive wall time. Phase windows and SQL/HTTP instruments
// overlap that wall time and must never be added to it.

if (!class_exists('SSPA_Boot_Timer')) {
    class SSPA_Boot_Timer {

        /** Hooks whose callbacks get individually timed. */
        private static $hooks = array(
            'plugins_loaded', 'after_setup_theme', 'init', 'widgets_init', 'wp_loaded',
            'wp_enqueue_scripts', 'admin_init', 'admin_menu', 'admin_enqueue_scripts',
            'template_redirect', 'rest_api_init',
            // Where cookies are commonly sent: wp runs the main query, send_headers sends headers.
            'wp', 'send_headers',
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

        private $reference_callbacks = array();
        private $unreflected_callbacks = array();
        private $hook_mode = 'selected';
        private $stack = array();
        private $wrappers;
        private $callback_count = 0;
        private $events = array();
        private $intervals = array();
        private $stream_spans = array();
        private $template = null;
        private $templates = array();
        private $template_starts = array();
        private $template_spans = array();
        private $cpu_end = null;
        private $wall_end = null;
        private $callback_wall_ms = 0;
        private $last_include_callback_ms = 0;
        private $cpu_start = null;
        private $wall_start = null;

        // Set-Cookie attribution: a cursor over the Set-Cookie lines in headers_list(), checked
        // at every boundary this timer already has. Names only; a value is never read.
        private $cookie_cursor = 0;
        private $cookie_events = array();
        private $cookie_owners = array();
        private $cookie_checks = 0;
        private $cookie_check_s = 0.0;

        private $milestones = array();   // name => seconds since $timestart
        private $plugin_ms = array();    // plugin file (dir/file.php) => include ms
        private $callbacks = array();    // [hook, callable, ms, label?]
        private $last_mark;

        public function install($hook_mode = 'selected') {
            $this->hook_mode = $hook_mode;
            $this->wrappers = new SplObjectStorage();
            $this->wall_start = microtime(true);
            $this->cpu_start = function_exists('getrusage') ? getrusage() : null;
            $this->last_mark = microtime(true);
            $this->mark('profiler_armed');
            // Anything already queued was set before the profiler armed.
            $this->cookie_check(null, 'before the profiler started');

            add_action('muplugins_loaded', function () {
                $this->mark('plugins_start');
                $this->last_mark = microtime(true);
                $this->last_include_callback_ms = $this->callback_wall_ms;
            }, PHP_INT_MAX);

            // Core fires this after each plugin file is included; the delta since the
            // previous event is that plugin's include + top-level cost (approximate for
            // the first plugin, which also absorbs core's small l10n setup).
            add_action('plugin_loaded', function ($plugin) {
                $now = microtime(true);
                $file = str_replace('\\', '/', (string) $plugin);
                $pos = strpos($file, '/wp-content/plugins/');
                $key = ($pos !== false) ? substr($file, $pos + strlen('/wp-content/plugins/')) : basename($file);
                $this->plugin_ms[$key] = round(max(0, ($now - $this->last_mark) * 1000 - ($this->callback_wall_ms - $this->last_include_callback_ms)), 2);
                $this->cookie_check(array('kind' => 'plugin_include', 'file' => $file, 'key' => $key), 'plugin include');
                $this->last_include_callback_ms = $this->callback_wall_ms;
                $this->last_mark = $now;
            });

            // MU and network plugins have their own per-file actions; a cookie set by one's top-level
            // code is that file's.
            foreach (array('mu_plugin_loaded' => 'mu_plugin_include', 'network_plugin_loaded' => 'plugin_include') as $action => $kind) {
                add_action($action, function ($plugin) use ($kind) {
                    $file = str_replace('\\', '/', (string) $plugin);
                    $this->cookie_check(array('kind' => $kind, 'file' => $file, 'key' => basename(dirname($file)) . '/' . basename($file)), $kind);
                });
            }

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

            add_filter('template_include', function ($file) {
                $this->template = $file;
                $this->event('template_include');
                return $file;
            }, PHP_INT_MAX);
            foreach (array('get_header', 'get_footer', 'get_sidebar', 'loop_start', 'loop_end', 'shutdown') as $boundary) {
                add_action($boundary, function () use ($boundary) { $this->event($boundary); }, PHP_INT_MIN);
            }
            add_action('all', function ($hook) {
                if (null !== $this->wall_end) return;
                if (0 === strpos($hook, 'get_template_part_')) $this->event($hook);
                if ('all' === $this->hook_mode || ('selected' === $this->hook_mode && in_array($hook, self::$hooks, true))) $this->wrap_pending($hook);
            }, PHP_INT_MIN);
            add_action('wp_before_load_template', function ($file) {
                $this->template_starts[] = array('file' => $file, 'start' => microtime(true), 'render' => isset($this->milestones['template_redirect']));
                $this->templates[] = $this->template;
                $this->template = $file;
                $this->event('before template ' . basename($file));
            }, PHP_INT_MIN);
            add_action('wp_after_load_template', function ($file) {
                $span = array_pop($this->template_starts);
                if ($span) { $span['end'] = microtime(true); $this->template_spans[] = $span; }
                $this->template = array_pop($this->templates);
                $this->event('after template ' . basename($file));
            }, PHP_INT_MAX);

            // Shortcodes and widgets are the render phase's named units of work; both
            // registries are plain arrays, fully populated by wp_loaded, and safe to
            // wrap in place.
            add_action('wp_loaded', function () {
                if ('none' !== $this->hook_mode) { $this->wrap_shortcodes(); $this->wrap_widgets(); }
            }, PHP_INT_MAX);

            add_filter('pre_render_block', function ($pre, $parsed = null) {
                $name = is_array($parsed) && !empty($parsed['blockName']) ? $parsed['blockName'] : null;
                if ('none' !== $this->hook_mode && $name && $this->block_is_dynamic($name)) {
                    $type = WP_Block_Type_Registry::get_instance()->get_registered($name);
                    $type->render_callback = $this->wrapper($type->render_callback, 'block', $name);
                }
                return $pre;
            }, PHP_INT_MIN, 2);
        }

        private function block_is_dynamic($name) {
            if (!class_exists('WP_Block_Type_Registry')) {
                return false;
            }
            $type = WP_Block_Type_Registry::get_instance()->get_registered($name);
            return $type && !empty($type->render_callback);
        }

        private function wrap_shortcodes() {
            global $shortcode_tags;
            if (!is_array($shortcode_tags)) {
                return;
            }
            foreach ($shortcode_tags as $tag => $callback) {
                $shortcode_tags[$tag] = $this->wrapper($callback, 'shortcode', '[' . $tag . ']');
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
                $wp_registered_widgets[$id]['callback'] = $this->wrapper($callback, 'widget', $label);
            }
        }

        private function mark($name) {
            if (!isset($this->milestones[$name])) {
                $start = isset($GLOBALS['timestart']) ? (float) $GLOBALS['timestart'] : $this->last_mark;
                $this->milestones[$name] = microtime(true) - $start;
            }
        }

        /**
         * Wrap the queue before WP_Hook enters it, preserving registry IDs,
         * priority and accepted_args. Reference signatures are handled below.
         */
        private function wrap_pending($hook) {
            global $wp_filter;
            if ('all' === $hook || !isset($wp_filter[$hook]) || !($wp_filter[$hook] instanceof WP_Hook)) return;
            // Inspect every entry on every firing: callbacks can be added after a hook's
            // first execution. Preserve priority, accepted_args and original registry ID.
            foreach ($wp_filter[$hook]->callbacks as $priority => $bucket) {
                foreach ($bucket as $idx => $entry) {
                    $wp_filter[$hook]->callbacks[$priority][$idx]['function'] = $this->wrapper($entry['function'], $hook);
                }
            }
        }

        private function wrapper($original, $hook, $label = null) {
            if ($original instanceof Closure && $this->wrappers->offsetExists($original)) return $original;
            $key = $hook . ':' . (is_object($original) ? spl_object_hash($original) : md5(serialize(is_array($original) ? array(is_object($original[0]) ? spl_object_hash($original[0]) : $original[0], $original[1]) : $original))) . ':' . $label;
            $class = is_array($original) && is_string($original[0]) ? $original[0] : null;
            if (is_string($original) && false !== strpos($original, '::')) $class = explode('::', $original, 2)[0];
            // Reflection would autoload this class before earlier-priority callbacks.
            // Leave its first invocation native; a later hook firing can inspect it.
            if ($class && !class_exists($class, false)) {
                $this->unreflected_callbacks[$key] = $hook . ':' . $this->callable_label($original);
                return $original;
            }
            // A generic variadic closure cannot preserve arbitrary PHP reference
            // parameters/returns. Keep these callbacks intact and report the gap.
            try {
                $ref = $this->reflection($original);
                $by_reference = $ref->returnsReference();
                foreach ($ref->getParameters() as $parameter) $by_reference = $by_reference || $parameter->isPassedByReference();
                if ($by_reference) {
                    $this->reference_callbacks[$key] = $hook . ':' . $this->callable_label($original);
                    return $original;
                }
            } catch (ReflectionException $e) {
                // Keep WordPress's native invocation, explicitly recording the
                // signature we could not inspect (including magic callables).
                $this->unreflected_callbacks[$key] = $hook . ':' . $this->callable_label($original);
                return $original;
            }
            $file = $ref->getFileName();
            if ($file && strpos(str_replace('\\', '/', $file), str_replace('\\', '/', dirname(__DIR__)) . '/') === 0) return $original;
            $wrapper = function (...$args) use ($original, $hook, $label, $key) {
                if (null !== $this->wall_end) return call_user_func_array($original, $args);
                $start = microtime(true);
                $this->callback_count++;
                $this->stack[] = 0.0;
                $render = isset($this->milestones['template_redirect']);
                if ($this->template && count($this->stack) === 1) $this->event('before ' . ($label ?: $hook));
                // Cookies queued since the last boundary belong to the enclosing timed callback
                // when one is running, otherwise to nobody we can name.
                $this->cookie_check($this->cookie_owners ? end($this->cookie_owners) : null, 'before ' . ($label ?: $hook));
                $this->cookie_owners[] = array('kind' => 'callback', 'hook' => $hook, 'callable' => $original, 'label' => $label);
                try {
                    return call_user_func_array($original, $args);
                } finally {
                    $this->cookie_check(array_pop($this->cookie_owners), 'after ' . ($label ?: $hook));
                    $end = microtime(true);
                    $wall = ($end - $start) * 1000;
                    $self = max(0, $wall - array_pop($this->stack));
                    $this->callback_wall_ms += $self;
                    if ($this->stack) {
                        $this->stack[count($this->stack) - 1] += $wall;
                    } else {
                        $this->intervals[] = array($start, $end);
                        if ($this->template) $this->event('after ' . ($label ?: $hook));
                    }
                    $phase_key = $key . ($render ? ':render' : ':boot');
                    if (!isset($this->callbacks[$phase_key])) $this->callbacks[$phase_key] = array($hook, $original, 0, $label, $render);
                    $this->callbacks[$phase_key][2] += $self;
                }
            };
            $this->wrappers->offsetSet($wrapper);
            return $wrapper;
        }

        /**
         * Compare the Set-Cookie lines queued now with the cursor and give any new ones to $owner
         * (a callback, a plugin include, or null: set outside a timed callback). headers_list() is
         * the queue PHP will send, so a value never has to be read: only the name before '='.
         * header_remove() or a replacing header() can shrink the queue; the cursor then follows it
         * down rather than attributing lines twice.
         */
        private function cookie_check($owner, $detected_at) {
            $started = microtime(true);
            $this->cookie_checks++;
            $lines = array();
            foreach (headers_list() as $header) {
                if (0 === strncasecmp($header, 'Set-Cookie:', 11)) {
                    $lines[] = $header;
                }
            }
            $count = count($lines);
            if ($count > $this->cookie_cursor) {
                $names = array();
                foreach (array_slice($lines, $this->cookie_cursor) as $line) {
                    if (preg_match('/^Set-Cookie:\s*([^=;\s]{1,200})=/i', $line, $m)) {
                        $names[] = $m[1];
                    }
                }
                if ($names && count($this->cookie_events) < 100) {
                    $this->cookie_events[] = array('names' => $names, 'owner' => $owner, 'detected_at' => $detected_at,
                        'phase' => isset($this->milestones['template_redirect']) ? 'render' : 'boot');
                }
            }
            $this->cookie_cursor = $count;
            $this->cookie_check_s += microtime(true) - $started;
        }

        /**
         * One row per cookie name the profiled PHP queued, with who queued it. Name and version are
         * read only for the few components that set a cookie, so a request without one pays nothing.
         */
        private function set_cookie_report($map) {
            $rows = array();
            $info = array();
            foreach ($this->cookie_events as $event) {
                $owner = $event['owner'];
                $row = array('component' => null, 'type' => null, 'component_name' => null, 'version' => null,
                    'hook' => null, 'callback' => null, 'file' => null, 'line' => null,
                    'attribution' => 'outside_timed_callback', 'detected_at' => (string) $event['detected_at'], 'phase' => $event['phase']);
                if ($owner && 'callback' === $owner['kind']) {
                    $source = $this->callable_source($owner['callable']);
                    $file = $this->callable_file($owner['callable']);
                    $cls = $file ? $map->classify_file($file) : array('component' => 'core', 'type' => 'core');
                    $row = array_merge($row, array('component' => $cls['component'], 'type' => $cls['type'], 'hook' => $owner['hook'],
                        'callback' => $owner['label'] ?: $this->callable_label($owner['callable']), 'file' => $source['file'],
                        'line' => $source['line'], 'attribution' => 'callback', 'detected_at' => null));
                } elseif ($owner) {
                    $cls = $map->classify_file($owner['file']);
                    $row = array_merge($row, array('component' => $cls['component'], 'type' => $cls['type'], 'file' => $owner['file'],
                        'attribution' => $owner['kind'], 'detected_at' => null));
                }
                if (null !== $row['component']) {
                    $key = $row['type'] . ':' . $row['component'];
                    if (!isset($info[$key])) {
                        $info[$key] = $this->component_info($row['component'], $row['type'], $row['file']);
                    }
                    $row['component_name'] = $info[$key]['name'];
                    $row['version'] = $info[$key]['version'];
                }
                foreach ($event['names'] as $name) {
                    $rows[] = array_merge(array('name' => $name), $row);
                }
            }
            return array_slice($rows, 0, 50);
        }

        /** Display name and version of one component, from its own header. */
        private function component_info($component, $type, $file) {
            $none = array('name' => $component, 'version' => null);
            if ('core' === $type) {
                return array('name' => 'WordPress', 'version' => isset($GLOBALS['wp_version']) ? (string) $GLOBALS['wp_version'] : null);
            }
            if ('theme' === $type && function_exists('wp_get_theme')) {
                $theme = wp_get_theme($component);
                return $theme->exists() ? array('name' => (string) $theme->get('Name'), 'version' => (string) $theme->get('Version') ?: null) : $none;
            }
            if (!function_exists('get_file_data')) {
                return $none;
            }
            $main = null;
            if ('plugin' === $type) {
                foreach (array_keys($this->plugin_ms) as $key) {
                    if (0 === strpos($key, $component . '/') || $key === $component . '.php') {
                        $main = WP_PLUGIN_DIR . '/' . $key;
                        break;
                    }
                }
                if (!$main) {
                    foreach ((array) get_option('active_plugins', array()) as $key) {
                        if (0 === strpos((string) $key, $component . '/')) {
                            $main = WP_PLUGIN_DIR . '/' . $key;
                            break;
                        }
                    }
                }
            } elseif ('mu-plugin' === $type && $file && is_file($file)) {
                $main = $file;
            }
            if (!$main || !is_file($main)) {
                return $none;
            }
            $data = get_file_data($main, array('name' => 'Plugin Name', 'version' => 'Version'));
            return array('name' => '' !== $data['name'] ? $data['name'] : $component, 'version' => '' !== $data['version'] ? $data['version'] : null);
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
            $this->finish();
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
            // Each component's type as the map classified it, so a theme or MU plugin that only
            // spends hook time is not stored as a plugin.
            $types = array();
            foreach ($this->callbacks as $c) {
                $hook = $c[0];
                $callable = $c[1];
                $ms = $c[2];
                $label = isset($c[3]) ? $c[3] : $this->callable_label($callable);
                $file = $this->callable_file($callable);
                $cls = $file ? $map->classify_file($file) : array('component' => 'core', 'type' => 'core');
                $component = $cls['component'];
                $types[$component] = $cls['type'];

                if (!isset($hooks[$hook])) {
                    $hooks[$hook] = array('ms' => 0, 'components' => array());
                }
                $hooks[$hook]['ms'] += $ms;
                $hooks[$hook]['components'][$component] =
                    round((isset($hooks[$hook]['components'][$component]) ? $hooks[$hook]['components'][$component] : 0) + $ms, 2);

                if ('core' !== $component) {
                    $components[$component] = round((isset($components[$component]) ? $components[$component] : 0) + $ms, 2);
                }
                $source = $this->callable_source($callable);
                $entry = array('hook' => $hook, 'label' => $label, 'component' => $component, 'ms' => round($ms, 2), 'file' => $source['file'], 'line' => $source['line'], 'evidence' => 'callback self wall time');
                $top[] = $entry;

                if (isset($c[4]) ? $c[4] : self::is_render_hook($hook)) {
                    $render['timed_ms'] += $ms;
                    $render['components'][$component] =
                        round((isset($render['components'][$component]) ? $render['components'][$component] : 0) + $ms, 2);
                    $render['top'][] = $entry;
                }
            }
            foreach ($this->stream_spans as $span) {
                $owner = $map->classify_file($span['file']);
                if (!$span['render']) continue;
                $render['timed_ms'] += $span['ms'];
                $component = $owner['component'];
                $render['components'][$component] = (isset($render['components'][$component]) ? $render['components'][$component] : 0) + $span['ms'];
            }
            $files = $this->template_files($map);
            foreach ($files as $entry) {
                if (empty($entry['render'])) continue;
                $render['timed_ms'] += $entry['ms'];
                $component = $entry['component'];
                $render['components'][$component] = (isset($render['components'][$component]) ? $render['components'][$component] : 0) + $entry['ms'];
                $render['top'][] = array('hook' => 'template file', 'label' => basename($entry['file']), 'component' => $component,
                    'ms' => $entry['ms'], 'file' => $entry['file'], 'line' => 1, 'evidence' => 'template residual wall time');
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
                'attributed_ms' => round($this->callback_wall_ms + array_sum($includes), 2),
                'reference_callbacks_untimed' => array_values($this->reference_callbacks),
                'unreflected_callbacks_untimed' => array_values($this->unreflected_callbacks),
                'hook_mode' => $this->hook_mode,
                'callback_count' => $this->callback_count,
                'timing_basis' => 'exclusive wall time; SQL and HTTP may be inside callbacks',
                'template_files' => $files,
                'template_files_unclosed' => count($this->template_starts),
                'render_regions' => $this->regions(),
                'largest_gap' => $this->largest_gap(),
                'cpu' => $this->cpu_report(),
                'segments' => $segments,
                'includes' => array_slice($includes, 0, 100, true),
                'hooks' => $hooks,
                'components' => array_slice($components, 0, 100, true),
                'component_types' => $types,
                'top_callbacks' => array_slice($top, 0, 15),
                'render' => $render,
                'assets' => $this->assets(),
                // Who queued each Set-Cookie line: names only, never values.
                'set_cookies' => $this->set_cookie_report($map),
                'set_cookie_checks' => array('checks' => $this->cookie_checks, 'ms' => round($this->cookie_check_s * 1000, 3)),
            );
        }

        /** Exact native load_template windows; the main file has context boundaries only. */
        private function template_files($map) {
            $files = array();
            foreach ($this->template_spans as $index => $span) {
                $covered = $this->intervals;
                foreach ($this->template_spans as $child_index => $child) {
                    if ($child_index !== $index && $child['start'] >= $span['start'] && $child['end'] <= $span['end']) $covered[] = array($child['start'], $child['end']);
                }
                usort($covered, function ($a, $b) { return $a[0] <=> $b[0]; });
                $cursor = $span['start'];
                $self = 0;
                foreach ($covered as $interval) {
                    if ($interval[1] <= $cursor) continue;
                    if ($interval[0] >= $span['end']) break;
                    $self += max(0, min($interval[0], $span['end']) - $cursor);
                    $cursor = max($cursor, $interval[1]);
                }
                $self += max(0, $span['end'] - $cursor);
                $key = $span['file'] . ($span['render'] ? ':render' : ':boot');
                if (!isset($files[$key])) {
                    $owner = $map->classify_file($span['file']);
                    $files[$key] = array('file' => $span['file'], 'component' => $owner['component'], 'render' => $span['render'], 'ms' => 0, 'calls' => 0);
                }
                $files[$key]['ms'] += $self * 1000;
                $files[$key]['calls']++;
            }
            foreach ($files as &$file) $file['ms'] = round($file['ms'], 2);
            unset($file);
            $files = array_values($files);
            usort($files, function ($a, $b) { return $b['ms'] <=> $a['ms']; });
            return $files;
        }

        private function event($name) {
            if (!$this->template || (null !== $this->wall_end && 'request_end' !== $name)) return;
            $this->events[] = array('name' => $name, 'time' => microtime(true), 'template' => $this->template);
        }

        /** Boundary windows locate code; they are not additional callback attribution. */
        private function regions() {
            $regions = array();
            for ($i = 1; $i < count($this->events); $i++) {
                $a = $this->events[$i - 1];
                $b = $this->events[$i];
                $regions[] = array('after' => $a['name'], 'before' => $b['name'],
                    'template' => $a['template'], 'ms' => round(($b['time'] - $a['time']) * 1000, 2));
            }
            usort($regions, function ($a, $b) { return $b['ms'] <=> $a['ms']; });
            return array_slice($regions, 0, 40);
        }

        private function largest_gap() {
            $intervals = $this->intervals;
            foreach ($this->template_spans as $span) {
                if ($span['render']) $intervals[] = array($span['start'], $span['end']);
            }
            usort($intervals, function ($a, $b) { return $a[0] <=> $b[0]; });
            $largest = null;
            $index = 0;
            for ($i = 1; $i < count($this->events); $i++) {
                $a = $this->events[$i - 1];
                $b = $this->events[$i];
                $cursor = $a['time'];
                while (isset($intervals[$index]) && $intervals[$index][1] <= $cursor) $index++;
                for ($j = $index; $j < count($intervals); $j++) {
                    $span = $intervals[$j];
                    if ($span[1] <= $cursor) continue;
                    if ($span[0] >= $b['time']) break;
                    $end = min($span[0], $b['time']);
                    if ($end > $cursor && (!$largest || ($end - $cursor) * 1000 > $largest['ms'])) {
                        $largest = array('ms' => ($end - $cursor) * 1000, 'after' => $a['name'], 'before' => $b['name'], 'template' => $a['template']);
                    }
                    $cursor = max($cursor, $span[1]);
                }
                if ($cursor < $b['time'] && (!$largest || ($b['time'] - $cursor) * 1000 > $largest['ms'])) {
                    $largest = array('ms' => ($b['time'] - $cursor) * 1000, 'after' => $a['name'], 'before' => $b['name'], 'template' => $a['template']);
                }
            }
            if ($largest) $largest['ms'] = round($largest['ms'], 2);
            return $largest;
        }

        /** Native I/O intervals subtract from enclosing callback/file wall time. */
        public function stream_interval($start, $end, $call) {
            if (null !== $this->wall_end) return;
            $ms = ($end - $start) * 1000;
            if ($this->stack) $this->stack[count($this->stack) - 1] += $ms;
            $this->intervals[] = array($start, $end);
            $this->callback_wall_ms += $ms;
            $this->stream_spans[] = array('ms' => $ms, 'file' => $call['file'], 'line' => $call['line'], 'url' => $call['url'], 'render' => isset($this->milestones['template_redirect']));
        }

        public function finish() {
            if (null !== $this->wall_end) return;
            $this->cookie_check(null, 'request end');
            $this->wall_end = microtime(true);
            $this->cpu_end = function_exists('getrusage') ? getrusage() : null;
            $this->mark('request_end');
            $this->event('request_end');
        }

        private function cpu_report() {
            $wall = ($this->wall_end - $this->wall_start) * 1000;
            if (null === $this->cpu_start) {
                return array('available' => false, 'wall_ms' => round($wall, 2));
            }
            $end = $this->cpu_end;
            $cpu = 0;
            foreach (array('utime', 'stime') as $kind) {
                $cpu += ($end['ru_' . $kind . '.tv_sec'] - $this->cpu_start['ru_' . $kind . '.tv_sec']) * 1000
                    + ($end['ru_' . $kind . '.tv_usec'] - $this->cpu_start['ru_' . $kind . '.tv_usec']) / 1000;
            }
            return array('available' => true, 'wall_ms' => round($wall, 2), 'cpu_ms' => round($cpu, 2),
                'non_cpu_ms' => round(max(0, $wall - $cpu), 2), 'scope' => 'profiler arm to request end');
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

        private function reflection($callable) {
            if (is_array($callable)) return new ReflectionMethod($callable[0], $callable[1]);
            if (is_string($callable) && false !== strpos($callable, '::')) {
                list($class, $method) = explode('::', $callable, 2);
                return new ReflectionMethod($class, $method);
            }
            if (is_object($callable) && !($callable instanceof Closure)) return new ReflectionMethod($callable, '__invoke');
            return new ReflectionFunction($callable);
        }

        private function callable_source($callable) {
            try {
                $ref = $this->reflection($callable);
                return array('file' => $ref->getFileName() ?: null, 'line' => $ref->getStartLine() ?: null);
            } catch (ReflectionException $e) {
                return array('file' => null, 'line' => null);
            }
        }

        /** Ownership can be the widget subclass; source remains the declaring method. */
        private function callable_file($callable) {
            $file = $this->callable_source($callable)['file'];
            if ($file && is_array($callable) && is_object($callable[0]) && false !== strpos(str_replace('\\', '/', $file), '/wp-includes/')) {
                $ref = new ReflectionClass($callable[0]);
                $subclass_file = $ref->getFileName();
                if ($subclass_file && false === strpos(str_replace('\\', '/', $subclass_file), '/wp-includes/')) return $subclass_file;
            }
            return $file;
        }
    }
}
