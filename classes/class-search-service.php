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

use WP_Block_Patterns_Registry;

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
	 * Bump this when search semantics change. Old cached entries are then
	 * naturally orphaned (different hash) and evicted by their TTL.
	 *
	 * @var int
	 */
	const CACHE_VERSION = 1;

	/**
	 * Register the cache-invalidation hooks. Call once at bootstrap.
	 */
	public function init() {
		// Surgical cache invalidation: only flush caches whose post_type matches the saved/deleted post.
		add_action( 'save_post', array( $this, 'invalidate_cache_for_post' ), 10, 2 );
		add_action( 'delete_post', array( $this, 'invalidate_cache_for_post' ), 10, 2 );
		add_action( 'wp_trash_post', array( $this, 'invalidate_cache_for_post' ) );
		add_action( 'untrashed_post', array( $this, 'invalidate_cache_for_post' ) );
		// Theme switches can change file-based template content; nuke everything to be safe.
		add_action( 'switch_theme', array( $this, 'invalidate_all_cache' ) );
	}

	/**
	 * Run a block search across the requested sources, returning the parsed result set.
	 *
	 * Reads from / writes to the transient cache transparently. Callers do not
	 * touch the cache directly.
	 *
	 * @param string   $block       Block name (e.g. core/paragraph).
	 * @param string   $post_type   Post type slug or "all" (only used for the "posts" source).
	 * @param string[] $post_status Statuses to include for post-based sources (publish, draft, pending, future, private).
	 * @param string[] $sources     Subset of: posts, templates, parts, patterns. Defaults to ["posts"].
	 * @return array
	 */
	public function search( $block, $post_type, $post_status, $sources = array( 'posts' ) ) {
		if ( empty( $sources ) ) {
			return array();
		}

		$cache_key = $this->get_cache_key( $block, $post_type, $post_status, $sources );
		$results   = get_transient( $cache_key );

		if ( false === $results ) {
			$results = array();

			/**
			 * Filters the list of sources to search for the current request.
			 *
			 * Third-party code can register additional sources by hooking this and
			 * appending custom keys, then handling them via `block_finder_results`.
			 *
			 * @param string[] $sources Sources to search.
			 * @param string   $block   Block name being searched for.
			 */
			$sources = apply_filters( 'block_finder_sources', $sources, $block );

			if ( in_array( 'posts', $sources, true ) ) {
				$results = array_merge( $results, $this->search_posts( $block, $post_type, $post_status ) );
			}
			if ( in_array( 'patterns', $sources, true ) ) {
				$results = array_merge( $results, $this->search_patterns( $block, $post_status ) );
			}
			if ( in_array( 'templates', $sources, true ) ) {
				$results = array_merge( $results, $this->search_templates( $block, 'wp_template' ) );
			}
			if ( in_array( 'parts', $sources, true ) ) {
				$results = array_merge( $results, $this->search_templates( $block, 'wp_template_part' ) );
			}

			/**
			 * Filters the assembled result set before it's cached and returned.
			 *
			 * @param array    $results     Result rows merged across all sources.
			 * @param string   $block       Block name searched for.
			 * @param string   $post_type   Post type slug or "all".
			 * @param string[] $post_status Statuses included.
			 * @param string[] $sources     Sources included.
			 */
			$results = apply_filters( 'block_finder_results', $results, $block, $post_type, $post_status, $sources );

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

		// Templates / template parts / reusable blocks can surface in any cross-source
		// search regardless of the user's post_type selection, so a per-post-type flush
		// would leave stale entries. Nuke everything in that case.
		$special_types = array( 'wp_template', 'wp_template_part', 'wp_block' );

		if ( in_array( $post->post_type, $special_types, true ) ) {
			$this->invalidate_all_cache();
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
	 * Flush every block-finder transient. Used on theme switch and when a special-type
	 * post (template / part / reusable block) changes.
	 */
	public function invalidate_all_cache() {
		global $wpdb;

		$patterns = array(
			'_transient_' . self::CACHE_PREFIX . '%',
			'_transient_timeout_' . self::CACHE_PREFIX . '%',
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
	private function search_posts( $block, $post_type, $post_status ) {
		global $wpdb;

		if ( empty( $post_status ) ) {
			return array();
		}

		$block_name     = str_replace( 'core/', '', $block );
		$direct_needle  = '%<!-- wp:' . $wpdb->esc_like( $block_name ) . '%';
		$pattern_needle = '%<!-- wp:pattern%';

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

		// Match the target block name OR any wp:pattern reference (which we then resolve
		// against the patterns registry in `traverse_blocks`). Filtering out non-matches
		// happens after parsing.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$query = $wpdb->prepare(
			"SELECT ID, post_title, post_content, post_status, post_type
			FROM {$wpdb->posts}
			WHERE post_type IN ($type_placeholders)
			AND post_status IN ($status_placeholders)
			AND ( post_content LIKE %s OR post_content LIKE %s )
			ORDER BY post_title ASC",
			array_merge( $candidate_post_types, $post_status, array( $direct_needle, $pattern_needle ) )
		);

		$posts = $wpdb->get_results( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		if ( empty( $posts ) ) {
			return array();
		}

		$results = array();

		foreach ( $posts as $post ) {
			$instances = $this->find_block_instances( $post->post_content, $block );
			if ( empty( $instances ) ) {
				continue;
			}

			$results[] = array(
				'id'              => $post->ID,
				'title'           => $post->post_title ? $post->post_title : __( 'No title available', 'block-finder' ),
				'source'          => 'post',
				'post_type'       => $post->post_type,
				'status'          => $post->post_status,
				'edit_link'       => get_edit_post_link( $post->ID, 'raw' ),
				'view_link'       => get_permalink( $post->ID ),
				'block_instances' => $instances,
			);
		}

		return $results;
	}

	/**
	 * Search across patterns. Covers both user-saved synced patterns (`wp_block`
	 * posts) and theme- / plugin-registered patterns from `WP_Block_Patterns_Registry`.
	 *
	 * @param string   $block       Block name.
	 * @param string[] $post_status Statuses to include for the user-saved side.
	 * @return array
	 */
	private function search_patterns( $block, $post_status ) {
		$results = array_merge(
			$this->search_synced_patterns( $block, $post_status ),
			$this->search_registered_patterns( $block )
		);

		// Stable alphabetical ordering across both sources.
		usort( $results, static fn( $a, $b ) => strcmp( $a['title'], $b['title'] ) );

		return $results;
	}

	/**
	 * Database-level search for user-saved synced patterns (`wp_block` post type).
	 *
	 * @param string   $block       Block name.
	 * @param string[] $post_status Statuses to include.
	 * @return array
	 */
	private function search_synced_patterns( $block, $post_status ) {
		global $wpdb;

		if ( empty( $post_status ) ) {
			return array();
		}

		$block_name     = str_replace( 'core/', '', $block );
		$direct_needle  = '%<!-- wp:' . $wpdb->esc_like( $block_name ) . '%';
		$pattern_needle = '%<!-- wp:pattern%';

		$status_placeholders = implode( ', ', array_fill( 0, count( $post_status ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$query = $wpdb->prepare(
			"SELECT ID, post_title, post_content, post_status
			FROM {$wpdb->posts}
			WHERE post_type = %s
			AND post_status IN ($status_placeholders)
			AND ( post_content LIKE %s OR post_content LIKE %s )
			ORDER BY post_title ASC",
			array_merge( array( 'wp_block' ), $post_status, array( $direct_needle, $pattern_needle ) )
		);

		$posts = $wpdb->get_results( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		if ( empty( $posts ) ) {
			return array();
		}

		$results = array();

		foreach ( $posts as $post ) {
			$instances = $this->find_block_instances( $post->post_content, $block );
			if ( empty( $instances ) ) {
				continue;
			}

			$results[] = array(
				'id'              => $post->ID,
				'title'           => $post->post_title ? $post->post_title : __( 'No title available', 'block-finder' ),
				'source'          => 'pattern',
				'post_type'       => 'wp_block',
				'status'          => $post->post_status,
				'edit_link'       => get_edit_post_link( $post->ID, 'raw' ),
				'view_link'       => '',
				'block_instances' => $instances,
			);
		}

		return $results;
	}

	/**
	 * Search theme- and plugin-registered patterns via `WP_Block_Patterns_Registry`.
	 *
	 * These are PHP/JSON-registered patterns (the bulk of what shows up in the editor's
	 * pattern picker for a modern block theme). They don't live in `wp_posts`, so they
	 * aren't covered by the synced-pattern query above.
	 *
	 * @param string $block Block name.
	 * @return array
	 */
	private function search_registered_patterns( $block ) {
		$registry     = WP_Block_Patterns_Registry::get_instance();
		$entries      = $registry->get_all_registered();
		$patterns_url = admin_url( 'site-editor.php?path=' . rawurlencode( '/patterns' ) );
		$results      = array();

		foreach ( $entries as $entry ) {
			if ( empty( $entry['name'] ) ) {
				continue;
			}

			// `get_registered()` lazy-loads content for file-based patterns. The bare
			// entry in `get_all_registered()` doesn't always carry the content yet.
			$pattern = $registry->get_registered( $entry['name'] );
			if ( empty( $pattern['content'] ) ) {
				continue;
			}

			$instances = $this->find_block_instances( $pattern['content'], $block );
			if ( empty( $instances ) ) {
				continue;
			}

			$results[] = array(
				'id'              => $entry['name'],
				'title'           => $pattern['title'] ?? $entry['name'],
				'source'          => 'pattern',
				'post_type'       => '',
				'status'          => 'publish',
				'edit_link'       => $patterns_url,
				'view_link'       => '',
				'block_instances' => $instances,
			);
		}

		return $results;
	}

	/**
	 * Search block themes' templates or template parts (both file-based and DB-based).
	 *
	 * @param string $block Block name.
	 * @param string $type  Either "wp_template" or "wp_template_part".
	 * @return array
	 */
	private function search_templates( $block, $type ) {
		$templates    = get_block_templates( array(), $type );
		$results      = array();
		$source_label = 'wp_template_part' === $type ? 'part' : 'template';

		// Templates are few enough (dozens at most) that parsing every one is fine, and
		// many modern themes (TT24, TT25) put all their real content inside theme-registered
		// patterns — so a strpos pre-filter on the target block name would miss them.
		foreach ( $templates as $template ) {
			if ( empty( $template->content ) ) {
				continue;
			}

			$instances = $this->find_block_instances( $template->content, $block );
			if ( empty( $instances ) ) {
				continue;
			}

			$edit_link = add_query_arg(
				array(
					'postType' => $type,
					'postId'   => $template->id,
					'canvas'   => 'edit',
				),
				admin_url( 'site-editor.php' )
			);

			$results[] = array(
				'id'              => $template->id,
				'title'           => $template->title ? $template->title : $template->slug,
				'source'          => $source_label,
				'post_type'       => $type,
				'status'          => $template->status ?? 'publish',
				'edit_link'       => $edit_link,
				'view_link'       => '',
				'block_instances' => $instances,
			);
		}

		usort( $results, static fn( $a, $b ) => strcmp( $a['title'], $b['title'] ) );

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
	 * Recurses into `core/pattern` references by looking the pattern up in the
	 * patterns registry and walking its content — modern block themes (TT24,
	 * TT25, etc.) often have template parts whose content is just a pattern
	 * reference, with the actual blocks living inside the registered pattern.
	 *
	 * `core/template-part` and `core/block` references are NOT followed; those
	 * entities surface on their own when their source is included in the search.
	 *
	 * @param array  $blocks       Parsed blocks.
	 * @param string $target       Block name to find.
	 * @param array  $parent_chain Names of ancestor blocks at this depth.
	 * @param array  $instances    Accumulator (by reference).
	 */
	private function traverse_blocks( $blocks, $target, $parent_chain, &$instances ) {
		// Cheap recursion guard — prevents pathological pattern cycles from hanging the search.
		if ( count( $parent_chain ) > 20 ) {
			return;
		}

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

			if (
				'core/pattern' === $block['blockName']
				&& ! empty( $block['attrs']['slug'] )
			) {
				$pattern = WP_Block_Patterns_Registry::get_instance()->get_registered( $block['attrs']['slug'] );
				if ( $pattern && ! empty( $pattern['content'] ) ) {
					$this->traverse_blocks(
						parse_blocks( $pattern['content'] ),
						$target,
						array_merge( $parent_chain, array( $block['blockName'] ) ),
						$instances
					);
				}
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
	 * @param string[] $sources     Sources included in the search.
	 * @return string
	 */
	private function get_cache_key( $block, $post_type, $post_status, $sources ) {
		$statuses       = $post_status;
		$sorted_sources = $sources;
		sort( $statuses );
		sort( $sorted_sources );
		return self::CACHE_PREFIX . $post_type . '_' . md5(
			self::CACHE_VERSION . '|' . $block . '|' . implode( ',', $statuses ) . '|' . implode( ',', $sorted_sources )
		);
	}
}
