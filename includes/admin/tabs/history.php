<?php
defined('ABSPATH') || exit;

global $wpdb;
$sspa_runs = SSPA_History_Series::recent_runs();
$sspa_optin = SSPA_Submitter::opted_in();
$sspa_remove_on_uninstall = (bool) sspa_get_option('remove_data_on_uninstall');
$sspa_update_detection = (bool) sspa_get_option('plugin_update_detection');
$sspa_comparable_runs = array_values(array_filter($sspa_runs, function ($sspa_run) {
    return 'done' === $sspa_run['status'] && in_array($sspa_run['run_type'], array('baseline', 'spot'), true);
}));
$sspa_after_run_id = $sspa_comparable_runs ? (int) $sspa_comparable_runs[0]['id'] : 0;
$sspa_before_run_id = isset($sspa_comparable_runs[1]) ? (int) $sspa_comparable_runs[1]['id'] : 0;
$sspa_history_series = $sspa_after_run_id ? SSPA_History_Series::build(0, 'request_wall_ms') : null;
if (is_array($sspa_history_series)) {
    $sspa_after_run_id = (int) $sspa_history_series['anchor_run_id'];
    if (!empty($sspa_history_series['previous']['run_ids'])) {
        $sspa_before_run_id = (int) end($sspa_history_series['previous']['run_ids']);
    }
} elseif ($sspa_after_run_id) {
    $sspa_latest_context = json_decode((string) $sspa_comparable_runs[0]['share_context'], true);
    if (is_array($sspa_latest_context) && !empty($sspa_latest_context['history_comparison']['baseline_run_id'])) {
        $sspa_candidate_before = (int) $sspa_latest_context['history_comparison']['baseline_run_id'];
        foreach ($sspa_comparable_runs as $sspa_comparable_run) {
            if ((int) $sspa_comparable_run['id'] === $sspa_candidate_before) {
                $sspa_before_run_id = $sspa_candidate_before;
                break;
            }
        }
    }
}

// Plain names for the analysis types, so the share control says what it would actually send.
$sspa_type_labels = array(
    'baseline' => __('full scan', 'super-speedy-performance-analysis'),
    'spot' => __('spot check', 'super-speedy-performance-analysis'),
    'deep' => __('Plugin Impact Analysis', 'super-speedy-performance-analysis'),
    'cache_impact' => __('cache analysis', 'super-speedy-performance-analysis'),
    'adhoc' => __('page analysis', 'super-speedy-performance-analysis'),
    'admin_save' => __('admin update/save analysis', 'super-speedy-performance-analysis'),
    'checkout' => __('checkout analysis', 'super-speedy-performance-analysis'),
);
$sspa_share_states = array(
    'pending' => __('Queued to share', 'super-speedy-performance-analysis'),
    'retry' => __('Retrying', 'super-speedy-performance-analysis'),
    'sent' => __('Shared', 'super-speedy-performance-analysis'),
    'permanent_failure' => __('Needs attention', 'super-speedy-performance-analysis'),
    'cancelled' => __('Paused', 'super-speedy-performance-analysis'),
);

?>
<div class="sspa-history-toolbar">
    <details>
        <summary><?php esc_html_e('Advanced history settings', 'super-speedy-performance-analysis'); ?></summary>
        <p>
            <label>
                <input type="checkbox" id="sspa-plugin-update-detection" value="1" <?php checked($sspa_update_detection); ?>>
                <?php esc_html_e('Offer a quick comparison after plugin changes', 'super-speedy-performance-analysis'); ?>
            </label>
            <span class="spinner" aria-hidden="true"></span><br>
            <span class="description"><?php esc_html_e('Enabled by default. Updates are only recorded here; analysis never runs inside the updater request.', 'super-speedy-performance-analysis'); ?></span>
        </p>
        <p>
            <label>
                <input type="checkbox" id="sspa-remove-data-on-uninstall" value="1" <?php checked($sspa_remove_on_uninstall); ?>>
                <?php esc_html_e('Delete all SSPA data when the plugin is deleted', 'super-speedy-performance-analysis'); ?>
            </label>
        </p>
    </details>
</div>
<?php

if ($sspa_after_run_id) {
    echo SSPA_History_Chart::render($sspa_history_series); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderer escapes every field.
}

