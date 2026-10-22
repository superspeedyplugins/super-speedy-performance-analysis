<?php
// SSPA test fixture for case 141: copied to the test site's docroot as zz-sspa-opcache-status.php.
// An independent, read-only opcache_get_status(false) from the same php-fpm that serves the site,
// to check the profiler's environment probe against. Loads no WordPress.
$sspa_status = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
header('Content-Type: application/json');
echo json_encode(array('sapi' => PHP_SAPI, 'status' => is_array($sspa_status) ? array(
    'enabled' => !empty($sspa_status['opcache_enabled']),
    'cache_full' => !empty($sspa_status['cache_full']),
    'memory_total' => $sspa_status['memory_usage']['used_memory'] + $sspa_status['memory_usage']['free_memory'] + $sspa_status['memory_usage']['wasted_memory'],
    'max_cached_keys' => $sspa_status['opcache_statistics']['max_cached_keys'],
    'oom_restarts' => $sspa_status['opcache_statistics']['oom_restarts'],
    'start_time' => $sspa_status['opcache_statistics']['start_time'],
) : null));
