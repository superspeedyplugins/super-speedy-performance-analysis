<?php
defined('ABSPATH') || exit;

/**
 * Compatibility facade for the Share UI and agent ability.
 *
 * Payload creation and transport are deliberately separate: submit() stores immutable bytes
 * locally and schedules the cron worker. It never performs network I/O in an admin request.
 */
class SSPA_Submitter {

    /**
     * Legacy content/rules hub URL. Community submissions use collector_url() instead.
     */
    public static function hub_url() {
        $url = get_option('sspa_hub_url');
        return $url ? untrailingslashit($url) : 'https://superspeedy.org';
    }

    /**
     * Retained for the independently signed rules feed.
     */
    public static function endpoint($path) {
        return self::hub_url() . '/?rest_route=/ssph/v1/' . ltrim($path, '/');
    }

    public static function collector_url() {
        return SSPA_Community_Identity::collector_url();
    }

    public static function opted_in() {
        return (bool) get_option('sspa_share_optin');
    }

    public static function register() {
        return SSPA_Community_Client::register();
    }

    /**
     * Queue the newest shareable run and nudge asynchronous delivery.
     *
     * @return true|WP_Error
     */
    public static function submit() {
        if (!self::opted_in()) {
            return new WP_Error('sspa_not_opted_in', __('Sharing is not enabled - opt in on the Share tab first.', 'super-speedy-performance-analysis'));
        }
        $row = SSPA_Community_Outbox::queue_latest();
        if (is_wp_error($row)) {
            return $row;
        }
        SSPA_Community_Outbox::nudge();
        return true;
    }

    public static function preview($outbox_id = 0) {
        $row = $outbox_id ? SSPA_Community_Outbox::get((int) $outbox_id) : SSPA_Community_Outbox::latest();
        if (!$row) {
            // Nothing queued: show what WOULD be sent for the latest shareable run, without
            // queueing it. "Turn it on and then look at what you agreed to" is the wrong order
            // for a consent decision.
            return self::dry_run_preview();
        }
        return SSPA_Community_Outbox::preview($row);
    }

    /**
     * Build the payload for the newest shareable run purely to show it. Nothing is stored,
     * nothing is queued and nothing is sent.
     */
    public static function dry_run_preview() {
        global $wpdb;
        $run_id = (int) $wpdb->get_var(
            'SELECT id FROM ' . SSPA_Schema::table('runs') . "
             WHERE status = 'done' OR (run_type = 'checkout' AND status = 'failed')
             ORDER BY id DESC LIMIT 1"
        );
        if (!$run_id) {
            return new WP_Error('sspa_no_run', __('Run an analysis first - there is nothing to preview yet.', 'super-speedy-performance-analysis'));
        }
        $payload = SSPA_Community_Exporter::build($run_id);
        if (is_wp_error($payload)) {
            return $payload;
        }
        $json = SSPA_Community_Schema::encode($payload);
        return is_wp_error($json) ? $json : $json;
    }

