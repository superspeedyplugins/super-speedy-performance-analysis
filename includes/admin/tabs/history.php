<?php
defined('ABSPATH') || exit;

global $wpdb;
$sspa_runs = $wpdb->get_results('SELECT * FROM ' . SSPA_Schema::table('runs') . ' ORDER BY id DESC LIMIT 50', ARRAY_A);
$sspa_optin = SSPA_Submitter::opted_in();

// Plain names for the analysis types, so the share control says what it would actually send.
$sspa_type_labels = array(
    'baseline' => __('full scan', 'super-speedy-performance-analysis'),
    'spot' => __('spot check', 'super-speedy-performance-analysis'),
    'deep' => __('deep analysis', 'super-speedy-performance-analysis'),
    'cache_impact' => __('cache analysis', 'super-speedy-performance-analysis'),
    'adhoc' => __('page analysis', 'super-speedy-performance-analysis'),
    'checkout' => __('checkout analysis', 'super-speedy-performance-analysis'),
);
$sspa_share_states = array(
    'pending' => __('Queued to share', 'super-speedy-performance-analysis'),
    'retry' => __('Retrying', 'super-speedy-performance-analysis'),
    'sent' => __('Shared', 'super-speedy-performance-analysis'),
    'permanent_failure' => __('Needs attention', 'super-speedy-performance-analysis'),
    'cancelled' => __('Paused', 'super-speedy-performance-analysis'),
);

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
                <th><?php esc_html_e('Share with community', 'super-speedy-performance-analysis'); ?></th>
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
                <td><?php echo $pages; ?></td>
                <td><?php echo $median_gen !== null ? esc_html(number_format((float) $median_gen, 1)) : '-'; ?></td>
                <td><?php echo is_array($notes) && isset($notes['findings']) ? (int) $notes['findings'] : '-'; ?></td>
                <td><?php echo is_array($notes) && isset($notes['score']) ? (int) $notes['score'] : '-'; ?></td>
                <td>
                    <?php if ($sspa_run_versions) : ?>
                        <details class="sspa-run-components">
                            <summary><?php printf(esc_html(_n('%d component', '%d components', count($sspa_run_versions), 'super-speedy-performance-analysis')), count($sspa_run_versions)); ?></summary>
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
                            <?php printf(esc_html__('Share this %s', 'super-speedy-performance-analysis'), esc_html($sspa_label)); ?>
                        </button>
                    <?php else : ?>
                        <span class="description"><?php esc_html_e('Not shareable', 'super-speedy-performance-analysis'); ?></span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <pre id="sspa-payload-preview" style="display:none;max-height:420px;overflow:auto;background:#fff;padding:12px;border:1px solid #dcdcde;"></pre>
<?php endif; ?>
