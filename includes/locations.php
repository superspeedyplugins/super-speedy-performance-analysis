<?php
defined('ABSPATH') || exit;

// WordPress locations, defined once under the plugin prefix. Standalone on purpose: the main
// plugin and the MU-armed profiler both require this file, so the profiler never depends on
// the main plugin having loaded. A Fast Ajax/REST trim or a plugin organiser can leave the
// main plugin out of a profiled request; when these lived in the main plugin file, every such
// capture fataled at shutdown on SSPA_CORE_DIR (issue #47, 0.43.14 to 0.49.4).
define('SSPA_CONTENT_DIR', WP_CONTENT_DIR);
define('SSPA_MU_DIR', WPMU_PLUGIN_DIR);
define('SSPA_PLUGINS_DIR', WP_PLUGIN_DIR);
define('SSPA_CORE_DIR', ABSPATH);
