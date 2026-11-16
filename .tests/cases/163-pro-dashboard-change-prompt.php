<?php
// Pro shows the "plugin changes were detected, run a quick comparison" prompt on the Dashboard as
// well as on Plugins, Updates and its own screens, and still never on an unrelated screen. The
// wordpress.org edition keeps it off the Dashboard (stripped; checked from its zip by
// .tests/wporg/edition/run.sh). Drives the real change-set record and the real notice callback.
function sspa_163_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
    if (!$ok) { $GLOBALS['sspa_163_failures']++; }
}
$GLOBALS['sspa_163_failures'] = 0;
wp_set_current_user(1);
$active = SSPA_Run_Controller::active_run_id(); if ($active) SSPA_Run_Controller::cancel($active);
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';

// A real pending change set, cleared of any earlier run's choices on the way in.
delete_option(SSPA_Change_Set::OPTION);
SSPA_Change_Set::record('sspa-case163/sspa-case163.php', 'updated', '1.0.0', '1.1.0');
sspa_163_t((bool) SSPA_Change_Set::pending(), 'precondition: a plugin change is pending');

$render = static function ($screen) {
    set_current_screen($screen);
    ob_start();
    SSPA_Admin_Page::toggle_prompt_notice();
    return ob_get_clean();
};
foreach (array('dashboard' => true, 'plugins' => true, 'update-core' => true, 'edit-post' => false, 'options-general' => false) as $screen => $shown) {
    $html = $render($screen);
    $has = false !== strpos($html, 'plugin change was detected') || false !== strpos($html, 'plugin changes were detected');
    sspa_163_t($has === $shown, ($shown ? 'shown' : 'not shown') . ' on the ' . $screen . ' screen');
}
delete_option(SSPA_Change_Set::OPTION);
if ($GLOBALS['sspa_163_failures']) { exit(1); }
