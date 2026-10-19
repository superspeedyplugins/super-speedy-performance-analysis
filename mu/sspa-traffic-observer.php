<?php
// Super Speedy Performance Analysis experimental traffic observer.
// Fixed loader: collection configuration is data, never executable PHP.
defined('ABSPATH') || exit;

$sspa_traffic_uploads = wp_upload_dir(null, false);
$sspa_traffic_config_path = $sspa_traffic_uploads['basedir'] . '/super-speedy-performance-analysis/traffic-config.json';
if (!is_readable($sspa_traffic_config_path)) {
    return;
}
require_once '%%SSPA_PLUGIN_DIR%%includes/class-sspa-filesystem.php';
$sspa_traffic_config = json_decode(SSPA_Filesystem::get_contents($sspa_traffic_config_path), true);
if (!is_array($sspa_traffic_config) || !isset($sspa_traffic_config['outcomes_until']) || time() > (int) $sspa_traffic_config['outcomes_until']) {
    return;
}

$sspa_traffic_config['observer_path'] = __FILE__;
$sspa_traffic_config['stopped_path'] = dirname($sspa_traffic_config_path) . '/sspa-traffic-observer.stopped';
require_once '%%SSPA_PLUGIN_DIR%%includes/traffic/class-sspa-traffic-authority.php';
if (!SSPA_Traffic_Authority::observe($sspa_traffic_config, static function() { return true; })) {
    return;
}
require_once '%%SSPA_PLUGIN_DIR%%traffic-observer/bootstrap.php';
SSPA_Traffic_Hot_Path::boot($sspa_traffic_config);
