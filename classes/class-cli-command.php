<?php
/**
 * WP-CLI command for Block Finder.
 *
 * @package Block_Finder
 */

namespace Block_Finder;

use WP_CLI;

/**
 * Run block-usage searches from the command line.
 *
 * Wraps `Search_Service` and skips the REST permission gate — CLI execution is
 * the auth boundary, same convention core's `wp post list` follows.
 */
class CLI_Command {

	/**
	 * Search backend.
	 *
	 * @var Search_Service
	 */
	private Search_Service $search_service;

	/**
	 * Allowed post statuses (matches the REST endpoint enum).
	 *
	 * @var string[]
	 */
	private const ALLOWED_STATUSES = array( 'publish', 'draft', 'pending', 'future', 'private' );

	/**
	 * Allowed search sources (matches the REST endpoint enum).
	 *
	 * @var string[]
	 */
	private const ALLOWED_SOURCES = array( 'posts', 'patterns', 'templates', 'parts' );

	/**
	 * Default columns for table / CSV output.
	 *
	 * @var string
	 */
	private const DEFAULT_FIELDS = 'id,title,source,status,count,nested,url';

	/**
	 * Constructor.
	 *
	 * @param Search_Service $search_service Shared search service.
	 */
	public function __construct( Search_Service $search_service ) {
		$this->search_service = $search_service;
	}

	/**
	 * Search posts for usage of a specific block.
	 *
	 * ## OPTIONS
	 *
	 * <block>
	 * : The block name to search for, e.g. core/paragraph or my-plugin/custom-block.
	 *
	 * [--post-type=<slug>]
	 * : A specific post type slug, or "all" for every editor-supporting public type.
	 * ---
	 * default: all
	 * ---
	 *
	 * [--post-status=<list>]
	 * : Comma-separated post statuses. Allowed: publish, draft, pending, future, private.
	 * ---
	 * default: publish
	 * ---
	 *
	 * [--sources=<list>]
	 * : Comma-separated sources to search. Allowed: posts, patterns, templates, parts. Templates and parts require a block theme.
	 * ---
	 * default: posts
	 * ---
	 *
	 * [--filter=<filter>]
	 * : Only return posts with at least one nested (InnerBlock) instance when set to "nested".
	 * ---
	 * default: all
	 * options:
	 *   - all
	 *   - nested
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format. "count" returns a single integer; "ids" returns one ID per line.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - count
	 *   - ids
	 * ---
	 *
	 * [--fields=<list>]
	 * : Comma-separated list of fields for table/csv/json output. Available: id, title, source, status, count, nested, url.
	 *
	 * ## EXAMPLES
	 *
	 *     # Quick check on a core block
	 *     $ wp block-finder search core/paragraph
	 *
	 *     # CSV export for an audit
	 *     $ wp block-finder search core/group --post-type=page --format=csv > group_usage.csv
	 *
	 *     # CI-friendly: exit code by post count
	 *     $ test "$(wp block-finder search my-plugin/deprecated --format=count)" -eq 0
	 *
	 *     # Pipe IDs into another command
	 *     $ wp block-finder search core/heading --filter=nested --format=ids | xargs -I {} wp post get {}
	 *
	 *     # Search across drafts too
	 *     $ wp block-finder search core/cover --post-status=publish,draft
	 *
	 *     # Include block-theme templates and patterns in the search
	 *     $ wp block-finder search core/heading --sources=posts,templates,parts,patterns
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments (block name).
	 * @param array $assoc_args Named arguments.
	 */
	public function search( $args, $assoc_args ) {
		$block      = (string) $args[0];
		$post_type  = (string) ( $assoc_args['post-type'] ?? 'all' );
		$filter     = (string) ( $assoc_args['filter'] ?? 'all' );
		$format     = (string) ( $assoc_args['format'] ?? 'table' );
		$fields_arg = (string) ( $assoc_args['fields'] ?? self::DEFAULT_FIELDS );
		$statuses   = $this->parse_status_list( (string) ( $assoc_args['post-status'] ?? 'publish' ) );
		$sources    = $this->parse_sources_list( (string) ( $assoc_args['sources'] ?? 'posts' ) );

		$results = $this->search_service->search( $block, $post_type, $statuses, $sources );

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

		if ( 'count' === $format ) {
			WP_CLI::log( (string) count( $results ) );
			return;
		}

		if ( 'ids' === $format ) {
			foreach ( $results as $result ) {
				WP_CLI::log( (string) $result['id'] );
			}
			return;
		}

		$rows   = array_map( array( $this, 'flatten_result' ), $results );
		$fields = array_values( array_filter( array_map( 'trim', explode( ',', $fields_arg ) ) ) );

		WP_CLI\Utils\format_items( $format, $rows, $fields );
	}

	/**
	 * Reduce a search result to a flat row suitable for table / csv / json output.
	 *
	 * @param array $result Result row from Search_Service::search().
	 * @return array
	 */
	private function flatten_result( $result ) {
		$total  = count( $result['block_instances'] );
		$nested = 0;
		foreach ( $result['block_instances'] as $instance ) {
			if ( ! $instance['is_root'] ) {
				++$nested;
			}
		}

		return array(
			'id'     => $result['id'],
			'title'  => $result['title'],
			'source' => $result['source'] ?? 'post',
			'status' => $result['status'],
			'count'  => $total,
			'nested' => $nested,
			'url'    => $result['edit_link'],
		);
	}

	/**
	 * Parse a comma-separated sources list, erroring on any value that's not in the allowed set.
	 *
	 * @param string $raw Comma-separated sources.
	 * @return string[]
	 */
	private function parse_sources_list( $raw ) {
		$requested = array_values(
			array_filter(
				array_map( 'trim', explode( ',', $raw ) )
			)
		);

		if ( empty( $requested ) ) {
			return array( 'posts' );
		}

		$invalid = array_diff( $requested, self::ALLOWED_SOURCES );
		if ( ! empty( $invalid ) ) {
			WP_CLI::error(
				sprintf(
					'Unknown source(s): %s. Allowed: %s.',
					implode( ', ', $invalid ),
					implode( ', ', self::ALLOWED_SOURCES )
				)
			);
		}

		return $requested;
	}

	/**
	 * Parse a comma-separated status list, erroring on any value that's not in the allowed set.
	 *
	 * @param string $raw Comma-separated statuses.
	 * @return string[]
	 */
	private function parse_status_list( $raw ) {
		$requested = array_values(
			array_filter(
				array_map( 'trim', explode( ',', $raw ) )
			)
		);

		if ( empty( $requested ) ) {
			return array( 'publish' );
		}

		$invalid = array_diff( $requested, self::ALLOWED_STATUSES );
		if ( ! empty( $invalid ) ) {
			WP_CLI::error(
				sprintf(
					'Unknown post status(es): %s. Allowed: %s.',
					implode( ', ', $invalid ),
					implode( ', ', self::ALLOWED_STATUSES )
				)
			);
		}

		return $requested;
	}
}
