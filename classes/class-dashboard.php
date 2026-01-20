<?php
/**
 * Dashboard module.
 *
 * @package Block_Finder
 */

namespace Block_Finder;

use WP_Block_Type_Registry;

/**
 * Class Dashboard
 *
 * This class is responsible for rendering the Block Finder dashboard widget
 * and handling AJAX queries to search for block usage across posts.
 *
 * @package Block_Finder
 */
class Dashboard extends Plugin_Module {

	/**
	 * Number of results to show per page.
	 *
	 * @var int
	 */
	const RESULTS_PER_PAGE = 10;

	/**
	 * Cache expiration time in seconds (1 hour).
	 *
	 * @var int
	 */
	const CACHE_EXPIRATION = 3600;

	/**
	 * Initialize the module.
	 */
	public function init() {
		add_action( 'wp_dashboard_setup', array( $this, 'tc_block_finder_dashboard' ) );
		add_action( 'wp_ajax_find_blocks', array( $this, 'tc_block_finder_query' ) );

		// Clear cache when posts are saved, updated, or deleted.
		add_action( 'save_post', array( $this, 'tc_block_finder_clear_cache' ) );
		add_action( 'delete_post', array( $this, 'tc_block_finder_clear_cache' ) );
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

		// Show empty state if no blocks or post types are available.
		if ( empty( $inserter_blocks ) || empty( $gutenberg_post_types ) ) {
			echo '<div class="block-finder-empty-state">';
			if ( empty( $gutenberg_post_types ) ) {
				echo '<p>' . esc_html__( 'No post types with editor support found.', 'block-finder' ) . '</p>';
			}
			if ( empty( $inserter_blocks ) ) {
				echo '<p>' . esc_html__( 'No blocks are registered.', 'block-finder' ) . '</p>';
			}
			echo '</div>';
			return;
		}

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
	 * from the WordPress site. Uses database-level filtering for performance,
	 * transient caching, and pagination for large result sets.
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
		$page      = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
		$filter    = isset( $_POST['filter'] ) ? sanitize_text_field( wp_unslash( $_POST['filter'] ) ) : 'all';

		// Check for cached results.
		$cache_key = $this->tc_block_finder_get_cache_key( $block, $post_type );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			$this->tc_block_finder_render_results( $cached, $block, $page, $filter );
			wp_die();
		}

		// Query database directly for posts containing the block.
		$results = $this->tc_block_finder_database_search( $block, $post_type );

		if ( empty( $results ) ) {
			$block_name      = str_replace( 'core/', '', $block );
			$block_label     = ucwords( str_replace( '-', ' ', $block_name ) );
			$post_type_label = 'all' === $post_type
				? esc_html__( 'any post type', 'block-finder' )
				: get_post_type_object( $post_type )->labels->name ?? $post_type;

			echo '<div class="block-finder-no-results">';
			echo '<p>' . sprintf(
				/* translators: 1: block name, 2: post type name */
				esc_html__( 'No posts found using the %1$s block in %2$s.', 'block-finder' ),
				'<strong>' . esc_html( $block_label ) . '</strong>',
				'<strong>' . esc_html( $post_type_label ) . '</strong>'
			) . '</p>';
			echo '</div>';
			wp_die();
		}

		// Cache the results.
		set_transient( $cache_key, $results, self::CACHE_EXPIRATION );

		// Render paginated results.
		$this->tc_block_finder_render_results( $results, $block, $page, $filter );

