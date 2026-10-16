<?php
function w1_traffic_t($ok, $label) { echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n"; }
$active = SSPA_Traffic_Collection::active(); if ($active) SSPA_Traffic_Collection::stop((int) $active['id'], true);
$started = SSPA_Traffic_Collection::start('1h', 'test');
if (is_wp_error($started)) { w1_traffic_t(false, $started->get_error_message()); return; }
$id = (int) $started['collection']['id'];
$path = wp_upload_dir()['basedir'] . '/super-speedy-performance-analysis/traffic-config.json';
$config = is_file($path) ? json_decode(file_get_contents($path), true) : null;
w1_traffic_t(is_array($config) && (int) $config['collection_id'] === $id, 'real collection writes JSON configuration in uploads');
w1_traffic_t(is_file($path) && (fileperms($path) & 0777) === 0600, 'traffic configuration is readable and writable only by its owner');
$bytes = file_get_contents(SSPA_Traffic_Helper::path());
w1_traffic_t($bytes === file_get_contents(SSPA_PLUGIN_DIR . 'mu/sspa-traffic-observer.php'), 'installed observer is the fixed source file with no generated PHP');
// Simulate upgrading the previously installed generated file while a real collection runs.
// The archived production template plus this real collection's generated data is a
// complete, valid legacy observer, not a partial or invented PHP configuration.
if (!is_array($config)) {
    SSPA_Traffic_Collection::stop($id, true);
    return; // The prerequisite JSON assertion above already reports the failure.
}
$legacy_template = file_get_contents(__DIR__ . '/../fixtures/legacy-traffic-observer.template');
$old = str_replace(
    array('/* %%SSPA_TRAFFIC_CONFIG_ASSIGNMENT%% */', '%%SSPA_TRAFFIC_PLUGIN_DIR%%'),
    array('$sspa_traffic_config = ' . var_export($config, true) . ';', addslashes(trailingslashit(SSPA_PLUGIN_DIR))),
    $legacy_template
);
file_put_contents(SSPA_Traffic_Helper::path(), $old);
if (is_file($path)) unlink($path);
update_option('sspa_behaviour_version', '0.43.4');
SSPA_Install::maybe_upgrade();
$config = is_file($path) ? json_decode(file_get_contents($path), true) : null;
w1_traffic_t(is_array($config) && (int) $config['collection_id'] === $id && file_get_contents(SSPA_Traffic_Helper::path()) === file_get_contents(SSPA_PLUGIN_DIR . 'mu/sspa-traffic-observer.php'), 'upgrade replaces generated config from the active collection without evaluating old PHP');
SSPA_Traffic_Collection::stop($id, true);
w1_traffic_t(!is_file($path), 'emergency stop removes live configuration');

// Existing observer-contract safety still retires a collection from an older observation format.
$started = SSPA_Traffic_Collection::start('1h', 'test');
if (is_wp_error($started)) { w1_traffic_t(false, $started->get_error_message()); return; }
$id = (int) $started['collection']['id'];
$config = json_decode(file_get_contents($path), true);
$old = str_replace(array('/* %%SSPA_TRAFFIC_CONFIG_ASSIGNMENT%% */', '%%SSPA_TRAFFIC_PLUGIN_DIR%%'), array('$sspa_traffic_config = ' . var_export($config, true) . ';', addslashes(trailingslashit(SSPA_PLUGIN_DIR))), $legacy_template);
file_put_contents(SSPA_Traffic_Helper::path(), $old);
unlink($path);
global $wpdb;
w1_traffic_t(false !== $wpdb->update(SSPA_Schema::table('traffic_collections'), array('observer_version' => 3), array('id' => $id)), 'old observer-contract fixture updates the real persisted version field');
update_option('sspa_behaviour_version', '0.43.4');
SSPA_Install::maybe_upgrade();
w1_traffic_t(!SSPA_Traffic_Collection::active() && !is_file($path) && !is_file(SSPA_Traffic_Helper::path()), 'an old observer-contract collection retires its generated configuration under the existing mismatch policy');
