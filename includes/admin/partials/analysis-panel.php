<?php
defined('ABSPATH') || exit;
?>
<div class="sspa-placeholder" id="sspa-run-panel"
     data-active-run="<?php echo esc_attr($sspa_active_run); ?>">
    <h2><?php esc_html_e('Analysis', 'super-speedy-performance-analysis'); ?></h2>

    <?php if ($sspa_last_run) : ?>
        <p>
            <?php
            printf(
                /* translators: 1: date, 2: page count */
                esc_html__('Last analysis: %1$s (%2$d pages profiled).', 'super-speedy-performance-analysis'),
                esc_html(get_date_from_gmt($sspa_last_run['finished'], get_option('date_format') . ' ' . get_option('time_format'))),
                (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE run_id = %d', SSPA_Schema::table('profiles'), $sspa_last_run['id']))
            );
            ?>
            <?php esc_html_e('See the Pages tab for results.', 'super-speedy-performance-analysis'); ?>
        </p>
        <p>
            <button type="button" class="button sspa-markdown-download" data-kind="run" data-id="<?php echo (int) $sspa_last_run['id']; ?>"><?php esc_html_e('Download Markdown', 'super-speedy-performance-analysis'); ?></button>
            <button type="button" class="button sspa-markdown-copy" data-kind="run" data-id="<?php echo (int) $sspa_last_run['id']; ?>"><?php esc_html_e('Copy Markdown', 'super-speedy-performance-analysis'); ?></button>
            <span class="description sspa-markdown-status" aria-live="polite"></span>
        </p>
    <?php else : ?>
        <p><?php esc_html_e('Run your first analysis to profile your key pages and see which plugins are costing you SQL time, RAM and page speed. The first run is read-only and non-destructive: it sends normal page requests to your own site (roughly four per page profiled).', 'super-speedy-performance-analysis'); ?></p>
    <?php endif; ?>

    <?php if ($sspa_foreign_dropin) : ?>
        <div class="notice notice-warning inline">
            <p>
                <strong><?php esc_html_e('Before you run:', 'super-speedy-performance-analysis'); ?></strong>
                <?php
                printf(
                    /* translators: %s: plugin owning db.php */
                    esc_html__('%s owns your database drop-in (wp-content/db.php). You can run degraded (no per-query row counts), or temporarily swap it for ours during the run. The swap disables that plugin\'s database layer SITE-WIDE for the duration - only choose it at a low-traffic time. It is restored automatically the moment the run finishes (or fails).', 'super-speedy-performance-analysis'),
                    esc_html($sspa_health['dropin_owner'])
                );
                ?>
            </p>
            <p>
                <label>
                    <input type="checkbox" id="sspa-swap-dropin" value="1">
                    <?php esc_html_e('Temporarily swap db.php for this run (I understand the warning above)', 'super-speedy-performance-analysis'); ?>
                </label>
            </p>
        </div>
    <?php endif; ?>

    <p>
        <label style="margin-right:1em">
            <input type="checkbox" id="sspa-include-writes" value="1">
            <?php esc_html_e('Include write profiles (saves a TEMPORARY copy of a post/product and steps a TEMPORARY order through processing - created and deleted automatically, no real content touched, no emails sent)', 'super-speedy-performance-analysis'); ?>
        </label>
    </p>
    <p>
        <button type="button" class="button button-primary" id="sspa-run-analysis"<?php disabled((bool) $sspa_active_run); ?>>
            <?php esc_html_e('Run Analysis', 'super-speedy-performance-analysis'); ?>
        </button>
        <?php if ($sspa_last_run) : ?>
        <button type="button" class="button" id="sspa-run-deep"<?php disabled((bool) $sspa_active_run); ?>>
            <?php esc_html_e('Run Deep Impact Scan', 'super-speedy-performance-analysis'); ?>
        </button>
        <?php if (wp_using_ext_object_cache() || file_exists(WP_CONTENT_DIR . '/object-cache.php')) : ?>
        <button type="button" class="button" id="sspa-run-cache"<?php disabled((bool) $sspa_active_run); ?>>
            <?php esc_html_e('Run Cache Impact Analysis', 'super-speedy-performance-analysis'); ?>
        </button>
        <?php endif; ?>
        <?php endif; ?>
        <button type="button" class="button" id="sspa-cancel-run" <?php echo $sspa_active_run ? '' : 'style="display:none"'; ?>>
            <?php esc_html_e('Cancel', 'super-speedy-performance-analysis'); ?>
        </button>
    </p>
    <?php if ($sspa_last_run) : ?>
    <p class="description">
        <?php esc_html_e('The Deep Impact Scan opens a chooser first: nothing is measured until you pick the plugins yourself, and the number of measurements and a time estimate are shown before anything starts. Each chosen plugin is then re-measured with that plugin excluded FOR THE TEST REQUESTS ONLY - your visitors always get the full site, no plugins are ever really deactivated, and nothing fires activation or deactivation hooks. Choosing every plugin runs the two-phase sweep: a cheap screen of each plugin on its busiest pages first, then the full treatment only for the plugins that showed a measurable impact. Start it, walk away - the floating monitor shows exactly where it is up to. Keeping this tab open (minimised is fine) is fastest; if you close it, WP-Cron continues the run in the background as your site receives traffic.', 'super-speedy-performance-analysis'); ?>
    </p>
    <?php endif; ?>
</div>
