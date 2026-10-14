<?php
/**
 * Autoloader for PHP classes inside a WordPress plugin.
 *
 * Wraps the Composer autoloader to provide graceful failure if it is missing.
 *
 * @package JDeepD\Wire_Desk;
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk;

// @phpstan-ignore function.impossibleType (This file is loaded before the Composer autoloader.)
if ( ! class_exists( 'JDeepD\Wire_Desk\Framework\AutoloaderTrait' ) ) {
	require_once WIRE_DESK_PATH . 'framework/AutoloaderTrait.php';
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
		if ( defined( 'WIRE_DESK_AUTOLOAD' ) && false === WIRE_DESK_AUTOLOAD ) {
			return true;
		}

		$autoloader = WIRE_DESK_PATH . 'vendor/autoload.php';

		return self::require_autoloader( $autoloader );
	}

	/**
	 * The error message to display when the autoloader is missing.
	 */
	private static function get_autoloader_error_message(): string {
		return sprintf(
			/* translators: %s: The plugin name. */
			__( '%s: The Composer autoloader was not found. If you installed the plugin from the GitHub source code, make sure to run `composer install`.', 'wire-desk' ),
			esc_html( 'Wire Desk' )
		);
	}
}
