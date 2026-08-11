<?php
defined('ABSPATH') || exit;

$sspa_loopback_timeout = SSPA_Crawler::sanitise_loopback_timeout(sspa_get_option('loopback_timeout'));
?>
<h3><?php esc_html_e('Measurement timeout', 'super-speedy-performance-analysis'); ?></h3>
<p class="description" style="max-width:56em">
    <?php esc_html_e('How long each measurement request may wait for a page before giving up, in seconds. Every measurement is its own request - a warm-up plus three samples - and each one gets this budget.', 'super-speedy-performance-analysis'); ?>
</p>
<p class="description" style="max-width:56em">
    <?php esc_html_e('Raise it when the pages you are diagnosing take longer than a minute to load: a page slower than this limit records nothing at all, and the slowest pages are exactly the ones worth measuring. Whatever server or proxy timeouts you raised to load the page in your own browser need to cover this number too, and PHP\'s max_execution_time should comfortably exceed FOUR times it, because one background worker makes the warm-up and samples back to back.', 'super-speedy-performance-analysis'); ?>
</p>
<p>
    <label>
        <input type="number" id="sspa-loopback-timeout" min="10" max="900" step="1"
               value="<?php echo esc_attr($sspa_loopback_timeout); ?>" style="width:6em">
        <?php esc_html_e('seconds (10-900, default 60)', 'super-speedy-performance-analysis'); ?>
    </label>
    <button type="button" class="button button-primary" id="sspa-save-settings" style="margin-left:8px">
        <?php esc_html_e('Save', 'super-speedy-performance-analysis'); ?>
    </button>
    <span id="sspa-settings-saved" class="description" style="margin-left:8px"></span>
</p>
