<?php
// Clicking the top-level Performance Analysis admin-bar item must do the same thing as its
// first child, Analyse this page. It must not navigate to the full report, which is the
// second child and has its own explicit menu entry.

function sspa_admin_bar_parent_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
}

wp_set_current_user(1);
show_admin_bar(true);
require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';

$sspa_bar = new WP_Admin_Bar();
$sspa_bar->initialize();
SSPA_Admin_Bar::nodes($sspa_bar);
SSPA_Adhoc::admin_bar_node($sspa_bar);

$sspa_parent = $sspa_bar->get_node('sspa-menu');
$sspa_analyse = $sspa_bar->get_node('sspa-adhoc');
$sspa_report = $sspa_bar->get_node('sspa-open-report');

sspa_admin_bar_parent_t($sspa_parent && $sspa_analyse, 'parent and Analyse this page nodes render');
sspa_admin_bar_parent_t(
    $sspa_parent && $sspa_analyse && $sspa_parent->href === $sspa_analyse->href,
    'clicking Performance Analysis targets Analyse this page'
);
sspa_admin_bar_parent_t(
    $sspa_parent && $sspa_report && $sspa_parent->href !== $sspa_report->href,
    'the parent does not target Open the full report'
);
