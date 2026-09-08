<?php
defined('ABSPATH') || exit;
/** Explicit, bounded diagnostic sampler. Only named actions are wrapped; filters are untouched. */
class SSPA_Endpoint_Detail {
    private $plugins = array();
    private $roots = array();
    private $wrapped = array();
    private $originals = array();
    private $last;
    private $overhead = 0;
    private $registrations = 0;
    private $truncated = false;
    private $http = array();
    private $actions;
    public function __construct($action = '') {
        $this->last = microtime(true);
        foreach ((array) get_option('active_plugins', array()) as $plugin) {
            $file = realpath(WP_PLUGIN_DIR . '/' . $plugin);
            if ($file) { $this->roots[$plugin] = dirname($plugin) === '.' ? wp_normalize_path($file) : wp_normalize_path(dirname($file)) . '/'; }
        }
        $this->actions = array('plugins_loaded', 'after_setup_theme', 'init', 'wp_loaded', 'admin_init', 'rest_api_init');
        if ($action) { $this->actions[] = $action; if (strpos($action, 'wp_ajax_') === 0) { $this->actions[] = 'wp_ajax_nopriv_' . substr($action, 8); } }
        add_action('muplugins_loaded', function () { $this->last = microtime(true); }, PHP_INT_MAX);
        add_action('plugin_loaded', array($this, 'included'), PHP_INT_MIN);
        add_action('all', array($this, 'dispatch'), PHP_INT_MIN, 1);
        foreach (array('plugins_loaded', 'wp_loaded') as $checkpoint) {
            add_action($checkpoint, function () use ($checkpoint) { $this->snapshot($checkpoint); }, PHP_INT_MAX);
        }
        add_filter('query', function ($sql) { $this->io('sql_count'); return $sql; }, PHP_INT_MIN);
        add_filter('pre_http_request', function ($pre, $args, $url) {
            $plugin = $this->caller();
            if ($plugin) { $this->plugins[$plugin]['io']['http_count']++; $this->http[hash('sha256', $url)][] = array($plugin, microtime(true)); }
            return $pre;
        }, PHP_INT_MIN, 3);
        add_action('http_api_debug', function ($response, $context, $class, $args, $url) {
            $key = hash('sha256', $url);
            if (!empty($this->http[$key])) { $start = array_pop($this->http[$key]); $this->plugins[$start[0]]['io']['http_timed_count']++; $this->plugins[$start[0]]['io']['http_ms'] += (microtime(true) - $start[1]) * 1000; }
        }, PHP_INT_MAX, 5);
        add_filter('wp_mail', function ($args) { $this->io('mail_count'); return $args; }, PHP_INT_MIN);
    }
    public function included($file) {
        $start = microtime(true); $plugin = plugin_basename($file);
        $this->ensure($plugin);
        $this->plugins[$plugin]['include_ms'] += ($start - $this->last) * 1000;
        $this->roots[$plugin] = dirname($plugin) === '.' ? wp_normalize_path(realpath($file)) : wp_normalize_path(realpath(dirname($file))) . '/';
        $this->last = microtime(true); $this->overhead += ($this->last - $start) * 1000000;
    }
    private function ensure($plugin) {
        if (!isset($this->plugins[$plugin])) { $this->plugins[$plugin] = array('plugin' => $plugin, 'samples' => 1, 'include_ms' => 0,
            'registered_hooks' => array(), 'executed_hooks' => array(), 'io' => array('sql_count' => 0, 'http_count' => 0, 'http_timed_count' => 0, 'http_ms' => 0, 'mail_count' => 0)); }
    }
    private function owner($file) {
        $file = wp_normalize_path($file);
        foreach ($this->roots as $plugin => $root) { if ($file === $root || (substr($root, -1) === '/' && strpos($file, $root) === 0)) { return $plugin; } }
        $normal = wp_normalize_path(WP_PLUGIN_DIR) . '/';
        if (strpos($file, $normal) === 0) {
            $relative = substr($file, strlen($normal));
            foreach ((array) get_option('active_plugins', array()) as $plugin) {
                if ($relative === $plugin || (dirname($plugin) !== '.' && strpos($relative, dirname($plugin) . '/') === 0)) { $this->ensure($plugin); return $plugin; }
            }
        }
        return '';
    }
    private function reflection($callback) {
        try {
            if (is_array($callback)) { return new ReflectionMethod($callback[0], $callback[1]); }
            if (is_string($callback) && strpos($callback, '::') !== false) { return new ReflectionMethod($callback); }
            if (is_object($callback) && !($callback instanceof Closure)) { return new ReflectionMethod($callback, '__invoke'); }
            return new ReflectionFunction($callback);
        } catch (ReflectionException $e) { return null; }
    }
    public function dispatch($hook) {
        if (!in_array($hook, $this->actions, true)) { return; }
        $start = microtime(true); global $wp_filter;
        if (empty($wp_filter[$hook]) || !($wp_filter[$hook] instanceof WP_Hook)) { return; }
        foreach ($wp_filter[$hook]->callbacks as $priority => &$entries) {
            foreach ($entries as $id => &$entry) {
                $key = $hook . ':' . $priority . ':' . $id;
                if (isset($this->wrapped[$key]) && $entry['function'] === $this->wrapped[$key]) { continue; }
                $callback = $entry['function']; $ref = $this->reflection($callback);
                if (!$ref || !$ref->getFileName()) { continue; }
                $plugin = $this->owner($ref->getFileName()); if ($plugin) { $this->ensure($plugin); }
                if (!$plugin || strpos($plugin, 'super-speedy-performance-analysis/') === 0) { continue; }
                // A variadic wrapper cannot promise PHP reference semantics for these callbacks.
                $references = $ref->returnsReference();
                foreach ($ref->getParameters() as $parameter) { $references = $references || $parameter->isPassedByReference(); }
                if ($references) { $this->truncated = true; continue; }
                if (count($this->wrapped) >= 300) { $this->truncated = true; break 2; }
                $wrapper = function (...$args) use ($callback, $plugin, $hook) {
                    $start = microtime(true);
                    if (!isset($this->plugins[$plugin]['executed_hooks'][$hook])) { $this->plugins[$plugin]['executed_hooks'][$hook] = array('hook' => $hook, 'count' => 0, 'inclusive_ms' => 0); }
                    $this->plugins[$plugin]['executed_hooks'][$hook]['count']++;
                    try { return call_user_func_array($callback, $args); }
                    finally { $this->plugins[$plugin]['executed_hooks'][$hook]['inclusive_ms'] += (microtime(true) - $start) * 1000; }
                };
                $this->originals[$key] = $callback; $this->wrapped[$key] = $wrapper; $entry['function'] = $wrapper;
            }
            unset($entry);
        }
        unset($entries);
        $this->overhead += (microtime(true) - $start) * 1000000;
    }
    public function snapshot($checkpoint) {
        $start = microtime(true); global $wp_filter;
        foreach ($wp_filter as $hook => $object) {
            if (!($object instanceof WP_Hook)) { continue; }
            foreach ($object->callbacks as $priority => $entries) {
                foreach ($entries as $id => $entry) {
                    $original = $this->originals[$hook . ':' . $priority . ':' . $id] ?? $entry['function'];
                    $ref = $this->reflection($original);
                    if (!$ref || !$ref->getFileName()) { continue; }
                    $plugin = $this->owner($ref->getFileName()); if ($plugin) { $this->ensure($plugin); }
                    if (!$plugin || strpos($plugin, 'super-speedy-performance-analysis/') === 0) { continue; }
                    if ($this->registrations >= 1000) { $this->truncated = true; break 3; }
                    $this->plugins[$plugin]['registered_hooks'][] = array('hook' => substr($hook, 0, 160), 'priority' => (int) $priority, 'accepted_args' => (int) $entry['accepted_args'], 'checkpoint' => $checkpoint);
                    $this->registrations++;
                }
            }
        }
        $this->overhead += (microtime(true) - $start) * 1000000;
    }
    private function caller() {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 32) as $frame) {
            if (empty($frame['file']) || strpos(wp_normalize_path($frame['file']), wp_normalize_path(__DIR__)) === 0) { continue; }
            $plugin = $this->owner($frame['file']); if ($plugin) { $this->ensure($plugin); } if ($plugin) { return $plugin; }
        }
        return '';
    }
    private function io($field) { $plugin = $this->caller(); if ($plugin) { $this->plugins[$plugin]['io'][$field]++; } }
    public function result() {
        foreach ($this->plugins as &$plugin) { $plugin['executed_hooks'] = array_values($plugin['executed_hooks']); } unset($plugin);
        return array('schema' => 'sspa/endpoint-activity@1', 'plugins' => array_values($this->plugins), 'coverage' => 'partial', 'truncated' => $this->truncated,
            'observer_preparation_us' => round($this->overhead), 'covered_actions' => $this->actions,
            'gaps' => array('Only named action callbacks present before dispatch are timed; callbacks added during dispatch may be missed.', 'Filters and REST direct permission/handler callbacks are not wrapped.', 'Callbacks that exit the request have execution counts but incomplete timing.', 'Reference callbacks are omitted.', 'Registration checkpoints omit callbacks removed between checkpoints.', 'SQL counts are attempts; SQL timing unavailable. Mail counts are construction attempts, not delivery. HTTP timing covers only http_timed_count completed transports.', 'Include deltas include core overhead; inclusive callback times overlap on recursion.', 'Observer preparation excludes stack attribution and storage overhead; benchmark externally.'));
    }
}
