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
	$readme = substr( $readme, 0, $start ) . "== Changelog ==\n\n" . rtrim( $match[1][0] ) . "\n\n[Complete changelog](https://github.com/superspeedyplugins/super-speedy-performance-analysis/releases)\n\n" . $next;
	$readme = preg_replace('/^This plugin is free\. The build downloaded.*\R/m', '', $readme);
    $readme = str_replace('1. In Plugins -> Add New -> Upload Plugin, upload the ZIP for your chosen distribution channel.', '1. In Plugins -> Add New, search for Super Speedy Performance Analysis and install it.', $readme);
    $readme = str_replace('3. Open Performance Analysis in the admin menu, or Super Speedy -> Performance Analysis when the shared Super Speedy menu is installed.', '3. Open Performance in the admin menu.', $readme);
	// The wordpress.org edition creates no user and sends no logged-in customer request. Each
	// replacement must match, so a readme rewording cannot leave the Pro wording in place.
	$edition = array(
		'The profiler is installed automatically: activation, the first analysis and the first admin page after an update install or refresh `wp-content/mu-plugins/sspa-loader.php` and, only when no drop-in exists, the conditional database drop-in `wp-content/db.php`. Disable profiler in Tools removes both and keeps them off until Enable profiler; deactivation removes both. Installation honours DISALLOW_FILE_MODS, and only files signed by this plugin are ever removed.'
			=> 'Activation writes nothing outside the plugin folder. The explicit Enable profiler action in Tools installs `wp-content/mu-plugins/sspa-loader.php` and, only when no drop-in exists, the conditional database drop-in `wp-content/db.php`; Disable profiler and deactivation remove both. Enable honours DISALLOW_FILE_MODS, and only files signed by this plugin are ever removed.',
		'Community figures on the Compare tab: when an administrator opens the Compare tab (at most once an hour, from a cached copy otherwise) or presses "Load community figures", the plugin downloads'
			=> 'Community figures on the Compare tab: only when an administrator presses "Load community figures", the plugin downloads',
		'Nothing is downloaded until the Compare tab is opened, and sharing is not needed to load the figures.'
			=> 'Nothing is downloaded until the button is pressed, and sharing is not needed to load the figures.',
		'Customer-session measurements create a restricted test customer account that cannot be logged into and is replaced on the next run. Administrator measurements use your existing account.'
			=> 'Administrator measurements use your own login, from the browser session that starts them; the plugin never creates, edits or deletes a user account. With guest checkout disabled, WooCommerce itself creates a customer account for the checkout flow\'s test purchase, and the results link to WordPress\'s Delete Users screen for it.',
		'then seven per page from the server - two anonymous, two carrying WooCommerce\'s basket cookies with no basket behind them, two as the plugin\'s synthetic logged-in test customer, and one final anonymous request that checks no logged-in content reached the anonymous cache. That is 36 requests for four pages.'
			=> 'then five per page from the server - two anonymous, two carrying WooCommerce\'s basket cookies with no basket behind them, and one final anonymous request. That is 28 requests for four pages.',
	);
	foreach ( $edition as $from => $to ) {
		if ( 1 !== substr_count( $readme, $from ) ) {
			throw new RuntimeException( 'wp.org readme: expected exactly one "' . substr( $from, 0, 60 ) . '..."' );
		}
		$readme = str_replace( $from, $to, $readme );
	}
	if ( false === file_put_contents( $file, $readme ) ) { throw new RuntimeException( 'Could not write the wp.org readme.' ); }
} catch ( Throwable $error ) {
	fwrite( STDERR, $error->getMessage() . PHP_EOL );
	exit( 1 );
}
