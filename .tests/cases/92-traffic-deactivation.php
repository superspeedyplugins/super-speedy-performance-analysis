<?php
// This lifecycle regression must never deactivate the caller's suite site.
require SSPA_PLUGIN_DIR . '.tests/lib/fleet.php';
$command = array('bash', SSPA_PLUGIN_DIR . '.tests/deactivation/run.sh');
$process = proc_open($command, array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')), $pipes);
if (!is_resource($process)) throw new RuntimeException('Cannot run isolated deactivation regression');
fclose($pipes[0]);
$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);
fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
echo $output;
sspa_fleet_assert($code===0 && strpos($output,'FAIL:')===false,'isolated deactivation regression passes'.($code ? ': '.$error : ''));
sspa_fleet_done();
