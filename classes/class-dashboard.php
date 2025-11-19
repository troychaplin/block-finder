<?php
/**
 * Enqueue assets.
 *
 * @package Block_Finder
 */

namespace Block_Finder;

use WP_Block_Type_Registry;

/**
 * Class Enqueues
 *
 * This class is responsible for enqueueing scripts and styles for the plugin.
 *
 * @package Block_Finder
 */
class Dashboard extends Plugin_Module {
	/**
	 * Initialize the module.
	 */
	public function init() {
		add_action( 'wp_dashboard_setup', array( $this, 'tc_block_finder_dashboard' ) );
		add_action( 'wp_ajax_find_blocks', array( $this, 'tc_block_finder_query' ) );
	}

	/**
	 * Renders the Block Finder dashboard page.
	 *
	 * This method is responsible for displaying the main dashboard interface
	 * for the Block Finder plugin in the WordPress admin area.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function tc_block_finder_dashboard() {
		add_meta_box( 'block_finder', esc_html__( 'Block Finder', 'block-finder' ), array( $this, 'tc_find_block_form' ), 'dashboard', 'normal', 'default' );
	}

	/**
	 * Renders the block finder search form in the dashboard.
	 *
	 * This method outputs the HTML form that allows users to search for
	 * specific blocks within their WordPress content.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function tc_find_block_form() {
		$all_blocks = WP_Block_Type_Registry::get_instance()->get_all_registered();

		// Get only blocks that can be inserted into the editor.
		$inserter_blocks = array_filter(
			$all_blocks,
			function ( $block_type ) {
				return isset( $block_type->supports['inserter'] ) ? $block_type->supports['inserter'] : true;
			}
		);

		// Sort inserter blocks alphabetically by title.
		uasort(
			$inserter_blocks,
			function ( $a, $b ) {
				return strcmp( $a->title, $b->title );
			}
		);

		// Get post types that support the Gutenberg editor.
		$post_types           = get_post_types( array( 'public' => true ), 'objects' );
		$gutenberg_post_types = array_filter(
			$post_types,
			function ( $post_type ) {
				return post_type_supports( $post_type->name, 'editor' );
			}
		);

		// Sort Gutenberg post types alphabetically by label.
		uasort(
			$gutenberg_post_types,
			function ( $a, $b ) {
				return strcmp( $a->label, $b->label );
			}
		);

		echo '<form id="block-finder-form">';
		echo '<label for="post-type-selector">' . esc_html__( 'Select a post type you wish to search in', 'block-finder' ) . '</label>';
		echo '<select id="post-type-selector" name="post_type">';
		echo '<option value="">' . esc_html__( '-- Select post type --', 'block-finder' ) . '</option>';
		echo '<option value="all">' . esc_html__( 'All Post Types', 'block-finder' ) . '</option>';
		foreach ( $gutenberg_post_types as $post_type ) {
			echo '<option value="' . esc_attr( $post_type->name ) . '">' . esc_html( $post_type->label ) . '</option>';
		}
		echo '</select>';

		echo '<label for="block-finder-selector">' . esc_html__( 'Select a block you would like to search for', 'block-finder' ) . '</label>';
		echo '<select id="block-finder-selector" name="block">';
		echo '<option value="">' . esc_html__( '-- Select block --', 'block-finder' ) . '</option>';
		foreach ( $inserter_blocks as $block_name => $block_type ) {
			if ( ! empty( $block_type->title ) ) {
				echo '<option value="' . esc_attr( $block_name ) . '">' . esc_html( $block_type->title ) . '</option>';
			}
		}
		echo '</select>';

		// Use WordPress button classes.
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Find Block', 'block-finder' ) . '</button>';
		echo '</form>';
		echo '<div id="block-finder-results"></div>';
	}

	/**
	 * Handles the AJAX query for finding blocks.
	 *
	 * This method processes AJAX requests to search and retrieve block usage data
	 * from the WordPress site. It should verify nonces and user permissions before
	 * executing the query.
	 *
	 * @since 1.0.0
	 * @access public
	 *
	 * @return void Outputs JSON response and terminates execution.
	 */
	public function tc_block_finder_query() {
		if ( ! check_ajax_referer( 'block_finder_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Nonce verification failed.', 'block-finder' ) ), 400 );
		}

		if ( empty( $_POST['block'] ) || empty( $_POST['post_type'] ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Block and post type values are required.', 'block-finder' ) ), 400 );
		}

		$block     = sanitize_text_field( wp_unslash( $_POST['block'] ) );
		$post_type = sanitize_text_field( wp_unslash( $_POST['post_type'] ) );

		$block_name = str_replace( 'core/', '', $block );
		$patterns   = array(
			'/<!-- wp:' . preg_quote( $block_name, '/' ) . '(.*?)-->/' => $block_name,
		);

		$found_elements = array();

		if ( $post_type === 'all' ) {
			$post_types           = get_post_types( array( 'public' => true ), 'names' );
			$gutenberg_post_types = array_filter(
				$post_types,
				function ( $post_type_name ) {
					return post_type_supports( $post_type_name, 'editor' );
				}
			);

			$args = array(
				'post_type'      => array_values( $gutenberg_post_types ),
				'nopaging'       => true,
				'posts_per_page' => -1,
			);
		} else {
			$args = array(
				'post_type'      => array( $post_type ),
				'nopaging'       => true,
				'posts_per_page' => -1,
			);
		}

		$query = new \WP_Query( $args );

		if ( ! $query->have_posts() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'No posts found for the selected post type.', 'block-finder' ) ), 404 );
		}

		while ( $query->have_posts() ) {
			$query->the_post();
			$content    = get_post_field( 'post_content', get_the_ID() );
			$post_id    = get_the_ID();
			$post_title = get_the_title();
			$post_url   = get_permalink();
			$edit_link  = get_edit_post_link( $post_id );

			if ( ! $post_title ) {
				$post_title = esc_html__( 'No title available', 'block-finder' );
			}

			foreach ( $patterns as $pattern => $category ) {
				if ( preg_match( $pattern, $content ) ) {
					$found_elements[ $category ][] = '<li>' . esc_html( $post_title ) . '<span><a href="' . esc_url( $edit_link ) . '">' . esc_html__( 'Edit', 'block-finder' ) . '</a><a href="' . esc_url( $post_url ) . '">' . esc_html__( 'View', 'block-finder' ) . '</a></span></li>';
				}
			}
		}

		wp_reset_postdata();

		if ( ! empty( $found_elements ) ) {
			foreach ( $found_elements as $category => $posts ) {
				$category_title = esc_html( ucwords( str_replace( '-', ' ', $category ) ) . ' Block' );
				echo '<h3>' . esc_html( $category_title ) . esc_html__( ' is used in the following:', 'block-finder' ) . '</h3>';
				echo '<ul>' . wp_kses_post( implode( '', $posts ) ) . '</ul>';
			}
		} else {
			echo '<ul><li>' . esc_html__( 'No blocks found', 'block-finder' ) . '</li></ul>';
		}

		wp_die();
	}
}
