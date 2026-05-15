<?php
/**
 * Asset enqueue logic for the Block Finder dashboard widget.
 *
 * @package Block_Finder
 */

namespace Block_Finder;

/**
 * Enqueues the dashboard CSS/JS. REST URL + nonce are provided automatically by
 * the `wp-api-fetch` script dependency that `@wordpress/scripts` injects into
 * the generated asset.php.
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

		// Note: `$asset_meta['dependencies']` is a list of *script* handles
		// extracted by @wordpress/scripts. They aren't valid style handles, so
		// the style enqueue takes an empty deps array.
		wp_enqueue_style(
			'block-finder-css',
			$this->build_dir->get_url( 'block-finder.css' ),
			array(),
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
	}
}
