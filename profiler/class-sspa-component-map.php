<?php
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

        public function __construct() {
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
         * Frames come innermost-first (each frame = the call site). The first frame that is
         * not core and not our own profiler code is the responsible component.
         *
         * @param array $frames Arrays of [file, line, fn].
         * @return array {component, type, caller}
         */
        public function attribute($frames) {
            $fallback = null;
            foreach ((array) $frames as $frame) {
                if (!isset($frame[0])) {
                    continue;
                }
                $file = str_replace('\\', '/', $frame[0]);
                if (strpos($file, $this->own_dir . '/') === 0) {
                    continue; // our own profiler/plugin frames never claim attribution
                }
                $c = $this->classify_file($file);
                if ($fallback === null) {
                    $fallback = $c + array('caller' => $this->caller_label($frame));
                }
                if ($c['type'] !== 'core') {
                    return $c + array('caller' => $this->caller_label($frame));
                }
            }
            return $fallback !== null ? $fallback : array('component' => 'core', 'type' => 'core', 'caller' => '');
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
                $file = $this->resolve_callable_file($name);
                if ($file) {
                    $frames[] = array($file, 0, $name);
                }
            }
            return $this->attribute($frames);
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
