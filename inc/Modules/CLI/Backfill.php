<?php
/**
 * Backfill CLI command for AI Post Triage.
 *
 * @package JDeepD\AI_Post_Triage\Modules\CLI
 */

declare( strict_types = 1 );

namespace JDeepD\AI_Post_Triage\Modules\CLI;

use JDeepD\AI_Post_Triage\Framework\Contracts\Interfaces\CLI_Command;
use JDeepD\AI_Post_Triage\Jobs\Backfill as Backfill_Job;
use JDeepD\AI_Post_Triage\Modules\Settings\Settings;
use WP_CLI;

/**
 * Class - Backfill
 *
 * Implements the `ai-post-triage backfill` WP-CLI command.
 */
final class Backfill implements CLI_Command {
	/**
	 * Get the command name.
	 *
	 * @return lowercase-string&non-empty-string
	 */
	public static function get_name(): string {
		return 'backfill';
	}

	/**
	 * Get the command description.
	 */
	public static function get_description(): string {
		return 'Add existing wire posts to AI Post Triage, newest first.';
	}

	/**
	 * Adds existing wire posts to AI Post Triage, newest first, and stops at the scoring window.
	 *
	 * Reads posts of the wire post types and of the wire terms' taxonomies. Posts already in
	 * AI Post Triage are skipped. Progress is saved after each batch, so a stopped backfill carries
	 * on where it left off. Added posts are scored in the background when automatic scoring is on.
	 *
	 * ## OPTIONS
	 *
	 * [--batch=<number>]
	 * : Posts read per batch, up to 2000.
	 * ---
	 * default: 500
	 * ---
	 *
	 * [--limit=<number>]
	 * : Stop after reading about this many posts. Run the command again to carry on.
	 *
	 * [--restart]
	 * : Forget the saved progress and start again from the newest post.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ai-post-triage backfill
	 *     wp ai-post-triage backfill --batch=1000 --limit=50000
	 *     wp ai-post-triage backfill --restart
	 *
	 * @param array<int, mixed>    $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public static function run( $args = [], $assoc_args = [] ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed, SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- Required by the interface.
		$batch = is_numeric( $assoc_args['batch'] ?? null ) ? (int) $assoc_args['batch'] : Backfill_Job::BATCH_SIZE;
		$limit = is_numeric( $assoc_args['limit'] ?? null ) ? (int) $assoc_args['limit'] : 0;

		if ( $batch < 1 || $batch > Backfill_Job::MAX_BATCH_SIZE ) {
			WP_CLI::error( sprintf( '--batch must be between 1 and %d.', Backfill_Job::MAX_BATCH_SIZE ) );
			return;
		}

		if ( $limit < 0 ) {
			WP_CLI::error( '--limit must be a positive number.' );
			return;
		}

		if ( ! empty( $assoc_args['restart'] ) ) {
			Backfill_Job::reset();
		}

		if ( ! Backfill_Job::post_types() ) {
			WP_CLI::error( 'No wire post types or terms are set. Choose them on the AI Post Triage settings screen first.' );
			return;
		}

		$progress = Backfill_Job::progress();

		if ( $progress['done'] ) {
			WP_CLI::success( sprintf( 'The backfill already finished after reading %d posts. Use --restart to run it again.', $progress['scanned'] ) );
			return;
		}

		$job     = new Backfill_Job();
		$scanned = 0;
		$added   = 0;

		do {
			$before   = $progress;
			$progress = $job->run_batch( $batch );
			$scanned += $progress['scanned'] - $before['scanned'];
			$added   += $progress['added'] - $before['added'];

			WP_CLI::log( sprintf( 'Read %d posts, added %d. Next batch starts below post %d.', $scanned, $added, $progress['cursor'] ) );

			if ( function_exists( 'wp_cache_flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
		} while ( ! $progress['done'] && ( 0 === $limit || $scanned < $limit ) );

		$summary = sprintf( 'Read %d %s and added %d to AI Post Triage.', $scanned, 1 === $scanned ? 'post' : 'posts', $added );

		if ( $added && ! Settings::get()['auto_score'] ) {
			$summary .= ' Automatic scoring is off, so they wait until you re-score them.';
		}

		WP_CLI::success( $progress['done'] ? $summary . ' The backfill is complete.' : $summary . ' Run the command again to carry on.' );
	}
}
