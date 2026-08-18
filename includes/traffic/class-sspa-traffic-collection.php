<?php
defined('ABSPATH') || exit;

/** Experimental traffic collection lifecycle and privacy-safe observation summaries. */
class SSPA_Traffic_Collection {

    const KEY_PREFIX = 'sspa_traffic_key_';
    const DEFAULT_EVENT_CEILING = 250000;
    const DEFAULT_DISK_CEILING = 33554432;
    const DEFAULT_SAMPLE_MODULUS = 100;
    const OUTCOME_SECONDS = 259200;
    const CONSERVATIVE_EVENT_BYTES = 160;
    const START_LOCK = 'sspa_traffic_start_lock';

    public static function register() {
        add_action('sspa_traffic_collection_tick', array(__CLASS__, 'scheduled_tick'), 10, 1);
    }

    public static function enabled() {
        if (defined('SSPA_TRAFFIC_EXPERIMENTAL') && !SSPA_TRAFFIC_EXPERIMENTAL) {
            return false;
        }
        return (bool) apply_filters('sspa_traffic_experimental_enabled', true);
    }

    public static function durations() {
        return array('24h' => DAY_IN_SECONDS, '72h' => 3 * DAY_IN_SECONDS, '7d' => 7 * DAY_IN_SECONDS);
    }

    public static function start($duration = '24h', $trigger = 'admin') {
        global $wpdb;
        if (!self::enabled()) {
            return new WP_Error('sspa_traffic_disabled', __('The experimental traffic collector is disabled.', 'super-speedy-performance-analysis'));
        }
        if (is_multisite()) {
            return new WP_Error('sspa_traffic_multisite', __('The experimental collector is not available on multisite yet because inactive subsites must remain completely untouched.', 'super-speedy-performance-analysis'));
        }
        if (!class_exists('WooCommerce')) {
            return new WP_Error('sspa_traffic_no_woocommerce', __('The first experimental collector requires WooCommerce.', 'super-speedy-performance-analysis'));
        }
        $durations = self::durations();
        if (!isset($durations[$duration])) {
            return new WP_Error('sspa_traffic_duration', __('Choose 24h, 72h or 7d.', 'super-speedy-performance-analysis'));
        }
        if (!self::start_lock()) {
            return new WP_Error('sspa_traffic_start_busy', __('Another request is starting a traffic collection. Try again.', 'super-speedy-performance-analysis'));
        }
        try {
            return self::start_locked($duration, $trigger, $durations);
        } finally {
            delete_option(self::START_LOCK);
        }
    }

