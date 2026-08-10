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
        return (int) $wpdb->get_var(
            'SELECT id FROM ' . SSPA_Schema::table('runs') . "
             WHERE status = 'done' AND run_type IN ('baseline','spot') ORDER BY id DESC LIMIT 1"
        );
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
     * MAX(id) rather than MAX(created): two sweeps in the same second are possible, and the
     * autoincrement is the only strict ordering.
     *
     * @param string $plugin Optional single plugin to restrict to.
     */
    public static function latest_impacts_sql($plugin = '') {
        global $wpdb;
        $table = SSPA_Schema::table('plugin_impacts');
        $where = '';
        if ('' !== $plugin) {
            $where = $wpdb->prepare(' WHERE plugin = %s', $plugin);
        }
        return "SELECT pi.* FROM $table pi
                JOIN (SELECT MAX(id) mid FROM $table$where
                      GROUP BY plugin, page_key, object_cache_mode) latest ON latest.mid = pi.id
                ORDER BY pi.page_key, pi.id";
    }

    public static function render($run_id, $mode) {
        global $wpdb;

        $run_id = (int) $run_id;
        $mode = SSPA_Attribution::sanitise_mode($mode);

        // Worst single page per component + totals across the run. Grouped in PHP rather than
        // SQL because caller mode is recomputed from the capture blobs, not stored in a table.
        $grouped = array();
        foreach (SSPA_Attribution::component_rows($run_id, $mode) as $row) {
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
        usort($grouped, function ($a, $b) {
            return $b['total_sql_ms'] <=> $a['total_sql_ms'];
        });

        // Latest cache-impact results (component => saved_pct), if a cache run has been done.
        $cache_notes = $wpdb->get_var(
            'SELECT notes FROM ' . SSPA_Schema::table('runs') . " WHERE run_type = 'cache_impact' AND status = 'done' ORDER BY id DESC LIMIT 1"
        );
        $cache = array();
        if ($cache_notes) {
            $decoded = json_decode($cache_notes, true);
            if (is_array($decoded) && !empty($decoded['components'])) {
                $cache = $decoded['components'];
            }
        }

        $impacts = array();
        foreach ($wpdb->get_results(self::latest_impacts_sql(), ARRAY_A) as $row) {
            $impacts[$row['plugin']][] = $row;
        }

        ob_start();
        ?>
        <table class="widefat striped sspa-pages-table" id="sspa-attrib-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Component', 'super-speedy-performance-analysis'); ?></th>
                    <th><?php esc_html_e('Type', 'super-speedy-performance-analysis'); ?></th>
                    <th><?php esc_html_e('Version', 'super-speedy-performance-analysis'); ?></th>
                    <th><?php esc_html_e('SQL total (ms)', 'super-speedy-performance-analysis'); ?></th>
                    <th><?php esc_html_e('Queries', 'super-speedy-performance-analysis'); ?></th>
                    <th><?php esc_html_e('Rows fetched', 'super-speedy-performance-analysis'); ?></th>
                    <th><?php esc_html_e('Slowest query (ms)', 'super-speedy-performance-analysis'); ?></th>
                    <th><?php esc_html_e('HTTP (ms)', 'super-speedy-performance-analysis'); ?></th>
                    <th><?php esc_html_e('Measured impact', 'super-speedy-performance-analysis'); ?></th>
                    <th><?php esc_html_e('Object cache', 'super-speedy-performance-analysis'); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($grouped as $c) :
                $impact_rows = isset($impacts[$c['component']]) ? $impacts[$c['component']] : null;
                // The version as measured by the run these attribution totals came from, not
                // the version installed right now - the two differ as soon as a plugin updates.
                $version = SSPA_Run_Controller::component_version($run_id, $c['component'], $c['component_type']); ?>
                <tr>
                    <td><code><?php echo esc_html($c['component']); ?></code></td>
                    <td><?php echo esc_html($c['component_type']); ?></td>
                    <td><?php echo $version ? esc_html($version) : '-'; ?></td>
                    <td><?php echo esc_html(number_format((float) $c['total_sql_ms'], 1)); ?></td>
                    <td><?php echo esc_html(number_format((int) $c['total_queries'])); ?></td>
                    <td><?php echo esc_html(number_format((int) $c['total_rows'])); ?></td>
                    <td><?php echo esc_html(number_format((float) $c['slowest_query_ms'], 1)); ?></td>
                    <td><?php echo esc_html(number_format((float) $c['total_http_ms'], 1)); ?></td>
                    <td><?php echo self::impact_cell($c, $impact_rows, $version, $run_id); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                    <td>
                        <?php
                        if (isset($cache[$c['component']]['saved_pct'])) {
                            $pct = (int) $cache[$c['component']]['saved_pct'];
                            if ($pct < 15) {
                                echo '<span class="sspa-badge sspa-blocked">' . esc_html(sprintf(__('cache-blind (%d%%)', 'super-speedy-performance-analysis'), $pct)) . '</span>';
                            } else {
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
        <?php
        return ob_get_clean();
    }

    /**
     * The Measured impact cell.
     *
     * Deep-analysis verdicts come from the plugin's own most recent sweep, which can be weeks
     * older than the attribution run beside it. A stale verdict reads as current unless the
     * cell says when it was taken, so the date is not optional decoration: a plugin fixed and
     * updated since the sweep keeps showing its old cost with nothing to indicate that.
     */
    private static function impact_cell($c, $impact_rows, $version, $run_id) {
        if (!$impact_rows) {
            return '<span class="sspa-badge">' . esc_html__('inferred - run Plugin Impact Analysis to measure', 'super-speedy-performance-analysis') . '</span>';
        }

        ob_start();

        // Steady-state mode first: normal (cache as-is, warmed) is the headline; 'warm'
        // appears only in pre-0.9.1 data.
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

        if ($measured) {
            // Typical and worst, never the sum. Adding the per-page deltas together produced a
            // number nobody experiences - no visitor loads every page type at once - and it grew
            // simply by measuring more pages, so a wider sweep made a plugin look worse.
            // Median rather than mean: deltas are skewed, and one pathological page should not
            // define "typical".
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

            $saves = $typical < 0;
            echo '<strong class="' . ($saves ? 'sspa-impact-saves' : 'sspa-impact-adds') . '">';
            echo esc_html($saves
                ? sprintf(__('saves %sms typically', 'super-speedy-performance-analysis'), number_format(abs($typical)))
                : sprintf(__('adds %sms typically', 'super-speedy-performance-analysis'), number_format($typical)));
            echo '</strong> ';
            $worst_delta = (float) $worst['delta_ttfb_ms'];
            echo esc_html(sprintf(
                $worst_delta < 0
                    ? __('up to %1$sms saved on %2$s', 'super-speedy-performance-analysis')
                    : __('up to %1$sms on %2$s', 'super-speedy-performance-analysis'),
                number_format(abs($worst_delta)),
                $worst['page_key']
            ));
            echo ' <span class="sspa-badge sspa-badge-measured">' . esc_html('warm' === $pref ? __('measured, warm cache', 'super-speedy-performance-analysis') : __('measured', 'super-speedy-performance-analysis')) . '</span><br>';
            echo '<small class="sspa-impact-detail">';
            echo esc_html(sprintf(
                /* translators: 1: pages with a measurable effect, 2: pages measured */
                __('measurable on %1$d of %2$d pages measured', 'super-speedy-performance-analysis'),
                count($measured),
                count($mode_rows)
            ));
            echo '</small>';
        } else {
            $floors = array_map(function ($r) {
                return (float) $r['noise_floor_ms'];
            }, $mode_rows);
            echo '<span class="sspa-badge">' . esc_html(sprintf(
                __('no measurable impact on %1$d pages (±%2$sms noise)', 'super-speedy-performance-analysis'),
                count($mode_rows),
                number_format($floors ? max($floors) : 30)
            )) . '</span>';
        }

        echo self::provenance($mode_rows, $version, $run_id); // phpcs:ignore WordPress.Security.EscapeOutput
        echo '<br><a href="#" class="sspa-impact-details" data-plugin="' . esc_attr($c['component']) . '">' . esc_html__('per-page breakdown', 'super-speedy-performance-analysis') . '</a>';

        return ob_get_clean();
    }

    /**
     * When the verdict was measured, and whether it can still be trusted.
     *
     * Two independent staleness signals, because they catch different things and only one of
     * them works on rows recorded before 0.12.1:
     *  - the version differs from the one the attribution run measured (exact, needs a version);
     *  - the sweep predates the attribution run (always available, catches historical rows).
     */
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
                /* translators: %s: date the deep analysis ran */
                __('measured %s, before the analysis above - re-measure to trust this', 'super-speedy-performance-analysis'),
                $when
            )) . '</small>';
        }
        return '<br><small class="sspa-impact-detail">' . esc_html(sprintf(
            /* translators: 1: date, 2: version or empty */
            $measured_version
                ? __('measured %1$s on version %2$s', 'super-speedy-performance-analysis')
                : __('measured %1$s', 'super-speedy-performance-analysis'),
            $when,
            (string) $measured_version
        )) . '</small>';
    }
}
