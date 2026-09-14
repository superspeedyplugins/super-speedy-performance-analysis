<?php
// Admin-bar "This site" nodes must deep-link to the Tools tab by URL fragment. Tab selection
// is driven by location.hash alone (sspa-admin.js), so a ?tab=tools query parameter is
// ignored and the visitor lands on Overview. The digests node must also describe the
// performance_schema state the server is actually in, using the same words as the Tools card
// it points at, rather than a fixed sentence that is only true when the schema is on but
// unreadable.

function sspa_79_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
    if (!$ok) { $GLOBALS['sspa_79_failures']++; }
}
$GLOBALS['sspa_79_failures'] = 0;

wp_set_current_user(1);
show_admin_bar(true);
require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';

$bar = new WP_Admin_Bar();
$bar->initialize();
SSPA_Admin_Bar::nodes($bar);

$tools = admin_url('admin.php?page=sspa#tools');
foreach (array('sspa-state-object-cache', 'sspa-state-excimer', 'sspa-state-digests') as $id) {
    $node = $bar->get_node($id);
    sspa_79_t($node && is_string($node->href), $id . ' renders with an href');
    $href = $node ? (string) $node->href : '';
    sspa_79_t(false === strpos($href, 'tab='), $id . ' does not guess a ?tab= parameter (' . $href . ')');
    sspa_79_t($href === $tools, $id . ' links to the Tools tab by fragment (' . $href . ')');
}

// No node anywhere in the plugin's menu may carry a ?tab= parameter: the admin page ignores it.
$guessed = array();
foreach ($bar->get_nodes() as $node) {
    if (0 === strpos((string) $node->id, 'sspa-') && !empty($node->href) && false !== strpos((string) $node->href, 'tab=')) {
        $guessed[] = $node->id;
    }
}
sspa_79_t(!$guessed, 'no sspa admin-bar node uses a ?tab= parameter (' . implode(', ', $guessed) . ')');

// The digests node reports the real performance_schema state in the Tools card's own words.
$ps = SSPA_Tools::performance_schema();
$caps = SSPA_Tools::capabilities();
$card_label = $caps['performance_schema']['label'];
$node = $bar->get_node('sspa-state-digests');
$title = $node ? wp_strip_all_tags((string) $node->title) : '';
$tooltip = $node && isset($node->meta['title']) ? (string) $node->meta['title'] : '';
echo "INFO: performance_schema status on this database: " . $ps['status'] . "\n";
sspa_79_t(false !== strpos($title, $card_label), 'digests node names the Tools card it points at (' . $title . ')');
sspa_79_t(false !== strpos($tooltip, $ps['detail']), 'digests tooltip carries the performance_schema detail for this server (' . $tooltip . ')');
if (!$ps['on']) {
    sspa_79_t(false === stripos($tooltip, 'one GRANT'), 'with performance_schema off, the tooltip does not claim one GRANT is enough');
}

if ($GLOBALS['sspa_79_failures']) { exit(1); }
