<?php
// A plugin reached through WordPress's supported symlink mapping keeps its real owner.
require __DIR__ . '/../lib/fleet.php';
require_once dirname(__DIR__, 2) . '/profiler/class-sspa-component-map.php';
$folder = ABSPATH . 'synthetic-symlink-plugin';
wp_mkdir_p($folder);
file_put_contents($folder . '/fixture.php', "<?php\n/**\n * Plugin Name: Synthetic Linked Attribution\n * Version: 1.0.0\n */\nfunction sspa_synthetic_linked_callback() { return true; }\n");
$link = WP_PLUGIN_DIR . '/sspa-linked-fixture';
if (!file_exists($link)) symlink($folder, $link);
activate_plugin('sspa-linked-fixture/fixture.php');
wp_register_plugin_realpath($link . '/fixture.php');
require_once $link . '/fixture.php';
$reflection = new ReflectionFunction('sspa_synthetic_linked_callback');
$map = new SSPA_Component_Map();
$actual = $map->classify_file($reflection->getFileName());
sspa_fleet_assert($actual['component'] === 'sspa-linked-fixture' && $actual['type'] === 'plugin', 'actual loaded symlinked callback is attributed to its WordPress plugin (actual ' . $actual['component'] . '/' . $actual['type'] . ')');
sspa_fleet_done();
