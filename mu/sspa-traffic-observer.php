<?php
// Super Speedy Performance Analysis experimental traffic observer.
// Fixed loader: collection configuration is data, never executable PHP.
defined('ABSPATH') || exit;

$sspa_traffic_uploads = wp_upload_dir(null, false);
$sspa_traffic_config_path = $sspa_traffic_uploads['basedir'] . '/super-speedy-performance-analysis/traffic-config.json';
if (!is_readable($sspa_traffic_config_path)) {
    return;
}
// The plugin folder can be missing while this file is installed: renamed or deleted over FTP to
// disable it, or mid-way through being replaced. Like the profiler loader and db.php, step aside
// rather than fatally erroring, which would take every page, wp-admin and the login down with it.
// Say why in a response header; nothing is written or logged per request.
foreach (array('includes/class-sspa-filesystem.php', 'includes/traffic/class-sspa-traffic-authority.php', 'traffic-observer/bootstrap.php') as $sspa_traffic_required) {
    if (!is_readable('%%SSPA_PLUGIN_DIR%%' . $sspa_traffic_required)) {
        if (!headers_sent()) {
            header('X-SSPA-Traffic-Observer: inactive; plugin files missing');
        }
        unset($sspa_traffic_uploads, $sspa_traffic_config_path, $sspa_traffic_required);
        return;
    }
}
unset($sspa_traffic_required);
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
