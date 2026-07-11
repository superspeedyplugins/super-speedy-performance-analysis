<?php
defined('ABSPATH') || exit;

global $wpdb;
$sspa_last_run_id = (int) $wpdb->get_var('SELECT id FROM ' . SSPA_Schema::table('runs') . " WHERE status = 'done' ORDER BY id DESC LIMIT 1");

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

    // Latest measured impact per plugin (from deep-analysis isolation runs).
    $sspa_impacts = array();
    foreach ($wpdb->get_results(
        'SELECT pi.* FROM ' . SSPA_Schema::table('plugin_impacts') . ' pi
         JOIN (SELECT plugin, MAX(id) max_id FROM ' . SSPA_Schema::table('plugin_impacts') . ' GROUP BY plugin) latest
           ON latest.max_id = pi.id',
        ARRAY_A
    ) as $sspa_row) {
        $sspa_impacts[$sspa_row['plugin']] = $sspa_row;
    }
    ?>
    <p class="description">
        <?php esc_html_e('Attribution totals come from the last analysis (all profiled pages). Measured impact comes from Deep Analysis isolation testing: the page-generation time this plugin adds to its worst page, proven by re-measuring with the plugin virtually disabled.', 'super-speedy-performance-analysis'); ?>
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
            $impact = isset($sspa_impacts[$c['component']]) ? $sspa_impacts[$c['component']] : null; ?>
            <tr>
                <td><code><?php echo esc_html($c['component']); ?></code></td>
                <td><?php echo esc_html($c['component_type']); ?></td>
                <td><?php echo esc_html(number_format((float) $c['total_sql_ms'], 1)); ?></td>
                <td><?php echo esc_html(number_format((int) $c['total_queries'])); ?></td>
                <td><?php echo esc_html(number_format((int) $c['total_rows'])); ?></td>
                <td><?php echo esc_html(number_format((float) $c['slowest_query_ms'], 1)); ?></td>
                <td><?php echo esc_html(number_format((float) $c['total_http_ms'], 1)); ?></td>
                <td>
                    <?php if ($impact && 'measured' === $impact['confidence']) : ?>
                        <strong><?php echo esc_html('+' . number_format((float) $impact['delta_ttfb_ms']) . 'ms'); ?></strong>
                        <?php printf(esc_html__('on %s', 'super-speedy-performance-analysis'), esc_html($impact['page_key'])); ?>
                        <span class="sspa-badge sspa-badge-measured"><?php esc_html_e('measured', 'super-speedy-performance-analysis'); ?></span>
                    <?php elseif ($impact && 'none' === $impact['confidence']) : ?>
                        <span class="sspa-badge"><?php esc_html_e('no measurable impact', 'super-speedy-performance-analysis'); ?></span>
                    <?php else : ?>
                        <span class="sspa-badge"><?php esc_html_e('inferred', 'super-speedy-performance-analysis'); ?></span>
                    <?php endif; ?>
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
