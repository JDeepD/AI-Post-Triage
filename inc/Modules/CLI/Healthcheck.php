<?php
/**
 * Healthcheck CLI command for Wire Desk.
 *
 * @package JDeepD\Wire_Desk\Modules\CLI
 * @since 0.0.1
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk\Modules\CLI;

use ActionScheduler_Store;
use JDeepD\Wire_Desk\Core\Schema;
use JDeepD\Wire_Desk\Decisions\Model_Catalogue;
use JDeepD\Wire_Desk\Decisions\OpenRouter_Client;
use JDeepD\Wire_Desk\Framework\Contracts\Interfaces\CLI_Command;
use JDeepD\Wire_Desk\Jobs\Maintenance;
use JDeepD\Wire_Desk\Jobs\Queue;
use JDeepD\Wire_Desk\Jobs\Usage;
use JDeepD\Wire_Desk\Modules\Settings\Settings;
use WP_CLI;

/**
 * Class - Healthcheck
 *
 * Implements the `wire-desk health-check` WP-CLI command.
 *
 * @phpstan-type Check array{status: 'pass'|'warn'|'fail', message: string}
 */
final class Healthcheck implements CLI_Command {
	/**
	 * Check results.
	 */
	public const PASS = 'pass';
	public const WARN = 'warn';
	public const FAIL = 'fail';

	/**
	 * Waiting jobs that count as a backlog.
	 */
	public const BACKLOG_WARN = 1000;

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
	 * Checks the table, the API key, the OpenRouter endpoint and the scoring jobs.
	 *
	 * ## OPTIONS
	 *
	 * [--skip-remote]
	 * : Don't contact OpenRouter.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wire-desk health-check
	 *     wp wire-desk health-check --skip-remote
	 *
	 * @param array<int, mixed>    $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public static function run( $args = [], $assoc_args = [] ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed, SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- Required by the interface.
		$checks = self::checks( empty( $assoc_args['skip-remote'] ) );
		$labels = [
			self::PASS => '✓ PASS',
			self::WARN => '! WARN',
			self::FAIL => '✗ FAIL',
		];

		WP_CLI::log( 'Wire Desk Health Check' );
		WP_CLI::log( '==============================' );

		foreach ( $checks as $name => $check ) {
			WP_CLI::log( sprintf( '  %s: %s  %s', $name, $labels[ $check['status'] ], $check['message'] ) );
		}

		$statuses = array_count_values( array_column( $checks, 'status' ) );

		if ( ! empty( $statuses[ self::FAIL ] ) ) {
			WP_CLI::error( 'One or more health checks failed. Please investigate the issues above.' );
			return;
		}

		if ( ! empty( $statuses[ self::WARN ] ) ) {
			WP_CLI::success( sprintf( 'Health checks passed with %d %s.', $statuses[ self::WARN ], 1 === $statuses[ self::WARN ] ? 'warning' : 'warnings' ) );
			return;
		}

		WP_CLI::success( 'All health checks passed!' );
	}

