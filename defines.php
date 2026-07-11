<?php
defined('ABSPATH') || exit;

// All plugin settings live in one option row (autoloaded: it is small and read on every
// admin page). Profiling data lives in the sspa_* tables, never in options.
function sspa_default_options() {
    return array(
        'remove_data_on_uninstall' => false,
        'blob_retention_runs' => 5, // used by the manual "delete older than" button, never auto-pruned
    );
}

function sspa_get_option($key) {
    $options = get_option('sspa_options', array());
    $defaults = sspa_default_options();
    if (array_key_exists($key, $options)) {
        return $options[$key];
    }
    return array_key_exists($key, $defaults) ? $defaults[$key] : null;
}

function sspa_update_option($key, $value) {
    $options = get_option('sspa_options', array());
    $options[$key] = $value;
    update_option('sspa_options', $options);
}
