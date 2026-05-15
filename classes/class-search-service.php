<?php
/**
 * Search service: owns the block-search query, parse, and cache pipeline.
 *
 * The REST controller (and any future CLI / popular-blocks endpoint / templates
 * source) talks to this class instead of running queries directly. Keeping the
 * pipeline in one place means one definition of "what counts as a block instance"
 * and one cache strategy across the plugin.
 *
 * @package Block_Finder
 */

namespace Block_Finder;

/**
 * Service that runs block searches against post content with parse + cache support.
 */
class Search_Service {

	/**
	 * Search-result cache TTL.
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
	 * Register the cache-invalidation hooks. Call once at bootstrap.
	 */
	public function init() {
		// Surgical cache invalidation: only flush caches whose post_type matches the saved/deleted post.
		add_action( 'save_post', array( $this, 'invalidate_cache_for_post' ), 10, 2 );
		add_action( 'delete_post', array( $this, 'invalidate_cache_for_post' ), 10, 2 );
		add_action( 'wp_trash_post', array( $this, 'invalidate_cache_for_post' ) );
		add_action( 'untrashed_post', array( $this, 'invalidate_cache_for_post' ) );
	}

	/**
	 * Run a block search, returning the parsed result set.
	 *
	 * Reads from / writes to the transient cache transparently. Callers do not
	 * touch the cache directly.
	 *
	 * @param string   $block       Block name (e.g. core/paragraph).
	 * @param string   $post_type   Post type slug or "all".
	 * @param string[] $post_status Statuses to include (publish, draft, pending, future, private).
	 * @return array
	 */
	public function search( $block, $post_type, $post_status ) {
		$cache_key = $this->get_cache_key( $block, $post_type, $post_status );
		$results   = get_transient( $cache_key );

		if ( false === $results ) {
			$results = $this->database_search( $block, $post_type, $post_status );
			set_transient( $cache_key, $results, self::CACHE_EXPIRATION );
		}

		return $results;
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

	/**
	 * Database-level search for posts containing the given block, parsed for nesting context.
	 *
	 * @param string   $block       Block name (e.g. core/paragraph).
	 * @param string   $post_type   Post type slug or "all".
	 * @param string[] $post_status Statuses to include (publish, draft, pending, future, private).
	 * @return array
	 */
	private function database_search( $block, $post_type, $post_status ) {
		global $wpdb;

		if ( empty( $post_status ) ) {
			return array();
		}

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

		$type_placeholders   = implode( ', ', array_fill( 0, count( $candidate_post_types ), '%s' ) );
		$status_placeholders = implode( ', ', array_fill( 0, count( $post_status ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$query = $wpdb->prepare(
			"SELECT ID, post_title, post_content, post_status
			FROM {$wpdb->posts}
			WHERE post_type IN ($type_placeholders)
			AND post_status IN ($status_placeholders)
			AND post_content LIKE %s
			ORDER BY post_title ASC",
			array_merge( $candidate_post_types, $post_status, array( $search_pattern ) )
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
				'status'          => $post->post_status,
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
	 * @param string $content Post content.
	 * @param string $target  Block name to find.
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
	 * Build the transient key. Post type prefix lets us invalidate per-post-type with LIKE.
	 *
	 * @param string   $block       Block name.
	 * @param string   $post_type   Post type slug or "all".
	 * @param string[] $post_status Statuses included in the search.
	 * @return string
	 */
	private function get_cache_key( $block, $post_type, $post_status ) {
		$statuses = $post_status;
		sort( $statuses );
		return self::CACHE_PREFIX . $post_type . '_' . md5( $block . '|' . implode( ',', $statuses ) );
	}
}
