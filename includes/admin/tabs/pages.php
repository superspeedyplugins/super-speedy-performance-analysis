<?php
defined('ABSPATH') || exit;

global $wpdb;
$sspa_runs_table = SSPA_Schema::table('runs');
$sspa_last_run_id = (int) $wpdb->get_var("SELECT id FROM $sspa_runs_table WHERE status = 'done' ORDER BY id DESC LIMIT 1");

if (!$sspa_last_run_id) : ?>
    <div class="sspa-placeholder">
        <p><?php esc_html_e('Per-page profiles (TTFB, SQL time, PHP time, RAM, query counts) will appear here after your first analysis.', 'super-speedy-performance-analysis'); ?></p>
    </div>
<?php else :
    $sspa_profiles = $wpdb->get_results($wpdb->prepare(
        'SELECT * FROM ' . SSPA_Schema::table('profiles') . ' WHERE run_id = %d ORDER BY page_gen_ms DESC',
        $sspa_last_run_id
    ), ARRAY_A);
    ?>
    <table class="widefat striped sspa-pages-table">
        <thead>
            <tr>
                <th><?php esc_html_e('Page', 'super-speedy-performance-analysis'); ?></th>
                <th><?php esc_html_e('Variant', 'super-speedy-performance-analysis'); ?></th>
                <th><?php esc_html_e('Generation (ms)', 'super-speedy-performance-analysis'); ?></th>
                <th><?php esc_html_e('SQL (ms)', 'super-speedy-performance-analysis'); ?></th>
                <th><?php esc_html_e('Queries', 'super-speedy-performance-analysis'); ?></th>
                <th><?php esc_html_e('Rows fetched', 'super-speedy-performance-analysis'); ?></th>
                <th><?php esc_html_e('HTTP (ms)', 'super-speedy-performance-analysis'); ?></th>
                <th><?php esc_html_e('PHP (ms)', 'super-speedy-performance-analysis'); ?></th>
                <th><?php esc_html_e('Peak RAM', 'super-speedy-performance-analysis'); ?></th>
                <th><?php esc_html_e('Status', 'super-speedy-performance-analysis'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($sspa_profiles as $p) : ?>
            <tr class="sspa-page-row" data-profile-id="<?php echo (int) $p['id']; ?>" title="<?php esc_attr_e('Click for per-plugin breakdown and worst queries', 'super-speedy-performance-analysis'); ?>">
                <td><code><?php echo esc_html($p['page_key']); ?></code></td>
                <td><?php echo esc_html($p['variant']); ?></td>
                <td><?php echo $p['page_gen_ms'] !== null ? esc_html(number_format((float) $p['page_gen_ms'], 1)) : '-'; ?></td>
                <td><?php echo $p['sql_ms'] !== null ? esc_html(number_format((float) $p['sql_ms'], 1)) : '-'; ?></td>
                <td><?php echo $p['sql_count'] !== null ? (int) $p['sql_count'] : '-'; ?></td>
                <td><?php echo $p['rows_returned_total'] !== null ? esc_html(number_format((int) $p['rows_returned_total'])) : '-'; ?></td>
                <td><?php echo $p['http_ms'] !== null ? esc_html(number_format((float) $p['http_ms'], 1)) : '-'; ?></td>
                <td><?php echo $p['php_ms'] !== null ? esc_html(number_format((float) $p['php_ms'], 1)) : '-'; ?></td>
                <td><?php echo $p['peak_mem_bytes'] ? esc_html(size_format((int) $p['peak_mem_bytes'])) : '-'; ?></td>
                <td>
                    <?php
                    if ($p['blocked_by']) {
                        echo '<span class="sspa-blocked">' . esc_html(sprintf(__('blocked by %s', 'super-speedy-performance-analysis'), $p['blocked_by'])) . '</span>';
                    } else {
                        echo (int) $p['response_code'];
                    }
                    ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
