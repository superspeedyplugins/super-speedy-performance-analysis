<?php
defined('ABSPATH') || exit;

/**
 * Table definitions + versioned migrations. Bump SSPA_Schema::DB_VERSION whenever a
 * definition changes; dbDelta handles additive changes, anything destructive gets an
 * explicit migration in upgrade().
 *
 * Design reference: .docs/brainstorm-performance-analysis.md section 3.4.
 */
class SSPA_Schema {

    const DB_VERSION = SSPA_DB_VERSION;

    const LOCK_OPTION = 'sspa_schema_lock';
    private static $lock_owner = '';

    public static function table($name) {
        global $wpdb;
        return $wpdb->prefix . 'sspa_' . $name;
    }

    /**
     * Schema work runs from plugins_loaded on whichever request arrives first after an update,
     * so it must not run twice at once: dbDelta plus ensure_run_uuids()'s ALTER TABLE are DDL,
     * and two concurrent requests can race the ADD UNIQUE into a duplicate-key-name error. The
     * lock is an add_option insert, which is the cheapest thing WordPress offers that fails for
     * the second caller, and it expires so a fatal cannot wedge the upgrade forever.
     */
    private static function lock() {
        self::$lock_owner = SSPA_Atomic_Claim::acquire(self::LOCK_OPTION, 300);
        return (bool) self::$lock_owner;
    }

    private static function unlock() {
        if (self::$lock_owner) {
            SSPA_Atomic_Claim::release(self::LOCK_OPTION, self::$lock_owner);
            self::$lock_owner = '';
        }
    }

    public static function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        $runs = self::table('runs');
        $profiles = self::table('profiles');
        $component_stats = self::table('component_stats');
        $findings = self::table('findings');
        $plugin_impacts = self::table('plugin_impacts');
        $site_metrics = self::table('site_metrics');
        $submission_outbox = self::table('submission_outbox');
        $submission_events = self::table('submission_events');
        $traffic_collections = self::table('traffic_collections');
        $traffic_events = self::table('traffic_events');
        $traffic_rollups = self::table('traffic_rollups');
        $traffic_actor_work = self::table('traffic_actor_work');
        $traffic_reports = self::table('traffic_reports');

        dbDelta("CREATE TABLE $runs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            run_uuid char(36) NOT NULL DEFAULT '',
            blog_id bigint(20) unsigned NOT NULL DEFAULT 1,
            run_type varchar(20) NOT NULL DEFAULT 'baseline',
            measurement_version int(10) unsigned NOT NULL DEFAULT 1,
            trigger_source varchar(20) NOT NULL DEFAULT 'manual',
            status varchar(20) NOT NULL DEFAULT 'queued',
            plugin_set longtext NULL,
            plugin_set_hash char(32) NOT NULL DEFAULT '',
            share_context longtext NULL,
            site_metrics_id bigint(20) unsigned NULL,
            started datetime NULL,
            finished datetime NULL,
            notes text NULL,
            PRIMARY KEY  (id),
            KEY run_uuid_lookup (run_uuid),
            KEY blog_status (blog_id,status),
            KEY status (status),
            KEY started (started)
        ) $charset_collate;");

        dbDelta("CREATE TABLE $profiles (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            run_id bigint(20) unsigned NOT NULL,
            page_key varchar(64) NOT NULL,
            url text NOT NULL,
            method varchar(8) NOT NULL DEFAULT 'GET',
            variant varchar(16) NOT NULL DEFAULT 'anon',
            plugin_set_hash char(32) NOT NULL DEFAULT '',
            object_cache_mode varchar(16) NOT NULL DEFAULT 'normal',
            samples longtext NULL,
            ttfb_ms float NULL,
            page_gen_ms float NULL,
            sql_ms float NULL,
            sql_count int(11) NULL,
            http_ms float NULL,
            http_count int(11) NULL,
            php_ms float NULL,
            peak_mem_bytes bigint(20) unsigned NULL,
            rows_returned_total bigint(20) unsigned NULL,
            dupe_query_count int(11) NULL,
            cache_hits int(11) NULL,
            cache_misses int(11) NULL,
            mail_count int(11) NULL,
            mail_ms float NULL,
            response_code smallint(6) NULL,
            blocked_by varchar(64) NULL,
            profile_blob longblob NULL,
            created datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY run_page (run_id,page_key),
            KEY page_key (page_key)
        ) $charset_collate;");

