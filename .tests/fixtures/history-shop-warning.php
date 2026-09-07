<?php
/** Test-only MU fixture: a real warning during PA measurements of the Shop page. */
defined('ABSPATH') || exit;
add_action('template_redirect', function () {
    if ('local' !== wp_get_environment_type() || empty($GLOBALS['sspa_capture']) || !function_exists('is_shop') || !is_shop()) {
        return;
    }
    $reporting = error_reporting(E_ALL);
    trigger_error('PA demonstration warning: the Shop page emitted this deliberate test warning.', E_USER_WARNING);
    error_reporting($reporting);
}, 20);
