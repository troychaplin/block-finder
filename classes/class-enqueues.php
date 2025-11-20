<?php
/**
 * Enqueue assets.
 *
 * @package Block_Finder
 */

namespace Block_Finder;

/**
 * Class Enqueues
 *
 * This class is responsible for enqueueing scripts and styles for the plugin.
 *
 * @package Block_Finder
 */
class Enqueues extends Plugin_Module {
	/**
	 * Path resolver for build directory.
	 *
	 * @var Plugin_Paths
	 */
	private Plugin_Paths $build_dir;

	/**
	 * Setup the class.
	 *
	 * @param string $build_path Absolute path to the build directory for all assets.
	 */
	public function __construct( string $build_path ) {
		$this->build_dir = new Plugin_Paths( $build_path );
	}

	/**
	 * Initialize the module.
	 */
	public function init() {
		add_action( 'admin_enqueue_scripts', array( $this, 'tc_block_finder_admin_assets' ) );
	}

	/**
	 * Enqueues the admin assets
	 */
	public function tc_block_finder_admin_assets() {
		$current_screen = get_current_screen();
		if ( $current_screen->base !== 'dashboard' ) {
			return;
		}

		$asset_meta = $this->build_dir->get_asset_meta( 'block-finder.js' );

		if ( $asset_meta ) {
			wp_enqueue_style(
				'block-finder-css',
				$this->build_dir->get_url( 'block-finder.css' ),
				$asset_meta['dependencies'],
				$asset_meta['version'],
				false
			);

			wp_enqueue_script(
				'block-finder-js',
				$this->build_dir->get_url( 'block-finder.js' ),
				$asset_meta['dependencies'],
				$asset_meta['version'],
				false
			);

			wp_localize_script(
				'block-finder-js',
				'blockFinderAjax',
				array(
					'ajax_url' => esc_url( admin_url( 'admin-ajax.php' ) ),
					'nonce'    => wp_create_nonce( 'block_finder_nonce' ),
				)
			);
		}
	}
}
