<?php
defined('ABSPATH') || exit;

/**
 * Activation and upgrade handling. Phase 1 adds the mu-loader and db.php shim
 * install/verify/health-check logic here (see .docs/implementation-plan.md).
 */
class SSPA_Install {

    public static function activate() {
        SSPA_Schema::create_tables();
    }

    public static function maybe_upgrade() {
        $installed = get_option('sspa_db_version');
        if ($installed !== SSPA_Schema::DB_VERSION) {
            SSPA_Schema::upgrade($installed);
        }
    }
}
