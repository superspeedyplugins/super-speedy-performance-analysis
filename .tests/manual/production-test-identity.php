<?php
// Give a guarded production collector test its own one-use installation identity.
// The disposable WordPress site is long-lived, while collector registrations are
// permanent. Reusing the site's ordinary identity would make a clean test depend on
// credentials left by an earlier run. These helpers restore every local value afterwards.

function sspa_production_test_identity_begin($collector) {
    $collector = untrailingslashit((string) $collector);
    $secret_option = 'sspa_collector_secret_' . md5($collector);
    $marker_option = 'sspa_collector_registered_' . md5($collector);
    $option_names = array('sspa_install_uuid', $secret_option, $marker_option);
    $snapshot = array();

    foreach ($option_names as $option_name) {
        $snapshot[$option_name] = array(
            'exists' => false !== get_option($option_name, false),
            'value' => get_option($option_name, null),
        );
    }

    update_option('sspa_install_uuid', wp_generate_uuid4(), false);
    delete_option($secret_option);
    delete_option($marker_option);

    return $snapshot;
}

function sspa_production_test_identity_restore($snapshot) {
    foreach ($snapshot as $option_name => $saved) {
        if ($saved['exists']) {
            update_option($option_name, $saved['value'], false);
        } else {
            delete_option($option_name);
        }
    }
}
