<?php
defined('ABSPATH') || exit;

$sspa_optin = SSPA_Submitter::opted_in();
$sspa_history = SSPA_Submitter::history();
$sspa_counts = SSPA_Community_Outbox::counts();
?>
<div class="sspa-placeholder">
    <h2><?php esc_html_e('Share with the community', 'super-speedy-performance-analysis'); ?></h2>
    <p><?php esc_html_e('Contribute your anonymised results to the community performance database at superspeedy.org. Together these submissions reveal which plugins are fast, which are slow, and which ignore your object cache - across thousands of real sites instead of one benchmark box.', 'super-speedy-performance-analysis'); ?></p>
    <p><?php esc_html_e('Each completed baseline, page profile, deep analysis, checkout flow, cache analysis and plugin-toggle spot-check is saved as its own versioned payload. This includes generic page classifications, aggregate timings, safe component identifiers and versions, measured deltas, findings, normalised query fingerprints, Excimer summaries and bucketed site characteristics when those records exist.', 'super-speedy-performance-analysis'); ?></p>
    <p><?php esc_html_e('Anonymisation happens in this plugin before the payload enters the local queue. Your domain, URLs, filesystem paths, raw SQL, credentials, emails, customer/order data and request contents are forbidden. A privacy validation failure prevents the payload from being queued or sent.', 'super-speedy-performance-analysis'); ?></p>

    <p>
        <label>
            <input type="checkbox" id="sspa-share-optin" value="1" <?php checked($sspa_optin); ?>>
            <strong><?php esc_html_e('Share my anonymised results with superspeedy.org', 'super-speedy-performance-analysis'); ?></strong>
        </label>
    </p>

    <p>
        <button type="button" class="button" id="sspa-preview-payload"><?php esc_html_e('Preview exact payload', 'super-speedy-performance-analysis'); ?></button>
        <button type="button" class="button button-primary" id="sspa-submit-now" <?php disabled(!$sspa_optin); ?>><?php esc_html_e('Queue latest run', 'super-speedy-performance-analysis'); ?></button>
    </p>

    <p class="description">
        <?php
        printf(
            esc_html__('Local queue: %1$d pending, %2$d retrying, %3$d archived, %4$d requiring attention. Network delivery runs in the background and safely retries after outages.', 'super-speedy-performance-analysis'),
            $sspa_counts['pending'],
            $sspa_counts['retry'],
            $sspa_counts['sent'],
            $sspa_counts['permanent_failure']
        );
        ?>
    </p>

    <pre id="sspa-payload-preview" style="display:none"></pre>

    <?php if ($sspa_history) : ?>
        <h3><?php esc_html_e('Submission history', 'super-speedy-performance-analysis'); ?></h3>
        <ul>
        <?php foreach ($sspa_history as $entry) : ?>
            <li>
                <?php echo esc_html($entry['time']); ?> -
                <?php echo $entry['ok'] ? '&#9989;' : (('permanent_failure' === $entry['state']) ? '&#10060;' : '&#8987;'); ?>
                <?php echo esc_html($entry['message']); ?>
                <code><?php echo esc_html(substr($entry['payload_sha256'], 0, 12)); ?></code>
            </li>
        <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <p class="description">
        <?php printf(esc_html__('Install ID: %s (random - not derived from your site).', 'super-speedy-performance-analysis'), esc_html(SSPA_Community_Identity::install_uuid())); ?>
        <?php printf(esc_html__('Collector: %s.', 'super-speedy-performance-analysis'), esc_html(SSPA_Community_Identity::collector_url())); ?>
    </p>
</div>