        dbDelta("CREATE TABLE $component_stats (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            profile_id bigint(20) unsigned NOT NULL,
            run_id bigint(20) unsigned NOT NULL,
            component varchar(191) NOT NULL,
            component_type varchar(16) NOT NULL DEFAULT 'plugin',
            query_count int(11) NOT NULL DEFAULT 0,
            sql_ms float NOT NULL DEFAULT 0,
            rows_returned bigint(20) unsigned NOT NULL DEFAULT 0,
            slowest_query_ms float NOT NULL DEFAULT 0,
            http_ms float NOT NULL DEFAULT 0,
            mail_ms float NOT NULL DEFAULT 0,
            cache_hits int(11) NOT NULL DEFAULT 0,
            cache_misses int(11) NOT NULL DEFAULT 0,
            include_ms float NULL,
            hook_ms float NULL,
            assets_count int(11) NULL,
            PRIMARY KEY  (id),
            KEY profile_id (profile_id),
            KEY run_component (run_id,component),
            KEY component (component)
        ) $charset_collate;");

        dbDelta("CREATE TABLE $findings (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            run_id bigint(20) unsigned NOT NULL,
            severity varchar(10) NOT NULL DEFAULT 'info',
            finding_type varchar(32) NOT NULL,
            component varchar(191) NULL,
            page_key varchar(64) NULL,
            evidence longtext NULL,
            recommendation_key varchar(64) NULL,
            confidence varchar(10) NOT NULL DEFAULT 'inferred',
            created datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY run_id (run_id),
            KEY component (component),
            KEY finding_type (finding_type)
        ) $charset_collate;");

        dbDelta("CREATE TABLE $plugin_impacts (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            blog_id bigint(20) unsigned NOT NULL DEFAULT 1,
            plugin varchar(191) NOT NULL,
            plugin_version varchar(32) NULL,
            group_members text NULL,
            page_key varchar(64) NOT NULL,
            method varchar(16) NOT NULL,
            object_cache_mode varchar(16) NOT NULL DEFAULT 'normal',
            delta_ttfb_ms float NULL,
            delta_sql_ms float NULL,
            delta_http_ms float NULL,
            delta_mem_bytes bigint(20) NULL,
            delta_queries int(11) NULL,
            noise_floor_ms float NULL,
            output_identical tinyint(1) NULL,
            confidence varchar(10) NOT NULL DEFAULT 'measured',
            baseline_run_id bigint(20) unsigned NULL,
            test_run_id bigint(20) unsigned NULL,
            created datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY plugin (plugin),
            KEY page_key (page_key)
        ) $charset_collate;");

        $captures = self::table('captures');
        // Scratch space: the profiler (running inside the profiled request) writes its
        // capture here keyed by token; the crawler ingests and deletes it. Orphans are
        // pruned by the hourly cleanup cron.
        dbDelta("CREATE TABLE $captures (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            token_id char(32) NOT NULL,
            capture longblob NULL,
            created datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY token_id (token_id),
            KEY created (created)
        ) $charset_collate;");

        dbDelta("CREATE TABLE $site_metrics (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            blog_id bigint(20) unsigned NOT NULL DEFAULT 1,
            metrics longtext NOT NULL,
            sector varchar(32) NULL,
            created datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY blog_created (blog_id,created)
        ) $charset_collate;");