if (count($sspa_comparable_runs) >= 2) : ?>
    <section class="sspa-history-compare-picker">
        <h3><?php esc_html_e('Compare points in time', 'super-speedy-performance-analysis'); ?></h3>
        <p class="description"><?php esc_html_e('Response time is the headline. New errors, warnings, failed validity checks, and declared expectations are shown first when they appear.', 'super-speedy-performance-analysis'); ?></p>
        <div class="sspa-history-compare-controls">
            <label><?php esc_html_e('Before', 'super-speedy-performance-analysis'); ?>
                <select id="sspa-history-before">
                    <?php foreach ($sspa_comparable_runs as $sspa_run) : ?>
                        <option value="<?php echo (int) $sspa_run['id']; ?>" <?php selected($sspa_before_run_id, (int) $sspa_run['id']); ?>>#<?php echo (int) $sspa_run['id']; ?> — <?php echo esc_html($sspa_run['started']); ?> (<?php echo esc_html($sspa_run['run_type']); ?>)</option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><?php esc_html_e('After', 'super-speedy-performance-analysis'); ?>
                <select id="sspa-history-after">
                    <?php foreach ($sspa_comparable_runs as $sspa_run) : ?>
                        <option value="<?php echo (int) $sspa_run['id']; ?>" <?php selected($sspa_after_run_id, (int) $sspa_run['id']); ?>>#<?php echo (int) $sspa_run['id']; ?> — <?php echo esc_html($sspa_run['started']); ?> (<?php echo esc_html($sspa_run['run_type']); ?>)</option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="button" class="button button-primary" id="sspa-history-compare"><?php esc_html_e('Compare', 'super-speedy-performance-analysis'); ?></button>
            <span class="spinner" aria-hidden="true"></span>
        </div>
        <div id="sspa-history-comparison" aria-live="polite">
            <?php echo SSPA_History::render(SSPA_History::compare($sspa_before_run_id, $sspa_after_run_id)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderer escapes every field. ?>
        </div>
    </section>
<?php elseif ($sspa_comparable_runs) : ?>
    <div class="sspa-placeholder"><p><?php esc_html_e('This is your first saved point in time. Run another full scan or quick comparison to see what changed.', 'super-speedy-performance-analysis'); ?></p></div>
<?php endif;

if (!$sspa_runs) : ?>
    <div class="sspa-placeholder">
        <p><?php esc_html_e('Trends across analysis runs will appear here, so you can see whether your site is getting slower as it grows.', 'super-speedy-performance-analysis'); ?></p>
    </div>
<?php else : ?>
    <div class="sspa-placeholder">
        <p>
            <?php if ($sspa_optin) : ?>
                <?php esc_html_e('Sharing every analysis is on, so each completed run below is queued for the community database automatically.', 'super-speedy-performance-analysis'); ?>
            <?php else : ?>
                <?php esc_html_e('Sharing every analysis is off. You can still contribute any single analysis below with Share this - it sends only that one run, and it does not turn on sharing for anything else.', 'super-speedy-performance-analysis'); ?>
            <?php endif; ?>
            <a href="#share" class="sspa-goto-tab" data-tab="share"><?php esc_html_e('Sharing settings and privacy details', 'super-speedy-performance-analysis'); ?></a>
        </p>
    </div>
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
                <th><?php esc_html_e('Components measured', 'super-speedy-performance-analysis'); ?></th>
                <th><?php esc_html_e('LLM report', 'super-speedy-performance-analysis'); ?></th>
                <th><?php esc_html_e('Share with community', 'super-speedy-performance-analysis'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($sspa_runs as $run) :
            $notes = json_decode((string) $run['notes'], true);
            $pages = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE run_id = %d', SSPA_Schema::table('profiles'), $run['id']));
            $median_gen = $wpdb->get_var($wpdb->prepare(
                "SELECT AVG(page_gen_ms) FROM %i WHERE run_id = %d AND page_gen_ms IS NOT NULL AND page_key != 'baseline'",
                SSPA_Schema::table('profiles'),
                $run['id']
            ));
            $sspa_label = isset($sspa_type_labels[$run['run_type']]) ? $sspa_type_labels[$run['run_type']] : $run['run_type'];
            // Versions as they were when this run measured, decoded from the run row already
            // in memory. Runs recorded before 0.12 kept no versions, so they stay unknown.
            $sspa_run_versions = SSPA_Run_Controller::decode_component_versions($run['plugin_set']);
            $sspa_outbox = SSPA_Community_Outbox::for_run_uuid($run['run_uuid']);
            $sspa_shareable = 'done' === $run['status'] || ('failed' === $run['status'] && 'checkout' === $run['run_type']);
            ?>
            <tr>
                <td>#<?php echo (int) $run['id']; ?></td>
                <td><?php echo esc_html($run['run_type']); ?></td>
                <td><?php echo esc_html($run['started']); ?></td>
                <td><?php echo esc_html($run['status']); ?></td>
                <td><?php echo (int) $pages; ?></td>
                <td><?php echo $median_gen !== null ? esc_html(number_format((float) $median_gen, 1)) : '-'; ?></td>
                <td><?php echo is_array($notes) && isset($notes['findings']) ? (int) $notes['findings'] : '-'; ?></td>
                <td><?php echo is_array($notes) && isset($notes['score']) ? (int) $notes['score'] . '/100' : '-'; ?></td>
                <td>
                    <?php if ($sspa_run_versions) : ?>
                        <details class="sspa-run-components">
                            <summary><?php /* translators: %d: number of components recorded for this run */ printf(esc_html(_n('%d component', '%d components', count($sspa_run_versions), 'super-speedy-performance-analysis')), count($sspa_run_versions)); ?></summary>
                            <ul>
                                <?php foreach ($sspa_run_versions as $sspa_component_key => $sspa_component_version) : ?>
                                    <li>
                                        <code><?php echo esc_html(substr($sspa_component_key, strpos($sspa_component_key, ':') + 1)); ?></code>
                                        <?php echo $sspa_component_version ? esc_html($sspa_component_version) : esc_html__('version unknown', 'super-speedy-performance-analysis'); ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </details>
                    <?php else : ?>
                        <span class="description"><?php esc_html_e('not recorded', 'super-speedy-performance-analysis'); ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($sspa_shareable) :
                        $sspa_markdown_kind = 'checkout' === $run['run_type'] ? 'checkout' : 'run'; ?>
                        <button type="button" class="button button-small sspa-markdown-download" data-kind="<?php echo esc_attr($sspa_markdown_kind); ?>" data-id="<?php echo (int) $run['id']; ?>"><?php esc_html_e('Download Markdown', 'super-speedy-performance-analysis'); ?></button>
                        <button type="button" class="button button-small sspa-markdown-copy" data-kind="<?php echo esc_attr($sspa_markdown_kind); ?>" data-id="<?php echo (int) $run['id']; ?>"><?php esc_html_e('Copy Markdown', 'super-speedy-performance-analysis'); ?></button>
                        <span class="description sspa-markdown-status" aria-live="polite"></span>
                    <?php else : ?>
                        <span class="description">-</span>
                    <?php endif; ?>
                </td>
                <td class="sspa-share-run-cell" data-run-id="<?php echo (int) $run['id']; ?>">
                    <?php if ($sspa_outbox) : ?>
                        <span class="sspa-share-state">
                            <?php echo esc_html(isset($sspa_share_states[$sspa_outbox['state']]) ? $sspa_share_states[$sspa_outbox['state']] : $sspa_outbox['state']); ?>
                            <?php if ('manual' === $sspa_outbox['consent_scope']) : ?>
                                <?php esc_html_e('(this run only)', 'super-speedy-performance-analysis'); ?>
                            <?php endif; ?>
                        </span>
                        <button type="button" class="button button-small sspa-preview-outbox" data-outbox-id="<?php echo (int) $sspa_outbox['id']; ?>"><?php esc_html_e('Preview data', 'super-speedy-performance-analysis'); ?></button>
                    <?php elseif ($sspa_shareable) : ?>
                        <button type="button" class="button button-small sspa-share-run" data-run-id="<?php echo (int) $run['id']; ?>">
                            <?php /* translators: %s: analysis type, e.g. "full scan" */ printf(esc_html__('Share this %s', 'super-speedy-performance-analysis'), esc_html($sspa_label)); ?>
                        </button>
                    <?php else : ?>
                        <span class="description"><?php esc_html_e('Not shareable', 'super-speedy-performance-analysis'); ?></span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div id="sspa-payload-summary" class="sspa-payload-summary" style="display:none"></div>
    <pre id="sspa-payload-preview" style="display:none;max-height:420px;overflow:auto;background:#fff;padding:12px;border:1px solid #dcdcde;"></pre>
<?php endif; ?>
