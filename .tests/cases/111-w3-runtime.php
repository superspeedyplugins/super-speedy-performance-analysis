<?php
// Exercise the real rendering, authority and registered-ability boundaries.
function sspa_111_t($ok, $message) { echo ($ok ? 'PASS' : 'FAIL') . ': ' . $message . "\n"; }
wp_set_current_user(1);
$uploads = wp_upload_dir(null, false);
sspa_111_t(dirname(SSPA_Traffic_Authority::path()) === $uploads['basedir'] . '/super-speedy-performance-analysis', 'traffic authority uses the site uploads directory');
sspa_111_t(dirname(SSPA_Traffic_Helper::stopped_path()) === $uploads['basedir'] . '/super-speedy-performance-analysis', 'traffic stop marker uses the site uploads directory');
sspa_111_t(in_array(SSPA_Abilities::CATEGORY . '/compare-traffic-collections', SSPA_Abilities::ability_names(), true), 'agent discovery lists the registered traffic comparison');
sspa_111_t(SSPA_Abilities::CATEGORY === 'sspa', 'registered abilities use the declared SSPA namespace');
$document = SSPA_History_Series::build(0, 'request_wall_ms');
if (!is_wp_error($document)) {
    $html = SSPA_History_Chart::render($document);
    sspa_111_t(strpos($html, '<script') === false && strpos($html, 'data-document=') !== false, 'History data is an escaped attribute without inline script markup');
}

// Keep network-wide file ownership in the normal suite, on its own native site.
putenv('SSPA_MULTISITE_SCENARIO=' . (getenv('SSPA_MULTISITE_SCENARIO') ?: 'tests-w3-multisite'));
$process = proc_open(array('bash', SSPA_PLUGIN_DIR . '.tests/multisite/run.sh'), array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')), $pipes);
if (!is_resource($process)) { throw new RuntimeException('Could not start network ownership regression'); }
fclose($pipes[0]); $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
echo $output;
sspa_111_t(0 === $exit && false === strpos($output, 'FAIL:'), 'isolated multisite ownership regression passes' . ($exit ? ': ' . $error : ''));
