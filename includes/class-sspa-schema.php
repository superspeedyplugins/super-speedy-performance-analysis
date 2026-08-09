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

    const DB_VERSION = '1.5';

    public static function table($name) {
        global $wpdb;
        return $wpdb->prefix . 'sspa_' . $name;
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
            page_key varchar(64) NOT NULL,
            method varchar(16) NOT NULL,
            object_cache_mode varchar(16) NOT NULL DEFAULT 'normal',
            delta_ttfb_ms float NULL,
            delta_sql_ms float NULL,
            delta_http_ms float NULL,
            delta_mem_bytes bigint(20) NULL,
            delta_queries int(11) NULL,
            noise_floor_ms float NULL,
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
        self::create_tables();
    }

    public static function drop_tables() {
        global $wpdb;
        foreach (array('submission_events', 'submission_outbox', 'runs', 'profiles', 'component_stats', 'findings', 'plugin_impacts', 'site_metrics', 'captures') as $name) {
            $table = self::table($name);
            $wpdb->query("DROP TABLE IF EXISTS $table");
        }
        delete_option('sspa_db_version');
    }
}
