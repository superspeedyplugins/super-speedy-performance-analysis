<?php
defined('ABSPATH') || exit;
// The share panel. Rendered only through SSPA_Share_Prompt::html(), which decides whether it is
// shown. Its wording follows the Share tab's consent text (version 5) and
// https://superspeedy.org/privacy-and-terms/: what "anonymised" means, what is never sent, who
// runs the service and that its data is never joined to sales records. It must never say that
// sharing is needed to use Compare or anything else: it is not.
?>
<section class="sspa-share-prompt" aria-labelledby="sspa-share-prompt-heading">
    <h2 id="sspa-share-prompt-heading"><?php esc_html_e('Add this site to the public performance comparison', 'super-speedy-performance-analysis'); ?></h2>
    <p><?php esc_html_e('Sharing adds this site\'s anonymised analysis results to the public comparison at superspeedy.org, where anyone can see how plugins, themes and setups perform on real sites rather than one benchmark box. Everything in this plugin, Compare included, works the same whether you share or not.', 'super-speedy-performance-analysis'); ?></p>
    <p><?php esc_html_e('Each completed analysis is sent as an anonymised payload: page types, timings and counts, plugin and theme names and versions, normalised query shapes and size bands such as "under 10,000 products". Your domain, URLs, file paths, raw SQL, credentials, emails, customer and order data and request contents are never sent.', 'super-speedy-performance-analysis'); ?>
        <a href="#share" class="sspa-goto-tab" data-tab="share"><?php esc_html_e('See the full description and exactly what gets sent', 'super-speedy-performance-analysis'); ?></a></p>
    <p><?php esc_html_e('superspeedy.org is operated by WP Intense OÜ, which also runs superspeedyplugins.com. Its data is never joined to leads, quote requests or customer records. Sharing grants WP Intense OÜ a permanent, royalty-free licence to store, process and publish aggregates and anonymised individual results.', 'super-speedy-performance-analysis'); ?>
        <a href="https://superspeedy.org/privacy-and-terms/" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Privacy policy and terms', 'super-speedy-performance-analysis'); ?></a></p>
    <p class="sspa-share-prompt-actions">
        <button type="button" class="button button-primary sspa-share-prompt-share"><?php esc_html_e('Share anonymised results automatically', 'super-speedy-performance-analysis'); ?></button>
        <button type="button" class="button sspa-share-prompt-snooze"><?php esc_html_e('Don\'t share for 24 hours', 'super-speedy-performance-analysis'); ?></button>
        <span class="sspa-share-prompt-status" aria-live="polite"></span>
    </p>
    <p class="description"><?php esc_html_e('Automatic sharing is the Share tab\'s "Share every analysis I run" setting: it covers each analysis as it finishes, from now on, and you can turn it off there at any time.', 'super-speedy-performance-analysis'); ?></p>
</section>
