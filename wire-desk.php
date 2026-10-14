<?php
/**
 * Wire Desk
 *
 * @package           JDeepD/Wire_Desk
 * @author            Jaydeep Das
 * @copyright         2026 Jaydeep Das
 * @license           GPL-3.0-or-later
 *
 * Plugin Name:       Wire Desk
 * Plugin URI:        https://github.com/JDeepD/wire-desk
 * Description:       Flags high-priority wire-ingested posts with a decision model and gives editors a review queue to decide what gets published.
 * x-release-please-start-version
 * Version:           0.0.1
 * x-release-please-end
 * Author:            Jaydeep Das
 * Author URI:        https://github.com/JDeepD
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       wire-desk
 * Domain Path:       /languages
 * Requires PHP:      8.2
 * Requires at least: 7.1
 * Tested up to:      7.1
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Define the plugin constants.
 */
function constants(): void {
	/**
	 * File path to the plugin's main file.
	 */
	define( 'WIRE_DESK_FILE', __FILE__ );

	/**
	 * Version of the plugin.
	 */
	define( 'WIRE_DESK_VERSION', '0.0.1' ); // x-release-please-version.

	/**
	 * Root path to the plugin directory.
	 */
	define( 'WIRE_DESK_PATH', plugin_dir_path( WIRE_DESK_FILE ) );

	/**
	 * Root URL to the plugin directory.
	 */
	define( 'WIRE_DESK_URL', plugin_dir_url( WIRE_DESK_FILE ) );
}

constants();

// If autoloader fails, we cannot proceed.
require_once __DIR__ . '/inc/Autoloader.php';
if ( ! class_exists( 'JDeepD\Wire_Desk\Autoloader' ) || ! \JDeepD\Wire_Desk\Autoloader::autoload() ) {
	return;
}

// Load the bundled Action Scheduler. When several plugins bundle it, it runs the newest copy.
if ( file_exists( WIRE_DESK_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php' ) ) {
	require_once WIRE_DESK_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
}

// Load the main plugin class.
if ( class_exists( 'JDeepD\Wire_Desk\Main' ) ) {
	\JDeepD\Wire_Desk\Main::get_instance();
}
