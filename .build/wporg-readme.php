#!/usr/bin/env php
<?php
/** Keep the current release notes in the submitted readme; source history remains complete. */
try {
	$file = $argv[1] ?? '';
	$readme = file_get_contents( $file );
	if ( ! preg_match( '/^== Changelog ==\R+(= [^\r\n]+ =\R.*?)(?=^= |^== |\z)/ms', $readme, $match, PREG_OFFSET_CAPTURE ) ) {
		throw new RuntimeException( 'Cannot find the current readme changelog section.' );
	}
	$start = $match[0][1];
	$tail = substr( $readme, $start + strlen( $match[0][0] ) );
	$next = preg_match( '/^== /m', $tail, $section, PREG_OFFSET_CAPTURE ) ? substr( $tail, $section[0][1] ) : '';
	$readme = substr( $readme, 0, $start ) . "== Changelog ==\n\n" . rtrim( $match[1][0] ) . "\n\n[Complete changelog](https://github.com/superspeedyplugins/super-speedy-performance-analysis/blob/main/readme.txt#changelog)\n\n" . $next;
	if ( false === file_put_contents( $file, $readme ) ) { throw new RuntimeException( 'Could not write the wp.org readme.' ); }
} catch ( Throwable $error ) {
	fwrite( STDERR, $error->getMessage() . PHP_EOL );
	exit( 1 );
}
