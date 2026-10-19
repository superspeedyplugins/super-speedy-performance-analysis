<?php
// Read the actual installable metadata with WordPress's plugin-header reader.
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$header = get_plugin_data(SSPA_PLUGIN_FILE, false, false);
$readme = file_get_contents(SSPA_PLUGIN_DIR . 'readme.txt');
preg_match('/^Stable tag:\s*(\S+)/m', $readme, $stable);
echo (($stable[1] ?? '') === $header['Version'] ? 'PASS' : 'FAIL') . ': Stable tag equals installed plugin header Version (' . ($stable[1] ?? 'missing') . '/' . $header['Version'] . ")\n";
$pot = file_get_contents(SSPA_PLUGIN_DIR . 'languages/super-speedy-performance-analysis.pot');
echo (strpos($pot, 'Project-Id-Version: ' . $header['Name'] . ' ' . $header['Version'] . '\n') !== false ? 'PASS' : 'FAIL') . ": translation template identifies this build\n";

// WP-CLI generates these synopses from the registered commands, not a copied specification.
foreach (array('cache-optimisation-report', 'traffic observations', 'traffic compare') as $command) {
    $help = WP_CLI::runcommand('help sspa ' . $command, array('return' => 'stdout', 'launch' => true, 'exit_error' => false));
    $ok = is_string($help) && false !== strpos($help, 'SYNOPSIS') && false === strpos($help, '--output');
    echo ($ok ? 'PASS' : 'FAIL') . ': real WP-CLI help for ' . $command . " advertises stdout without an output-path option\n";
}