        dbDelta("CREATE TABLE $submission_outbox (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            submission_uuid char(36) NOT NULL,
            run_id bigint(20) unsigned NOT NULL,
            run_uuid char(36) NOT NULL,
            run_type varchar(20) NOT NULL DEFAULT '',
            transport_version int(10) unsigned NOT NULL DEFAULT 1,
            payload_schema_major int(10) unsigned NOT NULL DEFAULT 1,
            payload_schema_minor int(10) unsigned NOT NULL DEFAULT 0,
            anonymisation_version int(10) unsigned NOT NULL DEFAULT 1,
            client_version varchar(32) NOT NULL,
            payload_created_at char(20) NOT NULL,
            payload_gzip longblob NULL,
            payload_sha256 char(64) NOT NULL,
            compressed_bytes bigint(20) unsigned NOT NULL,
            uncompressed_bytes bigint(20) unsigned NOT NULL,
            state varchar(24) NOT NULL DEFAULT 'pending',
            phase varchar(24) NOT NULL DEFAULT 'pending',
            consent_scope varchar(16) NOT NULL DEFAULT 'automatic',
            reservation_uuid char(36) NULL,
            upload_expires_at datetime NULL,
            receipt_uuid char(36) NULL,
            attempts int(10) unsigned NOT NULL DEFAULT 0,
            next_attempt datetime NULL,
            last_http_status smallint(6) NULL,
            last_error_code varchar(64) NULL,
            last_error_detail varchar(255) NULL,
            created datetime NOT NULL,
            last_attempt datetime NULL,
            sent_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY submission_uuid (submission_uuid),
            UNIQUE KEY run_uuid (run_uuid),
            KEY state_due (state,next_attempt),
            KEY created (created)
        ) $charset_collate;");

        dbDelta("CREATE TABLE $submission_events (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            outbox_id bigint(20) unsigned NOT NULL,
            state varchar(24) NOT NULL,
            phase varchar(24) NOT NULL,
            attempt int(10) unsigned NOT NULL DEFAULT 0,
            reason_code varchar(64) NULL,
            created datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY outbox_created (outbox_id,created)
        ) $charset_collate;");

        dbDelta("CREATE TABLE $traffic_collections (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            collection_uuid char(36) NOT NULL,
            blog_id bigint(20) unsigned NOT NULL DEFAULT 1,
            status_code tinyint(3) unsigned NOT NULL DEFAULT 1,
            started_at datetime NULL,
            collect_until datetime NULL,
            outcomes_until datetime NULL,
            finished_at datetime NULL,
            origin_sample_modulus smallint(5) unsigned NOT NULL DEFAULT 100,
            event_ceiling int(10) unsigned NOT NULL DEFAULT 250000,
            event_id_stop bigint(20) unsigned NOT NULL DEFAULT 0,
            disk_ceiling_bytes bigint(20) unsigned NOT NULL DEFAULT 33554432,
            aggregate_cursor bigint(20) unsigned NOT NULL DEFAULT 0,
            source_revision int(10) unsigned NOT NULL DEFAULT 0,
            source_ledger longtext NULL,
            stop_reason_code smallint(5) unsigned NULL,
            observer_version smallint(5) unsigned NOT NULL DEFAULT 1,
            preflight_insert_ms float NULL,
            created_by varchar(16) NOT NULL DEFAULT 'admin',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY collection_uuid (collection_uuid),
            KEY blog_status (blog_id,status_code),
            KEY collect_until (collect_until)
        ) $charset_collate;");

        dbDelta("CREATE TABLE $traffic_events (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            collection_id bigint(20) unsigned NOT NULL,
            observed_at int(10) unsigned NOT NULL,
            actor_key binary(12) NULL,
            related_actor_key binary(12) NULL,
            commerce_key binary(12) NULL,
            path_key binary(8) NULL,
            event_code tinyint(3) unsigned NOT NULL,
            actor_state tinyint(3) unsigned NOT NULL DEFAULT 0,
            automation_code tinyint(3) unsigned NOT NULL DEFAULT 0,
            surface_code tinyint(3) unsigned NOT NULL DEFAULT 0,
            page_class tinyint(3) unsigned NOT NULL DEFAULT 0,
            ssf_protection_code tinyint(3) unsigned NOT NULL DEFAULT 0,
            status_code smallint(5) unsigned NOT NULL DEFAULT 0,
            wall_ms mediumint(8) unsigned NOT NULL DEFAULT 0,
            cpu_us int(10) unsigned NULL,
            query_count mediumint(8) unsigned NULL,
            observer_us mediumint(8) unsigned NULL,
            value_minor bigint(20) NULL,
            currency binary(3) NULL,
            flags int(10) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY collection_id (collection_id,id)
        ) $charset_collate;");

