<?php
defined('ABSPATH') || exit;

$sspa_optin = SSPA_Submitter::opted_in();
$sspa_history = SSPA_Submitter::history();
$sspa_counts = SSPA_Community_Outbox::counts();
$sspa_queue_status = SSPA_Community_Outbox::queue_status();
$sspa_inventory = SSPA_Community_Backfill::inventory();
$sspa_consent_version = (int) get_option('sspa_share_consent_version', 0);
$sspa_consented_at = get_option('sspa_share_consented_at');
$sspa_backfill_restart = $sspa_inventory['remaining'] > 0 && 0 === $sspa_inventory['remaining_after_cursor'];
?>
<div class="sspa-placeholder">
    <h2><?php esc_html_e('Share with the community', 'super-speedy-performance-analysis'); ?></h2>
    <p><?php esc_html_e('Contribute your anonymised results to the community performance database at superspeedy.org. Together these submissions reveal which plugins are fast, which are slow, and which ignore your object cache - across thousands of real sites instead of one benchmark box.', 'super-speedy-performance-analysis'); ?></p>
    <p><?php esc_html_e('Each completed baseline, page profile, deep analysis, checkout flow, cache analysis and plugin-toggle spot-check is saved as its own versioned payload. This includes generic page classifications, aggregate timings, safe component identifiers and versions, measured deltas, findings, normalised query fingerprints, Excimer summaries and bucketed site characteristics when those records exist.', 'super-speedy-performance-analysis'); ?></p>
    <p><?php esc_html_e('Anonymisation happens in this plugin before the payload enters the local queue. Your domain, URLs, filesystem paths, raw SQL, credentials, emails, customer/order data and request contents are forbidden. A privacy validation failure prevents the payload from being queued or sent.', 'super-speedy-performance-analysis'); ?></p>

    <h3><?php esc_html_e('1. Share every analysis automatically', 'super-speedy-performance-analysis'); ?></h3>
    <p>
        <label>
            <input type="checkbox" id="sspa-share-optin" value="1" <?php checked($sspa_optin); ?>>
            <strong><?php esc_html_e('Share every analysis I run with superspeedy.org', 'super-speedy-performance-analysis'); ?></strong>
        </label>
    </p>
    <p class="description">
        <?php esc_html_e('Covers every full scan, spot check, deep analysis, cache analysis, page analysis and checkout analysis, now and in future, as soon as each one finishes.', 'super-speedy-performance-analysis'); ?>
        <?php
        printf(
            esc_html__('Consent text version %1$d%2$s. Turning sharing off stops new payload creation and delivery attempts; it does not delete already archived evidence.', 'super-speedy-performance-analysis'),
            SSPA_Community_Schema::CONSENT_VERSION,
            $sspa_consent_version && $sspa_consented_at ? ' accepted ' . esc_html($sspa_consented_at) . ' UTC' : ''
        );
        ?>
    </p>

    <p>
        <button type="button" class="button button-primary sspa-sharing-action" id="sspa-submit-now" <?php disabled(!$sspa_optin); ?>><?php esc_html_e('Queue latest run', 'super-speedy-performance-analysis'); ?></button>
    </p>

    <h3><?php esc_html_e('2. Or share individual analyses only', 'super-speedy-performance-analysis'); ?></h3>
    <p>
        <?php esc_html_e('You do not have to switch the setting above on. With it off, every analysis stays on your site until you choose one, and each Share this control sends that single run and nothing else - it never turns on sharing for anything else.', 'super-speedy-performance-analysis'); ?>
    </p>
    <p>
        <a class="button sspa-goto-tab" href="#history" data-tab="history"><?php esc_html_e('Choose analyses to share', 'super-speedy-performance-analysis'); ?></a>
    </p>

    <p class="description">
        <?php
        printf(
            esc_html__('Local queue: %1$d pending, %2$d retrying, %3$d archived, %4$d requiring attention and %5$d paused. Network delivery runs in the background and safely retries after outages.', 'super-speedy-performance-analysis'),
            $sspa_counts['pending'],
            $sspa_counts['retry'],
            $sspa_counts['sent'],
            $sspa_counts['permanent_failure'],
            $sspa_counts['cancelled']
        );
        ?>
        <?php if ($sspa_queue_status['oldest_pending']) : ?>
            <?php printf(esc_html__('Oldest pending: %s UTC.', 'super-speedy-performance-analysis'), esc_html($sspa_queue_status['oldest_pending'])); ?>
        <?php endif; ?>
        <?php if ($sspa_queue_status['next_attempt']) : ?>
            <?php printf(esc_html__('Next attempt: %s UTC.', 'super-speedy-performance-analysis'), esc_html($sspa_queue_status['next_attempt'])); ?>
        <?php endif; ?>
    </p>