    /**
     * A plain-English account of one payload, for people who do not read JSON - which is most
     * people, and exactly the people a privacy promise has to convince.
     */
    public static function describe_payload($json) {
        $payload = json_decode((string) $json, true);
        if (!is_array($payload)) {
            return array();
        }
        $counts = array();
        foreach ((array) (isset($payload['evidence']) ? $payload['evidence'] : array()) as $item) {
            $type = isset($item['type']) ? $item['type'] : 'unknown';
            $counts[$type] = isset($counts[$type]) ? $counts[$type] + 1 : 1;
        }
        $labels = array(
            'sspa/site-snapshot' => __('site summary (WordPress, PHP and database versions, bucketed size)', 'super-speedy-performance-analysis'),
            'sspa/page-profile' => __('page timings', 'super-speedy-performance-analysis'),
            'sspa/component-observation' => __('per-plugin measurements', 'super-speedy-performance-analysis'),
            'sspa/excimer-profile' => __('function-level samples', 'super-speedy-performance-analysis'),
            'sspa/finding' => __('findings', 'super-speedy-performance-analysis'),
            'sspa/plugin-impact' => __('plugin impact measurements', 'super-speedy-performance-analysis'),
            'sspa/cache-impact' => __('object cache measurements', 'super-speedy-performance-analysis'),
            'sspa/checkout-flow' => __('checkout step timings', 'super-speedy-performance-analysis'),
            'sspa/plugin-toggle-spot' => __('plugin activation spot checks', 'super-speedy-performance-analysis'),
            'sspa/adhoc-page-profile' => __('single page timings', 'super-speedy-performance-analysis'),
            'sspa/component-state' => __('performance settings published by plugins that opted in', 'super-speedy-performance-analysis'),
        );
        $includes = array();
        foreach ($counts as $type => $n) {
            $includes[] = sprintf('%d %s', $n, isset($labels[$type]) ? $labels[$type] : $type);
        }
        // Which plugins chose to publish their own settings, named, so the answer to "whose
        // settings are in here" does not require reading the JSON.
        // Named by the label the plugin gave itself where there is one, because "scalability-pro"
        // is our vocabulary and "Scalability Pro" is the customer's.
        $labels = array();
        if (class_exists('SSPA_Community_State')) {
            foreach (SSPA_Community_State::publishers() as $publisher) {
                $labels[$publisher['slug']] = $publisher['label'];
            }
        }
        $state_components = array();
        foreach ((array) (isset($payload['evidence']) ? $payload['evidence'] : array()) as $item) {
            if (isset($item['type']) && 'sspa/component-state' === $item['type'] && !empty($item['data']['component']['slug'])) {
                $slug = (string) $item['data']['component']['slug'];
                $state_components[] = isset($labels[$slug]) ? $labels[$slug] : $slug;
            }
        }
        $state_components = array_values(array_unique($state_components));

        return array(
            'run_type' => isset($payload['run']['run_type']) ? $payload['run']['run_type'] : '',
            'components' => count((array) (isset($payload['component_inventory']) ? $payload['component_inventory'] : array())),
            'includes' => $includes,
            'state_components' => $state_components,
            'excludes' => array(
                __('your domain name and every URL', 'super-speedy-performance-analysis'),
                __('filesystem paths', 'super-speedy-performance-analysis'),
                __('raw SQL - queries are reduced to a shape with all values removed', 'super-speedy-performance-analysis'),
                __('any customer, order, user or email data', 'super-speedy-performance-analysis'),
                // The narrower promise. Until 0.20.0 this said "option and setting values" with
                // no exception, and that was true. A plugin can now publish its own performance
                // settings by registering the `sspa_component_state` filter, and only those
                // appear - this plugin never reads another plugin's options itself, so a plugin
                // that has not opted in still contributes nothing.
                __('option and setting values, except the performance settings a plugin publishes about itself - listed in the preview below', 'super-speedy-performance-analysis'),
            ),
        );
    }

    public static function history() {
        $history = array();
        foreach (SSPA_Community_Outbox::history(20) as $row) {
            $history[] = array(
                'id' => (int) $row['id'],
                'time' => $row['sent_at'] ?: ($row['last_attempt'] ?: $row['created']),
                'ok' => ('sent' === $row['state']),
                'state' => $row['state'],
                'phase' => $row['phase'],
                'run_type' => $row['run_type'],
                'run_started' => $row['run_started'],
                'message' => self::history_message($row),
                'compressed_bytes' => (int) $row['compressed_bytes'],
                'payload_schema' => (int) $row['payload_schema_major'] . '.' . (int) $row['payload_schema_minor'],
                'payload_sha256' => $row['payload_sha256'],
                'receipt_uuid' => $row['receipt_uuid'],
                'next_attempt' => $row['next_attempt'],
                'last_error_code' => $row['last_error_code'],
                'last_error_detail' => $row['last_error_detail'],
            );
        }
        return $history;
    }

    private static function history_message($row) {
        if ('sent' === $row['state']) {
            return sprintf(__('%1$s archived (%2$s)', 'super-speedy-performance-analysis'), $row['run_type'], size_format((int) $row['compressed_bytes']));
        }
        if ('permanent_failure' === $row['state']) {
            return sprintf(__('%1$s requires attention: %2$s', 'super-speedy-performance-analysis'), $row['run_type'], $row['last_error_code']);
        }
        if ('retry' === $row['state']) {
            return sprintf(__('%1$s queued for retry: %2$s', 'super-speedy-performance-analysis'), $row['run_type'], $row['last_error_code']);
        }
        if ('cancelled' === $row['state']) {
            return sprintf(__('%s paused locally', 'super-speedy-performance-analysis'), $row['run_type']);
        }
        return sprintf(__('%s queued locally', 'super-speedy-performance-analysis'), $row['run_type']);
    }
}
