<?php
/**
 * Autoloader for PHP classes inside a WordPress plugin.
 *
 * Wraps the Composer autoloader to provide graceful failure if it is missing.
 *
 * @package JDeepD\AI_Post_Triage;
 */

declare( strict_types = 1 );

namespace JDeepD\AI_Post_Triage;

defined( 'ABSPATH' ) || exit;

// @phpstan-ignore function.impossibleType (This file is loaded before the Composer autoloader.)
if ( ! class_exists( 'JDeepD\AI_Post_Triage\Framework\AutoloaderTrait' ) ) {
	require_once AI_POST_TRIAGE_PATH . 'framework/AutoloaderTrait.php';
}

/**
 * Class - Autoloader
 */
final class Autoloader {
	use Framework\AutoloaderTrait;

	/**
	 * Attempts to autoload the Composer dependencies.
	 *
	 * If the autoloader is missing, it will display an admin notice and log an error.
	 */
	public static function autoload(): bool {
		// If we're not *supposed* to autoload anything, then return true.
		if ( defined( 'AI_POST_TRIAGE_AUTOLOAD' ) && false === AI_POST_TRIAGE_AUTOLOAD ) {
			return true;
		}

		$autoloader = AI_POST_TRIAGE_PATH . 'vendor/autoload.php';

		return self::require_autoloader( $autoloader );
	}

	/**
	 * The error message to display when the autoloader is missing.
	 */
	private static function get_autoloader_error_message(): string {
		return sprintf(
			/* translators: %s: The plugin name. */
			__( '%s: The Composer autoloader was not found. If you installed the plugin from the GitHub source code, make sure to run `composer install`.', 'ai-post-triage' ),
			esc_html( 'AI Post Triage' )
		);
	}
}
