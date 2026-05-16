<?php
/**
 * Dashboard widget for Block Finder.
 *
 * @package Block_Finder
 */

namespace Block_Finder;

use WP_Block_Type_Registry;

/**
 * Registers and renders the Block Finder dashboard widget (the search form).
 *
 * All search/query/render logic lives in REST_Controller.
 */
class Dashboard {

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'wp_dashboard_setup', array( $this, 'register_widget' ) );
	}

	/**
	 * Add the meta box to the dashboard.
	 */
	public function register_widget() {
		add_meta_box(
			'block_finder',
			esc_html__( 'Block Finder', 'block-finder' ),
			array( $this, 'render_form' ),
			'dashboard',
			'normal',
			'default'
		);
	}

	/**
	 * Output the search form. Submission is handled client-side against the REST endpoint.
	 */
	public function render_form() {
		$inserter_blocks      = $this->get_inserter_blocks();
		$gutenberg_post_types = $this->get_editor_post_types();

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

		$blocks_data = array();
		foreach ( $inserter_blocks as $block_name => $block_type ) {
			if ( ! empty( $block_type->title ) ) {
				$blocks_data[] = array(
					'value' => $block_name,
					'label' => $block_type->title,
				);
			}
		}

		$post_types_data = array(
			array(
				'value' => 'all',
				'label' => __( 'All Post Types', 'block-finder' ),
			),
		);
		foreach ( $gutenberg_post_types as $post_type ) {
			$post_types_data[] = array(
				'value' => $post_type->name,
				'label' => $post_type->label,
			);
		}
		?>
		<form id="block-finder-form">
			<div id="block-finder-selector-root" data-blocks="<?php echo esc_attr( wp_json_encode( $blocks_data ) ); ?>"></div>
			<input type="hidden" id="block-finder-selector" name="block" value="">

			<fieldset class="block-finder-sources">
				<legend><?php esc_html_e( 'Search in', 'block-finder' ); ?></legend>
				<?php
				$source_options = array(
					'posts'    => __( 'Posts', 'block-finder' ),
					'patterns' => __( 'Patterns', 'block-finder' ),
				);
				if ( wp_is_block_theme() ) {
					$source_options['templates'] = __( 'Templates', 'block-finder' );
					$source_options['parts']     = __( 'Template parts', 'block-finder' );
				}
				foreach ( $source_options as $value => $label ) :
					?>
					<label class="block-finder-source-option">
						<input
							type="checkbox"
							name="sources[]"
							value="<?php echo esc_attr( $value ); ?>"
							<?php checked( 'posts', $value ); ?>
						/>
						<?php echo esc_html( $label ); ?>
					</label>
				<?php endforeach; ?>
			</fieldset>

			<div class="block-finder-post-type-section" data-conditional-source="posts">
				<div id="block-finder-post-type-root" data-post-types="<?php echo esc_attr( wp_json_encode( $post_types_data ) ); ?>"></div>
				<input type="hidden" id="post-type-selector" name="post_type" value="all">
			</div>

			<fieldset class="block-finder-statuses" data-conditional-source="posts,patterns">
				<legend><?php esc_html_e( 'Post status', 'block-finder' ); ?></legend>
				<?php
				$statuses = array( 'publish', 'draft', 'pending', 'future', 'private' );
				foreach ( $statuses as $status ) :
					$obj   = get_post_status_object( $status );
					$label = $obj ? $obj->label : ucfirst( $status );
					?>
					<label class="block-finder-status-option">
						<input
							type="checkbox"
							name="post_status[]"
							value="<?php echo esc_attr( $status ); ?>"
							<?php checked( 'publish', $status ); ?>
						/>
						<?php echo esc_html( $label ); ?>
					</label>
				<?php endforeach; ?>
			</fieldset>

			<button type="submit" class="button button-primary"><?php esc_html_e( 'Find Block', 'block-finder' ); ?></button>
		</form>
		<div id="block-finder-results"></div>
		<?php
	}

	/**
	 * Inserter-visible blocks sorted alphabetically by title.
	 *
	 * @return array<string, \WP_Block_Type>
	 */
	private function get_inserter_blocks() {
		$all_blocks = WP_Block_Type_Registry::get_instance()->get_all_registered();

		$inserter_blocks = array_filter(
			$all_blocks,
			static fn( $block_type ) => $block_type->supports['inserter'] ?? true
		);

		uasort(
			$inserter_blocks,
			static fn( $a, $b ) => strcmp( $a->title, $b->title )
		);

		return $inserter_blocks;
	}

	/**
	 * Public post types that support the block editor, sorted alphabetically by label.
	 *
	 * @return array<string, \WP_Post_Type>
	 */
	private function get_editor_post_types() {
		$post_types = get_post_types( array( 'public' => true ), 'objects' );

		$gutenberg_post_types = array_filter(
			$post_types,
			static fn( $post_type ) => post_type_supports( $post_type->name, 'editor' )
		);

		uasort(
			$gutenberg_post_types,
			static fn( $a, $b ) => strcmp( $a->label, $b->label )
		);

		return $gutenberg_post_types;
	}
}
