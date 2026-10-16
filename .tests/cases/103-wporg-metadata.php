<?php
// Read the actual installable metadata with WordPress's plugin-header reader.
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$header = get_plugin_data(SSPA_PLUGIN_FILE, false, false);
$readme = file_get_contents(SSPA_PLUGIN_DIR . 'readme.txt');
preg_match('/^Stable tag:\s*(\S+)/m', $readme, $stable);
echo (($stable[1] ?? '') === $header['Version'] ? 'PASS' : 'FAIL') . ': Stable tag equals installed plugin header Version (' . ($stable[1] ?? 'missing') . '/' . $header['Version'] . ")\n";
$pot = file_get_contents(SSPA_PLUGIN_DIR . 'languages/super-speedy-performance-analysis.pot');
echo (strpos($pot, 'Project-Id-Version: ' . $header['Name'] . ' ' . $header['Version'] . '\n') !== false ? 'PASS' : 'FAIL') . ": translation template identifies this build\n";