        dbDelta("CREATE TABLE $traffic_rollups (
            collection_id bigint(20) unsigned NOT NULL,
            rollup_code smallint(5) unsigned NOT NULL,
            bucket_start int(10) unsigned NOT NULL DEFAULT 0,
            dimension_key varbinary(32) NOT NULL DEFAULT '',
            event_count bigint(20) unsigned NOT NULL DEFAULT 0,
            wall_ms_sum bigint(20) unsigned NOT NULL DEFAULT 0,
            cpu_us_sum bigint(20) unsigned NOT NULL DEFAULT 0,
            query_count_sum bigint(20) unsigned NOT NULL DEFAULT 0,
            value_minor_sum bigint(20) NOT NULL DEFAULT 0,
            histogram_blob blob NULL,
            quality_code tinyint(3) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (collection_id,rollup_code,bucket_start,dimension_key)
        ) $charset_collate;");

        dbDelta("CREATE TABLE $traffic_actor_work (
            collection_id bigint(20) unsigned NOT NULL,
            actor_key binary(12) NOT NULL,
            first_seen int(10) unsigned NOT NULL DEFAULT 0,
            last_seen int(10) unsigned NOT NULL DEFAULT 0,
            state_flags int(10) unsigned NOT NULL DEFAULT 0,
            eligible_views bigint(20) unsigned NOT NULL DEFAULT 0,
            origin_wall_ms bigint(20) unsigned NOT NULL DEFAULT 0,
            query_count bigint(20) unsigned NOT NULL DEFAULT 0,
            funnel_flags int(10) unsigned NOT NULL DEFAULT 0,
            last_value_minor bigint(20) NULL,
            currency binary(3) NULL,
            PRIMARY KEY  (collection_id,actor_key)
        ) $charset_collate;");

        dbDelta("CREATE TABLE $traffic_reports (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            collection_id bigint(20) unsigned NOT NULL,
            report_uuid char(36) NOT NULL,
            status_code tinyint(3) unsigned NOT NULL DEFAULT 1,
            snapshot_event_id bigint(20) unsigned NOT NULL DEFAULT 0,
            observed_from datetime NULL,
            observed_until datetime NULL,
            aggregate_cursor bigint(20) unsigned NOT NULL DEFAULT 0,
            source_revision int(10) unsigned NOT NULL DEFAULT 0,
            source_ledger longtext NULL,
            report_blob longblob NULL,
            error_code varchar(64) NULL,
            created_at datetime NOT NULL,
            started_at datetime NULL,
            finished_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY report_uuid (report_uuid),
            UNIQUE KEY report_boundary (collection_id,snapshot_event_id,source_revision),
            KEY collection_status (collection_id,status_code)
        ) $charset_collate;");

        self::ensure_run_uuids();

        update_option('sspa_db_version', self::DB_VERSION);
    }

    private static function ensure_run_uuids() {
        global $wpdb;
        $runs = self::table('runs');
        $rows = $wpdb->get_results("SELECT id, run_uuid FROM $runs", ARRAY_A);
        foreach ((array) $rows as $row) {
            if (!wp_is_uuid($row['run_uuid'], 4)) {
                $wpdb->update($runs, array('run_uuid' => wp_generate_uuid4()), array('id' => (int) $row['id']));
            }
        }
        $unique = $wpdb->get_var("SHOW INDEX FROM $runs WHERE Key_name = 'run_uuid_unique'");
        if (!$unique) {
            $wpdb->query("ALTER TABLE $runs ADD UNIQUE KEY run_uuid_unique (run_uuid)");
        }
    }

    public static function upgrade($from_version) {
        // No destructive migrations yet; dbDelta in create_tables() covers additive changes.
        if (!self::lock()) {
            return;
        }
        try {
            self::create_tables();
        } finally {
            self::unlock();
        }
    }

    public static function drop_tables() {
        global $wpdb;
        foreach (array('traffic_reports', 'traffic_actor_work', 'traffic_rollups', 'traffic_events', 'traffic_collections', 'submission_events', 'submission_outbox', 'runs', 'profiles', 'component_stats', 'findings', 'plugin_impacts', 'site_metrics', 'captures') as $name) {
            $table = self::table($name);
            $wpdb->query("DROP TABLE IF EXISTS $table");
        }
        delete_option('sspa_db_version');
    }
}