    private static function start_locked($duration, $trigger, $durations) {
        global $wpdb;
        $active = self::active();
        if ($active) {
            $actual_duration = self::timestamp($active['collect_until']) - self::timestamp($active['started_at']);
            if (abs($durations[$duration] - $actual_duration) > 120) {
                return new WP_Error('sspa_traffic_active_conflict', __('A traffic collection is already active with a different duration. Stop it before starting another.', 'super-speedy-performance-analysis'));
            }
            return self::status((int) $active['id']);
        }

        $now = time();
        $collect_until = $now + $durations[$duration];
        $outcomes_until = $collect_until + self::OUTCOME_SECONDS;
        $disk_ceiling = max(1048576, (int) apply_filters('sspa_traffic_disk_ceiling_bytes', self::DEFAULT_DISK_CEILING));
        $configured_event_ceiling = max(100, (int) apply_filters('sspa_traffic_event_ceiling', self::DEFAULT_EVENT_CEILING));
        $event_ceiling = min($configured_event_ceiling, max(100, (int) floor($disk_ceiling / self::CONSERVATIVE_EVENT_BYTES)));
        $sample_modulus = max(1, min(10000, (int) apply_filters('sspa_traffic_origin_sample_modulus', self::DEFAULT_SAMPLE_MODULUS)));
        $table = SSPA_Schema::table('traffic_collections');
        $inserted = $wpdb->insert($table, array(
            'collection_uuid' => wp_generate_uuid4(),
            'blog_id' => get_current_blog_id(),
            'status_code' => SSPA_Traffic_Codes::COLLECTION_PLANNED,
            'collect_until' => gmdate('Y-m-d H:i:s', $collect_until),
            'outcomes_until' => gmdate('Y-m-d H:i:s', $outcomes_until),
            'origin_sample_modulus' => $sample_modulus,
            'event_ceiling' => $event_ceiling,
            'disk_ceiling_bytes' => $disk_ceiling,
            'source_revision' => 0,
            'source_ledger' => wp_json_encode(array(
                'wordpress_origin' => array('available' => true, 'quality' => 'exact_target_and_sampled_anonymous'),
                'woocommerce' => array('available' => true, 'quality' => 'exact_observed_events'),
                'browser' => array('available' => false, 'quality' => 'unavailable'),
                'cloudflare' => array('available' => false, 'quality' => 'unavailable'),
            )),
            'observer_version' => SSPA_Traffic_Codes::OBSERVER_VERSION,
            'created_by' => sanitize_key($trigger),
            'created_at' => gmdate('Y-m-d H:i:s', $now),
        ));
        if (!$inserted) {
            return new WP_Error('sspa_traffic_collection_insert', __('Could not create the traffic collection row.', 'super-speedy-performance-analysis'));
        }
        $collection_id = (int) $wpdb->insert_id;
        $key_option = self::key_option($collection_id);

        try {
            $collection_key = bin2hex(random_bytes(32));
        } catch (Throwable $e) {
            $collection_key = '';
        }
        if (!$collection_key || !add_option($key_option, $collection_key, '', true)) {
            self::rollback_start($collection_id, $key_option);
            return new WP_Error('sspa_traffic_key', __('Could not create the temporary collection key.', 'super-speedy-performance-analysis'));
        }

        $preflight = self::preflight_insert($collection_id);
        if (is_wp_error($preflight)) {
            self::rollback_start($collection_id, $key_option);
            return $preflight;
        }
        if ($preflight > 5.0) {
            self::rollback_start($collection_id, $key_option);
            return new WP_Error('sspa_traffic_insert_slow', sprintf(
                /* translators: %s: measured p95 insert milliseconds */
                __('The database append pre-flight measured %s ms at p95, above the 5 ms safety ceiling. Exact collection was not started.', 'super-speedy-performance-analysis'),
                number_format_i18n($preflight, 2)
            ));
        }

        $events = SSPA_Schema::table('traffic_events');
        $max_id = (int) $wpdb->get_var("SELECT COALESCE(MAX(id),0) FROM $events");
        $event_id_stop = $max_id + $event_ceiling;
        $helper = SSPA_Traffic_Helper::install(array(
            'collection_id' => $collection_id,
            'collect_until' => $collect_until,
            'outcomes_until' => $outcomes_until,
            'event_id_stop' => $event_id_stop,
            'origin_sample_modulus' => $sample_modulus,
            'key_option' => $key_option,
        ));
        if (is_wp_error($helper)) {
            self::rollback_start($collection_id, $key_option);
            return $helper;
        }

        $wpdb->update($table, array(
            'status_code' => SSPA_Traffic_Codes::COLLECTION_RUNNING,
            'started_at' => gmdate('Y-m-d H:i:s', $now),
            'event_id_stop' => $event_id_stop,
            'preflight_insert_ms' => $preflight,
        ), array('id' => $collection_id));
        wp_schedule_single_event($collect_until + 5, 'sspa_traffic_collection_tick', array($collection_id));
        wp_schedule_single_event($outcomes_until + 5, 'sspa_traffic_collection_tick', array($collection_id));
        return self::status($collection_id);
    }

    private static function start_lock() {
        $now = time();
        if (add_option(self::START_LOCK, $now, '', false)) {
            return true;
        }
        $held = (int) get_option(self::START_LOCK);
        if ($held && $held >= $now - 60) {
            return false;
        }
        delete_option(self::START_LOCK);
        return (bool) add_option(self::START_LOCK, $now, '', false);
    }

    private static function preflight_insert($collection_id) {
        global $wpdb;
        $table = SSPA_Schema::table('traffic_events');
        $times = array();
        for ($i = 0; $i < 10; $i++) {
            $started = microtime(true);
            $ok = $wpdb->insert($table, array(
                'collection_id' => (int) $collection_id,
                'observed_at' => time(),
                'event_code' => SSPA_Traffic_Codes::EVENT_REQUEST,
                'actor_state' => SSPA_Traffic_Codes::ACTOR_UNKNOWN,
                'surface_code' => SSPA_Traffic_Codes::SURFACE_WP_ADMIN,
                'page_class' => SSPA_Traffic_Codes::PAGE_UNKNOWN,
                'flags' => SSPA_Traffic_Codes::FLAG_EXCLUDED_ADMIN,
            ));
            $times[] = (microtime(true) - $started) * 1000;
            if (!$ok) {
                $wpdb->delete($table, array('collection_id' => (int) $collection_id));
                return new WP_Error('sspa_traffic_preflight_insert', __('The database append pre-flight failed. Collection was not started.', 'super-speedy-performance-analysis'));
            }
        }
        $wpdb->delete($table, array('collection_id' => (int) $collection_id));
        sort($times, SORT_NUMERIC);
        return round((float) $times[8], 3);
    }

