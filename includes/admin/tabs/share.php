<?php
defined('ABSPATH') || exit;

$sspa_optin = SSPA_Submitter::opted_in();
$sspa_history = SSPA_Submitter::history();
?>
<div class="sspa-placeholder">
    <h2><?php esc_html_e('Share with the community', 'super-speedy-performance-analysis'); ?></h2>
    <p><?php esc_html_e('Contribute your anonymised results to the community performance database at superspeedy.org. Together these submissions reveal which plugins are fast, which are slow, and which ignore your object cache - across thousands of real sites instead of one benchmark box.', 'super-speedy-performance-analysis'); ?></p>
    <p><?php esc_html_e('What is shared: metric medians per generic page type, per-plugin attribution totals, measured isolation deltas, findings with normalised query fingerprints (literals stripped), plugin slugs + versions, and bucketed site sizes. What is NEVER shared: your domain or URLs, raw SQL, emails, customer data, or anything typed into your site. Your site is identified only by a random ID.', 'super-speedy-performance-analysis'); ?></p>

    <p>
        <label>
            <input type="checkbox" id="sspa-share-optin" value="1" <?php checked($sspa_optin); ?>>
            <strong><?php esc_html_e('Share my anonymised results with superspeedy.org', 'super-speedy-performance-analysis'); ?></strong>
        </label>
    </p>

    <p>
        <button type="button" class="button" id="sspa-preview-payload"><?php esc_html_e('Preview exact payload', 'super-speedy-performance-analysis'); ?></button>
        <button type="button" class="button button-primary" id="sspa-submit-now" <?php disabled(!$sspa_optin); ?>><?php esc_html_e('Submit now', 'super-speedy-performance-analysis'); ?></button>
    </p>

    <pre id="sspa-payload-preview" style="display:none"></pre>

    <?php if ($sspa_history) : ?>
        <h3><?php esc_html_e('Submission history', 'super-speedy-performance-analysis'); ?></h3>
        <ul>
        <?php foreach ($sspa_history as $entry) : ?>
            <li>
                <?php echo esc_html($entry['time']); ?> -
                <?php echo $entry['ok'] ? '&#9989;' : '&#10060;'; ?>
                <?php echo esc_html($entry['message']); ?>
            </li>
        <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <p class="description">
        <?php printf(esc_html__('Install ID: %s (random - not derived from your site).', 'super-speedy-performance-analysis'), esc_html(SSPA_Anonymiser::install_uuid())); ?>
    </p>
</div>
