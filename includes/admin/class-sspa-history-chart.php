<?php
defined('ABSPATH') || exit;

/** Server adapter for the renderer-neutral History series document. */
class SSPA_History_Chart {

    public static function render($document) {
        if (is_wp_error($document)) {
            return '<section class="sspa-history-chart-card"><h3>'
                . esc_html__('Performance by measured setup', 'super-speedy-performance-analysis')
                . '</h3><div class="sspa-placeholder"><p>' . esc_html($document->get_error_message()) . '</p></div></section>';
        }

        ob_start();
        ?>
        <section class="sspa-history-chart-card" data-sspa-history-chart>
            <div class="sspa-history-chart-heading">
                <div>
                    <h3><?php esc_html_e('Performance by measured setup', 'super-speedy-performance-analysis'); ?></h3>
                    <p class="description"><?php esc_html_e('Every point is a saved measurement. Medians compare the setup before the plugin or theme change with the current setup.', 'super-speedy-performance-analysis'); ?></p>
                </div>
                <div class="sspa-history-chart-controls">
                    <label>
                        <span><?php esc_html_e('Metric', 'super-speedy-performance-analysis'); ?></span>
                        <select class="sspa-history-metric">
                            <?php foreach (SSPA_History_Series::metrics() as $key => $metric) : ?>
                                <option value="<?php echo esc_attr($key); ?>" <?php selected($document['metric']['key'], $key); ?>><?php echo esc_html($metric['label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        <span><?php esc_html_e('Filter pages', 'super-speedy-performance-analysis'); ?></span>
                        <input type="search" class="sspa-history-page-filter" placeholder="<?php esc_attr_e('e.g. checkout', 'super-speedy-performance-analysis'); ?>">
                    </label>
                </div>
            </div>

            <?php echo self::period_summary($document); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- method escapes fields. ?>

            <?php if (!empty($document['empty_state'])) : ?>
                <div class="notice notice-info inline"><p><?php echo esc_html($document['empty_state']); ?></p></div>
            <?php endif; ?>
            <?php foreach ((array) $document['warnings'] as $warning) : ?>
                <div class="notice notice-warning inline"><p><?php echo esc_html($warning); ?></p></div>
            <?php endforeach; ?>

            <div class="sspa-history-chart-status" aria-live="polite"></div>
            <div class="sspa-history-chart" role="img" aria-label="<?php esc_attr_e('Previous and current measured setup performance by page', 'super-speedy-performance-analysis'); ?>"></div>
            <script type="application/json" class="sspa-history-chart-document"><?php
                echo wp_json_encode($document, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON hex flags make script-safe text.
            ?></script>
            <div class="sspa-history-chart-table"><?php echo self::table($document); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- method escapes fields. ?></div>
        </section>
        <?php
        return ob_get_clean();
    }

    public static function ajax_series() {
        check_ajax_referer('sspa_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'super-speedy-performance-analysis'), 403);
        }
        $document = SSPA_History_Series::build(
            isset($_POST['after_run_id']) ? (int) $_POST['after_run_id'] : 0,
            isset($_POST['metric']) ? sanitize_key(wp_unslash($_POST['metric'])) : 'request_wall_ms',
            isset($_POST['before_run_id']) ? (int) $_POST['before_run_id'] : 0
        );
        if (is_wp_error($document)) {
            wp_send_json_error($document->get_error_message());
        }
        wp_send_json_success(array(
            'document' => $document,
            'table' => self::table($document),
        ));
    }

    public static function table($document) {
        if (is_wp_error($document) || empty($document['pages'])) {
            return '<p class="description">' . esc_html__('No comparable page measurements are available.', 'super-speedy-performance-analysis') . '</p>';
        }
        $unit = $document['metric']['unit'];
        ob_start();
        ?>
        <details class="sspa-history-data-details">
            <summary><?php esc_html_e('View chart data', 'super-speedy-performance-analysis'); ?></summary>
            <div class="sspa-table-scroll">
                <table class="widefat striped sspa-history-data-table">
                    <thead><tr>
                        <th><?php esc_html_e('Page scenario', 'super-speedy-performance-analysis'); ?></th>
                        <th><?php esc_html_e('Previous points', 'super-speedy-performance-analysis'); ?></th>
                        <th><?php esc_html_e('Previous median', 'super-speedy-performance-analysis'); ?></th>
                        <th><?php esc_html_e('Current points', 'super-speedy-performance-analysis'); ?></th>
                        <th><?php esc_html_e('Current median', 'super-speedy-performance-analysis'); ?></th>
                        <th><?php esc_html_e('Change', 'super-speedy-performance-analysis'); ?></th>
                        <th><?php esc_html_e('Evidence state', 'super-speedy-performance-analysis'); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($document['pages'] as $page) :
                        $previous_values = wp_list_pluck($page['previous']['points'], 'value');
                        $current_values = wp_list_pluck($page['current']['points'], 'value');
                        ?>
                        <tr data-page-label="<?php echo esc_attr(strtolower($page['label'] . ' ' . $page['key'])); ?>">
                            <th scope="row">
                                <?php echo esc_html($page['label']); ?><br>
                                <span class="description"><?php echo esc_html($page['method'] . ' · ' . $page['variant'] . ' · ' . $page['object_cache_mode']); ?></span>
                            </th>
                            <td><?php echo esc_html(self::value_list($previous_values, $unit)); ?></td>
                            <td><?php echo esc_html(self::value($page['previous']['median'], $unit)); ?></td>
                            <td><?php echo esc_html(self::value_list($current_values, $unit)); ?></td>
                            <td><?php echo esc_html(self::value($page['current']['median'], $unit)); ?></td>
                            <td><?php echo esc_html(self::change($page['delta'], $unit)); ?></td>
                            <td>
                                <?php echo esc_html(self::state_label($page)); ?>
                                <?php if ($page['previous']['fault_count'] || $page['current']['fault_count']) : ?>
                                    <br><span class="sspa-history-fault-text"><?php
                                        echo esc_html(sprintf(
                                            /* translators: 1: previous failure summary, 2: current failure summary */
                                            __('Previous: %1$s. Current: %2$s.', 'super-speedy-performance-analysis'),
                                            self::fault_summary($page['previous']['faults']),
                                            self::fault_summary($page['current']['faults'])
                                        ));
                                    ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
        <?php
        return ob_get_clean();
    }

    private static function period_summary($document) {
        ob_start();
        ?>
        <div class="sspa-history-periods">
            <div class="sspa-history-period sspa-history-period-previous">
                <span><?php esc_html_e('Previous setup', 'super-speedy-performance-analysis'); ?></span>
                <?php if ($document['previous']) : ?>
                    <strong><?php echo esc_html(self::dates($document['previous'])); ?></strong>
                    <small><?php /* translators: %d: number of analysis runs */ printf(esc_html(_n('%d analysis', '%d analyses', $document['previous']['run_count'], 'super-speedy-performance-analysis')), (int) $document['previous']['run_count']); ?></small>
                <?php else : ?>
                    <strong><?php esc_html_e('Not measured yet', 'super-speedy-performance-analysis'); ?></strong>
                <?php endif; ?>
            </div>
            <div class="sspa-history-period sspa-history-period-current">
                <span><?php esc_html_e('Current setup', 'super-speedy-performance-analysis'); ?></span>
                <strong><?php echo esc_html(self::dates($document['current'])); ?></strong>
                <small><?php /* translators: %d: number of analysis runs */ printf(esc_html(_n('%d analysis', '%d analyses', $document['current']['run_count'], 'super-speedy-performance-analysis')), (int) $document['current']['run_count']); ?></small>
            </div>
        </div>
        <?php if (!empty($document['setup_changes'])) : ?>
            <div class="sspa-history-chart-changes">
                <strong><?php esc_html_e('What changed', 'super-speedy-performance-analysis'); ?></strong>
                <ul>
                    <?php foreach ($document['setup_changes'] as $change) : ?>
                        <li><code><?php echo esc_html($change['slug']); ?></code> <?php echo esc_html(self::component_change($change)); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }

    private static function dates($period) {
        $start = mysql2date(get_option('date_format'), $period['started'], false);
        $finish = mysql2date(get_option('date_format'), $period['finished'], false);
        return $start === $finish ? $start : $start . ' – ' . $finish;
    }

    private static function component_change($change) {
        if ('added' === $change['state']) {
            return sprintf(__('added at %s', 'super-speedy-performance-analysis'), $change['after_version']);
        }
        if ('removed' === $change['state']) {
            return sprintf(__('removed (was %s)', 'super-speedy-performance-analysis'), $change['before_version']);
        }
        return sprintf(__('%1$s → %2$s', 'super-speedy-performance-analysis'), $change['before_version'], $change['after_version']);
    }

    private static function value_list($values, $unit) {
        if (!$values) {
            return '—';
        }
        return implode(', ', array_map(function ($value) use ($unit) {
            return self::value($value, $unit);
        }, $values));
    }

    private static function value($value, $unit) {
        if (null === $value) {
            return '—';
        }
        if ('bytes' === $unit) {
            return size_format((float) $value, 1);
        }
        return number_format_i18n((float) $value, 'count' === $unit ? 0 : 1) . ('ms' === $unit ? ' ms' : '');
    }

    private static function change($delta, $unit) {
        if (null === $delta['absolute']) {
            return '—';
        }
        $absolute = ('bytes' === $unit)
            ? size_format(abs((float) $delta['absolute']), 1)
            : number_format_i18n(abs((float) $delta['absolute']), 'count' === $unit ? 0 : 1) . ('ms' === $unit ? ' ms' : '');
        return ((float) $delta['absolute'] >= 0 ? '+' : '−') . $absolute
            . (null !== $delta['percent'] ? ' (' . sprintf('%+.1f%%', $delta['percent']) . ')' : '');
    }

    private static function state_label($page) {
        if ($page['previous']['fault_count'] || $page['current']['fault_count']) {
            return __('Failed request evidence', 'super-speedy-performance-analysis');
        }
        if ('changed' === $page['output_state']) {
            return __('Output changed — review', 'super-speedy-performance-analysis');
        }
        if ('unchanged' === $page['output_state']) {
            return __('Output unchanged', 'super-speedy-performance-analysis');
        }
        return __('Output comparison unavailable', 'super-speedy-performance-analysis');
    }

    private static function fault_summary($faults) {
        if (!$faults) {
            return __('none', 'super-speedy-performance-analysis');
        }
        $counts = array_count_values(wp_list_pluck($faults, 'state'));
        $labels = array(
            'blocked' => __('blocked', 'super-speedy-performance-analysis'),
            'transport_error' => __('transport error', 'super-speedy-performance-analysis'),
            'http_error' => __('HTTP error', 'super-speedy-performance-analysis'),
            'missing' => __('missing measurement', 'super-speedy-performance-analysis'),
        );
        $parts = array();
        foreach ($labels as $state => $label) {
            if (!empty($counts[$state])) {
                $parts[] = (int) $counts[$state] . ' ' . $label;
            }
        }
        return implode(', ', $parts);
    }
}
