<?php
/** Records the actual runner selection; refuses a silently static-only CLI run. */
WP_CLI::add_hook( 'after_wp_load', static function () {
	$runner = \WordPress\Plugin_Check\Utilities\Plugin_Request_Utility::get_runner();
	if ( ! $runner ) { WP_CLI::error( 'Plugin Check did not initialize its runtime runner.' ); }
	$checks = $runner->get_checks_to_run();
	$slugs = array_keys( $checks );
	sort( $slugs );
	$path = getenv( 'SSPA_PCP_EVIDENCE' );
	$inventory = json_decode( file_get_contents( $path . '/checks.json' ), true );
	$expected = array_column( $inventory, 'slug' );
	sort( $expected );
	if ( $slugs !== $expected || count( $expected ) !== count( array_unique( $expected ) ) || count( $expected ) < 34 ) {
		WP_CLI::error( 'Actual Plugin Check runner does not contain the complete static/runtime inventory.' );
	}
	if ( false === file_put_contents( $path . '/selected-checks.json', json_encode( $slugs, JSON_PRETTY_PRINT ) . PHP_EOL ) ) {
		WP_CLI::error( 'Could not record the actual Plugin Check inventory.' );
	}
} );
