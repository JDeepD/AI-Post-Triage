<?php
/**
 * Prune CLI command for AI Post Triage.
 *
 * @package JDeepD\AI_Post_Triage\Modules\CLI
 */

declare( strict_types = 1 );

namespace JDeepD\AI_Post_Triage\Modules\CLI;

use JDeepD\AI_Post_Triage\Framework\Contracts\Interfaces\CLI_Command;
use JDeepD\AI_Post_Triage\Jobs\Maintenance;
use WP_CLI;

/**
 * Class - Prune
 *
 * Implements the `ai-post-triage prune` WP-CLI command.
 */
final class Prune implements CLI_Command {
	/**
	 * Get the command name.
	 *
	 * @return lowercase-string&non-empty-string
	 */
	public static function get_name(): string {
		return 'prune';
	}

	/**
	 * Get the command description.
	 */
	public static function get_description(): string {
		return 'Clear old per-question answers and finished jobs.';
	}

	/**
	 * Clears the per-question answers of old posts, and deletes finished AI Post Triage jobs.
	 *
	 * AI Post Triage does this once a day on its own. Scores and bands are kept; only the
	 * answers behind them are cleared.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<number>]
	 * : Clear answers of posts older than this many days. Defaults to the `ai_post_triage/details_retention_days` filter, 90 days.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ai-post-triage prune
	 *     wp ai-post-triage prune --days=30
	 *
	 * @param array<int, mixed>    $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public static function run( $args = [], $assoc_args = [] ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed, SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- Required by the interface.
		$days = isset( $assoc_args['days'] ) ? ( is_numeric( $assoc_args['days'] ) ? (int) $assoc_args['days'] : 0 ) : Maintenance::retention_days();

		if ( isset( $assoc_args['days'] ) && $days < 1 ) {
			WP_CLI::error( '--days must be a positive number.' );
			return;
		}

		$maintenance = new Maintenance();
		$details     = $days > 0 ? $maintenance->prune_details( $days ) : 0;
		$jobs        = $maintenance->prune_jobs();

		WP_CLI::success(
			sprintf(
				'%s Deleted %d finished %s.',
				$days > 0
					? sprintf( 'Cleared answers from %d %s older than %d %s.', $details, 1 === $details ? 'post' : 'posts', $days, 1 === $days ? 'day' : 'days' )
					: 'Answers are kept, because the retention is 0 days.',
				$jobs,
				1 === $jobs ? 'job' : 'jobs'
			)
		);
	}
}
