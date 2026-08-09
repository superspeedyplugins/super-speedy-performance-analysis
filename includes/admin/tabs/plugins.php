<?php
defined('ABSPATH') || exit;

$sspa_last_run_id = SSPA_Plugins_Table::latest_run_id();

if (!$sspa_last_run_id) : ?>
    <div class="sspa-placeholder">
        <p><?php esc_html_e('Per-plugin costs will appear here after your first analysis.', 'super-speedy-performance-analysis'); ?></p>
    </div>
<?php else :
    // Attribution mode is a read-only view switch (no state written, nothing recomputed on
    // the stored numbers), so a plain query arg is enough to deep-link one - no nonce, no
    // option. Switching it in the page is an AJAX table swap, like every other control here.
    $sspa_attrib_mode = SSPA_Attribution::sanitise_mode(
        isset($_GET['attrib']) ? sanitize_key(wp_unslash($_GET['attrib'])) : ''
    );
    ?>
    <div class="sspa-attrib-switch" style="margin:0 0 12px;">
        <strong><?php esc_html_e('Attribution:', 'super-speedy-performance-analysis'); ?></strong>
        <?php foreach (SSPA_Attribution::modes() as $sspa_mode_key => $sspa_mode_label) :
            $sspa_is_on = ($sspa_mode_key === $sspa_attrib_mode); ?>
            <button type="button"
                    class="button button-small sspa-attrib-mode<?php echo $sspa_is_on ? ' button-primary' : ''; ?>"
                    data-mode="<?php echo esc_attr($sspa_mode_key); ?>"
                    aria-pressed="<?php echo $sspa_is_on ? 'true' : 'false'; ?>">
                <?php echo esc_html($sspa_mode_label); ?>
            </button>
        <?php endforeach; ?>
        <p class="description">
            <span id="sspa-attrib-describe"><?php echo esc_html(SSPA_Attribution::describe($sspa_attrib_mode)); ?></span>
            <?php esc_html_e('Measured impact below is unaffected: it comes from disabling the plugin and re-measuring, so it does not depend on attribution at all.', 'super-speedy-performance-analysis'); ?>
        </p>
    </div>

    <p class="description">
        <?php esc_html_e('Attribution totals come from the last analysis (all profiled pages). Measured impact comes from Deep Analysis: every page re-measured with the plugin virtually disabled, in every cache mode. "adds" means the plugin costs you that much page-generation time; "saves" means pages got SLOWER without it - the plugin is speeding your site up. Click "per-page breakdown" for the full picture.', 'super-speedy-performance-analysis'); ?>
    </p>
    <p class="description">
        <?php esc_html_e('Careful with the SQL/query columns: they credit work to whichever component runs it. A plugin that REPLACES a slow feature (search, filtering) will show the queries it runs even when it is far faster than what it replaced - the measured impact is the true verdict on whether it costs or saves you time.', 'super-speedy-performance-analysis'); ?>
    </p>
    <div id="sspa-attrib-wrap">
        <?php echo SSPA_Plugins_Table::render($sspa_last_run_id, $sspa_attrib_mode); // phpcs:ignore WordPress.Security.EscapeOutput ?>
    </div>
<?php endif; ?>
