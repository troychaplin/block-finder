<?php
/**
 * Asset enqueue logic for the Block Finder dashboard widget.
 *
 * @package Block_Finder
 */

namespace Block_Finder;

/**
 * Enqueues the dashboard CSS/JS and bootstraps the REST URL + nonce for the front-end.
 */
class Enqueues {

	/**
	 * Path resolver for the build directory.
	 *
	 * @var Plugin_Paths
	 */
	private Plugin_Paths $build_dir;

	/**
	 * Constructor.
	 *
	 * @param string $build_path Absolute path to the build directory.
	 */
	public function __construct( string $build_path ) {
		$this->build_dir = new Plugin_Paths( $build_path );
	}

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Enqueue assets on the dashboard screen only.
	 */
	public function enqueue_admin_assets() {
		if ( get_current_screen()?->base !== 'dashboard' ) {
			return;
		}

		$asset_meta = $this->build_dir->get_asset_meta( 'block-finder.js' );

		if ( ! $asset_meta ) {
			return;
		}

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

		// Provide the REST URL and a wp_rest nonce to the front-end.
		// In Phase 3 this becomes unnecessary once `@wordpress/api-fetch` is wired up.
		$bootstrap = sprintf(
			'window.blockFinder = %s;',
			wp_json_encode(
				array(
					'restUrl' => esc_url_raw( rest_url( 'block-finder/v1/search' ) ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
				),
				JSON_HEX_TAG | JSON_UNESCAPED_SLASHES
			)
		);

		wp_add_inline_script( 'block-finder-js', $bootstrap, 'before' );
	}
}
