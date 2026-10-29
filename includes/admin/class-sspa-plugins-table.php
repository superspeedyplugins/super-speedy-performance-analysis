<?php
defined('ABSPATH') || exit;

/**
 * The Plugins tab's per-component table.
 *
 * Extracted from the tab template so the initial page render and the attribution-mode AJAX
 * switch return byte-identical markup from one code path. Two copies of this rendering would
 * drift the moment a column changed.
 */
class SSPA_Plugins_Table {

    /**
     * The run whose attribution totals the table shows. Profiling runs only: deep and cache
     * runs store partial or plugin-set-modified profiles that would corrupt the totals.
     */
    public static function latest_run_id() {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM %i
             WHERE status = 'done' AND run_type IN ('baseline','spot') ORDER BY id DESC LIMIT 1",
            SSPA_Schema::table('runs')
        ));
    }

    /**
     * The newest measurement for each plugin, page and cache mode.
     *
     * Deliberately NOT "every row from the plugin's most recent sweep". A sweep can be scoped
     * to one page (`--pages`), which is the cheap way to re-measure something you have just
     * fixed; keying on the run would then throw away every other page that sweep did not
     * touch, and the plugin would appear to affect one page only. Keying on the measurement
     * lets a targeted re-measure update exactly what it measured and leave the rest standing.
     *
     * This reader prepares and executes each complete branch and returns its rows.
     *
     * MAX(id) rather than MAX(created): two sweeps in the same second are possible, and the
     * autoincrement is the only strict ordering.
     *
     * @param string $plugin   Optional single plugin to restrict to.
     * @param string $page_key Optional single page to restrict to - the profile panel asks
     *                         "what has been measured HERE", which is the same query the
     *                         other way round.
     */
    public static function latest_impacts($plugin = '', $page_key = '') {
        global $wpdb;
        $table = SSPA_Schema::table('plugin_impacts');
        if ('' !== $plugin && '' !== $page_key) {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT pi.* FROM %i pi
                    JOIN (SELECT MAX(id) mid FROM %i WHERE plugin = %s AND page_key = %s
                          GROUP BY plugin, page_key, object_cache_mode) latest ON latest.mid = pi.id
                    ORDER BY pi.page_key, pi.id",
                $table, $table, $plugin, $page_key
            ), ARRAY_A);
        }
        if ('' !== $plugin) {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT pi.* FROM %i pi
                    JOIN (SELECT MAX(id) mid FROM %i WHERE plugin = %s
                          GROUP BY plugin, page_key, object_cache_mode) latest ON latest.mid = pi.id
                    ORDER BY pi.page_key, pi.id",
                $table, $table, $plugin
            ), ARRAY_A);
        }
        if ('' !== $page_key) {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT pi.* FROM %i pi
                    JOIN (SELECT MAX(id) mid FROM %i WHERE page_key = %s
                          GROUP BY plugin, page_key, object_cache_mode) latest ON latest.mid = pi.id
                    ORDER BY pi.page_key, pi.id",
                $table, $table, $page_key
            ), ARRAY_A);
        }
        return $wpdb->get_results($wpdb->prepare(
            "SELECT pi.* FROM %i pi
                JOIN (SELECT MAX(id) mid FROM %i
                      GROUP BY plugin, page_key, object_cache_mode) latest ON latest.mid = pi.id
                ORDER BY pi.page_key, pi.id",
            $table, $table
        ), ARRAY_A);
    }

    /**
     * Where the Plugins tab sends owners to compare their plugins with other sites.
     *
     * /categories/ is the live ranking index today. Change this one constant when
     * superspeedy.org's /leaderboards/ hub is deployed.
     */
    const PUBLIC_RANKINGS_URL = 'https://superspeedy.org/categories/';

    /**
     * An unseen summary reopens on the next visit for this long after the run finished. Past
     * that the Plugins tab is the record; a dialog about a run weeks old, or one from before
     * this feature existed on the first visit after an update, would be noise.
     */
    const SUMMARY_REOPEN_DAYS = 7;

    /** Sort orders the toolbar offers, in menu order. The first is the default. */
    public static function sort_orders() {
        return array(
            'impact' => __('Measured impact, typical page', 'super-speedy-performance-analysis'),
            'worst' => __('Measured impact, worst page', 'super-speedy-performance-analysis'),
            'sql' => __('SQL time', 'super-speedy-performance-analysis'),
            'queries' => __('Queries', 'super-speedy-performance-analysis'),
            'rows' => __('Rows fetched', 'super-speedy-performance-analysis'),
            'slowest' => __('Slowest query', 'super-speedy-performance-analysis'),
            'http' => __('HTTP time', 'super-speedy-performance-analysis'),
            'name' => __('Name', 'super-speedy-performance-analysis'),
        );
    }

    /** Show filters the toolbar offers. '' is everything. */
    public static function show_filters() {
        return array(
            '' => __('All plugins', 'super-speedy-performance-analysis'),
            'adds' => __('Costs time', 'super-speedy-performance-analysis'),
            'saves' => __('Saves time', 'super-speedy-performance-analysis'),
            'unmeasured' => __('Needs measuring', 'super-speedy-performance-analysis'),
        );
    }

    /**
     * @param int    $run_id Attribution run.
     * @param string $mode   Attribution mode.
     * @param string $page   One page key, or '' for every profiled page. A key that is not
     *                       among this run's pages or the measured impacts falls back to ''.
     */
    public static function render($run_id, $mode, $page = '') {
        global $wpdb;

        $run_id = (int) $run_id;
        $mode = SSPA_Attribution::sanitise_mode($mode);

        $impacts = array();
        $pages = array();
        $profiles = array();
        $rows = SSPA_Attribution::component_rows($run_id, $mode);
        foreach ($rows as $row) {
            $pages[$row['page_key']] = true;
            $profiles[$row['page_key']] = (int) $row['profile_id'];
        }
        foreach (self::latest_impacts() as $row) {
            $impacts[$row['plugin']][] = $row;
            $pages[$row['page_key']] = true;
        }
        $page = (string) $page;
        if ('' !== $page && !isset($pages[$page])) {
            $page = '';
        }

        // Worst single page per component + totals across the run (or the one chosen page).
        // Grouped in PHP rather than SQL because caller mode is recomputed from the capture
        // blobs, not stored in a table.
        $grouped = array();
        foreach ($rows as $row) {
            if ('' !== $page && $row['page_key'] !== $page) {
                continue;
            }
            $key = $row['component'] . '|' . $row['component_type'];
            if (!isset($grouped[$key])) {
                $grouped[$key] = array(
                    'component' => $row['component'],
                    'component_type' => $row['component_type'],
                    'total_sql_ms' => 0, 'total_queries' => 0, 'total_rows' => 0,
                    'total_http_ms' => 0, 'slowest_query_ms' => 0, 'worst_page_sql_ms' => 0,
                );
            }
            $g = &$grouped[$key];
            $g['total_sql_ms'] += (float) $row['sql_ms'];
            $g['total_queries'] += (int) $row['query_count'];
            $g['total_rows'] += (int) $row['rows_returned'];
            $g['total_http_ms'] += (float) $row['http_ms'];
            $g['slowest_query_ms'] = max($g['slowest_query_ms'], (float) $row['slowest_query_ms']);
            $g['worst_page_sql_ms'] = max($g['worst_page_sql_ms'], (float) $row['sql_ms']);
            unset($g);
        }

        // Latest cache-impact results (component => saved_pct), if a cache run has been done.
        $cache_notes = $wpdb->get_var($wpdb->prepare(
            "SELECT notes FROM %i WHERE run_type = 'cache_impact' AND status = 'done' ORDER BY id DESC LIMIT 1",
            SSPA_Schema::table('runs')
        ));
        $cache = array();
        if ($cache_notes) {
            $decoded = json_decode($cache_notes, true);
            if (is_array($decoded) && !empty($decoded['components'])) {
                $cache = $decoded['components'];
            }
        }

        foreach ($grouped as &$c) {
            $impact_rows = isset($impacts[$c['component']]) ? $impacts[$c['component']] : array();
            if ('' !== $page) {
                $impact_rows = array_values(array_filter($impact_rows, function ($r) use ($page) {
                    return $r['page_key'] === $page;
                }));
            }
            // The version as measured by the run these attribution totals came from, not
            // the version installed right now - the two differ as soon as a plugin updates.
            $c['version'] = SSPA_Run_Controller::component_version($run_id, $c['component'], $c['component_type']);
            $c['impact_rows'] = $impact_rows;
            $c['verdict'] = self::verdict($impact_rows);
            $c['stale'] = $c['verdict'] ? self::is_stale($c['verdict']['rows'], $c['version'], $run_id) : false;
        }
        unset($c);

        // Default order without JavaScript: measured cost, largest first, then the rest by SQL
        // time. The toolbar re-sorts the same rows in the browser.
        usort($grouped, function ($a, $b) {
            $ta = ($a['verdict'] && null !== $a['verdict']['typical']) ? $a['verdict']['typical'] : null;
            $tb = ($b['verdict'] && null !== $b['verdict']['typical']) ? $b['verdict']['typical'] : null;
            if (null !== $ta && null !== $tb && $ta !== $tb) {
                return $tb <=> $ta;
            }
            if ((null === $ta) !== (null === $tb)) {
                return null === $ta ? 1 : -1;
            }
            return $b['total_sql_ms'] <=> $a['total_sql_ms'];
        });

        ob_start();
        ?>
        <div class="sspa-plugins-toolbar">
            <label><?php esc_html_e('Page', 'super-speedy-performance-analysis'); ?>
                <select id="sspa-plugins-page">
                    <option value=""><?php
                        /* translators: %d: number of profiled pages */
                        echo esc_html(sprintf(_n('All profiled pages (%d)', 'All profiled pages (%d)', count($pages), 'super-speedy-performance-analysis'), count($pages))); ?></option>
                    <?php foreach (array_keys($pages) as $key) : ?>
                        <option value="<?php echo esc_attr($key); ?>"<?php selected($key, $page); ?>><?php echo esc_html($key); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><?php esc_html_e('Sort by', 'super-speedy-performance-analysis'); ?>
                <select id="sspa-plugins-sort">
                    <?php foreach (self::sort_orders() as $key => $label) : ?>
                        <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><?php esc_html_e('Show', 'super-speedy-performance-analysis'); ?>
                <select id="sspa-plugins-show">
                    <?php foreach (self::show_filters() as $key => $label) : ?>
                        <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><?php esc_html_e('Name', 'super-speedy-performance-analysis'); ?>
                <input type="search" id="sspa-plugins-name">
            </label>
            <span id="sspa-plugins-count" aria-live="polite"></span>
        </div>
        <?php if ('' !== $page) : ?>
            <p class="description sspa-plugins-page-note">
                <?php
                /* translators: %s: page key */
                echo esc_html(sprintf(__('Figures for %s only: the attribution columns are this page\'s numbers and Measured impact is the change on this page.', 'super-speedy-performance-analysis'), $page));
                if (!empty($profiles[$page])) {
                    echo ' <a href="#" class="sspa-plugins-open-profile" data-profile-id="' . esc_attr($profiles[$page]) . '">' . esc_html__('Open the page analysis', 'super-speedy-performance-analysis') . '</a>';
                }
                ?>
            </p>
        <?php endif; ?>
        <table class="widefat striped sspa-pages-table" id="sspa-attrib-table" data-page="<?php echo esc_attr($page); ?>">
            <thead>
                <tr>
                    <th data-sort-key="name"><?php esc_html_e('Component', 'super-speedy-performance-analysis'); ?></th>
                    <th><?php esc_html_e('Type', 'super-speedy-performance-analysis'); ?></th>
                    <th><?php esc_html_e('Version', 'super-speedy-performance-analysis'); ?></th>
                    <th data-sort-key="sql"><?php echo esc_html('' !== $page ? __('SQL (ms)', 'super-speedy-performance-analysis') : __('SQL total (ms)', 'super-speedy-performance-analysis')); ?></th>
                    <th data-sort-key="queries"><?php esc_html_e('Queries', 'super-speedy-performance-analysis'); ?></th>
                    <th data-sort-key="rows"><?php esc_html_e('Rows fetched', 'super-speedy-performance-analysis'); ?></th>
                    <th data-sort-key="slowest"><?php esc_html_e('Slowest query (ms)', 'super-speedy-performance-analysis'); ?></th>
                    <th data-sort-key="http"><?php esc_html_e('HTTP (ms)', 'super-speedy-performance-analysis'); ?></th>
                    <th data-sort-key="impact"><?php esc_html_e('Measured impact', 'super-speedy-performance-analysis'); ?></th>
                    <th><?php esc_html_e('Object cache', 'super-speedy-performance-analysis'); ?></th>
                    <th><span class="screen-reader-text"><?php esc_html_e('Measure', 'super-speedy-performance-analysis'); ?></span></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($grouped as $c) :
                $v = $c['verdict'];
                $state = !$v ? 'unmeasured' : (null === $v['typical'] ? 'none' : ($v['typical'] < 0 ? 'saves' : 'adds')); ?>
                <tr data-component="<?php echo esc_attr($c['component']); ?>"
                    data-state="<?php echo esc_attr($state); ?>"
                    data-stale="<?php echo $c['stale'] ? '1' : '0'; ?>"
                    data-sort-name="<?php echo esc_attr($c['component']); ?>"
                    data-sort-impact="<?php echo esc_attr($v && null !== $v['typical'] ? round($v['typical'], 1) : ''); ?>"
                    data-sort-worst="<?php echo esc_attr($v && $v['worst'] ? round((float) $v['worst']['delta_ttfb_ms'], 1) : ''); ?>"
                    data-sort-sql="<?php echo esc_attr(round((float) $c['total_sql_ms'], 1)); ?>"
                    data-sort-queries="<?php echo esc_attr((int) $c['total_queries']); ?>"
                    data-sort-rows="<?php echo esc_attr((int) $c['total_rows']); ?>"
                    data-sort-slowest="<?php echo esc_attr(round((float) $c['slowest_query_ms'], 1)); ?>"
                    data-sort-http="<?php echo esc_attr(round((float) $c['total_http_ms'], 1)); ?>">
                    <td><code><?php echo esc_html($c['component']); ?></code></td>
                    <td><?php echo esc_html($c['component_type']); ?></td>
                    <td><?php echo $c['version'] ? esc_html($c['version']) : '-'; ?></td>
                    <td><?php echo esc_html(number_format((float) $c['total_sql_ms'], 1)); ?></td>
                    <td><?php echo esc_html(number_format((int) $c['total_queries'])); ?></td>
                    <td><?php echo esc_html(number_format((int) $c['total_rows'])); ?></td>
                    <td><?php echo esc_html(number_format((float) $c['slowest_query_ms'], 1)); ?></td>
                    <td><?php echo esc_html(number_format((float) $c['total_http_ms'], 1)); ?></td>
                    <td class="sspa-impact-col"><?php echo wp_kses(self::impact_cell($c, $page, $run_id), SSPA_Admin_HTML::allowed_tags()); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                    <td>
                        <?php
                        if (isset($cache[$c['component']]['saved_pct'])) {
                            $pct = (int) $cache[$c['component']]['saved_pct'];
                            if ($pct < 15) {
                                /* translators: %d: percentage of queries the plugin ran without consulting the object cache */
                                echo '<span class="sspa-badge sspa-blocked">' . esc_html(sprintf(__('cache-blind (%d%%)', 'super-speedy-performance-analysis'), $pct)) . '</span>';
                            } else {
                                /* translators: %d: percentage of queries saved by the object cache */
                                echo esc_html(sprintf(__('%d%% queries saved', 'super-speedy-performance-analysis'), $pct));
                            }
                        } else {
                            echo '-';
                        }
                        ?>
                    </td>
                    <td>
                        <?php if ('plugin' === $c['component_type'] && 'super-speedy-performance-analysis' !== $c['component'] && strpos($c['component'], 'mu:') !== 0) : ?>
                            <button type="button" class="button button-small sspa-measure-plugin" data-plugin="<?php echo esc_attr($c['component']); ?>">
                                <?php esc_html_e('Measure', 'super-speedy-performance-analysis'); ?>
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="sspa-plugins-empty" hidden><?php esc_html_e('No plugins match these filters.', 'super-speedy-performance-analysis'); ?></p>
        <?php
        return ob_get_clean();
    }

    /**
     * The headline verdict for one plugin's impact rows: one figure the table and the
     * Impact Analysis summary both use, so the two can never disagree.
     *
     * Steady-state mode first: normal (cache as-is, warmed) is the headline; 'warm' appears
     * only in pre-0.9.1 data.
     *
     * Typical and worst, never the sum. Adding the per-page deltas together produced a number
     * nobody experiences - no visitor loads every page type at once - and it grew simply by
     * measuring more pages, so a wider sweep made a plugin look worse. Median rather than
     * mean: deltas are skewed, and one pathological page should not define "typical".
     *
     * @return array|null null when there are no rows; otherwise mode, rows (that mode's),
     *                    measured (count), pages (count), typical (ms, or null when nothing
     *                    measured), worst (row or null) and noise (largest floor, ms).
     */
    public static function verdict($impact_rows) {
        if (!$impact_rows) {
            return null;
        }
        $modes_present = array_values(array_unique(array_map(function ($r) {
            return $r['object_cache_mode'];
        }, $impact_rows)));
        $pref = in_array('normal', $modes_present, true) ? 'normal'
            : (in_array('warm', $modes_present, true) ? 'warm' : $modes_present[0]);
        $mode_rows = array_values(array_filter($impact_rows, function ($r) use ($pref) {
            return $r['object_cache_mode'] === $pref;
        }));
        $measured = array_values(array_filter($mode_rows, function ($r) {
            return 'measured' === $r['confidence'];
        }));
        $floors = array_map(function ($r) {
            return (float) $r['noise_floor_ms'];
        }, $mode_rows);

        $typical = null;
        $worst = null;
        if ($measured) {
            $deltas = array();
            $worst = $measured[0];
            foreach ($measured as $r) {
                $deltas[] = (float) $r['delta_ttfb_ms'];
                if (abs((float) $r['delta_ttfb_ms']) > abs((float) $worst['delta_ttfb_ms'])) {
                    $worst = $r;
                }
            }
            sort($deltas);
            $count = count($deltas);
            $typical = (0 === $count % 2)
                ? ($deltas[$count / 2 - 1] + $deltas[$count / 2]) / 2
                : $deltas[(int) floor($count / 2)];
        }

        return array(
            'mode' => $pref,
            'rows' => $mode_rows,
            'measured' => count($measured),
            'pages' => count($mode_rows),
            'typical' => $typical,
            'worst' => $worst,
            'noise' => $floors ? max($floors) : 30,
        );
    }

    /**
     * The verdict in words, as the table's Measured impact cell and the summary say it.
     *
     * @param string $page Set when the verdict is for one page: there is no "typical" of one.
     */
    public static function verdict_text($v, $page = '') {
        if (null === $v['typical']) {
            return sprintf(
                /* translators: 1: number of pages, 2: noise floor in milliseconds */
                _n('no measurable impact on %1$d page (±%2$sms noise)', 'no measurable impact on %1$d pages (±%2$sms noise)', $v['pages'], 'super-speedy-performance-analysis'),
                $v['pages'],
                number_format($v['noise'])
            );
        }
        if ('' !== $page) {
            return $v['typical'] < 0
                /* translators: 1: milliseconds saved, 2: page key */
                ? sprintf(__('saves %1$sms on %2$s', 'super-speedy-performance-analysis'), number_format(abs($v['typical'])), $page)
                /* translators: 1: milliseconds added, 2: page key */
                : sprintf(__('adds %1$sms on %2$s', 'super-speedy-performance-analysis'), number_format($v['typical']), $page);
        }
        $typical = $v['typical'] < 0
            /* translators: %s: milliseconds saved, formatted */
            ? sprintf(__('saves %sms typically', 'super-speedy-performance-analysis'), number_format(abs($v['typical'])))
            /* translators: %s: milliseconds added, formatted */
            : sprintf(__('adds %sms typically', 'super-speedy-performance-analysis'), number_format($v['typical']));
        $worst_delta = (float) $v['worst']['delta_ttfb_ms'];
        return $typical . ', ' . sprintf(
            $worst_delta < 0
                /* translators: 1: milliseconds saved, 2: page key */
                ? __('up to %1$sms saved on %2$s', 'super-speedy-performance-analysis')
                /* translators: 1: milliseconds added, 2: page key */
                : __('up to %1$sms on %2$s', 'super-speedy-performance-analysis'),
            number_format(abs($worst_delta)),
            $v['worst']['page_key']
        );
    }

    /**
     * The Measured impact cell.
     *
     * Plugin Impact Analysis verdicts come from the plugin's own most recent sweep, which can be weeks
     * older than the attribution run beside it. A stale verdict reads as current unless the
     * cell says when it was taken, so the date is not optional decoration: a plugin fixed and
     * updated since the sweep keeps showing its old cost with nothing to indicate that.
     */
    private static function impact_cell($c, $page, $run_id) {
        $v = $c['verdict'];
        if (!$v) {
            return '<span class="sspa-badge">' . esc_html('' !== $page
                ? __('not measured on this page - run a Plugin Impact Analysis to measure', 'super-speedy-performance-analysis')
                : __('inferred - run a Plugin Impact Analysis to measure', 'super-speedy-performance-analysis')) . '</span>';
        }

        ob_start();
        if (null !== $v['typical']) {
            $saves = $v['typical'] < 0;
            $text = self::verdict_text($v, $page);
            // The first clause carries the colour: "adds 42ms typically" or "adds 42ms on shop".
            $split = strpos($text, ', ');
            echo '<strong class="' . ($saves ? 'sspa-impact-saves' : 'sspa-impact-adds') . '">' . esc_html(false === $split ? $text : substr($text, 0, $split)) . '</strong> ';
            if (false !== $split) {
                echo esc_html(substr($text, $split + 2));
            }
            echo ' <span class="sspa-badge sspa-badge-measured">' . esc_html('warm' === $v['mode'] ? __('measured, warm cache', 'super-speedy-performance-analysis') : __('measured', 'super-speedy-performance-analysis')) . '</span>';
            if ('' === $page) {
                echo '<br><small class="sspa-impact-detail">' . esc_html(sprintf(
                    /* translators: 1: pages with a measurable effect, 2: pages measured */
                    __('measurable on %1$d of %2$d pages measured', 'super-speedy-performance-analysis'),
                    $v['measured'],
                    $v['pages']
                )) . '</small>';
            }
        } else {
            echo '<span class="sspa-badge">' . esc_html(self::verdict_text($v, $page)) . '</span>';
        }

        echo wp_kses(self::group_note($v['rows']), SSPA_Admin_HTML::allowed_tags()); // phpcs:ignore WordPress.Security.EscapeOutput
        echo wp_kses(self::provenance($v['rows'], $c['version'], $run_id), SSPA_Admin_HTML::allowed_tags()); // phpcs:ignore WordPress.Security.EscapeOutput
        echo '<br><a href="#" class="sspa-impact-details" data-plugin="' . esc_attr($c['component']) . '">' . esc_html__('per-page breakdown', 'super-speedy-performance-analysis') . '</a>';

        return ob_get_clean();
    }

    /**
     * The finished Plugin Impact Analysis, summarised for the screen that ran it.
     *
     * Built from the latest measurement rows filtered to this run, so the figures are the
     * table's own. For the run that has just finished every row is still the latest; for an
     * older run, plugins re-measured since then drop out of its summary, which is right -
     * the summary shows what still stands.
     *
     * @param array $run A runs-table row (SSPA_Report::latest_deep_run()).
     * @return string HTML, or '' when the run left no measurements.
     */
    public static function run_summary($run) {
        $run_id = (int) $run['id'];
        $by_plugin = array();
        $pages = array();
        foreach (self::latest_impacts() as $row) {
            if ((int) $row['test_run_id'] !== $run_id) {
                continue;
            }
            $by_plugin[$row['plugin']][] = $row;
            $pages[$row['page_key']] = true;
        }
        if (!$by_plugin) {
            return '';
        }
        $verdicts = array();
        foreach ($by_plugin as $plugin => $rows) {
            $verdicts[$plugin] = self::verdict($rows);
        }
        uasort($verdicts, function ($a, $b) {
            if ((null === $a['typical']) !== (null === $b['typical'])) {
                return null === $a['typical'] ? 1 : -1;
            }
            return (float) $b['typical'] <=> (float) $a['typical'];
        });

        // A one-page run links to the Plugins tab filtered to that page.
        $page = 1 === count($pages) ? (string) key($pages) : '';
        $view = admin_url('admin.php?page=sspa' . ('' !== $page ? '&sspa_page=' . rawurlencode($page) : '') . '#plugins');
        $elapsed = (!empty($run['started']) && !empty($run['finished']))
            ? max(0, strtotime($run['finished']) - strtotime($run['started'])) : 0;

        $html = '<div class="sspa-impact-summary" data-run-id="' . esc_attr($run_id) . '">';
        $html .= '<p>' . esc_html(sprintf(
            /* translators: 1: number of plugins, 2: number of pages, 3: date and time the run finished */
            __('Measured %1$s on %2$s, finished %3$s.', 'super-speedy-performance-analysis'),
            /* translators: %d: number of plugins */
            sprintf(_n('%d plugin', '%d plugins', count($verdicts), 'super-speedy-performance-analysis'), count($verdicts)),
            /* translators: %d: number of pages */
            sprintf(_n('%d page', '%d pages', count($pages), 'super-speedy-performance-analysis'), count($pages)),
            // Runs store UTC (gmdate); show it in the site's time zone.
            get_date_from_gmt((string) $run['finished'], get_option('date_format') . ' ' . get_option('time_format'))
        ));
        if ($elapsed) {
            /* translators: %s: human-readable duration */
            $html .= ' ' . esc_html(sprintf(__('It took %s.', 'super-speedy-performance-analysis'), human_time_diff(0, $elapsed)));
        }
        $html .= '</p><ul class="sspa-impact-summary-list">';
        $shown = 0;
        foreach ($verdicts as $plugin => $v) {
            if (++$shown > 10) {
                break;
            }
            $class = null === $v['typical'] ? 'sspa-impact-none' : ($v['typical'] < 0 ? 'sspa-impact-saves' : 'sspa-impact-adds');
            $html .= '<li><code>' . esc_html($plugin) . '</code> <span class="' . esc_attr($class) . '">' . esc_html(self::verdict_text($v, $page)) . '</span></li>';
        }
        $html .= '</ul>';
        if (count($verdicts) > 10) {
            /* translators: %d: number of further plugins */
            $html .= '<p>' . esc_html(sprintf(_n('and %d more plugin on the Plugins tab.', 'and %d more plugins on the Plugins tab.', count($verdicts) - 10, 'super-speedy-performance-analysis'), count($verdicts) - 10)) . '</p>';
        }
        $html .= '<p class="sspa-impact-summary-actions"><a class="button button-primary sspa-impact-summary-view" href="' . esc_url($view) . '">'
            . esc_html__('View on the Plugins tab', 'super-speedy-performance-analysis') . '</a> '
            . '<button type="button" class="button sspa-impact-summary-close">' . esc_html__('Close', 'super-speedy-performance-analysis') . '</button></p>';
        $html .= '</div>';
        return $html;
    }

    /**
     * The latest Plugin Impact Analysis this user has not yet seen the summary of.
     *
     * @return array|null { run_id, html } or null.
     */
    public static function unseen_summary($user_id) {
        $run = SSPA_Report::latest_deep_run();
        if (!$run || (int) $run['id'] <= (int) get_user_meta($user_id, 'sspa_impact_summary_seen', true)) {
            return null;
        }
        if (empty($run['finished']) || strtotime($run['finished'] . ' UTC') < time() - self::SUMMARY_REOPEN_DAYS * DAY_IN_SECONDS) {
            return null;
        }
        $html = self::run_summary($run);
        return '' === $html ? null : array('run_id' => (int) $run['id'], 'html' => $html);
    }

    /** AJAX: the summary of a finished run (run_id), or of the latest unseen one. */
    public static function ajax_summary() {
        check_ajax_referer('sspa_admin', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error(__('Permission denied.', 'super-speedy-performance-analysis'), 403); }
        $run_id = isset($_POST['run_id']) ? (int) $_POST['run_id'] : 0;
        if (!$run_id) {
            wp_send_json_success(self::unseen_summary(get_current_user_id()));
        }
        $run = SSPA_Report::latest_deep_run();
        // Only the latest Plugin Impact Analysis has a summary: an older one's figures may have
        // been superseded, and that is what the Plugins tab is for.
        if (!$run || (int) $run['id'] !== $run_id) {
            wp_send_json_success(null);
        }
        $html = self::run_summary($run);
        wp_send_json_success('' === $html ? null : array('run_id' => $run_id, 'html' => $html));
    }

    /** AJAX: record that this user has seen the summary of run_id. */
    public static function ajax_summary_seen() {
        check_ajax_referer('sspa_admin', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error(__('Permission denied.', 'super-speedy-performance-analysis'), 403); }
        $run_id = isset($_POST['run_id']) ? (int) $_POST['run_id'] : 0;
        if ($run_id > (int) get_user_meta(get_current_user_id(), 'sspa_impact_summary_seen', true)) {
            update_user_meta(get_current_user_id(), 'sspa_impact_summary_seen', $run_id);
        }
        wp_send_json_success();
    }

    /**
     * "measured together with X" - the verdict covers a group, not one plugin.
     *
     * A plugin that switches itself off without its dependency is excluded in the same cell as
     * that dependency, so the delta is the cost of having both. Reading it as the cost of one
     * would overstate it, and hide that removing one means removing the other.
     */
    private static function group_note($mode_rows) {
        $members = array();
        foreach ($mode_rows as $row) {
            if (!empty($row['group_members'])) {
                foreach (explode(',', (string) $row['group_members']) as $member) {
                    $member = trim($member);
                    if ('' !== $member) {
                        $members[$member] = true;
                    }
                }
            }
        }
        if (!$members) {
            return '';
        }
        return '<br><small class="sspa-impact-detail">' . esc_html(sprintf(
            /* translators: %s: comma-separated plugin slugs */
            __('measured together with %s, which cannot run without it', 'super-speedy-performance-analysis'),
            implode(', ', array_keys($members))
        )) . '</small>';
    }

    /**
     * When the verdict was measured, and whether it can still be trusted.
     *
     * Two independent staleness signals, because they catch different things and only one of
     * them works on rows recorded before 0.12.1:
     *  - the version differs from the one the attribution run measured (exact, needs a version);
     *  - the sweep predates the attribution run (always available, catches historical rows).
     */
    /**
     * Whether a verdict needs re-measuring: the same two signals provenance() prints, as a
     * flag for the "Needs measuring" filter.
     */
    private static function is_stale($mode_rows, $version, $run_id) {
        $measured_version = null;
        $test_run_id = 0;
        foreach ($mode_rows as $row) {
            if (null === $measured_version && !empty($row['plugin_version'])) {
                $measured_version = $row['plugin_version'];
            }
            $test_run_id = max($test_run_id, (int) $row['test_run_id']);
        }
        return ($measured_version && $version && $measured_version !== $version)
            || ($test_run_id > 0 && $test_run_id < (int) $run_id);
    }

    private static function provenance($mode_rows, $version, $run_id) {
        $measured_version = null;
        $created = null;
        $test_run_id = 0;
        foreach ($mode_rows as $row) {
            if (null === $created && !empty($row['created'])) {
                $created = $row['created'];
            }
            if (null === $measured_version && !empty($row['plugin_version'])) {
                $measured_version = $row['plugin_version'];
            }
            $test_run_id = max($test_run_id, (int) $row['test_run_id']);
        }
        if (!$created) {
            return '';
        }

        $when = mysql2date(get_option('date_format'), $created);
        $stale_version = ($measured_version && $version && $measured_version !== $version);
        $stale_run = ($test_run_id > 0 && $test_run_id < (int) $run_id);

        if ($stale_version) {
            return '<br><small class="sspa-impact-stale">' . esc_html(sprintf(
                /* translators: 1: version measured, 2: version now, 3: date */
                __('measured on version %1$s, you now run %2$s (%3$s) - re-measure to trust this', 'super-speedy-performance-analysis'),
                $measured_version,
                $version,
                $when
            )) . '</small>';
        }
        if ($stale_run) {
            return '<br><small class="sspa-impact-stale">' . esc_html(sprintf(
                /* translators: %s: date Plugin Impact Analysis ran */
                __('measured %s, before the analysis above - re-measure to trust this', 'super-speedy-performance-analysis'),
                $when
            )) . '</small>';
        }
        return '<br><small class="sspa-impact-detail">' . esc_html(sprintf(
            /* translators: 1: date, 2: version or empty */
            $measured_version
                /* translators: 1: date measured, 2: plugin version measured */
                ? __('measured %1$s on version %2$s', 'super-speedy-performance-analysis')
                /* translators: %1$s: date measured */
                : __('measured %1$s', 'super-speedy-performance-analysis'),
            $when,
            (string) $measured_version
        )) . '</small>';
    }
}