</div>

<div class="sspa-placeholder">
    <h2><?php esc_html_e('Existing analysis history', 'super-speedy-performance-analysis'); ?></h2>
    <p>
        <?php
        printf(
            esc_html__('%1$d eligible runs: %2$d already queued or archived and %3$d remaining. Of the remaining runs, %4$d retain detailed captures and %5$d retain scalar summaries only. Remaining detailed source data uses %6$s.', 'super-speedy-performance-analysis'),
            $sspa_inventory['eligible'],
            $sspa_inventory['queued'],
            $sspa_inventory['remaining'],
            $sspa_inventory['with_detail'],
            $sspa_inventory['scalar_only'],
            esc_html(size_format($sspa_inventory['source_detail_bytes']))
        );
        ?>
    </p>
    <?php if ($sspa_inventory['by_type']) : ?>
        <p class="description">
            <?php
            $sspa_type_bits = array();
            foreach ($sspa_inventory['by_type'] as $sspa_type => $sspa_type_counts) {
                $sspa_type_bits[] = sprintf('%s: %d/%d', $sspa_type, $sspa_type_counts['queued'], $sspa_type_counts['eligible']);
            }
            echo esc_html(implode(' | ', $sspa_type_bits));
            ?>
        </p>
    <?php endif; ?>
    <?php if ($sspa_inventory['remaining']) : ?>
        <p>
            <button
                type="button"
                class="button sspa-sharing-action"
                id="sspa-backfill"
                data-restart="<?php echo $sspa_backfill_restart ? '1' : '0'; ?>"
                <?php disabled(!$sspa_optin); ?>
            >
                <?php echo $sspa_backfill_restart ? esc_html__('Retry failed historical runs', 'super-speedy-performance-analysis') : esc_html__('Queue existing runs', 'super-speedy-performance-analysis'); ?>
            </button>
            <span id="sspa-backfill-status" class="description"></span>
        </p>
    <?php else : ?>
        <p><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> <?php esc_html_e('Every eligible historical run has an outbox record.', 'super-speedy-performance-analysis'); ?></p>
    <?php endif; ?>
    <?php if ($sspa_inventory['build_errors']) : ?>
        <details>
            <summary><?php printf(esc_html__('%d historical payload build error(s)', 'super-speedy-performance-analysis'), count($sspa_inventory['build_errors'])); ?></summary>
            <ul>
                <?php foreach ($sspa_inventory['build_errors'] as $sspa_error_run => $sspa_error) : ?>
                    <li><?php printf(esc_html__('Run %1$d: %2$s - %3$s', 'super-speedy-performance-analysis'), (int) $sspa_error_run, esc_html($sspa_error['code']), esc_html($sspa_error['detail'])); ?></li>
                <?php endforeach; ?>
            </ul>
        </details>
    <?php endif; ?>
</div>

