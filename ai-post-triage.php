<?php
/**
 * AI Post Triage
 *
 * @package           JDeepD/AI_Post_Triage
 * @author            Jaydeep Das
 * @copyright         2026 Jaydeep Das
 * @license           GPL-3.0-or-later
 *
 * Plugin Name:       AI Post Triage
 * Plugin URI:        https://github.com/JDeepD/ai-post-triage
 * Description:       Flags high-priority wire-ingested posts with a decision model and gives editors a review queue to decide what gets published.
 * x-release-please-start-version
 * Version:           0.1.6
 * x-release-please-end
 * Author:            Jaydeep Das
 * Author URI:        https://github.com/JDeepD
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       ai-post-triage
 * Requires PHP:      8.2
 * Requires at least: 7.1
 */

declare( strict_types = 1 );

namespace JDeepD\AI_Post_Triage;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Define the plugin constants.
 */
function constants(): void {
	/**
	 * File path to the plugin's main file.
	 */
	define( 'AI_POST_TRIAGE_FILE', __FILE__ );

	/**
	 * Version of the plugin.
	 */
	define( 'AI_POST_TRIAGE_VERSION', '0.1.6' ); // x-release-please-version.

	/**
	 * Root path to the plugin directory.
	 */
	define( 'AI_POST_TRIAGE_PATH', plugin_dir_path( AI_POST_TRIAGE_FILE ) );

	/**
	 * Root URL to the plugin directory.
	 */
	define( 'AI_POST_TRIAGE_URL', plugin_dir_url( AI_POST_TRIAGE_FILE ) );
}

constants();

// If autoloader fails, we cannot proceed.
require_once __DIR__ . '/inc/Autoloader.php';
if ( ! class_exists( 'JDeepD\AI_Post_Triage\Autoloader' ) || ! \JDeepD\AI_Post_Triage\Autoloader::autoload() ) {
	return;
}

// Load the bundled Action Scheduler. When several plugins bundle it, it runs the newest copy.
if ( file_exists( AI_POST_TRIAGE_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php' ) ) {
	require_once AI_POST_TRIAGE_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
}

// Load the main plugin class.
if ( class_exists( 'JDeepD\AI_Post_Triage\Main' ) ) {
	\JDeepD\AI_Post_Triage\Main::get_instance();
}