	/**
	 * Runs the checks.
	 *
	 * @param bool $remote Whether to contact OpenRouter.
	 * @return array<string, Check>
	 */
	public static function checks( bool $remote = true ): array {
		$checks = [
			'plugin_active'     => self::result( class_exists( 'JDeepD\Wire_Desk\Main' ), 'Plugin classes are loaded.', 'Plugin classes are missing.' ),
			'constants'         => self::result( defined( 'WIRE_DESK_PATH' ) && defined( 'WIRE_DESK_URL' ), 'Plugin constants are defined.', 'Plugin constants are missing.' ),
			'composer_autoload' => self::result( class_exists( 'Composer\Autoload\ClassLoader' ), 'Composer autoloader is loaded.', 'Composer autoloader is missing.' ),
			'table'             => self::result(
				Schema::table_exists() && Schema::is_current(),
				sprintf( 'Table %s is at schema version %d.', Schema::table_name(), Schema::VERSION ),
				sprintf( 'Table %s is missing or out of date. Deactivate and activate the plugin.', Schema::table_name() )
			),
			'api_key'           => self::result(
				'' !== OpenRouter_Client::api_key(),
				'An OpenRouter API key is set.',
				sprintf( 'No OpenRouter API key. Define %s or connect OpenRouter.', OpenRouter_Client::KEY_CONSTANT )
			),
		];

		if ( $remote ) {
			$models = Model_Catalogue::get( true );
			$models = is_array( $models ) ? $models : [];
			$model  = Settings::get()['model'];

			$checks['endpoint'] = self::result( (bool) $models, sprintf( 'OpenRouter listed %d decision models.', count( $models ) ), 'OpenRouter could not be reached.' );

			if ( $models ) {
				$checks['model'] = isset( $models[ $model ] )
					? self::check( self::PASS, sprintf( 'Model %s is available.', $model ) )
					: self::check( self::WARN, sprintf( 'Model %s is not in the OpenRouter list.', $model ) );
			}
		}

		$checks['action_scheduler'] = self::result( function_exists( 'as_schedule_single_action' ), 'Action Scheduler is loaded.', 'Action Scheduler is missing, so posts are not scored in the background.' );

		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			$waiting = self::count_jobs( Queue::HOOK, ActionScheduler_Store::STATUS_PENDING, self::BACKLOG_WARN );
			$failed  = self::count_jobs( '', ActionScheduler_Store::STATUS_FAILED, 100 );

			$checks['backlog'] = $waiting >= self::BACKLOG_WARN
				? self::check( self::WARN, sprintf( '%d or more posts are waiting to be scored.', self::BACKLOG_WARN ) )
				: self::check( self::PASS, sprintf( '%d %s waiting to be scored.', $waiting, 1 === $waiting ? 'post is' : 'posts are' ) );

			$checks['failed_jobs'] = $failed
				? self::check( self::WARN, sprintf( '%s%d Wire Desk %s failed. See Tools > Scheduled Actions.', $failed >= 100 ? 'At least ' : '', $failed, 1 === $failed ? 'job' : 'jobs' ) )
				: self::check( self::PASS, 'No Wire Desk jobs have failed.' );

			$checks['prune_job'] = function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( Maintenance::HOOK, [], Queue::GROUP )
				? self::check( self::PASS, 'The daily prune is scheduled.' )
				: self::check( self::WARN, 'The daily prune is not scheduled yet. It is added on the next admin page load.' );
		}

		$paused = Usage::paused_until();

		if ( $paused ) {
			$checks['scoring'] = self::check( self::WARN, sprintf( 'Scoring is paused after repeated failures until %s.', wp_date( 'Y-m-d H:i', $paused ) ) );
		} elseif ( Usage::budget_reached() ) {
			$checks['scoring'] = self::check( self::WARN, 'Today\'s budget is spent. Scoring continues tomorrow.' );
		} else {
			$checks['scoring'] = self::check( self::PASS, 'Scoring is running.' );
		}

		return $checks;
	}

	/**
	 * A pass or fail check.
	 *
	 * @param bool   $passed Whether the check passed.
	 * @param string $pass   Message when it passed.
	 * @param string $fail   Message when it failed.
	 * @return Check
	 */
	private static function result( bool $passed, string $pass, string $fail ): array {
		return $passed ? self::check( self::PASS, $pass ) : self::check( self::FAIL, $fail );
	}

	/**
	 * A check result.
	 *
	 * @param 'pass'|'warn'|'fail' $status  Status.
	 * @param string               $message Message.
	 * @return Check
	 */
	private static function check( string $status, string $message ): array {
		return [
			'status'  => $status,
			'message' => $message,
		];
	}

	/**
	 * Counts Wire Desk jobs, up to a limit.
	 *
	 * @param string $hook   Hook, or empty for every Wire Desk job.
	 * @param string $status Job status.
	 * @param int    $limit  Highest count needed.
	 */
	private static function count_jobs( string $hook, string $status, int $limit ): int {
		return count(
			as_get_scheduled_actions(
				array_filter(
					[
						'hook'     => $hook,
						'group'    => Queue::GROUP,
						'status'   => $status,
						'per_page' => $limit,
						'orderby'  => 'none',
					]
				),
				'ids'
			)
		);
	}
}
