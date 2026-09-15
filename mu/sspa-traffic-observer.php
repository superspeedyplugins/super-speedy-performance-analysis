<?php
// Super Speedy Performance Analysis experimental traffic observer.
// This generated file exists only while an explicitly started collection is active.
defined('ABSPATH') || exit;

$sspa_traffic_config = array('outcomes_until' => 0);
/* %%SSPA_TRAFFIC_CONFIG_ASSIGNMENT%% */
if (time() > (int) $sspa_traffic_config['outcomes_until']) {
    return;
}

require_once '%%SSPA_TRAFFIC_PLUGIN_DIR%%includes/traffic/class-sspa-traffic-authority.php';
if (!SSPA_Traffic_Authority::observe($sspa_traffic_config, static function() { return true; })) {
    return;
}
require_once '%%SSPA_TRAFFIC_PLUGIN_DIR%%traffic-observer/bootstrap.php';
SSPA_Traffic_Hot_Path::boot($sspa_traffic_config);
