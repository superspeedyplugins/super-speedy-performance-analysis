#!/usr/bin/env php
<?php
require_once __DIR__ . '/release-evidence.php';
$root = dirname( __DIR__ );
$manifest_path = '.github/feature-release-gate.json';
$mode = 'strict';
foreach ( array_slice( $argv, 1 ) as $argument ) {
	if ( 0 === strpos( $argument, '--root=' ) ) { $root = substr( $argument, 7 ); }
	elseif ( '--manifest-only' === $argument || '--record-tests' === $argument ) { $mode = $argument; }
	else { fwrite( STDERR, 'Unknown argument: ' . $argument . PHP_EOL ); exit( 2 ); }
}
try {
	$file = $root . '/' . $manifest_path;
	$manifest = is_file( $file ) ? json_decode( file_get_contents( $file ), true ) : null;
	if ( ! is_array( $manifest ) || 1 !== ( $manifest['schema'] ?? null ) || 'main' !== ( $manifest['core_branch'] ?? '' ) ) { throw new RuntimeException( 'Invalid PA feature-release manifest.' ); }
	foreach ( array( 'repository_environment', 'ref', 'path' ) as $field ) {
		if ( empty( $manifest['plan'][ $field ] ) || ! is_string( $manifest['plan'][ $field ] ) ) { throw new RuntimeException( 'Missing plan.' . $field ); }
	}
	sspa_gate_release_contract( $manifest );
	if ( ! empty( $manifest['release']['core_bugs'] ) ) { throw new RuntimeException( 'Private core issue declarations are not supported in this public repository.' ); }
	if ( '--manifest-only' === $mode ) { fwrite( STDOUT, 'Manifest structure is valid; release readiness was not checked.' . PHP_EOL ); }
	elseif ( '--record-tests' === $mode ) { sspa_gate_record( $root, $manifest, $manifest_path ); }
	else {
		$manifest['plan']['repository'] = getenv( $manifest['plan']['repository_environment'] );
		if ( ! $manifest['plan']['repository'] ) { throw new RuntimeException( 'Set ' . $manifest['plan']['repository_environment'] . ' for the private plan checkout.' ); }
		sspa_gate_strict( $root, $manifest, $manifest_path, getenv( 'SSPA_GATE_METADATA_ROOT' ) );
	}
} catch ( Throwable $error ) { fwrite( STDERR, 'ERROR: ' . $error->getMessage() . PHP_EOL ); exit( 1 ); }
