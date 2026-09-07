<?php
defined('ABSPATH') || exit;

/** Read-only view of one explicitly selected retained run. */
class SSPA_History_Run_View {
    public static function url($run_id = 0) {
        $args = array('page' => 'sspa');
        if ($run_id > 0) {
            $args['sspa_history_run'] = (int) $run_id;
        }
        return add_query_arg($args, admin_url('admin.php')) . '#history';
    }

    public static function ajax_run() {
        check_ajax_referer('sspa_admin', 'nonce');
        $id = isset($_POST['run_id']) ? absint($_POST['run_id']) : 0;
        $html = self::render($id);
        if (is_wp_error($html)) {
            wp_send_json_error($html->get_error_message(), 'sspa_forbidden' === $html->get_error_code() ? 403 : 404);
        }
        wp_send_json_success(array('run_id' => $id, 'html' => $html));
    }

    public static function render($run_id) {
        if (!current_user_can('manage_options')) {
            return new WP_Error('sspa_forbidden', __('You cannot view saved analysis reports.', 'super-speedy-performance-analysis'));
        }
        $run_id = (int) $run_id;
        $run = $run_id > 0 ? SSPA_Run_Controller::run_row($run_id) : null;
        if (!$run) {
            return new WP_Error('sspa_missing_run', __('This saved run is unavailable. It may have been deleted.', 'super-speedy-performance-analysis'));
        }
        $profiles = SSPA_History_Series::profile_rows($run_id);
        $report = SSPA_Report::build($run_id);
        if (is_wp_error($report)) {
            return $report;
        }
        // build() also exposes current site demographics and cross-run impacts. Neither
        // belongs to this saved snapshot; only its run-scoped pages/findings are used.
        $components = SSPA_Run_Controller::decode_component_versions($run['plugin_set']);
        $notes = json_decode((string) $run['notes'], true);
        ob_start();
        ?>
        <section class="sspa-history-saved-report" data-saved-run-id="<?php echo esc_attr($run_id); ?>">
            <p><a class="button sspa-history-back" href="<?php echo esc_url(self::url()); ?>"><?php esc_html_e('Back to History', 'super-speedy-performance-analysis'); ?></a></p>
            <h2 tabindex="-1"><?php
                /* translators: %d: saved analysis run ID. */
                echo esc_html(sprintf(__('Saved analysis #%d', 'super-speedy-performance-analysis'), $run_id));
            ?></h2>
            <p><?php echo esc_html($run['started']); ?> · <?php echo esc_html($run['run_type']); ?> · <?php echo esc_html($run['status']); ?></p>
            <p><?php esc_html_e('Recorded score:', 'super-speedy-performance-analysis'); ?> <?php echo null === $report['score'] ? esc_html__('not calculated', 'super-speedy-performance-analysis') : esc_html((int) $report['score'] . '/100'); ?></p>
            <?php if (!empty($run['finished'])) : ?><p><?php esc_html_e('Finished:', 'super-speedy-performance-analysis'); ?> <?php echo esc_html($run['finished']); ?></p><?php endif; ?>
            <p class="description"><?php esc_html_e('This report reads the saved analysis. Opening a page shows its retained profile without taking a new measurement.', 'super-speedy-performance-analysis'); ?></p>
            <h3><?php esc_html_e('Recorded components', 'super-speedy-performance-analysis'); ?></h3>
            <?php if ($components) : ?>
                <ul><?php foreach ($components as $key => $version) : ?>
                    <li><code><?php echo esc_html($key); ?></code> <?php echo esc_html($version ? $version : __('version unknown', 'super-speedy-performance-analysis')); ?></li>
                <?php endforeach; ?></ul>
            <?php else : ?><p><?php esc_html_e('Component versions were not recorded for this run.', 'super-speedy-performance-analysis'); ?></p><?php endif; ?>
            <h3><?php esc_html_e('Measured pages', 'super-speedy-performance-analysis'); ?></h3>
            <p class="description"><?php esc_html_e('These profiles use the full recorded plugin set. Diagnostics describe the retained profile; findings below may aggregate multiple requests.', 'super-speedy-performance-analysis'); ?></p>
            <?php if ($profiles) : ?>
                <div class="sspa-table-scroll"><table class="widefat striped sspa-history-saved-pages">
                    <thead><tr><th><?php esc_html_e('Page', 'super-speedy-performance-analysis'); ?></th><th><?php esc_html_e('Variant / cache', 'super-speedy-performance-analysis'); ?></th><th><?php esc_html_e('Generation (ms)', 'super-speedy-performance-analysis'); ?></th><th><?php esc_html_e('HTTP status / blocker', 'super-speedy-performance-analysis'); ?></th><th><?php esc_html_e('Diagnostics', 'super-speedy-performance-analysis'); ?></th></tr></thead>
                    <tbody><?php foreach ($profiles as $profile) : ?>
                        <tr><td><?php echo esc_html($profile['method'] . ' ' . $profile['page_key']); ?></td><td><?php echo esc_html($profile['variant'] . ' / ' . $profile['object_cache_mode']); ?></td><td><?php echo esc_html(self::measurement($profile['page_gen_ms'])); ?></td><td><?php echo esc_html(($profile['response_code'] ? $profile['response_code'] : __('not recorded', 'super-speedy-performance-analysis')) . ($profile['blocked_by'] ? ' / ' . $profile['blocked_by'] : '')); ?></td><td><button type="button" class="button sspa-history-profile" data-profile-id="<?php echo esc_attr((int) $profile['id']); ?>"><?php
                            /* translators: %d: retained page profile ID. */
                            echo esc_html(sprintf(__('Open profile #%d', 'super-speedy-performance-analysis'), (int) $profile['id']));
                        ?></button></td></tr>
                    <?php endforeach; ?></tbody>
                </table></div>
            <?php else : ?><p><?php esc_html_e('No full-plugin-set page profiles are retained for this run.', 'super-speedy-performance-analysis'); ?></p><?php endif; ?>
            <p class="sspa-history-profile-status" role="status"></p>
            <?php if (count($report['pages']) > count($profiles)) : ?>
                <details><summary><?php esc_html_e('All recorded measurement summaries, including plugin exclusions', 'super-speedy-performance-analysis'); ?></summary>
                    <p><?php esc_html_e('The saved report includes exclusion measurements beyond the full-plugin-set profiles above. Their summaries do not carry profile IDs, so they cannot be linked to a particular retained diagnostic capture here.', 'super-speedy-performance-analysis'); ?></p>
                    <div class="sspa-table-scroll"><table class="widefat striped"><thead><tr><th><?php esc_html_e('Page', 'super-speedy-performance-analysis'); ?></th><th><?php esc_html_e('Variant', 'super-speedy-performance-analysis'); ?></th><th><?php esc_html_e('Generation (ms)', 'super-speedy-performance-analysis'); ?></th><th><?php esc_html_e('HTTP status / blocker', 'super-speedy-performance-analysis'); ?></th></tr></thead><tbody>
                    <?php foreach ($report['pages'] as $page) : ?><tr><td><?php echo esc_html($page['page_key']); ?></td><td><?php echo esc_html($page['variant']); ?></td><td><?php echo esc_html(self::measurement($page['generation_ms'])); ?></td><td><?php echo esc_html(($page['response_code'] ? $page['response_code'] : __('not recorded', 'super-speedy-performance-analysis')) . ($page['blocked_by'] ? ' / ' . $page['blocked_by'] : '')); ?></td></tr><?php endforeach; ?>
                    </tbody></table></div>
                </details>
            <?php endif; ?>
            <h3><?php esc_html_e('Recorded findings', 'super-speedy-performance-analysis'); ?></h3>
            <?php if ($report['findings']) : ?><ul class="sspa-history-saved-findings">
                <?php foreach ($report['findings'] as $finding) : ?>
                    <li><h4><?php echo esc_html($finding['severity'] . ': ' . $finding['headline']); ?></h4><p><?php echo esc_html($finding['component'] . ' / ' . $finding['page_key']); ?></p><p><?php echo esc_html($finding['detail']); ?></p>
                    <?php if (!empty($finding['recommendation']['title'])) : ?><p><?php echo esc_html($finding['recommendation']['title']); ?> <?php echo esc_html($finding['recommendation']['body']); ?></p><?php endif; ?></li>
                <?php endforeach; ?>
            </ul><?php else : ?><p><?php esc_html_e('No findings are retained for this run. This does not establish that every request was fault-free.', 'super-speedy-performance-analysis'); ?></p><?php endif; ?>
            <?php if (is_array($notes) && $notes) : ?><details><summary><?php esc_html_e('Saved run notes and outcome', 'super-speedy-performance-analysis'); ?></summary><pre class="sspa-history-run-notes"><?php echo esc_html(wp_json_encode($notes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre></details><?php endif; ?>
        </section>
        <?php
        return ob_get_clean();
    }

    private static function measurement($value) {
        return null === $value ? __('not recorded', 'super-speedy-performance-analysis') : number_format_i18n((float) $value, 1);
    }
}
