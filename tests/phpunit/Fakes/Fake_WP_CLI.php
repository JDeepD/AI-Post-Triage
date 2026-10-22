<?php
/**
 * A stand-in for WP_CLI that records what commands print.
 *
 * @package JDeepD\AI_Post_Triage\Tests\Fakes
 */

declare( strict_types = 1 );

namespace JDeepD\AI_Post_Triage\Tests\Fakes;

/**
 * Class - Fake_WP_CLI
 */
class Fake_WP_CLI {
	/**
	 * Log lines.
	 *
	 * @var string[]
	 */
	public static array $logs = [];

	/**
	 * Success messages.
	 *
	 * @var string[]
	 */
	public static array $successes = [];

	/**
	 * Errors.
	 *
	 * @var string[]
	 */
	public static array $errors = [];

	/**
	 * Values printed with print_value(), with their format.
	 *
	 * @var array<int, array{value: mixed, format: string}>
	 */
	public static array $values = [];

	/**
	 * Tables printed with WP_CLI\Utils\format_items().
	 *
	 * @var array<int, array{format: string, items: array<int, mixed>, fields: string[]}>
	 */
	public static array $tables = [];

	/**
	 * Makes WP_CLI and WP_CLI\Utils\format_items() record here, and clears what was recorded.
	 *
	 * Call it from a test that runs in a separate process.
	 */
	public static function install(): void {
		if ( ! class_exists( 'WP_CLI' ) ) {
			class_alias( self::class, 'WP_CLI' );
		}

		require_once __DIR__ . '/fake-wp-cli-utils.php';

		self::$logs      = [];
		self::$successes = [];
		self::$errors    = [];
		self::$values    = [];
		self::$tables    = [];
	}

	/**
	 * Records a log line.
	 *
	 * @param string $message Message.
	 */
	public static function log( string $message ): void {
		self::$logs[] = $message;
	}

	/**
	 * Records a success message.
	 *
	 * @param string $message Message.
	 */
	public static function success( string $message ): void {
		self::$successes[] = $message;
	}

	/**
	 * Records an error. The real one exits.
	 *
	 * @param string $message Message.
	 */
	public static function error( string $message ): void {
		self::$errors[] = $message;
	}

	/**
	 * Records a printed value.
	 *
	 * @param mixed                $value      Value.
	 * @param array<string, mixed> $assoc_args Arguments, including the format.
	 */
	public static function print_value( $value, array $assoc_args = [] ): void {
		self::$values[] = [
			'value'  => $value,
			'format' => is_string( $assoc_args['format'] ?? null ) ? $assoc_args['format'] : '',
		];
	}
}
