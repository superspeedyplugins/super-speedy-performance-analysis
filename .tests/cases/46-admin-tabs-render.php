<?php
// Every admin tab renders: real markup, no SQL error, and nothing that should have been a
// PHP comment escaping into the page.
//
// Added 16 August 2026. The last one is not hypothetical: adding the /* translators: */
// comments that Plugin Check requires put 17 of them on their own line in the tab templates,
// which are inline-HTML files. Outside a <?php block that text is not a comment, it is page
// content, and it would have rendered to every admin visitor. Plugin Check still reported
// those 17 as MISSING comments (they were not attached to the gettext call), which is what
// gave it away - but nothing in the suite would have caught the visible-text half.
//
// The tab files are included directly rather than driven through admin-ajax: the menu hook
// sits behind is_admin(), which is false under wp-cli, so firing admin_menu proves nothing.

function sspa_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

global $wpdb;
wp_set_current_user(1);

$sspa_tabs = array('overview', 'pages', 'plugins', 'history', 'traffic', 'share');

// A tab that renders almost nothing is a broken tab, not a passing one. These floors are
// well under the real sizes (2.5k-10k) but well over an error page or an empty string.
$sspa_min_bytes = array(
    'overview' => 2000, 'pages' => 800, 'plugins' => 1500,
    'history' => 1500, 'traffic' => 800, 'share' => 2000,
);

foreach ($sspa_tabs as $sspa_tab) {
    $sspa_file = SSPA_PLUGIN_DIR . 'includes/admin/tabs/' . $sspa_tab . '.php';
    if (!file_exists($sspa_file)) {
        sspa_t(false, "tab template missing: $sspa_tab");
        continue;
    }

    $wpdb->last_error = '';
    ob_start();
    try {
        include $sspa_file;
    } catch (\Throwable $sspa_e) {
        ob_end_clean();
        sspa_t(false, "$sspa_tab threw: " . $sspa_e->getMessage());
        continue;
    }
    $sspa_html = ob_get_clean();

    sspa_t($wpdb->last_error === '', "$sspa_tab renders with no SQL error");
    sspa_t(strlen($sspa_html) >= $sspa_min_bytes[$sspa_tab],
        "$sspa_tab renders real markup (" . strlen($sspa_html) . ' >= ' . $sspa_min_bytes[$sspa_tab] . ' bytes)');
    sspa_t(stripos($sspa_html, 'database error') === false, "$sspa_tab shows no database error");

    // The regression this case exists for.
    sspa_t(strpos($sspa_html, 'translators:') === false, "$sspa_tab leaks no translators comment");
    sspa_t(strpos($sspa_html, '/*') === false && strpos($sspa_html, 'phpcs:') === false,
        "$sspa_tab leaks no PHP comment");

    // A placeholder that never got substituted reads as a broken sentence to the user.
    sspa_t(!preg_match('/%[0-9]+\$[sd]/', $sspa_html), "$sspa_tab has no unsubstituted printf placeholder");
}

// The same class of mistake, checked at the source rather than in one rendered state: no
// translators comment in ANY shipped template may sit outside a PHP block. A tab whose
// branch did not execute above would hide one from the render assertions.
$sspa_outside = array();
foreach (glob(SSPA_PLUGIN_DIR . 'includes/admin/tabs/*.php') as $sspa_path) {
    $sspa_lines = file($sspa_path, FILE_IGNORE_NEW_LINES);
    $sspa_in_php = false;
    foreach ($sspa_lines as $sspa_n => $sspa_line) {
        if (!$sspa_in_php && trim($sspa_line) !== '' && strpos(trim($sspa_line), '/* translators:') === 0) {
            $sspa_outside[] = basename($sspa_path) . ':' . ($sspa_n + 1);
        }
        // Track PHP context across the line AFTER testing its start.
        $sspa_off = 0;
        while (true) {
            if (!$sspa_in_php) {
                $sspa_pos = strpos($sspa_line, '<?php', $sspa_off);
                if ($sspa_pos === false) { break; }
                $sspa_in_php = true; $sspa_off = $sspa_pos + 5;
            } else {
                $sspa_pos = strpos($sspa_line, '?>', $sspa_off);
                if ($sspa_pos === false) { break; }
                $sspa_in_php = false; $sspa_off = $sspa_pos + 2;
            }
        }
    }
}
sspa_t(empty($sspa_outside),
    'no translators comment sits outside a PHP block' . ($sspa_outside ? ' (' . implode(', ', $sspa_outside) . ')' : ''));