    private static function rollback_start($collection_id, $key_option) {
        global $wpdb;
        SSPA_Traffic_Helper::remove(true);
        delete_option($key_option);
        $wpdb->delete(SSPA_Schema::table('traffic_events'), array('collection_id' => (int) $collection_id));
        $wpdb->delete(SSPA_Schema::table('traffic_collections'), array('id' => (int) $collection_id));
    }

    public static function active() {
        global $wpdb;
        $table = SSPA_Schema::table('traffic_collections');
        $running = implode(',', array(SSPA_Traffic_Codes::COLLECTION_RUNNING, SSPA_Traffic_Codes::COLLECTION_OUTCOME));
        $row = $wpdb->get_row($wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $running is a comma-joined list of integer class constants.
            "SELECT * FROM %i WHERE blog_id = %d AND status_code IN ($running) ORDER BY id DESC LIMIT 1",
            $table,
            (int) get_current_blog_id()
        ), ARRAY_A);
        if ($row) {
            self::reconcile($row);
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $row['id']), ARRAY_A);
            if ($row && !in_array((int) $row['status_code'], array(SSPA_Traffic_Codes::COLLECTION_RUNNING, SSPA_Traffic_Codes::COLLECTION_OUTCOME), true)) {
                $row = null;
            }
        }
        return $row ?: null;
    }

    public static function latest() {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM %i WHERE blog_id = %d ORDER BY id DESC LIMIT 1',
            SSPA_Schema::table('traffic_collections'),
            get_current_blog_id()
        ), ARRAY_A);
    }

    public static function get($collection_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM %i WHERE id = %d AND blog_id = %d',
            SSPA_Schema::table('traffic_collections'),
            (int) $collection_id,
            get_current_blog_id()
        ), ARRAY_A);
        if ($row) {
            self::reconcile($row);
            $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', SSPA_Schema::table('traffic_collections'), (int) $collection_id), ARRAY_A);
        }
        return $row ?: null;
    }

    public static function status($collection_id = 0) {
        global $wpdb;
        $row = $collection_id ? self::get($collection_id) : (self::active() ?: self::latest());
        if (!$row) {
            return array('available' => true, 'active' => false, 'collection' => null);
        }
        $events = SSPA_Schema::table('traffic_events');
        $summary = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) event_count, MIN(observed_at) first_event, MAX(observed_at) last_event, SUM(CASE WHEN event_code = 1 THEN 1 ELSE 0 END) request_count, COALESCE(SUM(CASE WHEN event_code = 1 THEN observer_us ELSE 0 END),0) observer_us_sum, COALESCE(MAX(CASE WHEN event_code = 1 THEN observer_us ELSE NULL END),0) observer_us_max FROM $events WHERE collection_id = %d",
            $row['id']
        ), ARRAY_A);
        $status = SSPA_Traffic_Codes::collection_status($row['status_code']);
        return array(
            'available' => true,
            'active' => in_array($status, array('running', 'outcome'), true),
            'experimental' => true,
            'collection' => array(
                'id' => (int) $row['id'],
                'uuid' => (string) $row['collection_uuid'],
                'status' => $status,
                'started_at' => self::iso($row['started_at']),
                'collect_until' => self::iso($row['collect_until']),
                'outcomes_until' => self::iso($row['outcomes_until']),
                'finished_at' => self::iso($row['finished_at']),
                'seconds_remaining' => max(0, self::timestamp('running' === $status ? $row['collect_until'] : $row['outcomes_until']) - time()),
                'origin_sample_modulus' => (int) $row['origin_sample_modulus'],
                'event_ceiling' => (int) $row['event_ceiling'],
                'event_count' => (int) ($summary['event_count'] ?? 0),
                'disk_ceiling_bytes' => (int) $row['disk_ceiling_bytes'],
                'table_bytes' => self::event_table_bytes(),
                'observer_state' => SSPA_Traffic_Helper::state(),
                'observer_version' => (int) $row['observer_version'],
                'preflight_insert_ms_p95' => $row['preflight_insert_ms'] !== null ? round((float) $row['preflight_insert_ms'], 3) : null,
                'observer_us_average' => !empty($summary['request_count']) ? round((float) $summary['observer_us_sum'] / (int) $summary['request_count'], 1) : null,
                'observer_us_max' => isset($summary['observer_us_max']) ? (int) $summary['observer_us_max'] : null,
                'first_event_at' => !empty($summary['first_event']) ? gmdate('c', (int) $summary['first_event']) : null,
                'last_event_at' => !empty($summary['last_event']) ? gmdate('c', (int) $summary['last_event']) : null,
                'stop_reason' => SSPA_Traffic_Codes::stop_reason($row['stop_reason_code']),
            ),
        );
    }

    public static function stop($collection_id = 0, $emergency = false) {
        global $wpdb;
        $row = $collection_id ? self::get($collection_id) : self::active();
        if (!$row) {
            return new WP_Error('sspa_traffic_no_collection', __('No active traffic collection was found.', 'super-speedy-performance-analysis'));
        }
        $table = SSPA_Schema::table('traffic_collections');
        if ($emergency) {
            SSPA_Traffic_Helper::remove(true);
            $wpdb->update($table, array(
                'status_code' => SSPA_Traffic_Codes::COLLECTION_STOPPED,
                'stop_reason_code' => SSPA_Traffic_Codes::STOP_EMERGENCY,
                'finished_at' => gmdate('Y-m-d H:i:s'),
            ), array('id' => (int) $row['id']));
            return self::status((int) $row['id']);
        }

        if ((int) $row['status_code'] === SSPA_Traffic_Codes::COLLECTION_RUNNING) {
            $now = time();
            $outcomes_until = $now + self::OUTCOME_SECONDS;
            $wpdb->update($table, array(
                'status_code' => SSPA_Traffic_Codes::COLLECTION_OUTCOME,
                'stop_reason_code' => SSPA_Traffic_Codes::STOP_MANUAL,
                'collect_until' => gmdate('Y-m-d H:i:s', $now),
                'outcomes_until' => gmdate('Y-m-d H:i:s', $outcomes_until),
            ), array('id' => (int) $row['id']));
            $helper = SSPA_Traffic_Helper::install(array(
                'collection_id' => (int) $row['id'],
                'collect_until' => $now,
                'outcomes_until' => $outcomes_until,
                'event_id_stop' => (int) $row['event_id_stop'],
                'origin_sample_modulus' => (int) $row['origin_sample_modulus'],
                'key_option' => self::key_option($row['id']),
            ));
            if (is_wp_error($helper)) {
                SSPA_Traffic_Helper::remove(true);
                $wpdb->update($table, array('status_code' => SSPA_Traffic_Codes::COLLECTION_INCOMPLETE), array('id' => (int) $row['id']));
            }
        }
        return self::status((int) $row['id']);
    }

    public static function deactivate() {
        global $wpdb;
        $row = self::active();
        SSPA_Traffic_Helper::remove(true);
        if ($row) {
            $wpdb->update(SSPA_Schema::table('traffic_collections'), array(
                'status_code' => SSPA_Traffic_Codes::COLLECTION_STOPPED,
                'stop_reason_code' => SSPA_Traffic_Codes::STOP_DEACTIVATED,
                'finished_at' => gmdate('Y-m-d H:i:s'),
            ), array('id' => (int) $row['id']));
        }
    }

    public static function delete($collection_id) {
        global $wpdb;
        $row = self::get((int) $collection_id);
        if (!$row) {
            return new WP_Error('sspa_traffic_no_collection', __('No traffic collection was found.', 'super-speedy-performance-analysis'));
        }
        if (in_array((int) $row['status_code'], array(SSPA_Traffic_Codes::COLLECTION_RUNNING, SSPA_Traffic_Codes::COLLECTION_OUTCOME), true)) {
            return new WP_Error('sspa_traffic_delete_active', __('Stop the traffic collection before deleting its data.', 'super-speedy-performance-analysis'));
        }
        $id = (int) $row['id'];
        $deleted = array();
        foreach (array('traffic_reports', 'traffic_actor_work', 'traffic_rollups', 'traffic_events') as $name) {
            $deleted[$name] = (int) $wpdb->delete(SSPA_Schema::table($name), array('collection_id' => $id));
        }
        delete_option(self::key_option($id));
        $deleted['traffic_collections'] = (int) $wpdb->delete(SSPA_Schema::table('traffic_collections'), array('id' => $id));
        return array('deleted' => true, 'collection_id' => $id, 'rows' => $deleted);
    }

    public static function scheduled_tick($collection_id) {
        $row = self::get((int) $collection_id);
        if ($row) {
            self::reconcile($row);
        }
    }

    private static function reconcile($row) {
        global $wpdb;
        $table = SSPA_Schema::table('traffic_collections');
        $status = (int) $row['status_code'];
        $helper_state = SSPA_Traffic_Helper::state();
        if (in_array($status, array(SSPA_Traffic_Codes::COLLECTION_RUNNING, SSPA_Traffic_Codes::COLLECTION_OUTCOME), true)
            && (int) $row['observer_version'] !== SSPA_Traffic_Codes::OBSERVER_VERSION) {
            SSPA_Traffic_Helper::remove(true);
            $wpdb->update($table, array(
                'status_code' => SSPA_Traffic_Codes::COLLECTION_INCOMPLETE,
                'stop_reason_code' => SSPA_Traffic_Codes::STOP_PLUGIN_UPDATE,
                'finished_at' => gmdate('Y-m-d H:i:s'),
            ), array('id' => (int) $row['id']));
            return;
        }
        if (in_array($status, array(SSPA_Traffic_Codes::COLLECTION_RUNNING, SSPA_Traffic_Codes::COLLECTION_OUTCOME), true)
            && in_array($helper_state, array('event_limit', 'database_error'), true)) {
            $wpdb->update($table, array(
                'status_code' => SSPA_Traffic_Codes::COLLECTION_INCOMPLETE,
                'stop_reason_code' => 'event_limit' === $helper_state ? SSPA_Traffic_Codes::STOP_EVENT_LIMIT : SSPA_Traffic_Codes::STOP_DATABASE_ERROR,
                'finished_at' => gmdate('Y-m-d H:i:s'),
            ), array('id' => (int) $row['id']));
            SSPA_Traffic_Helper::remove(true);
            return;
        }
        if (in_array($status, array(SSPA_Traffic_Codes::COLLECTION_RUNNING, SSPA_Traffic_Codes::COLLECTION_OUTCOME), true)
            && 'absent' === $helper_state) {
            $wpdb->update($table, array(
                'status_code' => SSPA_Traffic_Codes::COLLECTION_INCOMPLETE,
                'stop_reason_code' => SSPA_Traffic_Codes::STOP_OBSERVER_MISSING,
                'finished_at' => gmdate('Y-m-d H:i:s'),
            ), array('id' => (int) $row['id']));
            return;
        }
        $event_table_bytes = self::event_table_bytes();
        if (in_array($status, array(SSPA_Traffic_Codes::COLLECTION_RUNNING, SSPA_Traffic_Codes::COLLECTION_OUTCOME), true)
            && $event_table_bytes !== null && $event_table_bytes >= (int) $row['disk_ceiling_bytes']) {
            SSPA_Traffic_Helper::remove(true);
            $wpdb->update($table, array(
                'status_code' => SSPA_Traffic_Codes::COLLECTION_INCOMPLETE,
                'stop_reason_code' => SSPA_Traffic_Codes::STOP_DISK_LIMIT,
                'finished_at' => gmdate('Y-m-d H:i:s'),
            ), array('id' => (int) $row['id']));
            return;
        }
        $now = time();
        if ($now > self::timestamp($row['outcomes_until']) && in_array($status, array(SSPA_Traffic_Codes::COLLECTION_RUNNING, SSPA_Traffic_Codes::COLLECTION_OUTCOME), true)) {
            SSPA_Traffic_Helper::remove(true);
            $wpdb->update($table, array(
                'status_code' => SSPA_Traffic_Codes::COLLECTION_COMPLETE,
                'stop_reason_code' => SSPA_Traffic_Codes::STOP_EXPIRED,
                'finished_at' => gmdate('Y-m-d H:i:s'),
            ), array('id' => (int) $row['id']));
        } elseif ($now > self::timestamp($row['collect_until']) && $status === SSPA_Traffic_Codes::COLLECTION_RUNNING) {
            $wpdb->update($table, array('status_code' => SSPA_Traffic_Codes::COLLECTION_OUTCOME), array('id' => (int) $row['id']));
        }
    }

    public static function observations($collection_id = 0) {
        global $wpdb;
        $status = self::status($collection_id);
        if (empty($status['collection'])) {
            return new WP_Error('sspa_traffic_no_collection', __('No traffic collection was found.', 'super-speedy-performance-analysis'));
        }
        $id = (int) $status['collection']['id'];
        $table = SSPA_Schema::table('traffic_events');
        $event_counts = self::label_counts($wpdb->get_results($wpdb->prepare(
            'SELECT event_code code, COUNT(*) total FROM %i WHERE collection_id = %d GROUP BY event_code',
            $table,
            $id
        ), ARRAY_A), array('SSPA_Traffic_Codes', 'event'));
        $actors = self::label_counts($wpdb->get_results($wpdb->prepare(
            'SELECT actor_state code, COUNT(*) total FROM %i WHERE collection_id = %d AND event_code = 1 GROUP BY actor_state',
            $table,
            $id
        ), ARRAY_A), array('SSPA_Traffic_Codes', 'actor'));
        $surfaces = self::label_counts($wpdb->get_results($wpdb->prepare(
            'SELECT surface_code code, COUNT(*) total FROM %i WHERE collection_id = %d AND event_code = 1 GROUP BY surface_code',
            $table,
            $id
        ), ARRAY_A), array('SSPA_Traffic_Codes', 'surface'));
        $pages = self::label_counts($wpdb->get_results($wpdb->prepare(
            'SELECT page_class code, COUNT(*) total FROM %i WHERE collection_id = %d AND event_code = 1 GROUP BY page_class',
            $table,
            $id
        ), ARRAY_A), array('SSPA_Traffic_Codes', 'page_class'));
        $values = array();
        foreach ($wpdb->get_results($wpdb->prepare(
            "SELECT event_code, currency, COUNT(*) total, SUM(value_minor) value_minor_sum FROM $table WHERE collection_id = %d AND value_minor IS NOT NULL GROUP BY event_code,currency",
            $id
        ), ARRAY_A) as $row) {
            $values[] = array(
                'event' => SSPA_Traffic_Codes::event($row['event_code']),
                'currency' => $row['currency'] !== null ? (string) $row['currency'] : null,
                'events' => (int) $row['total'],
                'value_minor_sum' => (int) $row['value_minor_sum'],
            );
        }
        $cohorts = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(DISTINCT CASE WHEN event_code = 1 AND actor_state IN (3,5) THEN actor_key END) basket_actors,
                COUNT(DISTINCT CASE WHEN event_code = 1 AND actor_state IN (4,5) THEN actor_key END) logged_in_customer_actors,
                COUNT(DISTINCT CASE WHEN event_code = 1 AND actor_state = 6 THEN actor_key END) staff_actors,
                SUM(CASE WHEN event_code = 1 AND actor_state IN (3,5) THEN 1 ELSE 0 END) basket_actor_requests,
                SUM(CASE WHEN event_code = 1 AND actor_state IN (4,5) THEN 1 ELSE 0 END) logged_in_customer_requests,
                SUM(CASE WHEN event_code = 1 AND actor_state = 6 THEN 1 ELSE 0 END) staff_requests
             FROM $table WHERE collection_id = %d",
            $id
        ), ARRAY_A);
        $commerce = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(DISTINCT CASE WHEN event_code = 10 THEN actor_key END) basket_started_actors,
                COUNT(DISTINCT CASE WHEN event_code = 14 AND actor_key NOT IN (SELECT actor_key FROM $table started WHERE started.collection_id = %d AND started.event_code = 10 AND started.actor_key IS NOT NULL) THEN actor_key END) pre_existing_basket_actors,
                COUNT(DISTINCT CASE WHEN event_code = 30 AND (flags & 448) = 0 THEN commerce_key END) shopper_orders_created,
                COUNT(DISTINCT CASE WHEN event_code IN (31,32) AND (flags & 448) = 0 THEN commerce_key END) paid_order_events_observed
             FROM $table WHERE collection_id = %d",
            $id,
            $id
        ), ARRAY_A);
        $linked_paid = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM (
                SELECT commerce_key FROM $table
                WHERE collection_id = %d AND commerce_key IS NOT NULL AND (flags & 448) = 0
                GROUP BY commerce_key
                HAVING SUM(event_code = 30) > 0 AND SUM(event_code IN (31,32)) > 0
            ) linked",
            $id
        ));
        $payload = array(
            'schema' => SSPA_Traffic_Privacy::SCHEMA,
            'generated_at' => gmdate('c'),
            'plugin_version' => SSPA_VERSION,
            'experimental' => true,
            'collection' => $status['collection'],
            'source_ledger' => array(
                'wordpress_origin' => 'exact_target_and_sampled_anonymous',
                'woocommerce' => 'exact_observed_events',
                'browser' => 'unavailable',
                'cloudflare' => 'unavailable',
            ),
            'event_counts' => $event_counts,
            'request_actor_states' => $actors,
            'request_surfaces' => $surfaces,
            'request_page_classes' => $pages,
            'exact_origin_cohorts' => array(
                'quality' => 'exact_for_observed_origin_requests',
                'distinct_non_empty_basket_actors' => (int) ($cohorts['basket_actors'] ?? 0),
                'non_empty_basket_requests' => (int) ($cohorts['basket_actor_requests'] ?? 0),
                'distinct_logged_in_customer_actors' => (int) ($cohorts['logged_in_customer_actors'] ?? 0),
                'logged_in_customer_requests' => (int) ($cohorts['logged_in_customer_requests'] ?? 0),
                'distinct_staff_actors' => (int) ($cohorts['staff_actors'] ?? 0),
                'staff_requests' => (int) ($cohorts['staff_requests'] ?? 0),
            ),
            'woocommerce_observations' => array(
                'quality' => 'exact_observed_events_before_actor_finalisation',
                'distinct_basket_started_actors' => (int) ($commerce['basket_started_actors'] ?? 0),
                'distinct_pre_existing_basket_actors' => (int) ($commerce['pre_existing_basket_actors'] ?? 0),
                'distinct_shopper_orders_created' => (int) ($commerce['shopper_orders_created'] ?? 0),
                'distinct_paid_order_events_observed' => (int) ($commerce['paid_order_events_observed'] ?? 0),
                'distinct_created_orders_with_observed_paid_event' => $linked_paid,
            ),
            'commercial_value_observations' => $values,
            'limitations' => array(
                'experimental_phase_3_observations_not_a_traffic_performance_report',
                'anonymous_origin_requests_are_sampled',
                'edge_cache_hits_are_not_visible',
                'open_baskets_are_not_yet_finalised',
                'actor_and_order_joins_are_not_exported',
            ),
        );
        $problems = SSPA_Traffic_Privacy::validate_export($payload);
        if ($problems) {
            return new WP_Error('sspa_traffic_privacy', __('The experimental observation export failed its privacy allowlist.', 'super-speedy-performance-analysis'), $problems);
        }
        return $payload;
    }

    /**
     * Turn "code => count" rows into labelled counts.
     *
     * Takes the rows rather than the SQL: each caller now prepares its own literal query,
     * which is what lets the table name go through %i instead of being interpolated.
     */
    private static function label_counts($rows, $label_callback) {
        $out = array();
        foreach ((array) $rows as $row) {
            $out[] = array('label' => call_user_func($label_callback, $row['code']), 'count' => (int) $row['total']);
        }
        return $out;
    }

    private static function event_table_bytes() {
        global $wpdb;
        $table = SSPA_Schema::table('traffic_events');
        $bytes = $wpdb->get_var($wpdb->prepare(
            'SELECT COALESCE(DATA_LENGTH + INDEX_LENGTH,0) FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
            DB_NAME,
            $table
        ));
        return $bytes !== null ? (int) $bytes : null;
    }

    public static function key_option($collection_id) {
        return self::KEY_PREFIX . (int) get_current_blog_id() . '_' . (int) $collection_id;
    }

    private static function timestamp($mysql_gmt) {
        return $mysql_gmt ? (int) strtotime($mysql_gmt . ' UTC') : 0;
    }

    private static function iso($mysql_gmt) {
        $timestamp = self::timestamp($mysql_gmt);
        return $timestamp ? gmdate('c', $timestamp) : null;
    }
}
