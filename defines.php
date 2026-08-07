<?php
defined('ABSPATH') || exit;

// All plugin settings live in one option row (autoloaded: it is small and read on every
// admin page). Profiling data lives in the sspa_* tables, never in options.
function sspa_default_options() {
    return array(
        'remove_data_on_uninstall' => false,
        'blob_retention_runs' => 5, // used by the manual "delete older than" button, never auto-pruned

        // Checkout flow profiling. All defaults work unconfigured, so the button does
        // something sensible on a store that has never opened these settings.
        'checkout_product_id' => 0,       // 0 = cheapest purchasable, in-stock, simple product
        'checkout_quantity' => 1,
        'checkout_address' => null,       // null = store base country, valid postcode for it
        'checkout_email' => '',           // '' = admin_email tagged +sspa-perf-<run_id>
        'checkout_mail_mode' => 'deliver', // deliver | construct | suppress
        // Enum: no_payment | sandbox | live_declined. sandbox is only selectable when a
        // gateway adapter matched a gateway in test mode; live_declined is reserved and
        // deliberately unbuilt (doc A6.3).
        'checkout_payment_mode' => 'no_payment',
        'checkout_allow_integrations' => true,
        'checkout_allow_webhooks' => true,
        'checkout_consent' => false,      // set once the disclosure has been accepted
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
