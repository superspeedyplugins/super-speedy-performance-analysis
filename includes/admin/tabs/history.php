<?php
defined('ABSPATH') || exit;

global $wpdb;
$sspa_runs = $wpdb->get_results('SELECT * FROM ' . SSPA_Schema::table('runs') . ' ORDER BY id DESC LIMIT 50', ARRAY_A);

if (!$sspa_runs) : ?>
    <div class="sspa-placeholder">
        <p><?php esc_html_e('Trends across analysis runs will appear here, so you can see whether your site is getting slower as it grows.', 'super-speedy-performance-analysis'); ?></p>
    </div>
<?php else : ?>
    <table class="widefat striped sspa-pages-table">
        <thead>
            <tr>
                <th><?php esc_html_e('Run', 'super-speedy-performance-analysis'); ?></th>
                <th><?php esc_html_e('Type', 'super-speedy-performance-analysis'); ?></th>
                <th><?php esc_html_e('Started', 'super-speedy-performance-analysis'); ?></th>
                <th><?php esc_html_e('Status', 'super-speedy-performance-analysis'); ?></th>
                <th><?php esc_html_e('Pages', 'super-speedy-performance-analysis'); ?></th>
                <th><?php esc_html_e('Median generation (ms)', 'super-speedy-performance-analysis'); ?></th>
                <th><?php esc_html_e('Findings', 'super-speedy-performance-analysis'); ?></th>
                <th><?php esc_html_e('Score', 'super-speedy-performance-analysis'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($sspa_runs as $run) :
            $notes = json_decode((string) $run['notes'], true);
            $pages = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . SSPA_Schema::table('profiles') . ' WHERE run_id = %d', $run['id']));
            $median_gen = $wpdb->get_var($wpdb->prepare(
                'SELECT AVG(page_gen_ms) FROM ' . SSPA_Schema::table('profiles') . " WHERE run_id = %d AND page_gen_ms IS NOT NULL AND page_key != 'baseline'",
                $run['id']
            ));
            ?>
            <tr>
                <td>#<?php echo (int) $run['id']; ?></td>
                <td><?php echo esc_html($run['run_type']); ?></td>
                <td><?php echo esc_html($run['started']); ?></td>
                <td><?php echo esc_html($run['status']); ?></td>
                <td><?php echo $pages; ?></td>
                <td><?php echo $median_gen !== null ? esc_html(number_format((float) $median_gen, 1)) : '-'; ?></td>
                <td><?php echo is_array($notes) && isset($notes['findings']) ? (int) $notes['findings'] : '-'; ?></td>
                <td><?php echo is_array($notes) && isset($notes['score']) ? (int) $notes['score'] : '-'; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
