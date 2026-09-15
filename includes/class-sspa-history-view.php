<?php
defined('ABSPATH') || exit;

/** HTML adapter for the History comparison document. */
class SSPA_History_View {
    public static function render($comparison) {
        if (is_wp_error($comparison)) {
            return '<div class="notice notice-error inline"><p>' . esc_html($comparison->get_error_message()) . '</p></div>';
        }
        $headline = $comparison['headline'];
        $failed_validity = (int) $comparison['summary']['failed_validity_cases'];
        $failed_declared = (int) $comparison['summary']['failed_declared_cases'];
        $attention = array();
        foreach (array(
            'fatals' => array(__('new fatal error', 'super-speedy-performance-analysis'), __('new fatal errors', 'super-speedy-performance-analysis')),
            'transport_errors' => array(__('new transport error', 'super-speedy-performance-analysis'), __('new transport errors', 'super-speedy-performance-analysis')),
            'http_errors' => array(__('new HTTP error', 'super-speedy-performance-analysis'), __('new HTTP errors', 'super-speedy-performance-analysis')),
            'warnings' => array(__('new warning', 'super-speedy-performance-analysis'), __('new warnings', 'super-speedy-performance-analysis')),
            'critical_findings' => array(__('new critical finding', 'super-speedy-performance-analysis'), __('new critical findings', 'super-speedy-performance-analysis')),
        ) as $diagnostic => $labels) {
            if (!empty($comparison['new_diagnostics'][$diagnostic])) {
                $count = (int) $comparison['new_diagnostics'][$diagnostic];
                $attention[] = $count . ' ' . (1 === $count ? $labels[0] : $labels[1]);
            }
        }
        if ($failed_validity) {
            $attention[] = $failed_validity . ' ' . (1 === $failed_validity
                ? __('failed validity check', 'super-speedy-performance-analysis')
                : __('failed validity checks', 'super-speedy-performance-analysis'));
        }
        if ($failed_declared) {
            $attention[] = $failed_declared . ' ' . (1 === $failed_declared
                ? __('failed declared expectation', 'super-speedy-performance-analysis')
                : __('failed declared expectations', 'super-speedy-performance-analysis'));
        }
        ob_start();
        ?>
        <section class="sspa-history-comparison" data-before-run="<?php echo (int) $comparison['before']['id']; ?>" data-after-run="<?php echo (int) $comparison['after']['id']; ?>">
            <div class="sspa-history-summary sspa-history-status-<?php echo esc_attr($comparison['status']); ?>">
                <div>
                    <span class="sspa-history-kicker"><?php esc_html_e('Response time', 'super-speedy-performance-analysis'); ?></span>
                    <strong><?php echo null !== $headline['before'] ? esc_html(number_format($headline['before'], 1)) . ' ms' : '-'; ?></strong>
                    <span aria-hidden="true">&rarr;</span>
                    <strong><?php echo null !== $headline['after'] ? esc_html(number_format($headline['after'], 1)) . ' ms' : '-'; ?></strong>
                    <?php if (null !== $headline['delta']) : ?>
                        <span class="sspa-history-delta sspa-history-<?php echo esc_attr($headline['direction']); ?>">
                            <?php echo esc_html(sprintf('%+.1f ms', $headline['delta'])); ?>
                            <?php echo null !== $headline['percent'] ? esc_html(sprintf('(%+.1f%%)', $headline['percent'])) : ''; ?>
                        </span>
                    <?php endif; ?>
                    <p class="description"><?php
                        /* translators: %d: number of page scenarios contributing to both headline medians */
                        printf(esc_html(_n('Median across %d matching page measured successfully in both runs.', 'Median across %d matching pages measured successfully in both runs.', $comparison['summary']['headline_pages'], 'super-speedy-performance-analysis')), (int) $comparison['summary']['headline_pages']);
                    ?></p>
                </div>
                <div>
                    <?php if ($attention) : ?>
                        <strong><?php esc_html_e('Needs attention:', 'super-speedy-performance-analysis'); ?></strong>
                        <span><?php echo esc_html(implode(' · ', $attention)); ?></span>
                    <?php elseif ($comparison['summary']['output_changes']) : ?>
                        <strong><?php /* translators: %d: number of changed page outputs */ echo esc_html(sprintf(_n('%d output changed for review', '%d outputs changed for review', $comparison['summary']['output_changes'], 'super-speedy-performance-analysis'), $comparison['summary']['output_changes'])); ?></strong>
                    <?php else : ?>
                        <strong><?php esc_html_e('No new fault or output change found', 'super-speedy-performance-analysis'); ?></strong>
                    <?php endif; ?>
                </div>
            </div>

            <p class="description">
                <?php /* translators: 1: before run id, 2: after run id */ printf(esc_html__('Comparing point in time #%1$d with #%2$d. Missing evidence remains unknown rather than passing.', 'super-speedy-performance-analysis'), (int) $comparison['before']['id'], (int) $comparison['after']['id']); ?>
            </p>

            <?php if (empty($comparison['setup_changes_available'])) : ?>
                <p class="description"><?php esc_html_e('Setup-change evidence is unavailable for one of these older runs.', 'super-speedy-performance-analysis'); ?></p>
            <?php elseif (!empty($comparison['setup_changes'])) : ?>
                <details class="sspa-history-setup-changes">
                    <summary><?php /* translators: %d: number of component changes */ printf(esc_html(_n('%d setup change', '%d setup changes', count($comparison['setup_changes']), 'super-speedy-performance-analysis')), count($comparison['setup_changes'])); ?></summary>
                    <ul>
                    <?php foreach ($comparison['setup_changes'] as $change) : ?>
                        <li>
                            <code><?php echo esc_html($change['slug']); ?></code>
                            <span class="description">
                                <?php if ('added' === $change['state']) : ?>
                                    <?php /* translators: %s: newly installed component version */ echo esc_html(sprintf(__('added at %s', 'super-speedy-performance-analysis'), $change['after_version'] ?: __('unknown version', 'super-speedy-performance-analysis'))); ?>
                                <?php elseif ('removed' === $change['state']) : ?>
                                    <?php /* translators: %s: previously installed component version */ echo esc_html(sprintf(__('removed (was %s)', 'super-speedy-performance-analysis'), $change['before_version'] ?: __('unknown version', 'super-speedy-performance-analysis'))); ?>
                                <?php else : ?>
                                    <?php echo esc_html(($change['before_version'] ?: __('unknown', 'super-speedy-performance-analysis')) . ' → ' . ($change['after_version'] ?: __('unknown', 'super-speedy-performance-analysis'))); ?>
                                <?php endif; ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>

            <?php if (!empty($comparison['configuration_changes'])) : ?>
                <details class="sspa-history-setup-changes">
                    <summary><?php /* translators: %d: number of components whose published configuration state changed */ printf(esc_html(_n('%d configuration change', '%d configuration changes', count($comparison['configuration_changes']), 'super-speedy-performance-analysis')), count($comparison['configuration_changes'])); ?></summary>
                    <ul>
                    <?php foreach ($comparison['configuration_changes'] as $change) : ?>
                        <li><code><?php echo esc_html($change['slug']); ?></code> <span class="description"><?php echo esc_html($change['state']); ?></span></li>
                    <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>

            <div class="sspa-table-scroll"><table class="widefat striped sspa-history-compare-table">
                <thead><tr>
                    <th><?php esc_html_e('Page / variant', 'super-speedy-performance-analysis'); ?></th>
                    <th><?php esc_html_e('Validity', 'super-speedy-performance-analysis'); ?></th>
                    <th><?php esc_html_e('Generation time', 'super-speedy-performance-analysis'); ?></th>
                    <th><?php esc_html_e('Output', 'super-speedy-performance-analysis'); ?></th>
                    <th><?php esc_html_e('Declared expectation', 'super-speedy-performance-analysis'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($comparison['pages'] as $page) :
                    $gen = $page['metrics']['generation_ms']; ?>
                    <tr>
                        <td><code><?php echo esc_html($page['page_key']); ?></code><br><span class="description"><?php echo esc_html($page['variant']); ?></span></td>
                        <td>
                            <span class="sspa-history-validity-<?php echo esc_attr($page['validity']['before']); ?>"><?php echo esc_html($page['validity']['before']); ?></span>
                            &rarr;
                            <span class="sspa-history-validity-<?php echo esc_attr($page['validity']['after']); ?>"><?php echo esc_html($page['validity']['after']); ?></span>
                        </td>
                        <td>
                            <?php echo null !== $gen['before'] ? esc_html(number_format($gen['before'], 1)) : '-'; ?> &rarr;
                            <?php echo null !== $gen['after'] ? esc_html(number_format($gen['after'], 1)) : '-'; ?> ms
                            <?php if (null !== $gen['delta']) : ?><br><strong><?php echo esc_html(sprintf('%+.1f ms', $gen['delta'])); ?></strong><?php endif; ?>
                        </td>
                        <td><span class="sspa-history-output-<?php echo esc_attr($page['output']['state']); ?>"><?php echo esc_html($page['output']['state']); ?></span></td>
                        <td>
                            <span class="sspa-history-declared-<?php echo esc_attr($page['declared']['state']); ?>"><?php echo esc_html($page['declared']['state']); ?></span>
                            <?php if ('fail' === $page['declared']['response_code']['state']) : ?>
                                <br><small><?php echo esc_html(sprintf(
                                    /* translators: 1: expected HTTP status code, 2: actual HTTP status code. */
                                    __('HTTP %1$d expected, got %2$d', 'super-speedy-performance-analysis'),
                                    (int) $page['declared']['response_code']['expected'],
                                    (int) $page['declared']['response_code']['actual']
                                )); ?></small>
                            <?php elseif ('fail' === $page['declared']['signature_state']) : ?>
                                <br><small><?php esc_html_e('Output differs from the approved run', 'super-speedy-performance-analysis'); ?></small>
                            <?php endif; ?>
                            <?php if (!empty($page['output']['after_signature'])) : ?>
                                <button type="button" class="button button-small sspa-history-assert" data-mode="approve" data-page-identity="<?php echo esc_attr($page['key']); ?>"><?php esc_html_e('Use After as expected', 'super-speedy-performance-analysis'); ?></button>
                            <?php endif; ?>
                            <?php if ('not_declared' !== $page['declared']['state']) : ?>
                                <button type="button" class="button-link-delete sspa-history-assert" data-mode="clear" data-page-identity="<?php echo esc_attr($page['key']); ?>"><?php esc_html_e('Clear', 'super-speedy-performance-analysis'); ?></button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <p class="sspa-history-export-actions">
                <button type="button" class="button sspa-history-preview-export"><?php esc_html_e('Preview privacy-safe evidence', 'super-speedy-performance-analysis'); ?></button>
                <button type="button" class="button sspa-history-download-export" disabled><?php esc_html_e('Download reviewed evidence', 'super-speedy-performance-analysis'); ?></button>
                <span class="spinner" aria-hidden="true"></span>
            </p>
            <pre class="sspa-history-export-preview" hidden></pre>
        </section>
        <?php
        return ob_get_clean();
    }

}
