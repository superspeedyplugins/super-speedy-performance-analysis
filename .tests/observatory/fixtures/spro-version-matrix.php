<?php
/**
 * Permanent local fixture for comparing Scalability Pro pagination behaviour across releases.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'init',
	function () {
		register_post_type(
			'machine',
			array(
				'label'        => 'Machines',
				'public'       => true,
				'show_ui'      => true,
				'has_archive'  => true,
				'rewrite'      => array( 'slug' => 'machines' ),
				'supports'     => array( 'title', 'editor' ),
			)
		);
	},
	1
);

// Reproduce the reported cross-plugin filter boundary. Priority 2 deliberately restores the
// contract so WordPress itself cannot become a second, unrelated source of count(null) errors.
add_filter(
	'posts_results',
	function ( $posts, $query ) {
		return $query->get( 'spro_observatory_null_probe' ) ? null : $posts;
	},
	0,
	2
);
add_filter(
	'posts_results',
	function ( $posts, $query ) {
		return $query->get( 'spro_observatory_null_probe' ) && ! is_array( $posts ) ? array() : $posts;
	},
	2,
	2
);

add_shortcode(
	'spro_observatory_matrix',
	function () {
		$page = isset( $_GET['fixture_page'] ) ? max( 1, absint( $_GET['fixture_page'] ) ) : 1;
		$case = isset( $_GET['fixture_case'] ) ? sanitize_key( wp_unslash( $_GET['fixture_case'] ) ) : 'pagination';
		$args = array(
			'post_type'        => 'machine',
			'post_status'      => 'publish',
			'posts_per_page'   => 10,
			'paged'            => $page,
			'orderby'          => 'title',
			'order'            => 'ASC',
			'suppress_filters' => false,
		);
		if ( 'empty' === $case ) {
			$args['post__in'] = array( 0 );
		}
		if ( 'null' === $case ) {
			$args['spro_observatory_null_probe'] = true;
		}

		$query = new WP_Query( $args );
		$links = paginate_links(
			array(
				'base'      => add_query_arg( 'fixture_page', '%#%' ),
				'format'    => '',
				'current'   => $page,
				'total'     => max( 1, (int) $query->max_num_pages ),
				'type'      => 'plain',
				'prev_text' => 'Previous',
				'next_text' => 'Next',
			)
		);
		$has_next = false !== strpos( (string) $links, 'next page-numbers' );
		$html = sprintf(
			'<section id="spro-observatory-result" data-case="%s" data-page="%d" data-count="%d" data-found="%d" data-pages="%d" data-next="%d">',
			esc_attr( $case ),
			$page,
			count( $query->posts ),
			(int) $query->found_posts,
			(int) $query->max_num_pages,
			$has_next ? 1 : 0
		);
		$html .= '<h2>Scalability Pro version matrix</h2><div class="spro-observatory-items">';
		foreach ( $query->posts as $post ) {
			$html .= '<article data-machine-id="' . (int) $post->ID . '">' . esc_html( $post->post_title ) . '</article>';
		}
		$html .= '</div><nav class="spro-observatory-pagination">' . $links . '</nav></section>';
		wp_reset_postdata();
		return $html;
	}
);
