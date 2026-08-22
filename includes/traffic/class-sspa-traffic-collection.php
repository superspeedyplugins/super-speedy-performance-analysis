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
        return array(
            '1h' => HOUR_IN_SECONDS,
            '2h' => 2 * HOUR_IN_SECONDS,
            '4h' => 4 * HOUR_IN_SECONDS,
            '24h' => DAY_IN_SECONDS,
            '72h' => 3 * DAY_IN_SECONDS,
            '7d' => 7 * DAY_IN_SECONDS,
        );
    }

    public static function start($duration = '24h', $trigger = 'admin') {
        global $wpdb;
        if (!self::enabled()) {
            return new WP_Error('sspa_traffic_disabled', __('The experimental traffic collector is disabled.', 'super-speedy-performance-analysis'));
        }
        if (is_multisite()) {
            return new WP_Error('sspa_traffic_multisite', __('The experimental collector is not available on multisite yet because inactive subsites must remain completely untouched.', 'super-speedy-performance-analysis'));
        }
        $durations = self::durations();
        if (!isset($durations[$duration])) {
            return new WP_Error('sspa_traffic_duration', __('Choose 1h, 2h, 4h, 24h, 72h or 7d.', 'super-speedy-performance-analysis'));
        }
        $lock_owner = self::start_lock();
        if (!$lock_owner) {
            return new WP_Error('sspa_traffic_start_busy', __('Another request is starting a traffic collection. Try again.', 'super-speedy-performance-analysis'));
        }
        try {
            return self::start_locked($duration, $trigger, $durations);
        } finally {
            SSPA_Atomic_Claim::release(self::START_LOCK, $lock_owner);
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
        $stored_key = $collection_key ? SSPA_Atomic_Claim::create_value($key_option, $collection_key) : '';
        if (!$collection_key || !is_string($stored_key) || !hash_equals($collection_key, $stored_key)) {
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
        return SSPA_Atomic_Claim::acquire(self::START_LOCK, 60);
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
        $duration_seconds = self::observed_duration_seconds($status['collection']);
        $performance = self::request_performance_data(
            $id,
            (int) $status['collection']['origin_sample_modulus'],
            $duration_seconds
        );
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
            'request_performance_groups' => $performance['groups'],
            'origin_page_generation' => $performance['origin_page_generation'],
            'ssf_protection_opportunity' => $performance['ssf_protection_opportunity'],
            'cache_fragment_opportunity' => $performance['cache_fragment_opportunity'],
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
                'not_identified_as_automation_does_not_mean_human',
                'wordpress_origin_rows_are_requests_not_served_entirely_by_an_upstream_page_cache_or_cdn',
                'cloudflare_edge_hits_require_a_matching_cloudflare_analytics_export',
            ),
        );
        $problems = SSPA_Traffic_Privacy::validate_export($payload);
        if ($problems) {
            return new WP_Error('sspa_traffic_privacy', __('The experimental observation export failed its privacy allowlist.', 'super-speedy-performance-analysis'), $problems);
        }
        return $payload;
    }

    public static function comparison($before_collection_id, $after_collection_id) {
        $before = self::observations((int) $before_collection_id);
        if (is_wp_error($before)) {
            return $before;
        }
        $after = self::observations((int) $after_collection_id);
        if (is_wp_error($after)) {
            return $after;
        }
        $before_snapshot = self::comparison_snapshot($before);
        $after_snapshot = self::comparison_snapshot($after);
        $metrics = array(
            'origin_page_generation' => array(
                'wall_ms_average', 'wall_ms_p95', 'projected_daily_requests',
                'projected_daily_wall_ms', 'projected_daily_cpu_us', 'projected_daily_queries',
            ),
            'private_state_catalogue' => array(
                'wall_ms_average', 'projected_daily_requests', 'projected_daily_wall_ms',
                'projected_daily_cpu_us', 'projected_daily_queries',
            ),
            'ssf_protection_opportunity' => array('protectable_projected_daily_requests'),
        );
        $changes = array();
        foreach ($metrics as $section => $names) {
            $changes[$section] = array();
            foreach ($names as $name) {
                $changes[$section][$name] = self::metric_change(
                    $before_snapshot[$section][$name] ?? null,
                    $after_snapshot[$section][$name] ?? null
                );
            }
        }
        $result = array(
            'schema' => 'sspa/traffic-collection-comparison@1',
            'generated_at' => gmdate('c'),
            'normalisation' => 'request_volumes_and_processing_totals_projected_to_24_hours;_per_request_average_and_p95_not_duration_scaled',
            'before' => $before_snapshot,
            'after' => $after_snapshot,
            'changes' => $changes,
            'limitations' => array(
                'cloudflare_edge_hits_are_unavailable_without_matching_cloudflare_analytics',
                'not_identified_as_automation_does_not_mean_human',
                'net_fragment_saving_is_unavailable_until_fragment_requests_are_measured',
            ),
        );
        $problems = SSPA_Traffic_Privacy::validate_export($result);
        return $problems
            ? new WP_Error('sspa_traffic_privacy', __('The traffic comparison failed its privacy allowlist.', 'super-speedy-performance-analysis'), $problems)
            : $result;
    }

    private static function comparison_snapshot($observations) {
        return array(
            'collection_id' => (int) $observations['collection']['id'],
            'observed_duration_seconds' => (int) $observations['origin_page_generation']['observed_duration_seconds'],
            'origin_page_generation' => $observations['origin_page_generation'],
            'private_state_catalogue' => $observations['cache_fragment_opportunity']['private_state_catalogue'],
            'catalogue_page_classes' => $observations['cache_fragment_opportunity']['page_classes'],
            'ssf_protection_opportunity' => $observations['ssf_protection_opportunity'],
        );
    }

    private static function metric_change($before, $after) {
        if ($before === null || $after === null) {
            return array('before' => $before, 'after' => $after, 'absolute' => null, 'percent' => null, 'quality' => 'unavailable');
        }
        $before = (float) $before;
        $after = (float) $after;
        $absolute = $after - $before;
        $percent = $before != 0.0 ? round($absolute / $before * 100, 4) : ($after == 0.0 ? 0.0 : null);
        return array(
            'before' => $before,
            'after' => $after,
            'absolute' => round($absolute, 4),
            'percent' => $percent,
            'quality' => 'normalised_comparison',
        );
    }

    private static function observed_duration_seconds($collection) {
        $started = !empty($collection['started_at']) ? strtotime($collection['started_at']) : 0;
        $until = !empty($collection['collect_until']) ? strtotime($collection['collect_until']) : 0;
        if (!$started || !$until) {
            return 0;
        }
        return max(1, min(time(), $until) - $started);
    }

    /**
     * Build privacy-safe comparable aggregates from retained request rows.
     *
     * Exact and sampled observations remain separate groups. Estimated totals weight only
     * sampled rows by the collection modulus; raw exact and sampled counts are never added.
     */
    private static function request_performance_data($collection_id, $sample_modulus, $duration_seconds) {
        global $wpdb;
        $table = SSPA_Schema::table('traffic_events');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT actor_key,actor_state,automation_code,surface_code,page_class,ssf_protection_code,wall_ms,cpu_us,query_count,flags
             FROM $table WHERE collection_id = %d AND event_code = %d ORDER BY id",
            $collection_id,
            SSPA_Traffic_Codes::EVENT_REQUEST
        ), ARRAY_A);
        $groups = array();
        foreach ((array) $rows as $row) {
            $sampled = ((int) $row['flags'] & SSPA_Traffic_Codes::FLAG_SAMPLED) !== 0;
            $key = implode(':', array(
                (int) $row['actor_state'],
                (int) $row['surface_code'],
                (int) $row['page_class'],
                $sampled ? 1 : 0,
            ));
            if (!isset($groups[$key])) {
                $groups[$key] = array(
                    'actor_state_code' => (int) $row['actor_state'],
                    'surface_code' => (int) $row['surface_code'],
                    'page_class_code' => (int) $row['page_class'],
                    'sampling' => $sampled ? 'sampled' : 'exact',
                    'requests' => 0,
                    'actors' => array(),
                    'wall_ms' => array(),
                    'wall_ms_sum' => 0,
                    'cpu_us_sum' => 0,
                    'cpu_measurements' => 0,
                    'query_count_sum' => 0,
                    'query_measurements' => 0,
                );
            }
            $groups[$key]['requests']++;
            if ($row['actor_key'] !== null) {
                $groups[$key]['actors'][bin2hex($row['actor_key'])] = true;
            }
            $wall_ms = (int) $row['wall_ms'];
            $groups[$key]['wall_ms'][] = $wall_ms;
            $groups[$key]['wall_ms_sum'] += $wall_ms;
            if ($row['cpu_us'] !== null) {
                $groups[$key]['cpu_us_sum'] += (int) $row['cpu_us'];
                $groups[$key]['cpu_measurements']++;
            }
            if ($row['query_count'] !== null) {
                $groups[$key]['query_count_sum'] += (int) $row['query_count'];
                $groups[$key]['query_measurements']++;
            }
        }

        ksort($groups, SORT_NATURAL);
        $export = array();
        foreach ($groups as $group) {
            sort($group['wall_ms'], SORT_NUMERIC);
            $observed = (int) $group['requests'];
            $weight = 'sampled' === $group['sampling'] ? max(1, (int) $sample_modulus) : 1;
            $p95_index = max(0, (int) ceil($observed * 0.95) - 1);
            $export[] = array(
                'actor_state' => SSPA_Traffic_Codes::actor($group['actor_state_code']),
                'surface' => SSPA_Traffic_Codes::surface($group['surface_code']),
                'page_class' => SSPA_Traffic_Codes::page_class($group['page_class_code']),
                'sampling' => $group['sampling'],
                'quality' => 1 === $weight ? 'exact' : 'estimated',
                'observed_requests' => $observed,
                'distinct_actors' => count($group['actors']),
                'sample_modulus' => $weight,
                'estimated_requests' => $observed * $weight,
                'wall_ms_sum' => (int) $group['wall_ms_sum'],
                'estimated_wall_ms_sum' => (int) $group['wall_ms_sum'] * $weight,
                'wall_ms_average' => $observed ? round($group['wall_ms_sum'] / $observed, 3) : null,
                'wall_ms_p95' => $observed ? (int) $group['wall_ms'][$p95_index] : null,
                'cpu_us_sum' => $group['cpu_measurements'] ? (int) $group['cpu_us_sum'] : null,
                'estimated_cpu_us_sum' => $group['cpu_measurements'] ? (int) $group['cpu_us_sum'] * $weight : null,
                'cpu_measurements' => (int) $group['cpu_measurements'],
                'query_count_sum' => $group['query_measurements'] ? (int) $group['query_count_sum'] : null,
                'estimated_query_count_sum' => $group['query_measurements'] ? (int) $group['query_count_sum'] * $weight : null,
                'query_measurements' => (int) $group['query_measurements'],
            );
        }

        $origin = self::performance_totals($export, $duration_seconds);
        $origin['wall_ms_p95'] = self::weighted_wall_p95($rows, $sample_modulus);
        return array(
            'groups' => $export,
            'origin_page_generation' => $origin,
            'ssf_protection_opportunity' => self::ssf_protection_opportunity($rows, $sample_modulus, $duration_seconds),
            'cache_fragment_opportunity' => self::cache_fragment_opportunity($export, $duration_seconds),
        );
    }

    private static function performance_totals($groups, $duration_seconds) {
        $totals = self::sum_performance_groups($groups);
        $totals['observed_duration_seconds'] = (int) $duration_seconds;
        $totals['projected_daily_requests'] = $duration_seconds > 0
            ? (int) round($totals['estimated_requests'] * DAY_IN_SECONDS / $duration_seconds)
            : null;
        $totals['projected_daily_wall_ms'] = $duration_seconds > 0
            ? (int) round($totals['estimated_wall_ms_sum'] * DAY_IN_SECONDS / $duration_seconds)
            : null;
        $totals['projected_daily_cpu_us'] = $duration_seconds > 0 && $totals['estimated_cpu_us_sum'] !== null
            ? (int) round($totals['estimated_cpu_us_sum'] * DAY_IN_SECONDS / $duration_seconds)
            : null;
        $totals['projected_daily_queries'] = $duration_seconds > 0 && $totals['estimated_query_count_sum'] !== null
            ? (int) round($totals['estimated_query_count_sum'] * DAY_IN_SECONDS / $duration_seconds)
            : null;
        return $totals;
    }

    private static function weighted_wall_p95($rows, $sample_modulus) {
        $histogram = array();
        $total = 0;
        foreach ((array) $rows as $row) {
            $sampled = ((int) $row['flags'] & SSPA_Traffic_Codes::FLAG_SAMPLED) !== 0;
            $weight = $sampled ? max(1, (int) $sample_modulus) : 1;
            $wall = (int) $row['wall_ms'];
            $histogram[$wall] = ($histogram[$wall] ?? 0) + $weight;
            $total += $weight;
        }
        if (!$total) {
            return null;
        }
        ksort($histogram, SORT_NUMERIC);
        $rank = max(1, (int) ceil($total * 0.95));
        $seen = 0;
        foreach ($histogram as $wall => $count) {
            $seen += $count;
            if ($seen >= $rank) {
                return (int) $wall;
            }
        }
        return null;
    }

    private static function sum_performance_groups($groups) {
        $result = array(
            'quality' => 'unavailable',
            'observed_requests' => array('exact' => 0, 'sampled' => 0),
            'estimated_requests' => 0,
            'estimated_wall_ms_sum' => 0,
            'estimated_cpu_us_sum' => null,
            'estimated_query_count_sum' => null,
            'wall_ms_average' => null,
        );
        $cpu = 0;
        $queries = 0;
        $cpu_available = false;
        $queries_available = false;
        foreach ((array) $groups as $group) {
            $sampling = 'sampled' === $group['sampling'] ? 'sampled' : 'exact';
            $result['observed_requests'][$sampling] += (int) $group['observed_requests'];
            $result['estimated_requests'] += (int) $group['estimated_requests'];
            $result['estimated_wall_ms_sum'] += (int) $group['estimated_wall_ms_sum'];
            if ($group['estimated_cpu_us_sum'] !== null) {
                $cpu += (int) $group['estimated_cpu_us_sum'];
                $cpu_available = true;
            }
            if ($group['estimated_query_count_sum'] !== null) {
                $queries += (int) $group['estimated_query_count_sum'];
                $queries_available = true;
            }
        }
        if ($result['estimated_requests']) {
            $result['quality'] = $result['observed_requests']['sampled'] ? 'estimated' : 'exact';
            $result['wall_ms_average'] = round($result['estimated_wall_ms_sum'] / $result['estimated_requests'], 3);
        }
        $result['estimated_cpu_us_sum'] = $cpu_available ? $cpu : null;
        $result['estimated_query_count_sum'] = $queries_available ? $queries : null;
        return $result;
    }

    private static function cache_fragment_opportunity($groups, $duration_seconds) {
        $public = array();
        $catalogue = array();
        $private = array();
        $without_private_state = array();
        $page_groups = array('product_single' => array(), 'product_archive' => array(), 'shop' => array());
        $private_states = array('guest_non_empty_basket', 'logged_in_no_basket', 'logged_in_non_empty_basket');
        $catalogue_pages = array_keys($page_groups);
        foreach ((array) $groups as $group) {
            if ('public_html_get_head' !== $group['surface']) {
                continue;
            }
            $public[] = $group;
            if (!in_array($group['page_class'], $catalogue_pages, true)) {
                continue;
            }
            $catalogue[] = $group;
            $page_groups[$group['page_class']][] = $group;
            if (in_array($group['actor_state'], $private_states, true)) {
                $private[] = $group;
            } elseif ('staff' !== $group['actor_state']) {
                $without_private_state[] = $group;
            }
        }
        $page_breakdown = array();
        foreach ($page_groups as $page => $items) {
            $page_private = array_values(array_filter($items, function ($group) use ($private_states) {
                return in_array($group['actor_state'], $private_states, true);
            }));
            $page_breakdown[$page] = array(
                'all' => self::sum_performance_groups($items),
                'logged_in_or_non_empty_basket' => self::sum_performance_groups($page_private),
            );
        }
        $private_totals = self::daily_projection(self::sum_performance_groups($private), $duration_seconds);
        return array(
            'denominator' => 'wordpress_origin_public_html_get_head_requests',
            'observed_duration_seconds' => (int) $duration_seconds,
            'total_public_html_get_head' => self::sum_performance_groups($public),
            'catalogue_public_html_get_head' => self::sum_performance_groups($catalogue),
            'private_state_catalogue' => $private_totals,
            'without_logged_in_or_basket_state_catalogue' => self::sum_performance_groups($without_private_state),
            'page_classes' => $page_breakdown,
            'gross_page_generation_work_potentially_avoided' => array(
                'quality' => $private_totals['quality'],
                'wall_ms' => $private_totals['estimated_wall_ms_sum'],
                'cpu_us' => $private_totals['estimated_cpu_us_sum'],
                'queries' => $private_totals['estimated_query_count_sum'],
            ),
            'fragment_processing' => array('quality' => 'unavailable', 'requests' => null, 'wall_ms' => null, 'cpu_us' => null, 'queries' => null),
            'net_saving' => array('quality' => 'unavailable', 'wall_ms' => null, 'cpu_us' => null, 'queries' => null),
        );
    }

    private static function daily_projection($totals, $duration_seconds) {
        $factor = $duration_seconds > 0 ? DAY_IN_SECONDS / $duration_seconds : null;
        $totals['projected_daily_requests'] = $factor !== null ? (int) round($totals['estimated_requests'] * $factor) : null;
        $totals['projected_daily_wall_ms'] = $factor !== null ? (int) round($totals['estimated_wall_ms_sum'] * $factor) : null;
        $totals['projected_daily_cpu_us'] = $factor !== null && $totals['estimated_cpu_us_sum'] !== null ? (int) round($totals['estimated_cpu_us_sum'] * $factor) : null;
        $totals['projected_daily_queries'] = $factor !== null && $totals['estimated_query_count_sum'] !== null ? (int) round($totals['estimated_query_count_sum'] * $factor) : null;
        return $totals;
    }

    private static function ssf_protection_opportunity($rows, $sample_modulus, $duration_seconds) {
        $groups = array();
        $protection_available = false;
        foreach ((array) $rows as $row) {
            $automation_code = (int) $row['automation_code'];
            if (!$automation_code && SSPA_Traffic_Codes::ACTOR_AUTOMATED_CLAIMED === (int) $row['actor_state']) {
                $automation_code = SSPA_Traffic_Codes::AUTOMATION_CLAIMED_GENERIC;
            }
            $protection_code = (int) $row['ssf_protection_code'];
            if (SSPA_Traffic_Codes::SSF_PROTECTION_UNAVAILABLE !== $protection_code) {
                $protection_available = true;
            }
            $sampled = ((int) $row['flags'] & SSPA_Traffic_Codes::FLAG_SAMPLED) !== 0;
            $key = implode(':', array($automation_code, $protection_code, $sampled ? 1 : 0));
            if (!isset($groups[$key])) {
                $groups[$key] = array(
                    'automation_code' => $automation_code,
                    'protection_code' => $protection_code,
                    'sampling' => $sampled ? 'sampled' : 'exact',
                    'observed_requests' => 0,
                    'wall_ms_sum' => 0,
                    'cpu_us_sum' => 0,
                    'cpu_available' => false,
                    'query_count_sum' => 0,
                    'query_available' => false,
                );
            }
            $groups[$key]['observed_requests']++;
            $groups[$key]['wall_ms_sum'] += (int) $row['wall_ms'];
            if ($row['cpu_us'] !== null) {
                $groups[$key]['cpu_us_sum'] += (int) $row['cpu_us'];
                $groups[$key]['cpu_available'] = true;
            }
            if ($row['query_count'] !== null) {
                $groups[$key]['query_count_sum'] += (int) $row['query_count'];
                $groups[$key]['query_available'] = true;
            }
        }
        $export = array();
        $claimed = 0;
        $not_identified = 0;
        $protectable = 0;
        $protectable_wall = 0;
        $protectable_cpu = 0;
        $protectable_queries = 0;
        $protectable_cpu_available = false;
        $protectable_queries_available = false;
        foreach ($groups as $group) {
            $weight = 'sampled' === $group['sampling'] ? max(1, (int) $sample_modulus) : 1;
            $estimated = $group['observed_requests'] * $weight;
            if (SSPA_Traffic_Codes::AUTOMATION_NOT_IDENTIFIED === $group['automation_code']) {
                $not_identified += $estimated;
            } else {
                $claimed += $estimated;
            }
            if (SSPA_Traffic_Codes::AUTOMATION_NOT_IDENTIFIED !== $group['automation_code'] && SSPA_Traffic_Codes::ssf_protectable($group['protection_code'])) {
                $protectable += $estimated;
                $protectable_wall += $group['wall_ms_sum'] * $weight;
                if ($group['cpu_available']) {
                    $protectable_cpu += $group['cpu_us_sum'] * $weight;
                    $protectable_cpu_available = true;
                }
                if ($group['query_available']) {
                    $protectable_queries += $group['query_count_sum'] * $weight;
                    $protectable_queries_available = true;
                }
            }
            $export[] = array(
                'automation' => SSPA_Traffic_Codes::automation($group['automation_code']),
                'ssf_reason' => SSPA_Traffic_Codes::ssf_protection($group['protection_code']),
                'sampling' => $group['sampling'],
                'quality' => 1 === $weight ? 'exact' : 'estimated',
                'observed_requests' => (int) $group['observed_requests'],
                'sample_modulus' => $weight,
                'estimated_requests' => $estimated,
                'estimated_wall_ms_sum' => $group['wall_ms_sum'] * $weight,
                'estimated_cpu_us_sum' => $group['cpu_available'] ? $group['cpu_us_sum'] * $weight : null,
                'estimated_query_count_sum' => $group['query_available'] ? $group['query_count_sum'] * $weight : null,
            );
        }
        return array(
            'quality' => $protection_available ? ($sample_modulus > 1 ? 'estimated' : 'exact') : 'unavailable',
            'denominator' => 'claimed_automation_wordpress_origin_requests',
            'observed_duration_seconds' => (int) $duration_seconds,
            'claimed_automation_estimated_requests' => $claimed,
            'not_identified_as_automation_estimated_requests' => $not_identified,
            'protectable_claimed_automation_estimated_requests' => $protection_available ? $protectable : null,
            'protectable_percent_of_claimed_automation' => $protection_available && $claimed ? round($protectable / $claimed * 100, 4) : null,
            'protectable_projected_daily_requests' => $protection_available && $duration_seconds > 0 ? (int) round($protectable * DAY_IN_SECONDS / $duration_seconds) : null,
            'protectable_estimated_wall_ms_sum' => $protection_available ? $protectable_wall : null,
            'protectable_estimated_cpu_us_sum' => $protection_available && $protectable_cpu_available ? $protectable_cpu : null,
            'protectable_estimated_query_count_sum' => $protection_available && $protectable_queries_available ? $protectable_queries : null,
            'groups' => $export,
            'cloudflare_edge_quality' => 'unavailable',
        );
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