<div class="sspa-placeholder">
    <h2><?php esc_html_e('Submission history', 'super-speedy-performance-analysis'); ?></h2>
    <?php if ($sspa_history) : ?>
        <table class="widefat striped sspa-outbox-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Run', 'super-speedy-performance-analysis'); ?></th>
                    <th><?php esc_html_e('State', 'super-speedy-performance-analysis'); ?></th>
                    <th><?php esc_html_e('Payload', 'super-speedy-performance-analysis'); ?></th>
                    <th><?php esc_html_e('Receipt / error', 'super-speedy-performance-analysis'); ?></th>
                    <th><?php esc_html_e('Actions', 'super-speedy-performance-analysis'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($sspa_history as $entry) : ?>
                <tr>
                    <td><code><?php echo esc_html($entry['run_type']); ?></code><br><small><?php echo esc_html($entry['run_started']); ?> UTC</small></td>
                    <td><strong><?php echo esc_html($entry['state']); ?></strong><br><small><?php echo esc_html($entry['phase']); ?></small></td>
                    <td><?php echo esc_html(size_format($entry['compressed_bytes'])); ?>, schema <?php echo esc_html($entry['payload_schema']); ?><br><code><?php echo esc_html(substr($entry['payload_sha256'], 0, 16)); ?></code></td>
                    <td>
                        <?php if ($entry['receipt_uuid']) : ?>
                            <code><?php echo esc_html($entry['receipt_uuid']); ?></code>
                        <?php elseif ($entry['last_error_code']) : ?>
                            <code><?php echo esc_html($entry['last_error_code']); ?></code><br><small><?php echo esc_html($entry['last_error_detail']); ?></small>
                        <?php else : ?>
                            <?php echo esc_html($entry['message']); ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <button type="button" class="button button-small sspa-preview-outbox" data-outbox-id="<?php echo (int) $entry['id']; ?>"><?php esc_html_e('Preview', 'super-speedy-performance-analysis'); ?></button>
                        <?php if (in_array($entry['state'], array('retry', 'permanent_failure'), true)) : ?>
                            <button type="button" class="button button-small sspa-outbox-action sspa-sharing-action" data-operation="retry" data-outbox-id="<?php echo (int) $entry['id']; ?>" <?php disabled(!$sspa_optin); ?>><?php esc_html_e('Retry now', 'super-speedy-performance-analysis'); ?></button>
                        <?php endif; ?>
                        <?php if (in_array($entry['state'], array('pending', 'retry', 'permanent_failure'), true)) : ?>
                            <button type="button" class="button button-small sspa-outbox-action" data-operation="pause" data-outbox-id="<?php echo (int) $entry['id']; ?>"><?php esc_html_e('Pause', 'super-speedy-performance-analysis'); ?></button>
                        <?php elseif ('cancelled' === $entry['state']) : ?>
                            <button type="button" class="button button-small sspa-outbox-action sspa-sharing-action" data-operation="resume" data-outbox-id="<?php echo (int) $entry['id']; ?>" <?php disabled(!$sspa_optin); ?>><?php esc_html_e('Resume', 'super-speedy-performance-analysis'); ?></button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else : ?>
        <p><?php esc_html_e('No payloads have been queued yet.', 'super-speedy-performance-analysis'); ?></p>
    <?php endif; ?>

    <p>
        <button type="button" class="button sspa-preview-outbox" data-outbox-id="0">
            <?php esc_html_e('See exactly what gets sent', 'super-speedy-performance-analysis'); ?>
        </button>
        <span class="description"><?php esc_html_e('Builds the payload for your most recent analysis and shows it. Nothing is queued or sent.', 'super-speedy-performance-analysis'); ?></span>
    </p>
    <div id="sspa-payload-summary" class="sspa-payload-summary" style="display:none"></div>
    <pre id="sspa-payload-preview" style="display:none"></pre>
    <p class="description">
        <?php printf(esc_html__('Install ID: %s (random - not derived from your site).', 'super-speedy-performance-analysis'), esc_html(SSPA_Community_Identity::install_uuid())); ?>
        <?php printf(esc_html__('Collector: %s.', 'super-speedy-performance-analysis'), esc_html(SSPA_Community_Identity::collector_url())); ?>
    </p>
</div>
