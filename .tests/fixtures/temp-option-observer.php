<?php
// Read-only diagnostic of the real cross-process lifecycle. Never substitutes option data.
add_filter('pre_option_sspa_flow_temp', static function ($pre) {
    global $wpdb;
    $stored = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", SSPA_Checkout_Flow::TEMP_OPTION));
    $cached = wp_cache_get(SSPA_Checkout_Flow::TEMP_OPTION, 'options', false, $found);
    $missing = wp_cache_get('notoptions', 'options');
    $record = array('time' => microtime(true), 'stored' => maybe_unserialize($stored), 'cache_found' => $found, 'cached' => $cached, 'negative_cached' => is_array($missing) && isset($missing[SSPA_Checkout_Flow::TEMP_OPTION]));
    $dir = dirname(__DIR__, 2) . '/.data/lifecycle-observer';
    wp_mkdir_p($dir);
    file_put_contents($dir . '/temp-option.jsonl', wp_json_encode($record) . "\n", FILE_APPEND | LOCK_EX);
    return $pre;
});
