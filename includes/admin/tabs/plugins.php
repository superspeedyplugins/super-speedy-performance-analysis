<?php
defined('ABSPATH') || exit;

global $wpdb;
// Attribution comes from profiling runs only - deep/cache runs store partial or
// plugin-set-modified profiles that would corrupt the totals here.
$sspa_last_run_id = (int) $wpdb->get_var('SELECT id FROM ' . SSPA_Schema::table('runs') . " WHERE status = 'done' AND run_type IN ('baseline','spot') ORDER BY id DESC LIMIT 1");

if (!$sspa_last_run_id) : ?>
    <div class="sspa-placeholder">
        <p><?php esc_html_e('Per-plugin costs will appear here after your first analysis.', 'super-speedy-performance-analysis'); ?></p>
    </div>
<?php else :
    // Worst single page per component + totals across the run.
    $sspa_components = $wpdb->get_results($wpdb->prepare(
        'SELECT cs.component, cs.component_type,
                SUM(cs.sql_ms) total_sql_ms, SUM(cs.query_count) total_queries,
                SUM(cs.rows_returned) total_rows, SUM(cs.http_ms) total_http_ms,
                MAX(cs.slowest_query_ms) slowest_query_ms,
                MAX(cs.sql_ms) worst_page_sql_ms
         FROM ' . SSPA_Schema::table('component_stats') . ' cs
         WHERE cs.run_id = %d
         GROUP BY cs.component, cs.component_type
         ORDER BY total_sql_ms DESC',
        $sspa_last_run_id
    ), ARRAY_A);

    // Latest cache-impact results (component => saved_pct), if a cache run has been done.
    $sspa_cache_notes = $wpdb->get_var(
        'SELECT notes FROM ' . SSPA_Schema::table('runs') . " WHERE run_type = 'cache_impact' AND status = 'done' ORDER BY id DESC LIMIT 1"
    );
    $sspa_cache = array();
    if ($sspa_cache_notes) {
        $sspa_decoded = json_decode($sspa_cache_notes, true);
        if (is_array($sspa_decoded) && !empty($sspa_decoded['components'])) {
            $sspa_cache = $sspa_decoded['components'];
        }
    }

    // Measured impacts per plugin from each plugin's most recent sweep: one row per
    // page per cache mode.
    $sspa_impacts = array();
    foreach ($wpdb->get_results(
        'SELECT pi.* FROM ' . SSPA_Schema::table('plugin_impacts') . ' pi
         JOIN (SELECT plugin, MAX(test_run_id) tr FROM ' . SSPA_Schema::table('plugin_impacts') . ' GROUP BY plugin) latest
           ON latest.plugin = pi.plugin AND latest.tr = pi.test_run_id
         ORDER BY pi.page_key, pi.id',
        ARRAY_A
    ) as $sspa_row) {
        $sspa_impacts[$sspa_row['plugin']][] = $sspa_row;
    }
    ?>
    <p class="description">
        <?php esc_html_e('Attribution totals come from the last analysis (all profiled pages). Measured impact comes from Deep Analysis: every page re-measured with the plugin virtually disabled, in every cache mode. "adds" means the plugin costs you that much page-generation time; "saves" means pages got SLOWER without it - the plugin is speeding your site up. Click "per-page breakdown" for the full picture.', 'super-speedy-performance-analysis'); ?>
    </p>
    <p class="description">
        <?php esc_html_e('Careful with the SQL/query columns: they credit work to whichever component runs it. A plugin that REPLACES a slow feature (search, filtering) will show the queries it runs even when it is far faster than what it replaced - the measured impact is the true verdict on whether it costs or saves you time.', 'super-speedy-performance-analysis'); ?>
    </p>
    <table class="widefat striped sspa-pages-table">
        <thead>
            <tr>
                <th><?php esc_html_e('Component', 'super-speedy-performance-analysis'); ?></th>
                <th><?php esc_html_e('Type', 'super-speedy-performance-analysis'); ?></th>
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
        <?php foreach ($sspa_components as $c) :
            $impact_rows = isset($sspa_impacts[$c['component']]) ? $sspa_impacts[$c['component']] : null; ?>
            <tr>
                <td><code><?php echo esc_html($c['component']); ?></code></td>
                <td><?php echo esc_html($c['component_type']); ?></td>
                <td><?php echo esc_html(number_format((float) $c['total_sql_ms'], 1)); ?></td>
                <td><?php echo esc_html(number_format((int) $c['total_queries'])); ?></td>
                <td><?php echo esc_html(number_format((int) $c['total_rows'])); ?></td>
                <td><?php echo esc_html(number_format((float) $c['slowest_query_ms'], 1)); ?></td>
                <td><?php echo esc_html(number_format((float) $c['total_http_ms'], 1)); ?></td>
                <td>
                    <?php
                    if ($impact_rows) {
                        // Steady-state mode first: normal (cache as-is, warmed) is the
                        // headline; 'warm' appears only in pre-0.9.1 data.
                        $sspa_modes_present = array_values(array_unique(array_map(function ($r) {
                            return $r['object_cache_mode'];
                        }, $impact_rows)));
                        $sspa_pref = in_array('normal', $sspa_modes_present, true) ? 'normal'
                            : (in_array('warm', $sspa_modes_present, true) ? 'warm' : $sspa_modes_present[0]);
                        $sspa_mode_rows = array_values(array_filter($impact_rows, function ($r) use ($sspa_pref) {
                            return $r['object_cache_mode'] === $sspa_pref;
                        }));
                        $sspa_measured = array_values(array_filter($sspa_mode_rows, function ($r) {
                            return 'measured' === $r['confidence'];
                        }));

                        if ($sspa_measured) {
                            $sspa_net = 0.0;
                            $sspa_worst = $sspa_measured[0];
                            foreach ($sspa_measured as $r) {
                                $sspa_net += (float) $r['delta_ttfb_ms'];
                                if (abs((float) $r['delta_ttfb_ms']) > abs((float) $sspa_worst['delta_ttfb_ms'])) {
                                    $sspa_worst = $r;
                                }
                            }
                            $sspa_saves = $sspa_net < 0;
                            echo '<strong class="' . ($sspa_saves ? 'sspa-impact-saves' : 'sspa-impact-adds') . '">';
                            echo esc_html($sspa_saves
                                ? sprintf(__('saves %sms', 'super-speedy-performance-analysis'), number_format(abs($sspa_net)))
                                : sprintf(__('adds %sms', 'super-speedy-performance-analysis'), number_format($sspa_net)));
                            echo '</strong> ';
                            printf(
                                esc_html__('across %1$d of %2$d pages', 'super-speedy-performance-analysis'),
                                count($sspa_measured),
                                count($sspa_mode_rows)
                            );
                            echo ' <span class="sspa-badge sspa-badge-measured">' . esc_html('warm' === $sspa_pref ? __('measured, warm cache', 'super-speedy-performance-analysis') : __('measured', 'super-speedy-performance-analysis')) . '</span><br>';
                            $sspa_worst_delta = (float) $sspa_worst['delta_ttfb_ms'];
                            echo '<small class="sspa-impact-detail">';
                            echo esc_html(sprintf(
                                $sspa_worst_delta < 0
                                    ? __('biggest saving: %1$sms on %2$s', 'super-speedy-performance-analysis')
                                    : __('biggest cost: %1$sms on %2$s', 'super-speedy-performance-analysis'),
                                number_format(abs($sspa_worst_delta)),
                                $sspa_worst['page_key']
                            ));
                            echo '</small>';
                        } else {
                            $sspa_floors = array_map(function ($r) {
                                return (float) $r['noise_floor_ms'];
                            }, $sspa_mode_rows);
                            echo '<span class="sspa-badge">' . esc_html(sprintf(
                                __('no measurable impact on %1$d pages (±%2$sms noise)', 'super-speedy-performance-analysis'),
                                count($sspa_mode_rows),
                                number_format($sspa_floors ? max($sspa_floors) : 30)
                            )) . '</span>';
                        }
                        echo '<br><a href="#" class="sspa-impact-details" data-plugin="' . esc_attr($c['component']) . '">' . esc_html__('per-page breakdown', 'super-speedy-performance-analysis') . '</a>';
                    } else {
                        echo '<span class="sspa-badge">' . esc_html__('inferred - run Deep Analysis to measure', 'super-speedy-performance-analysis') . '</span>';
                    }
                    ?>
                </td>
                <td>
                    <?php
                    if (isset($sspa_cache[$c['component']]['saved_pct'])) {
                        $pct = (int) $sspa_cache[$c['component']]['saved_pct'];
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
<?php endif; ?>
