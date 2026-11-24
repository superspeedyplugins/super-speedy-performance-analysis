<?php
defined('ABSPATH') || exit;
// Where each query came from: the hooks and shortcodes running when it executed, the template
// file and page area, the WP_Query that built it, and (when PA's own wpdb is not installed) a
// short call stack with line numbers. Armed by SSPA_Capture for token-bearing requests only.
//
// One observation per query, through wpdb's own `query` filter at PHP_INT_MAX, so it works under
// PA's profiling wpdb and under a foreign db.php that keeps the core query() path (Query Monitor,
// plain SAVEQUERIES). Per query it copies $wp_current_filter and a few scalars; the WP_Query facts
// are read once per WP_Query, from the SQL its posts_request produced. Context is resolved into the
// capture only for the queries whose full SQL is retained (see SSPA_Capture::collect_sql()).
//
// Plan: .docs/2026-11-24-slowest-query-context.md.

if (!class_exists('SSPA_Query_Context')) {
    class SSPA_Query_Context {

        /** Same ceiling as the profiling wpdb's log. */
        const MAX_RECORDS = 20000;
        /** WP_Query instances described per request; later ones get no wp_query facts. */
        const MAX_WP_QUERIES = 200;
        /** Frames kept per query for display. */
        const FRAMES = 6;
        /** Frames read per query when PA's wpdb did not already take a backtrace. */
        const RAW_FRAMES = 24;
        /**
         * Frames read here are kept only for queries that can still be retained with their full
         * SQL: the slowest KEEP_SLOWEST so far (twice the capture's FULL_SQL_TOP_N, because the
         * time used is an upper bound), and any that took KEEP_MS or more (FULL_SQL_MS). The rest are dropped as the next query starts, so a page
         * of 20,000 queries holds a few dozen stacks, not 20,000.
         */
        const KEEP_SLOWEST = 40;
        const KEEP_MS = 50;
        /** How far ahead a SAVEQUERIES entry is looked for among the observations. */
        const MATCH_WINDOW = 64;

        /**
         * Hooks that only carry other work: the innermost meaningful hook skips them, the way
         * attribution skips core frames. A query inside [shortcode] inside the_content belongs to
         * the shortcode and to whatever fired the_content's caller, not to the_content.
         */
        private static $generic = array(
            'the_content', 'do_shortcode_tag', 'pre_do_shortcode_tag', 'widget_text', 'widget_text_content',
            'widget_block_content', 'render_block', 'pre_render_block', 'render_block_data', 'the_excerpt',
            'get_the_excerpt', 'widget_display_callback', 'dynamic_sidebar_params', 'the_title', 'all',
            'wp_before_load_template', 'wp_after_load_template', 'template_include',
        );

        /** Observations so far; records are keyed by it. Read by SSPA_Profiling_WPDB::query(). */
        public $seq = 0;
        private $records = array();
        private $truncated = false;
        private $stacks = array();
        private $stack_ids = array();
        private $shortcodes = array();
        private $template = null;
        private $templates = array();
        private $area = null;
        private $area_hook = null;
        private $area_saved = array();
        private $area_templates = array();
        private $pending_area = null;
        private $widget = null;
        private $wp_queries = array();
        private $wp_query_ids = array();
        private $pending = null;
        private $pending_found = null;
        private $take_frames;
        private $shortcode_tags = null;
        /** Observation whose frames are not yet settled, and when it was observed. */
        private $open_seq = null;
        private $open_at = 0.0;
        /** observation => upper bound on its ms, for the slowest few whose frames are kept. */
        private $slowest = array();
        private $slowest_min = null;

        /**
         * @param bool $take_frames True when PA's profiling wpdb is not installed, so the frames
         *                          must be read here.
         */
        public function install($take_frames) {
            $this->take_frames = (bool) $take_frames;
            add_filter('query', array($this, 'observe'), PHP_INT_MAX);
            add_filter('pre_do_shortcode_tag', array($this, 'shortcode_open'), PHP_INT_MAX, 2);
            add_filter('do_shortcode_tag', array($this, 'shortcode_close'), PHP_INT_MIN, 2);
            // posts_pre_query fires for every WP_Query, suppress_filters or not (posts_request does
            // not: get_posts() sets suppress_filters), with the final statement in ->request.
            add_filter('posts_pre_query', array($this, 'note_posts_pre_query'), PHP_INT_MAX, 2);
            // A split query runs this statement (SELECT SQL_CALC_FOUND_ROWS wp_posts.ID ...)
            // instead, and fills the posts by ID afterwards.
            add_filter('posts_request_ids', array($this, 'note_posts_request'), PHP_INT_MAX, 2);
            add_filter('found_posts', array($this, 'note_found_posts'), PHP_INT_MAX, 2);
            add_filter('found_posts_query', array($this, 'note_found_posts_query'), PHP_INT_MAX, 2);
            add_filter('template_include', array($this, 'note_template_include'), PHP_INT_MAX);
            add_action('wp_before_load_template', array($this, 'template_open'), PHP_INT_MIN);
            add_action('wp_after_load_template', array($this, 'template_close'), PHP_INT_MAX);
            foreach (array('get_header' => 'header', 'get_sidebar' => 'sidebar', 'get_footer' => 'footer') as $hook => $area) {
                add_action($hook, function () use ($hook, $area) {
                    $this->pending_area = array($area, $this->area);
                    $this->area = $area;
                    $this->area_hook = $hook;
                }, PHP_INT_MIN);
            }
            add_action('loop_start', array($this, 'loop_start'), PHP_INT_MIN);
            add_action('dynamic_sidebar_before', array($this, 'sidebar_open'), PHP_INT_MIN);
            add_action('dynamic_sidebar_after', array($this, 'sidebar_close'), PHP_INT_MAX);
            add_action('dynamic_sidebar', array($this, 'widget_open'), PHP_INT_MIN);
            // Before SSPA_Boot_Timer wraps the shortcode callbacks (wp_loaded, PHP_INT_MAX), so a
            // shortcode's owner is read from its real callback, not from the timing wrapper.
            add_action('wp_loaded', function () {
                $this->shortcode_tags = isset($GLOBALS['shortcode_tags']) && is_array($GLOBALS['shortcode_tags']) ? $GLOBALS['shortcode_tags'] : array();
            }, PHP_INT_MAX - 1);
        }

        // ---------------- request state ----------------

        public function shortcode_open($return, $tag) {
            if (false === $return) {
                // $wp_current_filter still holds pre_do_shortcode_tag itself.
                $this->shortcodes[] = array((string) $tag, max(0, count((array) $GLOBALS['wp_current_filter']) - 1));
            }
            return $return;
        }

        public function shortcode_close($output, $tag) {
            $top = end($this->shortcodes);
            if ($top && $top[0] === (string) $tag) {
                array_pop($this->shortcodes);
            }
            return $output;
        }

        public function note_posts_pre_query($posts, $query = null) {
            $this->pending = (null === $posts && is_object($query) && isset($query->request)) ? array(trim((string) $query->request), $query) : null;
            return $posts;
        }

        /** The total WordPress computed, kept without holding the WP_Query itself. */
        public function note_found_posts($found, $query = null) {
            $index = is_object($query) ? $this->wp_query_index($query) : null;
            if (null !== $index && empty($this->wp_queries[$index][0]['no_found_rows'])) {
                $this->wp_queries[$index][0]['total'] = (int) $found;
            }
            return $found;
        }

        /** Index of an already-described WP_Query, or null; a freed object's reused hash is not it. */
        private function wp_query_index($query) {
            $id = spl_object_hash($query);
            if (!isset($this->wp_query_ids[$id])) {
                return null;
            }
            $index = $this->wp_query_ids[$id];
            $ref = $this->wp_queries[$index][1];
            return ($ref && $ref->get() === $query) ? $index : null;
        }

        public function note_posts_request($sql, $query = null) {
            $this->pending = is_object($query) ? array(trim((string) $sql), $query) : null;
            return $sql;
        }

        public function note_found_posts_query($sql, $query = null) {
            $this->pending_found = is_object($query) ? array(trim((string) $sql), $query) : null;
            return $sql;
        }

        public function note_template_include($file) {
            $this->template = is_string($file) ? $file : null;
            return $file;
        }

        public function template_open($file) {
            $this->templates[] = $this->template;
            $this->template = (string) $file;
            if (null !== $this->pending_area) {
                $this->area_templates[] = array((string) $file, $this->pending_area[0], $this->pending_area[1]);
                $this->pending_area = null;
            }
        }

        public function template_close($file) {
            $this->template = array_pop($this->templates);
            $top = end($this->area_templates);
            if ($top && $top[0] === (string) $file) {
                array_pop($this->area_templates);
                // header.php and sidebar.php end their area; what follows footer.php is still the footer.
                if ('footer' !== $top[1]) {
                    $this->area = $top[2] ? $top[2] : 'content';
                    $this->area_hook = null;
                }
            }
        }

        public function loop_start($query = null) {
            if (is_object($query) && method_exists($query, 'is_main_query') && $query->is_main_query() && in_array($this->area, array(null, 'content'), true)) {
                $this->area = 'content';
                $this->area_hook = 'loop_start';
            }
        }

        public function sidebar_open($index = '') {
            $this->area_saved[] = array($this->area, $this->area_hook, $this->widget);
            $this->area = 'sidebar';
            $this->area_hook = 'dynamic_sidebar_before';
            $this->widget = array('sidebar' => (string) $index, 'id' => null, 'name' => null);
        }

        public function sidebar_close($index = '') {
            $saved = array_pop($this->area_saved);
            if ($saved) {
                list($this->area, $this->area_hook, $this->widget) = $saved;
            }
        }

        public function widget_open($widget = null) {
            if (is_array($widget) && is_array($this->widget)) {
                $this->widget['id'] = isset($widget['id']) ? (string) $widget['id'] : null;
                $this->widget['name'] = isset($widget['name']) ? (string) $widget['name'] : null;
            }
        }

        // ---------------- one observation per query ----------------

        public function observe($sql) {
            $this->seq++;
            if (count($this->records) >= self::MAX_RECORDS) {
                $this->truncated = true;
                return $sql;
            }
            $stack = isset($GLOBALS['wp_current_filter']) ? (array) $GLOBALS['wp_current_filter'] : array();
            array_pop($stack); // 'query' itself
            if ($this->shortcodes) {
                $with = array();
                $at = 0;
                foreach ($this->shortcodes as $open) {
                    for (; $at < $open[1] && $at < count($stack); $at++) {
                        $with[] = $stack[$at];
                    }
                    $with[] = '[' . $open[0] . ']';
                }
                for (; $at < count($stack); $at++) {
                    $with[] = $stack[$at];
                }
                $stack = $with;
            }
            $key = implode("\x1f", $stack);
            if (!isset($this->stack_ids[$key])) {
                $this->stack_ids[$key] = count($this->stacks);
                $this->stacks[] = $stack;
            }

            $wpq = null;
            $role = null;
            if (null !== $this->pending && trim((string) $sql) === $this->pending[0]) {
                $wpq = $this->describe_wp_query($this->pending[1]);
                $role = 'posts';
                $this->pending = null;
            } elseif (null !== $this->pending_found && trim((string) $sql) === $this->pending_found[0]) {
                $wpq = $this->describe_wp_query($this->pending_found[1]);
                $role = 'found_rows';
                $this->pending_found = null;
            }

            $frames = null;
            if ($this->take_frames) {
                $this->settle_frames();
                $frames = array();
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- bounded argument-free frames locate a measured query during a signed profiling request; nothing is logged.
                $raw = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, self::RAW_FRAMES);
                // Start below wpdb::query()'s apply_filters('query'): the frames above it are this
                // observer and the hook machinery.
                $from = 0;
                foreach ($raw as $i => $f) {
                    if ('apply_filters' === $f['function'] && isset($f['file']) && preg_match('#/(class-wpdb|wp-db)\.php$#', str_replace('\\', '/', $f['file']))) {
                        $from = $i + 1;
                        break;
                    }
                }
                foreach (array_slice($raw, $from) as $f) {
                    if (isset($f['file'])) {
                        $frames[] = array($f['file'], isset($f['line']) ? $f['line'] : 0, (isset($f['class']) ? $f['class'] . '::' : '') . $f['function']);
                    }
                }
            }

            $this->records[$this->seq] = array(
                $sql, $this->stack_ids[$key], $this->template, $this->area_now($stack), $this->area_hook,
                $this->widget, $wpq, $role, $frames,
            );
            if ($this->take_frames) {
                $this->open_seq = $this->seq;
                $this->open_at = microtime(true);
            }
            return $sql;
        }

        /**
         * Decide whether the previous query keeps its frames. Its time is not known here, so the
         * time since it started stands in: an upper bound, which can keep a fast query's frames
         * but never drops a slow one's.
         */
        private function settle_frames() {
            if (null === $this->open_seq) {
                return;
            }
            $seq = $this->open_seq;
            $ms = (microtime(true) - $this->open_at) * 1000;
            $this->open_seq = null;
            if ($ms >= self::KEEP_MS) {
                return;
            }
            if (count($this->slowest) < self::KEEP_SLOWEST) {
                $this->slowest[$seq] = $ms;
                if (null === $this->slowest_min || $ms < $this->slowest[$this->slowest_min]) {
                    $this->slowest_min = $seq;
                }
                return;
            }
            if ($ms <= $this->slowest[$this->slowest_min]) {
                $evict = $seq;
            } else {
                $evict = $this->slowest_min;
                unset($this->slowest[$evict]);
                $this->slowest[$seq] = $ms;
                $this->slowest_min = array_search(min($this->slowest), $this->slowest, true);
            }
            if (isset($this->records[$evict])) {
                $this->records[$evict][8] = null;
            }
        }

        /** wp_head and wp_footer place a query by running, whatever the template state says. */
        private function area_now($stack) {
            if ($stack) {
                if (in_array('wp_footer', $stack, true)) {
                    return 'footer';
                }
                if (in_array('wp_head', $stack, true)) {
                    return 'header';
                }
            }
            return $this->area;
        }

        private function describe_wp_query($query) {
            $known = $this->wp_query_index($query);
            if (null !== $known) {
                return $known;
            }
            if (count($this->wp_queries) >= self::MAX_WP_QUERIES) {
                return null;
            }
            $qv = isset($query->query_vars) && is_array($query->query_vars) ? $query->query_vars : array();
            $asked = isset($query->query) && is_array($query->query) ? $query->query : array();
            if (!empty($asked['showposts'])) {
                $per_page_from = 'showposts';
            } elseif (isset($asked['numberposts'], $asked['posts_per_page']) && (int) $asked['numberposts'] === (int) $asked['posts_per_page']) {
                // get_posts() copies numberposts into posts_per_page; both arrive here.
                $per_page_from = 'numberposts';
            } elseif (isset($asked['posts_per_page'])) {
                $per_page_from = 'posts_per_page';
            } else {
                $per_page_from = 'default';
            }
            $spro = array();
            foreach ($qv as $name => $value) {
                if (0 === strpos((string) $name, 'spro_') && (is_scalar($value) || null === $value)) {
                    $spro[(string) $name] = $value;
                }
            }
            $post_type = isset($qv['post_type']) ? $qv['post_type'] : '';
            $facts = array(
                'main' => method_exists($query, 'is_main_query') ? (bool) $query->is_main_query() : false,
                'posts_per_page' => isset($qv['posts_per_page']) ? (int) $qv['posts_per_page'] : null,
                'per_page_from' => $per_page_from,
                'paged' => !empty($qv['paged']) ? (int) $qv['paged'] : 0,
                'offset' => (isset($qv['offset']) && '' !== $qv['offset']) ? (int) $qv['offset'] : null,
                'no_found_rows' => !empty($qv['no_found_rows']),
                'fields' => !empty($qv['fields']) ? (string) $qv['fields'] : 'all',
                'post_type' => is_array($post_type) ? array_values(array_map('strval', $post_type)) : (string) $post_type,
                'suppress_filters' => !empty($qv['suppress_filters']),
                'tax_query' => $this->tax_summary($query),
                'meta_query' => $this->meta_summary($query),
                'spro' => $spro ? $spro : null,
            );
            $facts['total'] = null;
            $index = count($this->wp_queries);
            // A weak reference: the WP_Query is freed as it would be without the profiler.
            $this->wp_queries[] = array($facts, class_exists('WeakReference') ? WeakReference::create($query) : null);
            $this->wp_query_ids[spl_object_hash($query)] = $index;
            return $index;
        }

        private function tax_summary($query) {
            if (!isset($query->tax_query) || !is_object($query->tax_query) || empty($query->tax_query->queries)) {
                return null;
            }
            $clauses = array();
            $walk = function ($queries) use (&$walk, &$clauses) {
                foreach ((array) $queries as $key => $clause) {
                    if (!is_array($clause) || count($clauses) >= 10) {
                        continue;
                    }
                    if (isset($clause['taxonomy'])) {
                        $terms = isset($clause['terms']) ? (array) $clause['terms'] : array();
                        $clauses[] = array(
                            'taxonomy' => (string) $clause['taxonomy'],
                            'field' => isset($clause['field']) ? (string) $clause['field'] : 'term_id',
                            'operator' => isset($clause['operator']) ? (string) $clause['operator'] : 'IN',
                            'terms' => array_slice(array_values(array_filter($terms, 'is_scalar')), 0, 10),
                            'term_count' => count($terms),
                            'include_children' => !isset($clause['include_children']) || (bool) $clause['include_children'],
                        );
                    } else {
                        $walk($clause);
                    }
                }
            };
            $walk($query->tax_query->queries);
            return array('relation' => isset($query->tax_query->relation) ? (string) $query->tax_query->relation : 'AND', 'clauses' => $clauses);
        }

        private function meta_summary($query) {
            if (!isset($query->meta_query) || !is_object($query->meta_query) || empty($query->meta_query->queries)) {
                return null;
            }
            $clauses = array();
            $walk = function ($queries) use (&$walk, &$clauses) {
                foreach ((array) $queries as $key => $clause) {
                    if (!is_array($clause) || count($clauses) >= 10) {
                        continue;
                    }
                    if (isset($clause['key']) || isset($clause['compare']) || isset($clause['value'])) {
                        $clauses[] = array(
                            'key' => isset($clause['key']) ? (string) $clause['key'] : null,
                            'compare' => isset($clause['compare']) ? strtoupper((string) $clause['compare']) : (isset($clause['value']) && is_array($clause['value']) ? 'IN' : '='),
                            'type' => isset($clause['type']) ? strtoupper((string) $clause['type']) : 'CHAR',
                        );
                    } elseif ('relation' !== $key) {
                        $walk($clause);
                    }
                }
            };
            $walk($query->meta_query->queries);
            return array('relation' => isset($query->meta_query->relation) ? (string) $query->meta_query->relation : 'AND', 'clauses' => $clauses);
        }

        // ---------------- report ----------------

        /**
         * Observation numbers for SAVEQUERIES entries, matched by their SQL in order. The log was
         * filled by the same wpdb::query() calls that fired the filter, so a forward walk finds
         * each one; queries from before the profiler armed match nothing and get no context.
         *
         * @param array $sqls log position => SQL as logged
         * @return array log position => observation number
         */
        public function match($sqls) {
            $out = array();
            $seqs = array_keys($this->records);
            $at = 0;
            $total = count($seqs);
            foreach ($sqls as $position => $sql) {
                for ($look = $at; $look < $total && $look < $at + self::MATCH_WINDOW; $look++) {
                    if ($this->records[$seqs[$look]][0] === $sql) {
                        $out[$position] = $seqs[$look];
                        $at = $look + 1;
                        break;
                    }
                }
            }
            return $out;
        }

        /**
         * The context of one observation, ready for the capture.
         *
         * @param int                 $seq    observation number
         * @param array|null          $frames the profiling wpdb's frames for this query, if any
         * @param SSPA_Component_Map  $map
         * @param string              $sql    the query as executed
         * @return array|null
         */
        public function context($seq, $frames, $map, $sql) {
            if (!isset($this->records[$seq])) {
                return null;
            }
            list(, $stack_id, $template, $area, $area_hook, $widget, $wpq, $role, $own_frames) = $this->records[$seq];
            $stack = $this->stacks[$stack_id];

            $hook = null;
            $shortcodes = array();
            foreach ($stack as $entry) {
                if ('[' === substr($entry, 0, 1)) {
                    $shortcodes[] = substr($entry, 1, -1);
                }
            }
            for ($i = count($stack) - 1; $i >= 0; $i--) {
                if ('[' !== substr($stack[$i], 0, 1) && !in_array($stack[$i], self::$generic, true)) {
                    $hook = $stack[$i];
                    break;
                }
            }
            $source = 'stack';
            if (null === $hook && null !== $area_hook) {
                // Nothing meaningful is running: the template the area hook loaded is, as when
                // footer.php calls do_shortcode() after get_footer has returned.
                $hook = $area_hook;
                $source = 'area';
            }
            $path = $stack;
            if (null !== $area_hook && !in_array($area_hook, $stack, true)) {
                array_unshift($path, $area_hook);
            }
            $path = array_values(array_filter($path, function ($entry) {
                return !in_array($entry, self::$generic, true);
            }));

            $shortcode = null;
            if ($shortcodes) {
                $tag = end($shortcodes);
                $owner = $this->shortcode_owner($tag, $map);
                $shortcode = array('tag' => $tag, 'owner' => $owner['component'], 'owner_type' => $owner['type'], 'stack' => $shortcodes);
            }

            $wp_query = null;
            $spro = null;
            $total = null;
            if (null !== $wpq && isset($this->wp_queries[$wpq])) {
                $facts = $this->wp_queries[$wpq][0];
                $wp_query = $facts;
                $wp_query['role'] = $role;
                $spro = $facts['spro'];
                $total = $facts['total'];
                unset($wp_query['spro'], $wp_query['total']);
            }

            return array(
                'hook_stack' => array_values($stack),
                'hook' => $hook,
                'hook_from' => (null === $hook) ? null : $source,
                'hook_path' => implode(' > ', $path),
                'shortcode' => $shortcode,
                'template' => $template ? $map->display_file($template) : null,
                'area' => $area,
                'area_hook' => $area_hook,
                'widget' => (is_array($widget) && null !== $widget['name']) ? $widget : null,
                'wp_query' => $wp_query,
                'found_rows' => array(
                    'calc_found_rows' => false !== stripos((string) $sql, 'SQL_CALC_FOUND_ROWS'),
                    'total' => $total,
                ),
                'spro' => $spro,
                'frames' => $map->display_frames(null !== $frames ? $frames : (array) $own_frames, self::FRAMES),
                'frames_from' => null !== $frames ? 'profiling_wpdb' : 'query_filter',
            );
        }

        private function shortcode_owner($tag, $map) {
            $callback = isset($this->shortcode_tags[$tag]) ? $this->shortcode_tags[$tag]
                : (isset($GLOBALS['shortcode_tags'][$tag]) ? $GLOBALS['shortcode_tags'][$tag] : null);
            $file = null;
            try {
                if ($callback instanceof Closure || (is_string($callback) && function_exists($callback))) {
                    $file = (new ReflectionFunction($callback))->getFileName();
                } elseif (is_array($callback) && isset($callback[0], $callback[1])) {
                    $file = (new ReflectionMethod($callback[0], $callback[1]))->getFileName();
                } elseif (is_string($callback) && false !== strpos($callback, '::')) {
                    $file = (new ReflectionMethod($callback))->getFileName();
                } elseif (is_object($callback) && method_exists($callback, '__invoke')) {
                    $file = (new ReflectionMethod($callback, '__invoke'))->getFileName();
                }
            } catch (Throwable $e) {
                $file = null;
            }
            return $file ? $map->classify_file($file) : array('component' => null, 'type' => null);
        }

        public function summary() {
            return array('observed' => $this->seq, 'truncated' => $this->truncated, 'wp_queries' => count($this->wp_queries));
        }
    }
}
