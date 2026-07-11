<?php
defined('ABSPATH') || exit;

/**
 * The deep-analysis brain: decides which measurement to take next and turns results into
 * per-plugin impacts. PURE LOGIC - no WordPress calls, no HTTP - so it unit-tests against
 * synthetic cost functions and its state round-trips through JSON between batches.
 *
 * Three measurement kinds:
 * - baseline: the page with nothing excluded; its sample spread sets the noise gate
 *   (max(3 x stddev, 30ms) - deltas below it are reported as no measurable impact).
 * - single: one suspect excluded on its worst page -> exact attributable cost.
 * - bisect: a candidate SET excluded on a slow page. Faster page = culprits inside the
 *   set -> split and recurse (both halves, so multiple culprits are found). A fatal
 *   response means we broke a dependency: split and recurse without measuring; a fatal
 *   singleton is recorded as an unresolved dependency, not an impact.
 */
class SSPA_Isolation_Planner {

    const GATE_MIN_MS = 30;

    public $state;

    /**
     * @param array $singles [ ['plugin' => slug|'theme', 'page_key' => k], ... ]
     * @param array $bisects [ ['page_key' => k, 'candidates' => [slugs]], ... ]
     */
    public static function create($singles, $bisects, $max_measurements = 60) {
        $planner = new self();
        $planner->state = array(
            'max_measurements' => (int) $max_measurements,
            'measure_count' => 0,
            'next_id' => 1,
            'pages' => array(),   // page_key => ['baseline' => metrics|null, 'gate' => float|null, 'fatal' => bool]
            'singles' => array(), // ['plugin','page_key','done'=>bool]
            'bisect' => array(),  // ['page_key','stack'=>[ [slugs...], ... ]]
            'current' => null,    // issued spec awaiting record()
            'impacts' => array(),
            'unresolved' => array(),
            'truncated' => false,
        );
        foreach ($singles as $s) {
            $planner->state['singles'][] = array('plugin' => $s['plugin'], 'page_key' => $s['page_key'], 'done' => false);
            $planner->touch_page($s['page_key']);
        }
        foreach ($bisects as $b) {
            if (!empty($b['candidates'])) {
                $planner->state['bisect'][] = array('page_key' => $b['page_key'], 'stack' => array(array_values($b['candidates'])));
                $planner->touch_page($b['page_key']);
            }
        }
        return $planner;
    }

    public static function from_state($state) {
        $planner = new self();
        $planner->state = $state;
        return $planner;
    }

    private function touch_page($page_key) {
        if (!isset($this->state['pages'][$page_key])) {
            $this->state['pages'][$page_key] = array('baseline' => null, 'gate' => null, 'fatal' => false);
        }
    }

    /**
     * @return array|null spec {id, kind, page_key, exclude, theme_swap, plugin?} or null when done.
     */
    public function next() {
        if ($this->state['current']) {
            return $this->state['current']; // re-issue: last spec was never recorded
        }
        if ($this->state['measure_count'] >= $this->state['max_measurements']) {
            if ($this->has_pending_work()) {
                $this->state['truncated'] = true;
            }
            return null;
        }

        // 1. Baselines for any page still needed by pending work.
        foreach ($this->state['pages'] as $page_key => $page) {
            if ($page['baseline'] === null && !$page['fatal'] && $this->page_has_pending_work($page_key)) {
                return $this->issue(array('kind' => 'baseline', 'page_key' => $page_key, 'exclude' => array(), 'theme_swap' => false));
            }
        }

        // 2. Singles.
        foreach ($this->state['singles'] as $i => $s) {
            if (!$s['done'] && $this->page_ready($s['page_key'])) {
                return $this->issue(array(
                    'kind' => 'single',
                    'single_index' => $i,
                    'plugin' => $s['plugin'],
                    'page_key' => $s['page_key'],
                    'exclude' => ('theme' === $s['plugin']) ? array() : array($s['plugin']),
                    'theme_swap' => ('theme' === $s['plugin']),
                ));
            }
        }

        // 3. Bisection.
        foreach ($this->state['bisect'] as $i => $task) {
            if (!empty($task['stack']) && $this->page_ready($task['page_key'])) {
                $set = end($task['stack']);
                return $this->issue(array(
                    'kind' => 'bisect',
                    'bisect_index' => $i,
                    'page_key' => $task['page_key'],
                    'exclude' => $set,
                    'theme_swap' => false,
                ));
            }
        }

        return null;
    }

    private function issue($spec) {
        $spec['id'] = $this->state['next_id']++;
        $this->state['current'] = $spec;
        return $spec;
    }

    private function page_ready($page_key) {
        $p = $this->state['pages'][$page_key];
        return $p['baseline'] !== null && !$p['fatal'];
    }

    private function page_has_pending_work($page_key) {
        foreach ($this->state['singles'] as $s) {
            if (!$s['done'] && $s['page_key'] === $page_key) {
                return true;
            }
        }
        foreach ($this->state['bisect'] as $task) {
            if (!empty($task['stack']) && $task['page_key'] === $page_key) {
                return true;
            }
        }
        return false;
    }

