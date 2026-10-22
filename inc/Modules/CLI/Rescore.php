<?php
/**
 * Rescore CLI command for AI Post Triage.
 *
 * @package JDeepD\AI_Post_Triage\Modules\CLI
 */

declare( strict_types = 1 );

namespace JDeepD\AI_Post_Triage\Modules\CLI;

use JDeepD\AI_Post_Triage\Framework\Contracts\Interfaces\CLI_Command;
use JDeepD\AI_Post_Triage\Jobs\Rescore as Rescore_Job;
use WP_CLI;

/**
 * Class - Rescore
 *
 * Implements the `ai-post-triage rescore` WP-CLI command.
 */
final class Rescore implements CLI_Command {
	/**
	 * Get the command name.
	 *
	 * @return lowercase-string&non-empty-string
	 */
	public static function get_name(): string {
		return 'rescore';
	}

	/**
	 * Get the command description.
	 */
	public static function get_description(): string {
		return 'Queue the newest wire posts to be scored again.';
	}

	/**
	 * Queues the newest wire posts to be scored again in the background.
	 *
	 * Posts unchanged since they were last scored aren't sent to the model again.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<number>]
	 * : How many of the newest posts to queue, up to 10000.
	 * ---
	 * default: 100
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp ai-post-triage rescore
	 *     wp ai-post-triage rescore --limit=500
	 *
	 * @param array<int, mixed>    $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public static function run( $args = [], $assoc_args = [] ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed, SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- Required by the interface.
		$limit = is_numeric( $assoc_args['limit'] ?? null ) ? (int) $assoc_args['limit'] : 100;

		if ( $limit < 1 || $limit > Rescore_Job::MAX_LIMIT ) {
			WP_CLI::error( sprintf( '--limit must be between 1 and %d.', Rescore_Job::MAX_LIMIT ) );
			return;
		}

		$queued  = ( new Rescore_Job() )->queue( $limit );
		$batches = (int) ceil( $queued / Rescore_Job::BATCH_SIZE );

		WP_CLI::success(
			sprintf(
				'Queued %d %s in %d %s.',
				$queued,
				1 === $queued ? 'post' : 'posts',
				$batches,
				1 === $batches ? 'batch' : 'batches'
			)
		);
	}
}
