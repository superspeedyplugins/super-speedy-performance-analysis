<?php
defined('ABSPATH') || exit;

$sspa_traffic_status = SSPA_Traffic_Collection::status();
$sspa_traffic_collection = isset($sspa_traffic_status['collection']) ? $sspa_traffic_status['collection'] : null;
$sspa_traffic_active = !empty($sspa_traffic_status['active']);
$sspa_traffic_has_woo = class_exists('WooCommerce');
$sspa_endpoint_evidence = $sspa_traffic_collection && method_exists('SSPA_Report', 'endpoint_evidence')
    ? SSPA_Report::endpoint_evidence((int) $sspa_traffic_collection['id'])
    : null;
?>
<div class="sspa-placeholder sspa-traffic-panel" data-active="<?php echo $sspa_traffic_active ? '1' : '0'; ?>" data-collection-id="<?php echo $sspa_traffic_collection ? (int) $sspa_traffic_collection['id'] : 0; ?>">
    <h2><?php esc_html_e('Traffic collector', 'super-speedy-performance-analysis'); ?> <span class="sspa-status sspa-status-blocked"><?php esc_html_e('Experimental', 'super-speedy-performance-analysis'); ?></span></h2>
    <p><?php esc_html_e('Observe the anonymous traffic reaching WordPress, with exact request coverage for logged-in visitors and, on a shop, visitors with non-empty baskets. Broad anonymous origin traffic is sampled.', 'super-speedy-performance-analysis'); ?></p>
    <p class="description"><?php esc_html_e('This is an experimental collector, not the finished Traffic Performance Analysis. The observations download is provided so its measurements, gaps and overhead can be reviewed while the design evolves.', 'super-speedy-performance-analysis'); ?></p>

    <?php if (!$sspa_traffic_has_woo) : ?>
        <?php // Not a blocker. Only the commerce funnel needs WooCommerce; requests, timings,
              // cohorts and automation classification are collected on any WordPress site. ?>
        <div class="notice notice-info inline"><p><?php esc_html_e('WooCommerce is not active on this site, so the commerce funnel - baskets, orders and revenue exposure - will be empty. Everything else is collected as normal.', 'super-speedy-performance-analysis'); ?></p></div>
    <?php endif; ?>
    <?php if (is_multisite()) : ?>
        <div class="notice notice-warning inline"><p><?php esc_html_e('Traffic collection is deliberately unavailable on multisite until an inactive subsite can remain completely untouched.', 'super-speedy-performance-analysis'); ?></p></div>
    <?php endif; ?>

    <div class="sspa-traffic-status" aria-live="polite">
        <?php if ($sspa_traffic_collection) : ?>
            <h3><?php /* translators: %d: the traffic collection id */ printf(esc_html__('Collection #%d', 'super-speedy-performance-analysis'), (int) $sspa_traffic_collection['id']); ?></h3>
            <table class="widefat striped sspa-traffic-summary"><tbody>
                <tr><th><?php esc_html_e('State', 'super-speedy-performance-analysis'); ?></th><td data-traffic-value="status"><?php echo esc_html($sspa_traffic_collection['status']); ?></td></tr>
                <tr><th><?php esc_html_e('Started', 'super-speedy-performance-analysis'); ?></th><td data-traffic-value="started_at"><?php echo esc_html($sspa_traffic_collection['started_at'] ?: '—'); ?></td></tr>
                <tr><th><?php esc_html_e('Request collection ends', 'super-speedy-performance-analysis'); ?></th><td data-traffic-value="collect_until"><?php echo esc_html($sspa_traffic_collection['collect_until'] ?: '—'); ?></td></tr>
                <tr><th><?php esc_html_e('Order outcome window ends', 'super-speedy-performance-analysis'); ?></th><td data-traffic-value="outcomes_until"><?php echo esc_html($sspa_traffic_collection['outcomes_until'] ?: '—'); ?></td></tr>
                <tr><th><?php esc_html_e('Event rows', 'super-speedy-performance-analysis'); ?></th><td data-traffic-value="event_count"><?php echo esc_html(number_format_i18n((int) $sspa_traffic_collection['event_count'])); ?></td></tr>
                <tr><th><?php esc_html_e('Event ceiling', 'super-speedy-performance-analysis'); ?></th><td data-traffic-value="event_ceiling"><?php echo esc_html(number_format_i18n((int) $sspa_traffic_collection['event_ceiling'])); ?></td></tr>
                <tr><th><?php esc_html_e('Event table bytes', 'super-speedy-performance-analysis'); ?></th><td data-traffic-value="table_bytes"><?php echo $sspa_traffic_collection['table_bytes'] === null ? esc_html__('Unavailable', 'super-speedy-performance-analysis') : esc_html(size_format((int) $sspa_traffic_collection['table_bytes'])); ?></td></tr>
                <tr><th><?php esc_html_e('Database pre-flight p95', 'super-speedy-performance-analysis'); ?></th><td data-traffic-value="preflight_insert_ms_p95"><?php echo $sspa_traffic_collection['preflight_insert_ms_p95'] === null ? '—' : esc_html($sspa_traffic_collection['preflight_insert_ms_p95'] . ' ms'); ?></td></tr>
                <tr><th><?php esc_html_e('Observer preparation', 'super-speedy-performance-analysis'); ?></th><td data-traffic-value="observer_us_average"><?php echo $sspa_traffic_collection['observer_us_average'] === null ? '—' : esc_html($sspa_traffic_collection['observer_us_average'] . ' µs average'); ?></td></tr>
                <tr><th><?php esc_html_e('Stop reason', 'super-speedy-performance-analysis'); ?></th><td data-traffic-value="stop_reason"><?php echo esc_html($sspa_traffic_collection['stop_reason'] ?: '—'); ?></td></tr>
            </tbody></table>
        <?php else : ?>
            <p><?php esc_html_e('No collection has been started.', 'super-speedy-performance-analysis'); ?></p>
        <?php endif; ?>
    </div>

    <?php if (is_array($sspa_endpoint_evidence)) : ?>
        <h3 id="sspa-endpoint-evidence"><?php esc_html_e('AJAX and REST endpoint evidence', 'super-speedy-performance-analysis'); ?></h3>
        <p class="description"><?php esc_html_e('Passive evidence from exact registered endpoints in this bounded collection. It ranks measured opportunity; it does not replay requests or automatically decide which plugins are safe to remove.', 'super-speedy-performance-analysis'); ?></p>
        <?php if (empty($sspa_endpoint_evidence['endpoints'])) : ?>
            <p><?php esc_html_e('No registered AJAX, WooCommerce AJAX or REST endpoint has been observed in this collection.', 'super-speedy-performance-analysis'); ?></p>
        <?php else : ?>
            <table class="widefat striped sspa-endpoint-evidence-table">
                <thead><tr>
                    <th><?php esc_html_e('Endpoint', 'super-speedy-performance-analysis'); ?></th>
                    <th><?php esc_html_e('Requests', 'super-speedy-performance-analysis'); ?></th>
                    <th><?php esc_html_e('Wall time', 'super-speedy-performance-analysis'); ?></th>
                    <th><?php esc_html_e('Queries', 'super-speedy-performance-analysis'); ?></th>
                    <th><?php esc_html_e('Responses', 'super-speedy-performance-analysis'); ?></th>
                    <th><?php esc_html_e('Plugin evidence', 'super-speedy-performance-analysis'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($sspa_endpoint_evidence['endpoints'] as $sspa_endpoint) :
                    $identity = isset($sspa_endpoint['identity']) ? $sspa_endpoint['identity'] : array();
                    $observations = isset($sspa_endpoint['observations']) ? $sspa_endpoint['observations'] : array();
                    $wall = isset($observations['whole_request_wall_ms']) ? $observations['whole_request_wall_ms'] : array();
                    $queries = isset($observations['query_count']) ? $observations['query_count'] : array();
                    $observer = isset($observations['observer_overhead_us']) ? $observations['observer_overhead_us'] : array();
                    $statuses = isset($observations['status_counts']) ? $observations['status_counts'] : array();
                    $endpoint_name = !empty($identity['route_pattern']) ? $identity['route_pattern'] : (isset($identity['action']) ? $identity['action'] : '');
                    $status_parts = array();
                    foreach (array('2xx', '3xx', '4xx', '5xx', 'other') as $status_class) {
                        if (!empty($statuses[$status_class])) { $status_parts[] = $status_class . ': ' . (int) $statuses[$status_class]; }
                    }
                    ?>
                    <tr>
                        <td><code><?php echo esc_html($endpoint_name); ?></code><br><span class="description"><?php echo esc_html(implode(' · ', array_filter(array(isset($identity['transport']) ? str_replace('_', '-', $identity['transport']) : '', isset($identity['method']) ? $identity['method'] : '', isset($identity['auth_context']) ? str_replace('_', ' ', $identity['auth_context']) : '')))); ?></span></td>
                        <td><?php echo esc_html(number_format_i18n(isset($observations['count']) ? (int) $observations['count'] : 0)); ?></td>
                        <td><?php /* translators: 1: median milliseconds, 2: p95 milliseconds, 3: total milliseconds */ printf(esc_html__('median %1$s ms; p95 %2$s ms; total %3$s ms', 'super-speedy-performance-analysis'), esc_html(isset($wall['median']) ? $wall['median'] : '—'), esc_html(isset($wall['p95']) ? $wall['p95'] : '—'), esc_html(isset($wall['sum']) ? $wall['sum'] : '—')); ?></td>
                        <td><?php /* translators: 1: median query count, 2: p95 query count */ printf(esc_html__('median %1$s; p95 %2$s', 'super-speedy-performance-analysis'), esc_html(isset($queries['median']) ? $queries['median'] : '—'), esc_html(isset($queries['p95']) ? $queries['p95'] : '—')); ?></td>
                        <td><?php echo esc_html($status_parts ? implode('; ', $status_parts) : '—'); ?></td>
                        <td><?php echo 'unknown' === (isset($sspa_endpoint['quality']['activity']) ? $sspa_endpoint['quality']['activity'] : '') ? esc_html__('Unknown — detailed activity was not sampled.', 'super-speedy-performance-analysis') : esc_html__('Measured', 'super-speedy-performance-analysis'); ?><br><span class="description"><?php /* translators: %s: observer overhead p95 in microseconds */ printf(esc_html__('Observer overhead p95: %s µs', 'super-speedy-performance-analysis'), esc_html(isset($observer['p95']) ? $observer['p95'] : '—')); ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (!$sspa_traffic_active) : ?>
        <h3><?php esc_html_e('Start an experimental collection', 'super-speedy-performance-analysis'); ?></h3>
        <p>
            <label for="sspa-traffic-duration"><?php esc_html_e('Duration', 'super-speedy-performance-analysis'); ?></label>
            <select id="sspa-traffic-duration">
                <option value="1h"><?php esc_html_e('1 hour', 'super-speedy-performance-analysis'); ?></option>
                <option value="2h"><?php esc_html_e('2 hours', 'super-speedy-performance-analysis'); ?></option>
                <option value="4h"><?php esc_html_e('4 hours', 'super-speedy-performance-analysis'); ?></option>
                <option value="24h" selected><?php esc_html_e('24 hours', 'super-speedy-performance-analysis'); ?></option>
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
