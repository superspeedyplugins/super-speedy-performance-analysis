<?php
// Compare the production browser extractor with the production PHP scanner on real HTTP output.
require_once SSPA_PLUGIN_DIR . '.tests/lib/fleet.php';
$fixture = WPMU_PLUGIN_DIR . '/sspa-browser-evidence-test.php';
if (!copy(SSPA_PLUGIN_DIR . '.tests/fixtures/browser-evidence-runtime.php', $fixture)) throw new RuntimeException('Cannot install response-evidence fixture');
foreach (array(home_url('/'), add_query_arg('sspa_w3_browser_evidence', '1', home_url('/'))) as $response_url) {
$response = wp_remote_get($response_url, array('timeout' => 30));
sspa_fleet_assert(!is_wp_error($response) && 200 === wp_remote_retrieve_response_code($response), 'real Home response is available for both extractors');
if (is_wp_error($response)) { continue; }
$body = wp_remote_retrieve_body($response);
$headers = array();
foreach (wp_remote_retrieve_headers($response) as $name => $value) { $headers[strtolower($name)] = $value; }
$path = SSPA_PLUGIN_DIR . '.data/w3/browser-evidence-input.json';
wp_mkdir_p(dirname($path));
file_put_contents($path, wp_json_encode(array('body' => $body, 'headers' => $headers)));
$node = <<<'JS'
const fs = require('node:fs'), vm = require('node:vm');
const context = {window:{}, TextEncoder};
vm.createContext(context);
vm.runInContext(fs.readFileSync(process.argv[1], 'utf8'), context);
const input = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
process.stdout.write(JSON.stringify(context.window.SSPABrowserEvidence.derive(input.body, input.headers)));
JS;
$process = proc_open(array('node', '-e', $node, SSPA_PLUGIN_DIR . 'includes/admin/js/sspa-browser-evidence.js', $path), array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')), $pipes);
fclose($pipes[0]); $raw = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
sspa_fleet_assert(0 === $exit, 'production browser extractor executes: ' . $error);
$derived = SSPA_Cache_Recon::validate_browser_evidence(map_deep(json_decode($raw, true), 'sanitize_text_field'));
sspa_fleet_assert(!is_wp_error($derived), 'browser-derived fields survive the actual server validation');
if (!is_wp_error($derived)) {
    // Browser first limits JS characters, then PHP used to limit UTF-8 bytes.
    $bounded = substr($body, 0, 20000);
    sspa_fleet_assert(SSPA_Crawler::body_hash($bounded, $headers) === $derived['body_hash'], 'browser output identity equals the established PHP hash on actual page bytes');
    $php = SSPA_Cache_Recon::scan_response($bounded, $headers, array(), true);
    if (false !== strpos($response_url, 'sspa_w3_browser_evidence')) {
        sspa_fleet_assert(!empty($php['nonce_names']) && $php['forms'] > 0 && $php['client_state_reads']['cookie'] > 0 && in_array('wishlist', $php['private_surface_hints'], true), 'real WordPress fixture produces nonce, form, state-read and private-region evidence');
    }
    $browser = SSPA_Cache_Recon::from_browser_evidence($derived, $headers, array());
    file_put_contents(SSPA_PLUGIN_DIR . '.data/w3/browser-evidence-comparison.json', wp_json_encode(array('php'=>$php,'browser'=>$browser)));
    sspa_fleet_assert($php == $browser, 'browser privacy-safe signals retain the established response-scanner evidence');
}
}
sspa_fleet_done();
