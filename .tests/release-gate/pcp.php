<?php
/** Exercises the actual PCP report gate and readme transformation without WordPress. */
$root = dirname( __DIR__, 2 );
$work = $root . '/.data/wporg-review/validator-tests';
if ( ! is_dir( $work ) ) { mkdir( $work, 0775, true ); }
function pcp_test_command( array $arguments, $expected, $description ) {
	$process = proc_open( array_merge( array( PHP_BINARY ), $arguments ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'redirect', 1 ) ), $pipes );
	fclose( $pipes[0] );
	$output = stream_get_contents( $pipes[1] );
	fclose( $pipes[1] );
	$exit = proc_close( $process );
	if ( ( 0 === $expected ) !== ( 0 === $exit ) ) { throw new RuntimeException( $description . ': unexpected exit ' . $exit . "\n" . $output ); }
	echo 'PASS: ' . $description . PHP_EOL;
	return $output;
}
$allowed = array( 'WordPress.DB.DirectDatabaseQuery.DirectQuery', 'WordPress.DB.DirectDatabaseQuery.NoCaching', 'WordPress.DB.DirectDatabaseQuery.SchemaChange', 'WordPress.DB.SlowDBQuery.slow_db_query_meta_key', 'WordPress.DB.SlowDBQuery.slow_db_query_meta_value' );
$rows = array();
foreach ( $allowed as $code ) { $rows[] = array( 'file' => 'example.php', 'line' => 7, 'type' => 'WARNING', 'code' => $code, 'message' => 'Synthetic checker finding.' ); }
$gate = $root . '/.build/validate-pcp.php';
$report = $work . '/report.json';
file_put_contents( $report, json_encode( $rows ) );
pcp_test_command( array( $gate, $report ), 0, 'all five approved database warning codes pass the real validator' );
$forbidden = $rows[0];
$forbidden['code'] = 'WordPress.Security.EscapeOutput.OutputNotEscaped';
file_put_contents( $report, json_encode( array_merge( $rows, array( $forbidden ) ) ) );
pcp_test_command( array( $gate, $report ), 1, 'one forbidden warning fails among permitted database warnings' );
$forbidden['code'] = $allowed[0];
$forbidden['type'] = 'ERROR';
file_put_contents( $report, json_encode( array( $forbidden ) ) );
pcp_test_command( array( $gate, $report ), 1, 'an error with an allowed warning code still fails' );
foreach ( array( '', '[', '{}', '[{}]', '[{"type":"WARNING"}]' ) as $broken ) {
	file_put_contents( $report, $broken );
	pcp_test_command( array( $gate, $report ), 1, 'incomplete or malformed output fails: ' . json_encode( $broken ) );
}
file_put_contents( $report, '[]' );
pcp_test_command( array( $gate, $report ), 0, 'completed empty finding list passes' );
$readme = $work . '/readme.txt';
file_put_contents( $readme, "Before\n== Changelog ==\n\n= 1.2.3 =\n* Current.\n\n= 1.2.2 =\n* Previous.\n\n== Upgrade Notice ==\nKeep this.\n" );
pcp_test_command( array( $root . '/.build/wporg-readme.php', $readme ), 0, 'real staged readme transform runs' );
$text = file_get_contents( $readme );
if ( false === strpos( $text, '* Current.' ) || false !== strpos( $text, '* Previous.' ) || false === strpos( $text, 'Keep this.' ) || false === strpos( $text, 'Complete changelog' ) ) { throw new RuntimeException( 'Staged readme lost current/other content or retained old releases.' ); }
echo "PASS: staged readme preserves current and following sections, links full history and removes older entries\n";