		wp_die();
	}

	/**
	 * Performs a database-level search for posts containing a specific block.
	 *
	 * Uses $wpdb with LIKE clause to filter at the database level, then parses
	 * block content to detect nesting levels and parent blocks.
	 *
	 * @since 1.1.0
	 * @access private
	 *
	 * @param string $block     The block name to search for.
	 * @param string $post_type The post type to search in, or 'all' for all types.
	 * @return array Array of post data with id, title, edit_link, view_link, and block_instances.
	 */
	private function tc_block_finder_database_search( $block, $post_type ) {
		global $wpdb;

		// Build the block pattern to search for in the database.
		// We search for the block comment pattern: <!-- wp:blockname.
		$block_name     = str_replace( 'core/', '', $block );
		$search_pattern = '%<!-- wp:' . $wpdb->esc_like( $block_name ) . '%';

		// Get post types to search.
		if ( 'all' === $post_type ) {
			$post_types           = get_post_types( array( 'public' => true ), 'names' );
			$gutenberg_post_types = array_values(
				array_filter(
					$post_types,
					function ( $post_type_name ) {
						return post_type_supports( $post_type_name, 'editor' );
					}
				)
			);
		} else {
			$gutenberg_post_types = array( $post_type );
		}

		if ( empty( $gutenberg_post_types ) ) {
			return array();
		}

		// Build placeholders for post types.
		// Placeholders are safely generated from array_fill with %s format specifiers.
		$placeholders = implode( ', ', array_fill( 0, count( $gutenberg_post_types ), '%s' ) );

		// Prepare the query with post types and block pattern.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$query = $wpdb->prepare(
			"SELECT ID, post_title, post_content
			FROM {$wpdb->posts}
			WHERE post_type IN ($placeholders)
			AND post_status = 'publish'
			AND post_content LIKE %s
			ORDER BY post_title ASC",
			array_merge( $gutenberg_post_types, array( $search_pattern ) )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$posts = $wpdb->get_results( $query );

		if ( empty( $posts ) ) {
			return array();
		}

		$results = array();

		foreach ( $posts as $post ) {
			$post_title = $post->post_title ? $post->post_title : esc_html__( 'No title available', 'block-finder' );

			// Parse blocks and find instances of the target block with nesting info.
			$block_instances = $this->tc_block_finder_find_block_instances( $post->post_content, $block );

			$results[] = array(
				'id'              => $post->ID,
				'title'           => $post_title,
				'edit_link'       => get_edit_post_link( $post->ID, 'raw' ),
				'view_link'       => get_permalink( $post->ID ),
				'block_instances' => $block_instances,
			);
		}

		return $results;
	}

	/**
	 * Finds all instances of a specific block in content with nesting information.
	 *
	 * @since 1.2.0
	 * @access private
	 *
	 * @param string $content    The post content to parse.
	 * @param string $block_name The block name to search for (e.g., 'core/paragraph').
	 * @return array Array of block instances with nesting info.
	 */
	private function tc_block_finder_find_block_instances( $content, $block_name ) {
		$parsed_blocks = parse_blocks( $content );
		$instances     = array();

		$this->tc_block_finder_traverse_blocks( $parsed_blocks, $block_name, array(), $instances );

		return $instances;
	}

	/**
	 * Recursively traverses blocks to find instances of a target block.
	 *
	 * @since 1.2.0
	 * @access private
	 *
	 * @param array  $blocks      Array of parsed blocks.
	 * @param string $target_name The block name to find.
	 * @param array  $parent_chain Array of parent block names leading to current level.
	 * @param array  $instances   Reference to array collecting found instances.
	 * @return void
	 */
	private function tc_block_finder_traverse_blocks( $blocks, $target_name, $parent_chain, &$instances ) {
		foreach ( $blocks as $block ) {
			// Skip empty or null blocks.
			if ( empty( $block['blockName'] ) ) {
				continue;
			}

			$current_block_name = $block['blockName'];

			// Check if this block matches our target.
			if ( $current_block_name === $target_name ) {
				$instances[] = array(
					'is_root'      => empty( $parent_chain ),
					'parent_chain' => $parent_chain,
					'depth'        => count( $parent_chain ),
				);
			}

			// Recursively check inner blocks.
			if ( ! empty( $block['innerBlocks'] ) ) {
				$new_parent_chain = array_merge( $parent_chain, array( $current_block_name ) );
				$this->tc_block_finder_traverse_blocks( $block['innerBlocks'], $target_name, $new_parent_chain, $instances );
			}
		}
	}

	/**
	 * Renders the search results with pagination and filter buttons.
	 *
	 * @since 1.1.0
	 * @access private
	 *
	 * @param array  $results The array of post results.
	 * @param string $block   The block name that was searched for.
	 * @param int    $page    The current page number.
	 * @param string $filter  The filter type: 'all', 'root', or 'nested'.
	 * @return void
	 */
	private function tc_block_finder_render_results( $results, $block, $page, $filter = 'all' ) {
		// Store original count before filtering.
		$all_count = count( $results );

		// Count posts with nested instances.
		$nested_count = 0;
		foreach ( $results as $result ) {
			if ( ! empty( $result['block_instances'] ) ) {
				foreach ( $result['block_instances'] as $instance ) {
					if ( ! $instance['is_root'] ) {
						++$nested_count;
						break; // Only count once per post.
					}
				}
			}
		}

		// Apply filter to results.
		if ( 'nested' === $filter ) {
			$results = array_filter(
				$results,
				function ( $result ) {
					if ( empty( $result['block_instances'] ) ) {
						return false;
					}
					foreach ( $result['block_instances'] as $instance ) {
						if ( ! $instance['is_root'] ) {
							return true;
						}
					}
					return false;
				}
			);
			$results = array_values( $results ); // Re-index array.
		}

		$total_results = count( $results );
		$total_pages   = max( 1, ceil( $total_results / self::RESULTS_PER_PAGE ) );
		$page          = max( 1, min( $page, $total_pages ) );
		$offset        = ( $page - 1 ) * self::RESULTS_PER_PAGE;

		// Get results for current page.
		$paged_results = array_slice( $results, $offset, self::RESULTS_PER_PAGE );

		// Build category title.
		$block_name     = str_replace( 'core/', '', $block );
		$category_title = ucwords( str_replace( '-', ' ', $block_name ) ) . ' Block';

		// Results header with count.
		echo '<h3>' . esc_html( $category_title ) . esc_html__( ' is used in the following:', 'block-finder' );
		echo ' <span class="block-finder-count">(' . esc_html( $total_results ) . ' ' . esc_html( _n( 'post', 'posts', $total_results, 'block-finder' ) ) . ')</span>';
		echo '</h3>';

		// Filter links (only show if there are posts with nested blocks).
		if ( $nested_count > 0 ) {
			echo '<div class="block-finder-filters">';
			echo '<a href="#" class="block-finder-filter-link' . ( 'all' === $filter ? ' active' : '' ) . '" data-filter="all">';
			/* translators: %d: total number of posts */
			echo esc_html( sprintf( __( 'All Blocks (%d)', 'block-finder' ), $all_count ) );
			echo '</a>';
			echo '<a href="#" class="block-finder-filter-link' . ( 'nested' === $filter ? ' active' : '' ) . '" data-filter="nested">';
			/* translators: %d: number of posts with inner blocks */
			echo esc_html( sprintf( __( 'InnerBlocks (%d)', 'block-finder' ), $nested_count ) );
			echo '</a>';
			echo '</div>';
		}

		// Results list.
		echo '<ul class="block-finder-list">';
		foreach ( $paged_results as $result ) {
			// Determine if this post has root and/or nested instances.
			$has_root   = false;
			$has_nested = false;
			if ( ! empty( $result['block_instances'] ) ) {
				foreach ( $result['block_instances'] as $instance ) {
					if ( $instance['is_root'] ) {
						$has_root = true;
					} else {
						$has_nested = true;
					}
				}
			}

			// Build data attributes for filtering.
			$data_attrs = '';
			if ( $has_root ) {
				$data_attrs .= ' data-has-root="1"';
			}
			if ( $has_nested ) {
				$data_attrs .= ' data-has-nested="1"';
			}

			echo '<li' . $data_attrs . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<div class="block-finder-result-content">';
			echo '<span class="block-finder-result-title">' . esc_html( $result['title'] ) . '</span>';

			// Show context indicators for nested blocks.
			if ( ! empty( $result['block_instances'] ) ) {
				$nested_parents = array();
				foreach ( $result['block_instances'] as $instance ) {
					if ( ! $instance['is_root'] && ! empty( $instance['parent_chain'] ) ) {
						// Get the immediate parent (last item in the chain).
						$immediate_parent = end( $instance['parent_chain'] );
						// Remove namespace prefix and format the name.
						$parent_name                    = preg_replace( '/^[a-z0-9-]+\//', '', $immediate_parent );
						$parent_name                    = ucwords( str_replace( '-', ' ', $parent_name ) );
						$nested_parents[ $parent_name ] = true;
					}
				}

				// Only show parent indicators if there are nested instances.
				if ( ! empty( $nested_parents ) ) {
					echo '<span class="block-finder-context">';
					foreach ( array_keys( $nested_parents ) as $parent_name ) {
						echo '<span class="context-nested" data-type="nested">' . esc_html__( 'Parent:', 'block-finder' ) . ' ' . esc_html( $parent_name ) . '</span>';
					}
					echo '</span>';
				}
			}

			echo '</div>';
			echo '<span class="block-finder-result-actions">';
			echo '<a href="' . esc_url( $result['edit_link'] ) . '">' . esc_html__( 'Edit', 'block-finder' ) . '</a>';
			echo '<a href="' . esc_url( $result['view_link'] ) . '">' . esc_html__( 'View', 'block-finder' ) . '</a>';
			echo '</span>';
			echo '</li>';
		}
		echo '</ul>';

		// Pagination.
		if ( $total_pages > 1 ) {
			echo '<div class="block-finder-pagination" data-total-pages="' . esc_attr( $total_pages ) . '" data-current-page="' . esc_attr( $page ) . '">';
			echo '<span class="block-finder-page-info">';
			/* translators: 1: current page number, 2: total pages */
			echo esc_html( sprintf( __( 'Page %1$d of %2$d', 'block-finder' ), $page, $total_pages ) );
			echo '</span>';
			echo '<span class="block-finder-page-buttons">';

			if ( $page > 1 ) {
				echo '<button type="button" class="button block-finder-prev" data-page="' . esc_attr( $page - 1 ) . '">' . esc_html__( 'Previous', 'block-finder' ) . '</button>';
			}

			if ( $page < $total_pages ) {
				echo '<button type="button" class="button block-finder-next" data-page="' . esc_attr( $page + 1 ) . '">' . esc_html__( 'Next', 'block-finder' ) . '</button>';
			}

			echo '</span>';
			echo '</div>';
		}
	}

	/**
	 * Generates a cache key for block finder results.
	 *
	 * @since 1.1.0
	 * @access private
	 *
	 * @param string $block     The block name.
	 * @param string $post_type The post type.
	 * @return string The cache key.
	 */
	private function tc_block_finder_get_cache_key( $block, $post_type ) {
		return 'block_finder_' . md5( $block . '_' . $post_type );
	}

	/**
	 * Clears the block finder cache.
	 *
	 * Called when posts are saved, updated, or deleted to ensure
	 * search results remain accurate.
	 *
	 * @since 1.1.0
	 * @access public
	 *
	 * @return void
	 */
	public function tc_block_finder_clear_cache() {
		global $wpdb;

		// Delete all block finder transients.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				'_transient_block_finder_%',
				'_transient_timeout_block_finder_%'
			)
		);
	}
}
