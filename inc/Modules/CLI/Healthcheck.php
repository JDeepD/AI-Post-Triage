<?php
/**
 * Healthcheck CLI command for Wire Desk.
 *
 * @package JDeepD\Wire_Desk\Modules\CLI
 * @since 0.0.1
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk\Modules\CLI;

use JDeepD\Wire_Desk\Framework\Contracts\Interfaces\CLI_Command;

/**
 * Class - Healthcheck
 *
 * Implements the `wire-desk health-check` WP-CLI command.
 */
final class Healthcheck implements CLI_Command {
	/**
	 * Get the command name.
	 *
	 * @return lowercase-string&non-empty-string
	 */
	public static function get_name(): string {
		return 'health-check';
	}

	/**
	 * Get the command description.
	 */
	public static function get_description(): string {
		return 'Run a health check on the plugin.';
	}

	/**
	 * Run the health check.
	 *
	 * @param array<int, mixed>    $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public static function run( $args = [], $assoc_args = [] ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
		$checks = [];

		$checks['plugin_active']     = class_exists( 'JDeepD\Wire_Desk\Main' );
		$checks['constants']         = defined( 'WIRE_DESK_PATH' ) && defined( 'WIRE_DESK_URL' );
		$checks['composer_autoload'] = class_exists( 'Composer\Autoload\ClassLoader' );

		\WP_CLI::log( 'Wire Desk Health Check' );
		\WP_CLI::log( '==============================' );

		$all_passed = true;
		foreach ( $checks as $check_name => $passed ) {
			$status = $passed ? '✓ PASS' : '✗ FAIL';
			\WP_CLI::log( "  {$check_name}: {$status}" );

			if ( ! $passed ) {
				$all_passed = false;
			}
		}

		if ( ! $all_passed ) {
			\WP_CLI::error( 'One or more health checks failed. Please investigate the issues above.' );
		}

		\WP_CLI::success( 'All health checks passed!' );
	}
}
