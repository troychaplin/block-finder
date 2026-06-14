<?php
/**
 * Plugin Name:       Block Finder
 * Description:       This plugin provides a dashboard to search for specific blocks
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Version:           1.1.1
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
define( 'BLOCK_FINDER_VERSION', '1.1.1' );

// Load the bundled Composer autoloader if it hasn't been provided already.
if ( ! class_exists( Block_Finder\Plugin_Paths::class ) && file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

if ( ! class_exists( Block_Finder\Plugin_Paths::class ) ) {
	wp_trigger_error( 'Block Finder: Composer autoload file not found. Please run `composer install`.', E_USER_ERROR );
	return;
}

$block_finder_search_service = new Block_Finder\Search_Service();
$block_finder_search_service->init();

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'block-finder', new Block_Finder\CLI_Command( $block_finder_search_service ) );
}

( new Block_Finder\Enqueues( __DIR__ . '/build' ) )->init();
( new Block_Finder\Dashboard() )->init();
( new Block_Finder\REST_Controller( $block_finder_search_service ) )->init();
