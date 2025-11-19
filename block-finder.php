<?php
/**
 * Plugin Name:       Block Finder
 * Description:       This plugin provides a dashboard to search for specific blocks
 * Requires at least: 6.3
 * Requires PHP:      7.0
 * Version:           1.0.6
 * Author:            Troy Chaplin
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       block-finder
 *
 * @package Block_Finder
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin version.
define( 'BLOCK_FINDER_VERSION', '1.0.6' );

// Include our bundled autoload if not loaded globally.
if ( ! class_exists( Block_Finder\Plugin_Paths::class ) && file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

if ( ! class_exists( Block_Finder\Plugin_Paths::class ) ) {
	wp_trigger_error( 'Block Finder: Composer autoload file not found. Please run `composer install`.', E_USER_ERROR );
	return;
}

// Instantiate our modules.
$block_finder_modules = array(
	new Block_Finder\Enqueues( __DIR__ . '/build' ),
	new Block_Finder\Dashboard( __DIR__ . '/build' ),
);

foreach ( $block_finder_modules as $block_finder_module ) {
	if ( is_a( $block_finder_module, Block_Finder\Plugin_Module::class ) ) {
		$block_finder_module->init();
	}
}
