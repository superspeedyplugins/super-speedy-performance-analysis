<?php
defined('ABSPATH') || exit;
// Standalone. Maps stack frames (file paths, or bare callable names in degraded mode) to
// the responsible component: a plugin slug, the theme, an mu-plugin, or core.

if (!class_exists('SSPA_Component_Map')) {
    class SSPA_Component_Map {

        private $plugin_dir;
        private $mu_dir;
        private $theme_dirs = array();
        private $abspath;
        private $own_dir;
        private $reflection_cache = array();
        private $db_layer_cache = array();

        /**
         * Directory names that mark third-party code vendored INSIDE a component. PHP loads
         * exactly one copy of a shared library, so the component it lives in is an accident
         * of autoloader order, not a statement about who is spending the time.
         *
         * Deliberately conservative. A false positive here blames the wrong plugin, which is
         * the exact bug this list exists to fix, so bare "lib"/"libs"/"libraries" are NOT
         * included: plugins commonly use those for their own code.
         */
        private static $lib_markers = array(
            'vendor', 'vendor_prefixed', 'vendors', 'freemius', 'packages', 'third-party', 'thirdparty',
        );

        /**
         * Two legitimate answers to "whose cost is this", so we answer both.
         *
         * CALLER: the component that asked for the work. A plugin calling wc_get_product()
         * 200 times in a loop instead of one aggregate query is that plugin's fault, not
         * WooCommerce's, and this is the mode that matches Deep Analysis - disable the
         * plugin and those calls disappear.
         *
         * CODE_OWNER: the component whose code actually ran. What you want when deciding
         * which codebase to open, or when judging WooCommerce's own performance.
         *
         * Neither is universally right, so the chain is captured once and the mode is a
         * pure function over it. Nothing is lost at capture time.
         *
         * CODE_OWNER is the default. CALLER cannot be the global default: on a normal shop
         * page the chain is [woocommerce, <theme>], because the theme template is what calls
         * into WooCommerce, so caller mode would charge the theme for WooCommerce's own
         * rendering. Themes would look catastrophic and WooCommerce would look free. Caller
         * mode earns its keep on the N+1 findings, where the repeated call IS the waste.
         */
        const MODE_CALLER = 'caller';
        const MODE_CODE_OWNER = 'code_owner';
        const CHAIN_MAX = 4;

        private $mode;

        public function __construct($mode = self::MODE_CODE_OWNER) {
            $this->mode = ($mode === self::MODE_CALLER) ? self::MODE_CALLER : self::MODE_CODE_OWNER;
            $content = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : '';
            $this->plugin_dir = $this->norm(defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : $content . '/plugins');
            $this->mu_dir = $this->norm(defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : $content . '/mu-plugins');
            $this->abspath = $this->norm(defined('ABSPATH') ? ABSPATH : '');
            $this->own_dir = $this->norm(dirname(__DIR__));
            if (!empty($GLOBALS['wp_theme_directories']) && is_array($GLOBALS['wp_theme_directories'])) {
                foreach ($GLOBALS['wp_theme_directories'] as $dir) {
                    $this->theme_dirs[] = $this->norm($dir);
                }
            } else {
                $this->theme_dirs[] = $this->norm($content . '/themes');
            }
        }

        private function norm($path) {
            return rtrim(str_replace('\\', '/', (string) $path), '/');
        }

        /**
         * @return array {component, type} - type: plugin|theme|mu-plugin|core|other
         */
        public function classify_file($file) {
            $file = str_replace('\\', '/', (string) $file);

            if ($this->plugin_dir && strpos($file, $this->plugin_dir . '/') === 0) {
                $rest = substr($file, strlen($this->plugin_dir) + 1);
                $slash = strpos($rest, '/');
                $slug = ($slash === false) ? preg_replace('/\.php$/', '', $rest) : substr($rest, 0, $slash);
                return array('component' => $slug, 'type' => 'plugin');
            }
            if ($this->mu_dir && strpos($file, $this->mu_dir . '/') === 0) {
                $rest = substr($file, strlen($this->mu_dir) + 1);
                $slash = strpos($rest, '/');
                $name = ($slash === false) ? preg_replace('/\.php$/', '', $rest) : substr($rest, 0, $slash);
                return array('component' => 'mu:' . $name, 'type' => 'mu-plugin');
            }
            foreach ($this->theme_dirs as $theme_root) {
                if (strpos($file, $theme_root . '/') === 0) {
                    $rest = substr($file, strlen($theme_root) + 1);
                    $slash = strpos($rest, '/');
                    $slug = ($slash === false) ? $rest : substr($rest, 0, $slash);
                    return array('component' => $slug, 'type' => 'theme');
                }
            }
            if ($this->abspath && strpos($file, $this->abspath . '/') === 0) {
                return array('component' => 'core', 'type' => 'core');
            }
            return array('component' => 'other', 'type' => 'other');
        }

        /**
         * Frames come innermost-first (each frame = the call site). Builds the component
         * chain and resolves it under this instance's mode.
         *
         * Attribution is only ever a hypothesis. Measured impact (virtually disabling the
         * plugin and re-measuring) is the experiment, and is immune to every ambiguity here.
         *
         * @param array $frames Arrays of [file, line, fn].
         * @return array {component, type, caller, via, vendored, chain}
         */
        public function attribute($frames) {
            $chain = $this->chain($frames);
            if (empty($chain)) {
                return array(
                    'component' => 'core',
                    'type' => 'core',
                    'caller' => $this->first_frame_label($frames),
                    'via' => null,
                    'vendored' => false,
                    'chain' => array(),
                );
            }
            return self::resolve($chain, $this->mode);
        }

        /**
         * Distinct components in the stack, innermost first. Core frames and our own frames
         * never appear, and consecutive repeats collapse - which is why a plugin calling deep
         * into its OWN bundled library needs no special case: it yields a single entry, so
         * both modes agree on it.
         *
         * @return array of {component, type, label, library}
         */
        private function chain($frames) {
            $chain = array();
            foreach ((array) $frames as $frame) {
                if (!isset($frame[0])) {
                    continue;
                }
                $file = str_replace('\\', '/', $frame[0]);
                if (strpos($file, $this->own_dir . '/') === 0) {
                    continue; // our own profiler/plugin frames never claim attribution
                }
                $c = $this->classify_file($file);
                if ($c['type'] === 'core') {
                    continue;
                }
                $depth = count($chain);
                if ($depth && $chain[$depth - 1]['component'] === $c['component']) {
                    continue;
                }
                $chain[] = array(
                    'component' => $c['component'],
                    'type' => $c['type'],
                    'label' => $this->caller_label($frame),
                    'library' => $this->library_label($file, $c),
                );
                if (count($chain) >= self::CHAIN_MAX) {
                    break;
                }
            }
            return $chain;
        }

        /**
         * Attribution for a given mode. Pure function of the chain, so a stored chain can be
         * re-resolved into the other mode later without re-profiling anything.
         *
         * @return array {component, type, caller, via, vendored, chain}
         */
        public static function resolve($chain, $mode) {
            $executor = $chain[0];
            $caller = isset($chain[1]) ? $chain[1] : null;

            $pick = $executor;
            if ($caller !== null) {
                if ($executor['library'] !== null) {
                    // Vendored code is nobody's OWN code: PHP loads one shared copy and the
                    // component hosting it on disk is decided by autoloader order. Blaming it
                    // is wrong in both modes, so the caller takes the cost either way.
                    $pick = $caller;
                } elseif ($mode === self::MODE_CALLER) {
                    $pick = $caller;
                }
            }

            // Name where the work actually happened whenever that is not the blamed component,
            // and name the library rather than its host plugin when the code is vendored.
            $via = null;
            if ($pick !== $executor) {
                $via = ($executor['library'] !== null) ? $executor['library'] : $executor['component'];
            } elseif ($executor['library'] !== null) {
                $via = $executor['library'];
            }

            // "type:component" so a stored chain can be re-resolved into the other mode later
            // WITHOUT re-profiling and without a second slug-to-type lookup. Split on the
            // first colon only: mu-plugin components are themselves "mu:name".
            $slugs = array();
            foreach ($chain as $link) {
                $slugs[] = $link['type'] . ':' . $link['component'];
            }

            return array(
                'component' => $pick['component'],
                'type' => $pick['type'],
                'caller' => $pick['label'],
                'via' => $via,
                'vendored' => ($executor['library'] !== null),
                'chain' => $slugs,
            );
        }

        private function first_frame_label($frames) {
            foreach ((array) $frames as $frame) {
                if (!isset($frame[0])) {
                    continue;
                }
                if (strpos(str_replace('\\', '/', $frame[0]), $this->own_dir . '/') === 0) {
                    continue;
                }
                return $this->caller_label($frame);
            }
            return '';
        }

        /**
         * The file's path relative to its component root (i.e. below the plugin/theme slug),
         * or null when the file belongs to no component.
         */
        private function relative_path($file) {
            $roots = array($this->plugin_dir, $this->mu_dir);
            foreach ($this->theme_dirs as $theme_root) {
                $roots[] = $theme_root;
            }
            foreach ($roots as $root) {
                if ($root && strpos($file, $root . '/') === 0) {
                    $rest = substr($file, strlen($root) + 1);
                    $slash = strpos($rest, '/');
                    return ($slash === false) ? '' : substr($rest, $slash + 1);
                }
            }
            return null;
        }

        /**
         * Path segments naming the library, taken from just after the marker directory.
         *
         * @return array|null Null when the file is not inside a vendored library.
         */
        private function library_segments($file) {
            $rel = $this->relative_path($file);
            if ($rel === null || $rel === '') {
                return null;
            }
            $found = false;
            $after = array();
            foreach (explode('/', $rel) as $part) {
                if (!$found) {
                    if (in_array(strtolower($part), self::$lib_markers, true)) {
                        $found = true;
                    }
                    continue;
                }
                if (strpos($part, '.php') !== false) {
                    break; // reached the file itself
                }
                $after[] = $part;
                if (count($after) >= 2) {
                    break;
                }
            }
            return $found ? $after : null;
        }

        /** @return string|null Null when the file is not inside a vendored library. */
        private function library_label($file, $c) {
            $segments = $this->library_segments($file);
            if ($segments === null) {
                return null;
            }
            $lib = !empty($segments) ? implode('/', $segments) : 'bundled library';
            return $lib . ' (in ' . $c['component'] . ')';
        }

        private function caller_label($frame) {
            $file = basename(str_replace('\\', '/', $frame[0]));
            $line = isset($frame[1]) ? $frame[1] : 0;
            $fn = isset($frame[2]) ? $frame[2] : '';
            return $fn . ' (' . $file . ':' . $line . ')';
        }

        /**
         * Degraded mode: core SAVEQUERIES gives a comma-separated summary of callable
         * names, not files. Resolve names to files via reflection (cached) and attribute.
         *
         * @param string $caller_summary e.g. "require('wp-load.php'), WP_Query->get_posts, MyPlugin\\Foo->bar"
         */
        public function attribute_from_summary($caller_summary) {
            $names = array_reverse(array_map('trim', explode(',', (string) $caller_summary)));
            $frames = array();
            foreach ($names as $name) {
                if ($name === '' || strpos($name, 'require') === 0 || strpos($name, 'include') === 0) {
                    continue;
                }
                if ($this->is_db_layer($name)) {
                    // A wpdb subclass executing the query (QM_DB, LudicrousDB, ...) is the
                    // transport, not the spender. Without this, riding Query Monitor's
                    // db.php attributes EVERY query to query-monitor, because QM_DB->query
                    // is the innermost resolvable frame of every stack.
                    continue;
                }
                $file = $this->resolve_callable_file($name);
                if ($file) {
                    $frames[] = array($file, 0, $name);
                }
            }
            return $this->attribute($frames);
        }

        /** True when the callable is a method of wpdb or any wpdb subclass. */
        private function is_db_layer($name) {
            $pos = strpos($name, '->');
            if ($pos === false) {
                $pos = strpos($name, '::');
            }
            if ($pos === false) {
                return false;
            }
            $class = substr($name, 0, $pos);
            if (!isset($this->db_layer_cache[$class])) {
                $this->db_layer_cache[$class] = class_exists($class, false)
                    && ('wpdb' === $class || is_subclass_of($class, 'wpdb'));
            }
            return $this->db_layer_cache[$class];
        }

        private function resolve_callable_file($name) {
            if (array_key_exists($name, $this->reflection_cache)) {
                return $this->reflection_cache[$name];
            }
            $file = null;
            try {
                if (strpos($name, '->') !== false || strpos($name, '::') !== false) {
                    list($class, $method) = preg_split('/->|::/', $name, 2);
                    if (class_exists($class, false) && method_exists($class, $method)) {
                        $ref = new ReflectionMethod($class, $method);
                        $file = $ref->getFileName() ?: null;
                    }
                } elseif (function_exists($name)) {
                    $ref = new ReflectionFunction($name);
                    $file = $ref->getFileName() ?: null;
                }
            } catch (Throwable $e) {
                $file = null;
            }
            $this->reflection_cache[$name] = $file;
            return $file;
        }
    }
}
