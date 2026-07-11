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
    ?>
    <p class="description">
        <?php esc_html_e('Inferred from query attribution across the last analysis (totals over all profiled pages). Measured per-plugin impact via isolation testing arrives with Deep Analysis in an upcoming release.', 'super-speedy-performance-analysis'); ?>
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
                <th><?php esc_html_e('Confidence', 'super-speedy-performance-analysis'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($sspa_components as $c) : ?>
            <tr>
                <td><code><?php echo esc_html($c['component']); ?></code></td>
                <td><?php echo esc_html($c['component_type']); ?></td>
                <td><?php echo esc_html(number_format((float) $c['total_sql_ms'], 1)); ?></td>
                <td><?php echo esc_html(number_format((int) $c['total_queries'])); ?></td>
                <td><?php echo esc_html(number_format((int) $c['total_rows'])); ?></td>
                <td><?php echo esc_html(number_format((float) $c['slowest_query_ms'], 1)); ?></td>
                <td><?php echo esc_html(number_format((float) $c['total_http_ms'], 1)); ?></td>
                <td><span class="sspa-badge"><?php esc_html_e('inferred', 'super-speedy-performance-analysis'); ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
