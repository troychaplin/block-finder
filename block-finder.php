<?php

/**
 * Plugin Name:       Block Finder
 * Description:       This plugin provides a dashboard to search for specific blocks
 * Requires at least: 6.3
 * Requires PHP:      7.0
 * Version:           1.0.1
 * Author:            Troy Chaplin
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       block-finder
 *
 * @package           block-finder
 */

// Exit if accessed directly
if (! defined('ABSPATH')) exit;

// Define plugin version
define('BLOCK_FINDER_VERSION', '1.0.1');

// Setup autoloading
require_once __DIR__ . '/vendor/autoload.php';

// Include dependencies
use BlockFinder\Functions;

// Enqueue block editor assets
$loadAssets = new Functions(__FILE__, BLOCK_FINDER_VERSION);
add_action('admin_enqueue_scripts', [$loadAssets, 'enqueueAdminAssets']);
add_action('wp_dashboard_setup', [$loadAssets, 'blockFinderDashboard']);
add_action('wp_ajax_find_blocks', [$loadAssets, 'blockQuery']);
