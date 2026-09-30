#!/usr/bin/env php
<?php
/** Fail closed on every PCP error and every warning outside the documented database exceptions. */
try {
	if ( 2 !== $argc ) { throw new RuntimeException( 'Usage: validate-pcp.php <strict-json-report>' ); }
	$raw = file_get_contents( $argv[1] );
	$rows = json_decode( $raw, true );
	if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $rows ) || '[' !== substr( ltrim( $raw ), 0, 1 ) ) {
		throw new RuntimeException( 'Plugin Check did not produce a complete JSON result list.' );
	}
	$allowed = array(
		'WordPress.DB.DirectDatabaseQuery.DirectQuery',
		'WordPress.DB.DirectDatabaseQuery.NoCaching',
		'WordPress.DB.DirectDatabaseQuery.SchemaChange',
		'WordPress.DB.SlowDBQuery.slow_db_query_meta_key',
		'WordPress.DB.SlowDBQuery.slow_db_query_meta_value',
	);
	$counts = array();
	$forbidden = 0;
	foreach ( $rows as $row ) {
		foreach ( array( 'file', 'type', 'code', 'message' ) as $key ) {
			if ( ! is_array( $row ) || ! isset( $row[ $key ] ) || ! is_string( $row[ $key ] ) || '' === trim( $row[ $key ] ) ) {
				throw new RuntimeException( 'Incomplete Plugin Check finding: ' . $key );
			}
		}
		$counts[ $row['code'] ] = ( $counts[ $row['code'] ] ?? 0 ) + 1;
		if ( 'WARNING' !== $row['type'] || ! in_array( $row['code'], $allowed, true ) ) {
			++$forbidden;
			fwrite( STDERR, $row['type'] . ' ' . $row['file'] . ':' . ( $row['line'] ?? 0 ) . ' ' . $row['code'] . ': ' . $row['message'] . PHP_EOL );
		}
	}
	ksort( $counts );
	foreach ( $counts as $code => $count ) { echo $count . ' ' . $code . PHP_EOL; }
	if ( $forbidden ) { throw new RuntimeException( $forbidden . ' forbidden Plugin Check findings.' ); }
	echo 'PASS: Plugin Check has zero errors and only permitted database warnings (' . count( $rows ) . ').' . PHP_EOL;
} catch ( Throwable $error ) {
	fwrite( STDERR, 'FAIL: ' . $error->getMessage() . PHP_EOL );
	exit( 1 );
}
