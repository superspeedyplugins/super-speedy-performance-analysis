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
foreach (array('sspa-state-object-cache', 'sspa-state-excimer') as $id) {
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

// There is no digests node. "no rows-examined" read as "the query view is missing" when
// queries and counts are fully visible; performance_schema only adds the server's own
// rows-examined and no-index counters, and that does not earn a warning on every admin page.
// The Tools card still explains the setting for anyone who goes looking.
sspa_79_t(null === $bar->get_node('sspa-state-digests'), 'the admin bar carries no MySQL digests / query fingerprints node');
$mentions = array();
foreach ($bar->get_nodes() as $node) {
    $text = wp_strip_all_tags((string) $node->title) . ' ' . (isset($node->meta['title']) ? $node->meta['title'] : '');
    if (0 === strpos((string) $node->id, 'sspa-') && preg_match('/rows-examined|query fingerprints|MySQL digests|performance_schema/i', $text)) {
        $mentions[] = $node->id;
    }
}
sspa_79_t(!$mentions, 'no admin-bar node mentions digests, fingerprints or rows-examined (' . implode(', ', $mentions) . ')');
$excimer = $bar->get_node('sspa-state-excimer');
sspa_79_t($excimer && '' !== wp_strip_all_tags((string) $excimer->title), 'the Excimer node remains, as the capability worth surfacing');

if ($GLOBALS['sspa_79_failures']) { exit(1); }
