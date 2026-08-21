<?php
defined('ABSPATH') || exit;

$sspa_workflow_types = SSPA_Workflow_Analysis::object_types();
?>

<div class="sspa-workflow-grid">
    <section class="sspa-placeholder sspa-workflow-card">
        <h2><?php esc_html_e('Checkout and order handling', 'super-speedy-performance-analysis'); ?></h2>
        <p><?php esc_html_e('Measure a complete WooCommerce purchase, then opening and completing its order. The pre-flight shows exactly what the store will trigger before anything runs.', 'super-speedy-performance-analysis'); ?></p>
        <?php if (class_exists('WooCommerce')) : ?>
            <p><button type="button" class="button button-primary sspa-ck-open"><?php esc_html_e('Analyse checkout and order flow', 'super-speedy-performance-analysis'); ?></button></p>
        <?php else : ?>
            <p class="description"><?php esc_html_e('WooCommerce is not active, so there is no checkout flow to analyse.', 'super-speedy-performance-analysis'); ?></p>
        <?php endif; ?>
    </section>

    <section class="sspa-placeholder sspa-workflow-card">
        <h2><?php esc_html_e('Edit and save', 'super-speedy-performance-analysis'); ?></h2>
        <p><?php esc_html_e('Load the selected item in a controlled same-site editor and profile its real no-change update request. This exercises the normal save hooks without changing a title, taxonomy or meta value, so no recovery save is needed.', 'super-speedy-performance-analysis'); ?></p>

        <?php if ($sspa_workflow_types) : ?>
            <div class="sspa-workflow-fields">
                <label for="sspa-workflow-object-type">
                    <strong><?php esc_html_e('Content type', 'super-speedy-performance-analysis'); ?></strong>
                    <select id="sspa-workflow-object-type">
                        <?php foreach ($sspa_workflow_types as $sspa_workflow_type) : ?>
                            <option value="<?php echo esc_attr($sspa_workflow_type['key']); ?>"><?php echo esc_html($sspa_workflow_type['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label for="sspa-workflow-object-id">
                    <strong><?php esc_html_e('Item', 'super-speedy-performance-analysis'); ?></strong>
                    <select id="sspa-workflow-object-id" disabled>
                        <option><?php esc_html_e('Loading latest item…', 'super-speedy-performance-analysis'); ?></option>
                    </select>
                    <span class="description"><?php esc_html_e('Defaults to the most recently modified item.', 'super-speedy-performance-analysis'); ?></span>
                </label>

                <label for="sspa-workflow-transport">
                    <strong><?php esc_html_e('Save transport', 'super-speedy-performance-analysis'); ?></strong>
                    <select id="sspa-workflow-transport" disabled></select>
                </label>
            </div>

            <label class="sspa-workflow-mail">
                <input type="checkbox" id="sspa-workflow-suppress-mail" checked>
                <strong><?php esc_html_e('Suppress email delivery', 'super-speedy-performance-analysis'); ?></strong>
                <span class="description"><?php esc_html_e('Recommended. Email attempts are still counted and attributed, but no message reaches the mail transport.', 'super-speedy-performance-analysis'); ?></span>
            </label>

            <p>
                <button type="button" class="button button-primary" id="sspa-workflow-run" disabled><?php esc_html_e('Analyse edit/save', 'super-speedy-performance-analysis'); ?></button>
                <span id="sspa-workflow-status" class="description" aria-live="polite"></span>
            </p>
            <p class="description"><?php esc_html_e('The result ends when the measured HTTP request returns. Work executed later by Action Scheduler is intentionally outside this profile and will have its own analysis workflow.', 'super-speedy-performance-analysis'); ?></p>
            <iframe id="sspa-workflow-frame" title="<?php esc_attr_e('Controlled workflow editor', 'super-speedy-performance-analysis'); ?>"></iframe>
        <?php else : ?>
            <p class="description"><?php esc_html_e('No editable public content types are available to this user.', 'super-speedy-performance-analysis'); ?></p>
        <?php endif; ?>
    </section>
</div>
