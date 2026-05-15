<?php
/**
 * REST API controller for Block Finder searches.
 *
 * @package Block_Finder
 */

namespace Block_Finder;

use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Handles searching post content for block usage via the REST API.
 */
class REST_Controller extends WP_REST_Controller {

	/**
	 * Number of results to show per page.
	 *
	 * @var int
	 */
	const RESULTS_PER_PAGE = 10;

	/**
	 * Cache expiration time in seconds.
	 *
	 * @var int
	 */
	const CACHE_EXPIRATION = HOUR_IN_SECONDS;

	/**
	 * Transient key prefix. Structured as `{prefix}{post_type}_{hash}` so we
	 * can invalidate by post type with a LIKE query on save_post / delete_post.
	 *
	 * @var string
	 */
	const CACHE_PREFIX = 'block_finder_';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->namespace = 'block-finder/v1';
		$this->rest_base = 'search';
	}

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		// Surgical cache invalidation: only flush caches whose post_type matches the saved/deleted post.
		add_action( 'save_post', array( $this, 'invalidate_cache_for_post' ), 10, 2 );
		add_action( 'delete_post', array( $this, 'invalidate_cache_for_post' ), 10, 2 );
		add_action( 'wp_trash_post', array( $this, 'invalidate_cache_for_post' ) );
		add_action( 'untrashed_post', array( $this, 'invalidate_cache_for_post' ) );
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
			)
		);
	}

	/**
	 * Query args schema for the search endpoint.
	 *
	 * @return array
	 */
	public function get_collection_params() {
		return array(
			'block'     => array(
				'description'       => __( 'Block name to search for (e.g. core/paragraph).', 'block-finder' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'post_type' => array(
				'description'       => __( 'Post type slug, or "all" for every editor-supporting public post type.', 'block-finder' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'page'      => array(
				'description' => __( 'Page number for pagination.', 'block-finder' ),
				'type'        => 'integer',
				'default'     => 1,
				'minimum'     => 1,
			),
			'filter'    => array(
				'description' => __( 'Result subset: "all" or "nested" (only posts with InnerBlocks instances).', 'block-finder' ),
				'type'        => 'string',
				'default'     => 'all',
				'enum'        => array( 'all', 'nested' ),
			),
		);
	}

	/**
	 * Permission callback. The dashboard widget is admin-only and `edit_posts` is the right gate.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function get_items_permissions_check( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		if ( current_user_can( 'edit_posts' ) ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'Sorry, you are not allowed to search for blocks.', 'block-finder' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Run the search and return rendered HTML plus a total count.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_items( $request ) {
		$block     = $request->get_param( 'block' );
		$post_type = $request->get_param( 'post_type' );
		$page      = (int) $request->get_param( 'page' );
		$filter    = $request->get_param( 'filter' );

		$cache_key = $this->get_cache_key( $block, $post_type );
		$results   = get_transient( $cache_key );

		if ( false === $results ) {
			$results = $this->database_search( $block, $post_type );
			set_transient( $cache_key, $results, self::CACHE_EXPIRATION );
		}

		if ( empty( $results ) ) {
			$html = $this->render_no_results( $block, $post_type );
		} else {
			$html = $this->render_results( $results, $block, $post_type, $page, $filter );
		}

		return rest_ensure_response(
			array(
				'html'  => $html,
				'total' => count( $results ),
			)
		);
	}

	/**
	 * Database-level search for posts containing the given block, parsed for nesting context.
	 *
	 * @param string $block     Block name (e.g. core/paragraph).
	 * @param string $post_type Post type slug or "all".
	 * @return array
	 */
	private function database_search( $block, $post_type ) {
		global $wpdb;

		$block_name     = str_replace( 'core/', '', $block );
		$search_pattern = '%<!-- wp:' . $wpdb->esc_like( $block_name ) . '%';

		if ( 'all' === $post_type ) {
			$candidate_post_types = array_values(
				array_filter(
					get_post_types( array( 'public' => true ), 'names' ),
					static fn( $type ) => post_type_supports( $type, 'editor' )
				)
			);
		} else {
			$candidate_post_types = array( $post_type );
		}

		if ( empty( $candidate_post_types ) ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $candidate_post_types ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$query = $wpdb->prepare(
			"SELECT ID, post_title, post_content
			FROM {$wpdb->posts}
			WHERE post_type IN ($placeholders)
			AND post_status = 'publish'
			AND post_content LIKE %s
			ORDER BY post_title ASC",
			array_merge( $candidate_post_types, array( $search_pattern ) )
		);

		$posts = $wpdb->get_results( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $posts ) ) {
			return array();
		}

		$results = array();

		foreach ( $posts as $post ) {
			$results[] = array(
				'id'              => $post->ID,
				'title'           => $post->post_title ? $post->post_title : __( 'No title available', 'block-finder' ),
				'edit_link'       => get_edit_post_link( $post->ID, 'raw' ),
				'view_link'       => get_permalink( $post->ID ),
				'block_instances' => $this->find_block_instances( $post->post_content, $block ),
			);
		}

		return $results;
	}

	/**
	 * Parse blocks in content and locate every instance of $target with its parent chain.
	 *
	 * @param string $content    Post content.
	 * @param string $target     Block name to find.
	 * @return array
	 */
	private function find_block_instances( $content, $target ) {
		$instances = array();
		$this->traverse_blocks( parse_blocks( $content ), $target, array(), $instances );
		return $instances;
	}

	/**
	 * Recursive traversal that records each match with its parent chain.
	 *
	 * @param array  $blocks       Parsed blocks.
	 * @param string $target       Block name to find.
	 * @param array  $parent_chain Names of ancestor blocks at this depth.
	 * @param array  $instances    Accumulator (by reference).
	 */
	private function traverse_blocks( $blocks, $target, $parent_chain, &$instances ) {
		foreach ( $blocks as $block ) {
			if ( empty( $block['blockName'] ) ) {
				continue;
			}

			if ( $block['blockName'] === $target ) {
				$instances[] = array(
					'is_root'      => empty( $parent_chain ),
					'parent_chain' => $parent_chain,
					'depth'        => count( $parent_chain ),
				);
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$this->traverse_blocks(
					$block['innerBlocks'],
					$target,
					array_merge( $parent_chain, array( $block['blockName'] ) ),
					$instances
				);
			}
		}
	}

	/**
	 * Render the "no results" message for a given block + post type combination.
	 *
	 * @param string $block     Block name.
	 * @param string $post_type Post type slug or "all".
	 * @return string
	 */
	private function render_no_results( $block, $post_type ) {
		$block_label     = ucwords( str_replace( '-', ' ', str_replace( 'core/', '', $block ) ) );
		$post_type_label = 'all' === $post_type
			? __( 'any post type', 'block-finder' )
			: ( get_post_type_object( $post_type )?->labels?->name ?? $post_type );

		ob_start();
		?>
		<div class="block-finder-no-results">
			<p>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: 1: block name, 2: post type name */
						__( 'No posts found using the %1$s block in %2$s.', 'block-finder' ),
						'<strong>' . esc_html( $block_label ) . '</strong>',
						'<strong>' . esc_html( $post_type_label ) . '</strong>'
					),
					array( 'strong' => array() )
				);
				?>
			</p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render the results list with pagination and filter links.
	 *
	 * @param array  $results   Posts found.
	 * @param string $block     Block name searched for.
	 * @param string $post_type Post type slug or "all".
	 * @param int    $page      Current page.
	 * @param string $filter    "all" or "nested".
	 * @return string
	 */
	private function render_results( $results, $block, $post_type, $page, $filter ) {
		$all_count    = count( $results );
		$nested_count = 0;

		foreach ( $results as $result ) {
			foreach ( $result['block_instances'] as $instance ) {
				if ( ! $instance['is_root'] ) {
					++$nested_count;
					break;
				}
			}
		}

		if ( 'nested' === $filter ) {
			$results = array_values(
				array_filter(
					$results,
					static function ( $result ) {
						foreach ( $result['block_instances'] as $instance ) {
							if ( ! $instance['is_root'] ) {
								return true;
							}
						}
						return false;
					}
				)
			);
		}

		$total_results = count( $results );
		$total_pages   = max( 1, (int) ceil( $total_results / self::RESULTS_PER_PAGE ) );
		$page          = max( 1, min( $page, $total_pages ) );
		$offset        = ( $page - 1 ) * self::RESULTS_PER_PAGE;
		$paged_results = array_slice( $results, $offset, self::RESULTS_PER_PAGE );

		$block_label     = ucwords( str_replace( '-', ' ', str_replace( 'core/', '', $block ) ) );
		$post_type_label = $this->get_post_type_label_for_count( $post_type, $total_results );

		$heading = sprintf(
			/* translators: 1: block name, 2: formatted number of matches, 3: post type label (pluralised for count) */
			__( '%1$s block has been found in %2$s %3$s', 'block-finder' ),
			$block_label,
			number_format_i18n( $total_results ),
			$post_type_label
		);

		ob_start();
		?>
		<h3><?php echo esc_html( $heading ); ?></h3>
		<?php if ( $nested_count > 0 ) : ?>
			<div class="block-finder-filters">
				<a href="#" class="block-finder-filter-link<?php echo 'all' === $filter ? ' active' : ''; ?>" data-filter="all">
					<?php
					/* translators: %d: total number of posts */
					echo esc_html( sprintf( __( 'All Blocks (%d)', 'block-finder' ), $all_count ) );
					?>
				</a>
				<a href="#" class="block-finder-filter-link<?php echo 'nested' === $filter ? ' active' : ''; ?>" data-filter="nested">
					<?php
					/* translators: %d: number of posts with Innerblock instances */
					echo esc_html( sprintf( __( 'Innerblocks (%d)', 'block-finder' ), $nested_count ) );
					?>
				</a>
			</div>
		<?php endif; ?>
		<ul class="block-finder-list">
			<?php foreach ( $paged_results as $result ) : ?>
				<?php
				$total_instances  = count( $result['block_instances'] );
				$nested_instances = 0;
				$has_root         = false;
				foreach ( $result['block_instances'] as $instance ) {
					if ( $instance['is_root'] ) {
						$has_root = true;
					} else {
						++$nested_instances;
					}
				}
				?>
				<li
					<?php echo $has_root ? ' data-has-root="1"' : ''; ?>
					<?php echo $nested_instances > 0 ? ' data-has-nested="1"' : ''; ?>
				>
					<div class="block-finder-result-content">
						<span class="block-finder-result-title"><?php echo esc_html( $result['title'] ); ?></span>
						<span class="block-finder-meta">
							<span class="block-finder-meta-item">
								<?php
								/* translators: %s: number of block instances in this post */
								echo esc_html( sprintf( __( 'Count: %s', 'block-finder' ), number_format_i18n( $total_instances ) ) );
								?>
							</span>
							<?php if ( $nested_instances > 0 ) : ?>
								<span class="block-finder-meta-sep" aria-hidden="true">|</span>
								<span class="block-finder-meta-item">
									<?php
									/* translators: %s: number of times the block appears as an InnerBlock */
									echo esc_html( sprintf( __( 'As Innerblock: %s', 'block-finder' ), number_format_i18n( $nested_instances ) ) );
									?>
								</span>
							<?php endif; ?>
						</span>
					</div>
					<span class="block-finder-result-actions">
						<a href="<?php echo esc_url( $result['edit_link'] ); ?>"><?php esc_html_e( 'Edit', 'block-finder' ); ?></a>
						<a href="<?php echo esc_url( $result['view_link'] ); ?>"><?php esc_html_e( 'View', 'block-finder' ); ?></a>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php if ( $total_pages > 1 ) : ?>
			<div class="block-finder-pagination" data-total-pages="<?php echo esc_attr( (string) $total_pages ); ?>" data-current-page="<?php echo esc_attr( (string) $page ); ?>">
				<span class="block-finder-page-info">
					<?php
					/* translators: 1: current page number, 2: total pages */
					echo esc_html( sprintf( __( 'Page %1$d of %2$d', 'block-finder' ), $page, $total_pages ) );
					?>
				</span>
				<span class="block-finder-page-buttons">
					<?php if ( $page > 1 ) : ?>
						<button type="button" class="button block-finder-prev" data-page="<?php echo esc_attr( (string) ( $page - 1 ) ); ?>">
							<?php esc_html_e( 'Previous', 'block-finder' ); ?>
						</button>
					<?php endif; ?>
					<?php if ( $page < $total_pages ) : ?>
						<button type="button" class="button block-finder-next" data-page="<?php echo esc_attr( (string) ( $page + 1 ) ); ?>">
							<?php esc_html_e( 'Next', 'block-finder' ); ?>
						</button>
					<?php endif; ?>
				</span>
			</div>
		<?php endif; ?>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Pluralised, lower-cased post type label suitable for inline use ("12 pages", "1 product").
	 *
	 * For 'all', falls back to "post(s)" since matches can span multiple types.
	 *
	 * @param string $post_type Post type slug or "all".
	 * @param int    $count     Match count.
	 * @return string
	 */
	private function get_post_type_label_for_count( $post_type, $count ) {
		if ( 'all' === $post_type ) {
			return _n( 'post', 'posts', $count, 'block-finder' );
		}

		$post_type_obj = get_post_type_object( $post_type );
		if ( ! $post_type_obj ) {
			return $post_type;
		}

		$label = 1 === $count
			? $post_type_obj->labels->singular_name
			: $post_type_obj->labels->name;

		return strtolower( $label );
	}

	/**
	 * Build the transient key. Post type prefix lets us invalidate per-post-type with LIKE.
	 *
	 * @param string $block     Block name.
	 * @param string $post_type Post type slug or "all".
	 * @return string
	 */
	private function get_cache_key( $block, $post_type ) {
		return self::CACHE_PREFIX . $post_type . '_' . md5( $block );
	}

	/**
	 * Clear cached results for the post's post type (and the "all" aggregate).
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post   Post object when provided by the hook.
	 */
	public function invalidate_cache_for_post( $post_id, $post = null ) {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! $post instanceof \WP_Post ) {
			$post = get_post( $post_id );
		}

		if ( ! $post ) {
			return;
		}

		global $wpdb;

		$patterns = array(
			'_transient_' . self::CACHE_PREFIX . $post->post_type . '_%',
			'_transient_timeout_' . self::CACHE_PREFIX . $post->post_type . '_%',
			'_transient_' . self::CACHE_PREFIX . 'all_%',
			'_transient_timeout_' . self::CACHE_PREFIX . 'all_%',
		);

		foreach ( $patterns as $pattern ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
					$pattern
				)
			);
		}
	}
}
