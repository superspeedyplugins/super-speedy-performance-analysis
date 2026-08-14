<?php
defined('ABSPATH') || exit;

$sspa_traffic_status = SSPA_Traffic_Collection::status();
$sspa_traffic_collection = isset($sspa_traffic_status['collection']) ? $sspa_traffic_status['collection'] : null;
$sspa_traffic_active = !empty($sspa_traffic_status['active']);
$sspa_traffic_has_woo = class_exists('WooCommerce');
?>
<div class="sspa-placeholder sspa-traffic-panel" data-active="<?php echo $sspa_traffic_active ? '1' : '0'; ?>" data-collection-id="<?php echo $sspa_traffic_collection ? (int) $sspa_traffic_collection['id'] : 0; ?>">
    <h2><?php esc_html_e('Traffic collector', 'super-speedy-performance-analysis'); ?> <span class="sspa-status sspa-status-blocked"><?php esc_html_e('Experimental', 'super-speedy-performance-analysis'); ?></span></h2>
    <p><?php esc_html_e('Observe anonymous WooCommerce traffic reaching WordPress, with exact request coverage for logged-in visitors and visitors with non-empty baskets. Broad anonymous origin traffic is sampled.', 'super-speedy-performance-analysis'); ?></p>
    <p class="description"><?php esc_html_e('This is an experimental collector, not the finished Traffic Performance Analysis. The observations download is provided so its measurements, gaps and overhead can be reviewed while the design evolves.', 'super-speedy-performance-analysis'); ?></p>

    <?php if (!$sspa_traffic_has_woo) : ?>
        <div class="notice notice-warning inline"><p><?php esc_html_e('The first collector requires WooCommerce.', 'super-speedy-performance-analysis'); ?></p></div>
    <?php elseif (is_multisite()) : ?>
        <div class="notice notice-warning inline"><p><?php esc_html_e('Traffic collection is deliberately unavailable on multisite until an inactive subsite can remain completely untouched.', 'super-speedy-performance-analysis'); ?></p></div>
    <?php endif; ?>

    <div class="sspa-traffic-status" aria-live="polite">
        <?php if ($sspa_traffic_collection) : ?>
            <h3><?php printf(esc_html__('Collection #%d', 'super-speedy-performance-analysis'), (int) $sspa_traffic_collection['id']); ?></h3>
            <table class="widefat striped sspa-traffic-summary"><tbody>
                <tr><th><?php esc_html_e('State', 'super-speedy-performance-analysis'); ?></th><td data-traffic-value="status"><?php echo esc_html($sspa_traffic_collection['status']); ?></td></tr>
                <tr><th><?php esc_html_e('Started', 'super-speedy-performance-analysis'); ?></th><td data-traffic-value="started_at"><?php echo esc_html($sspa_traffic_collection['started_at'] ?: '—'); ?></td></tr>
                <tr><th><?php esc_html_e('Request collection ends', 'super-speedy-performance-analysis'); ?></th><td data-traffic-value="collect_until"><?php echo esc_html($sspa_traffic_collection['collect_until'] ?: '—'); ?></td></tr>
                <tr><th><?php esc_html_e('Order outcome window ends', 'super-speedy-performance-analysis'); ?></th><td data-traffic-value="outcomes_until"><?php echo esc_html($sspa_traffic_collection['outcomes_until'] ?: '—'); ?></td></tr>
                <tr><th><?php esc_html_e('Event rows', 'super-speedy-performance-analysis'); ?></th><td data-traffic-value="event_count"><?php echo number_format_i18n((int) $sspa_traffic_collection['event_count']); ?></td></tr>
                <tr><th><?php esc_html_e('Event ceiling', 'super-speedy-performance-analysis'); ?></th><td data-traffic-value="event_ceiling"><?php echo number_format_i18n((int) $sspa_traffic_collection['event_ceiling']); ?></td></tr>
                <tr><th><?php esc_html_e('Event table bytes', 'super-speedy-performance-analysis'); ?></th><td data-traffic-value="table_bytes"><?php echo $sspa_traffic_collection['table_bytes'] === null ? esc_html__('Unavailable', 'super-speedy-performance-analysis') : esc_html(size_format((int) $sspa_traffic_collection['table_bytes'])); ?></td></tr>
                <tr><th><?php esc_html_e('Database pre-flight p95', 'super-speedy-performance-analysis'); ?></th><td data-traffic-value="preflight_insert_ms_p95"><?php echo $sspa_traffic_collection['preflight_insert_ms_p95'] === null ? '—' : esc_html($sspa_traffic_collection['preflight_insert_ms_p95'] . ' ms'); ?></td></tr>
                <tr><th><?php esc_html_e('Observer preparation', 'super-speedy-performance-analysis'); ?></th><td data-traffic-value="observer_us_average"><?php echo $sspa_traffic_collection['observer_us_average'] === null ? '—' : esc_html($sspa_traffic_collection['observer_us_average'] . ' µs average'); ?></td></tr>
                <tr><th><?php esc_html_e('Stop reason', 'super-speedy-performance-analysis'); ?></th><td data-traffic-value="stop_reason"><?php echo esc_html($sspa_traffic_collection['stop_reason'] ?: '—'); ?></td></tr>
            </tbody></table>
        <?php else : ?>
            <p><?php esc_html_e('No collection has been started.', 'super-speedy-performance-analysis'); ?></p>
        <?php endif; ?>
    </div>

    <?php if (!$sspa_traffic_active) : ?>
        <h3><?php esc_html_e('Start an experimental collection', 'super-speedy-performance-analysis'); ?></h3>
        <p>
            <label for="sspa-traffic-duration"><?php esc_html_e('Duration', 'super-speedy-performance-analysis'); ?></label>
            <select id="sspa-traffic-duration">
                <option value="24h"><?php esc_html_e('24 hours', 'super-speedy-performance-analysis'); ?></option>
                <option value="72h"><?php esc_html_e('72 hours', 'super-speedy-performance-analysis'); ?></option>
                <option value="7d"><?php esc_html_e('7 days', 'super-speedy-performance-analysis'); ?></option>
            </select>
        </p>
        <p><label><input type="checkbox" id="sspa-traffic-confirm"> <?php esc_html_e('I understand that this writes small anonymous event rows for observed traffic and WooCommerce funnel events until the stated hard limits or stop time.', 'super-speedy-performance-analysis'); ?></label></p>
        <p><button type="button" class="button button-primary" id="sspa-traffic-start" <?php disabled(!$sspa_traffic_has_woo || is_multisite()); ?>><?php esc_html_e('Start collection', 'super-speedy-performance-analysis'); ?></button></p>
    <?php else : ?>
        <p class="sspa-traffic-actions">
            <?php if ('running' === $sspa_traffic_collection['status']) : ?>
                <button type="button" class="button" id="sspa-traffic-stop"><?php esc_html_e('Stop collecting requests', 'super-speedy-performance-analysis'); ?></button>
            <?php endif; ?>
            <button type="button" class="button" id="sspa-traffic-emergency-stop"><?php esc_html_e('Emergency stop', 'super-speedy-performance-analysis'); ?></button>
        </p>
        <p class="description"><?php esc_html_e('A normal stop ends request collection and keeps only the bounded 72-hour order outcome window. Emergency stop removes the observer immediately.', 'super-speedy-performance-analysis'); ?></p>
    <?php endif; ?>

    <?php if ($sspa_traffic_collection) : ?>
        <p><button type="button" class="button" id="sspa-traffic-observations"><?php esc_html_e('Download experimental observations', 'super-speedy-performance-analysis'); ?></button> <span class="sspa-traffic-message description"></span></p>
        <?php if (!$sspa_traffic_active) : ?>
            <p><button type="button" class="button button-link-delete" id="sspa-traffic-delete"><?php esc_html_e('Delete this collection and its raw data', 'super-speedy-performance-analysis'); ?></button></p>
        <?php endif; ?>
    <?php endif; ?>

    <details>
        <summary><?php esc_html_e('Privacy and resource limits', 'super-speedy-performance-analysis'); ?></summary>
        <p><?php esc_html_e('Stored: timestamps, anonymous keyed actor/order/path joins, fixed state and event codes, timings, query counts, aggregate basket/order value and ISO currency.', 'super-speedy-performance-analysis'); ?></p>
        <p><?php esc_html_e('Never stored: names, email or postal addresses, IP addresses, raw cookies, raw user agents, user/session/order/product ids, product names, SKUs, coupon codes, query values, form bodies or response content.', 'super-speedy-performance-analysis'); ?></p>
        <p><?php esc_html_e('Each observed request buffers its events and performs at most one append statement at shutdown. Collection retires itself at its timestamp or event ceiling even when WP-Cron is unavailable.', 'super-speedy-performance-analysis'); ?></p>
        <p><?php esc_html_e('Experimental raw data and its temporary join key are retained after stopping so you can download observations. Use Delete this collection and its raw data when you have finished reviewing it.', 'super-speedy-performance-analysis'); ?></p>
    </details>
</div>