    private function has_pending_work() {
        foreach ($this->state['pages'] as $page_key => $p) {
            if ($this->page_has_pending_work($page_key)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array $result {gen_ms, sql_ms, mem_bytes, queries, samples: [gen_ms...], fatal: bool}
     */
    public function record($result) {
        $spec = $this->state['current'];
        if (!$spec) {
            return;
        }
        $this->state['current'] = null;
        $this->state['measure_count']++;
        $page_key = $spec['page_key'];

        if ('baseline' === $spec['kind']) {
            if (!empty($result['fatal'])) {
                $this->state['pages'][$page_key]['fatal'] = true;
                $this->state['unresolved'][] = array('type' => 'page_fatal', 'page_key' => $page_key);
                return;
            }
            $this->state['pages'][$page_key]['baseline'] = $result;
            $this->state['pages'][$page_key]['gate'] = max(self::GATE_MIN_MS, 3 * self::stddev($result['samples']));
            return;
        }

        if ('single' === $spec['kind']) {
            $this->state['singles'][$spec['single_index']]['done'] = true;
            if (!empty($result['fatal'])) {
                $this->state['unresolved'][] = array('type' => 'fatal_without', 'plugin' => $spec['plugin'], 'page_key' => $page_key);
                return;
            }
            $this->add_impact($spec['plugin'], $page_key, 'single_out', $result);
            return;
        }

        // bisect
        $task = &$this->state['bisect'][$spec['bisect_index']];
        array_pop($task['stack']);
        $set = $spec['exclude'];

        if (!empty($result['fatal'])) {
            if (count($set) > 1) {
                // Broke a dependency somewhere inside the set: recurse without measuring.
                // Rotating before the split gives the next level a different grouping, so
                // co-dependent pairs tend to land in the same half and stop fataling.
                $rotated = $set;
                $rotated[] = array_shift($rotated);
                foreach ($this->split($rotated) as $half) {
                    $task['stack'][] = $half;
                }
            } else {
                $this->state['unresolved'][] = array('type' => 'fatal_without', 'plugin' => $set[0], 'page_key' => $page_key);
            }
            return;
        }

        $baseline = $this->state['pages'][$page_key]['baseline'];
        $gate = $this->state['pages'][$page_key]['gate'];
        $delta = $baseline['gen_ms'] - $result['gen_ms'];

        if ($delta <= $gate) {
            return; // no measurable cost inside this set
        }
        if (count($set) === 1) {
            $this->add_impact($set[0], $page_key, 'bisect', $result);
            return;
        }
        foreach ($this->split($set) as $half) {
            $task['stack'][] = $half;
        }
    }

    private function split($set) {
        $mid = (int) ceil(count($set) / 2);
        return array(array_slice($set, 0, $mid), array_slice($set, $mid));
    }

    private function add_impact($plugin, $page_key, $method, $result) {
        $baseline = $this->state['pages'][$page_key]['baseline'];
        $gate = $this->state['pages'][$page_key]['gate'];
        $delta_gen = $baseline['gen_ms'] - $result['gen_ms'];
        $measurable = $delta_gen > $gate;
        $this->state['impacts'][] = array(
            'plugin' => $plugin,
            'page_key' => $page_key,
            'method' => $method,
            'delta_ttfb_ms' => round($delta_gen, 1),
            'delta_sql_ms' => round($baseline['sql_ms'] - $result['sql_ms'], 1),
            'delta_mem_bytes' => (int) ($baseline['mem_bytes'] - $result['mem_bytes']),
            'delta_queries' => (int) ($baseline['queries'] - $result['queries']),
            'noise_floor_ms' => round($gate, 1),
            'confidence' => $measurable ? 'measured' : 'none',
        );
    }

    public function impacts() {
        return $this->state['impacts'];
    }

    public function unresolved() {
        return $this->state['unresolved'];
    }

    public function truncated() {
        return $this->state['truncated'];
    }

    public function done_count() {
        return $this->state['measure_count'];
    }

    /**
     * Rough upper-bound of remaining measurements, for progress display only.
     */
    public function estimate_remaining() {
        $n = 0;
        foreach ($this->state['pages'] as $page_key => $p) {
            if ($p['baseline'] === null && !$p['fatal'] && $this->page_has_pending_work($page_key)) {
                $n++;
            }
        }
        foreach ($this->state['singles'] as $s) {
            if (!$s['done']) {
                $n++;
            }
        }
        foreach ($this->state['bisect'] as $task) {
            foreach ($task['stack'] as $set) {
                // Each pending set may expand into ~2*log2(size) further measurements.
                $n += 1 + 2 * (int) ceil(log(max(2, count($set)), 2));
            }
        }
        return min($n, $this->state['max_measurements'] - $this->state['measure_count']);
    }

    public static function stddev($values) {
        $values = array_values(array_filter((array) $values, 'is_numeric'));
        $n = count($values);
        if ($n < 2) {
            return 0.0;
        }
        $mean = array_sum($values) / $n;
        $sum = 0.0;
        foreach ($values as $v) {
            $sum += ($v - $mean) ** 2;
        }
        return sqrt($sum / $n);
    }
}
