<?php
defined('ABSPATH') || exit;

class SSPH_Schema {

    const DB_VERSION = '1.0';

    public static function table($name) {
        global $wpdb;
        return $wpdb->prefix . 'ssph_' . $name;
    }

    public static function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();

        $installs = self::table('installs');
        $submissions = self::table('submissions');
        $impacts = self::table('plugin_impacts');

        dbDelta("CREATE TABLE $installs (
            install_uuid char(36) NOT NULL,
            secret char(64) NOT NULL,
            first_seen datetime NOT NULL,
            last_seen datetime NOT NULL,
            wp varchar(20) NULL,
            php varchar(20) NULL,
            reputation float NOT NULL DEFAULT 1,
            PRIMARY KEY  (install_uuid)
        ) $charset_collate;");

        dbDelta("CREATE TABLE $submissions (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            install_uuid char(36) NOT NULL,
            schema_version int(11) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'quarantined',
            payload longblob NULL,
            received_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY install_uuid (install_uuid),
            KEY received_at (received_at)
        ) $charset_collate;");

        dbDelta("CREATE TABLE $impacts (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            submission_id bigint(20) unsigned NOT NULL,
            install_uuid char(36) NOT NULL,
            plugin varchar(191) NOT NULL,
            page_key varchar(64) NULL,
            method varchar(16) NULL,
            delta_ttfb_ms float NULL,
            delta_sql_ms float NULL,
            delta_mem_bytes bigint(20) NULL,
            delta_queries int(11) NULL,
            confidence varchar(10) NULL,
            received_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY plugin (plugin),
            KEY install_uuid (install_uuid)
        ) $charset_collate;");

        update_option('ssph_db_version', self::DB_VERSION);
    }
}
