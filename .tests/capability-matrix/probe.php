<?php
// Invoked in real WordPress under each container's actual PHP extensions and DB user.
$expected_enabled = getenv('SSPA_MATRIX_EXTENSIONS') === '1';
$expected_ps = getenv('SSPA_MATRIX_PS');
global $sspa_matrix_failures;
$sspa_matrix_failures = array();
function sspa_matrix_check($ok, $label) {
    if (!$ok) { $GLOBALS['sspa_matrix_failures'][] = $label; }
    echo ($ok ? 'PASS: ' : 'FAIL: ') . $label . "\n";
}
$caps = SSPA_Tools::capabilities();
$ps = SSPA_Tools::performance_schema();
foreach (array('xhprof'=>'tideways_xhprof', 'spx'=>'spx') as $extension=>$key) {
    sspa_matrix_check(extension_loaded($extension) === $expected_enabled, "$extension actual extension presence");
    sspa_matrix_check($caps[$key]['status'] === ($expected_enabled ? 'available' : 'missing') && $caps[$key]['used'] === false, "$key detected without claiming use");
}
sspa_matrix_check(extension_loaded('opentelemetry') === $expected_enabled, 'actual OpenTelemetry extension presence');
$apms = SSPA_Tools::detected_apms();
sspa_matrix_check(isset($apms['opentelemetry']) === $expected_enabled, 'OpenTelemetry APM detection matches real extension');
sspa_matrix_check($ps['status'] === ($expected_ps === 'active' ? 'available' : $expected_ps), 'database permission detection matches actual account');
sspa_matrix_check($caps['performance_schema']['status'] === $expected_ps, 'database capability card status');
sspa_matrix_check(class_exists('Automattic\\WooCommerce\\Utilities\\OrderUtil') && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(), 'HPOS enabled');
ob_start();
include SSPA_PLUGIN_DIR . 'includes/admin/tabs/tools.php';
$html = ob_get_clean();
file_put_contents('/evidence/tools.html', $html);
foreach (array('tideways_xhprof','spx','performance_schema') as $key) {
    $label = preg_quote(esc_html($caps[$key]['label']), '~');
    $status = preg_quote($caps[$key]['status'], '~');
    sspa_matrix_check((bool) preg_match('~<tr>\s*<td><strong>'.$label.'</strong>.*?sspa-status-'.$status.'~s', $html), "$key real rendered card matches detection");
    $needs = in_array($caps[$key]['status'], array('missing','blocked'), true);
    sspa_matrix_check((strpos($html, 'id="sspa-steps-'.$key.'"') !== false) === $needs, "$key installation guidance appears only when needed");
}
sspa_matrix_check((strpos($html, 'OpenTelemetry') !== false) === $expected_enabled, 'rendered optional APM matches detection');
sspa_matrix_check(strpos($html, 'never installs anything itself') !== false, 'rendered instructions are guidance only');
$tokens = token_get_all(file_get_contents(SSPA_PLUGIN_DIR.'includes/class-sspa-tools.php'));
$forbidden = array('exec','shell_exec','passthru','proc_open','popen','system','file_put_contents','ini_set');
foreach ($tokens as $i=>$token) {
    if (!is_array($token) || $token[0] !== T_STRING || !in_array(strtolower($token[1]),$forbidden,true)) { continue; }
    $j=$i+1; while (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) { $j++; }
    sspa_matrix_check(!isset($tokens[$j]) || $tokens[$j] !== '(', 'no executable installation call '.$token[1]);
}
file_put_contents('/evidence/detection.json', wp_json_encode(array('php'=>PHP_VERSION,'extensions'=>get_loaded_extensions(),'capabilities'=>$caps,'performance_schema'=>$ps,'apms'=>$apms,'failures'=>$sspa_matrix_failures), JSON_PRETTY_PRINT));
if ($sspa_matrix_failures) { exit(1); }
echo "PASS: capability matrix row\n";
