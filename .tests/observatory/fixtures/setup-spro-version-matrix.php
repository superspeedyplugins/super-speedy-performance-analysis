<?php
/** Seed the deterministic version-matrix fixture at the start of each observatory preparation. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

$existing = get_posts(
	array(
		'post_type'      => 'machine',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);
foreach ( $existing as $post_id ) {
	wp_delete_post( $post_id, true );
}
for ( $number = 1; $number <= 25; $number++ ) {
	wp_insert_post(
		array(
			'post_type'    => 'machine',
			'post_status'  => 'publish',
			'post_title'   => sprintf( 'Matrix machine %02d', $number ),
			'post_content' => 'Deterministic Scalability Pro observatory fixture.',
		)
	);
}

$page = get_page_by_path( 'spro-version-matrix' );
$page_id = $page ? (int) $page->ID : 0;
$page_id = wp_insert_post(
	array(
		'ID'           => $page_id,
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_name'    => 'spro-version-matrix',
		'post_title'   => 'Scalability Pro version matrix',
		'post_content' => '[spro_observatory_matrix]',
	)
);
if ( is_wp_error( $page_id ) ) {
	WP_CLI::error( $page_id->get_error_message() );
}

$options = get_option( 'wpiperf_settings', array() );
$options = is_array( $options ) ? $options : array();
$options['calctotals'] = 'remove';
$options['calctotals_admin'] = 'remove';
$options['calctotals_pagecount'] = 'progressive';
update_option( 'wpiperf_settings', $options, false );
flush_rewrite_rules( false );
WP_CLI::log( 'Seeded 25 machines and the version-matrix page.' );
