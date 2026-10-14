<?php
/**
 * Score CLI command for Wire Desk.
 *
 * @package JDeepD\Wire_Desk\Modules\CLI
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk\Modules\CLI;

use JDeepD\Wire_Desk\Data\Wire_Post;
use JDeepD\Wire_Desk\Framework\Contracts\Interfaces\CLI_Command;
use JDeepD\Wire_Desk\Scoring\Post_Scorer;
use WP_CLI;

/**
 * Class - Score
 *
 * Implements the `wire-desk score` WP-CLI command.
 */
final class Score implements CLI_Command {
	/**
	 * Get the command name.
	 *
	 * @return lowercase-string&non-empty-string
	 */
	public static function get_name(): string {
		return 'score';
	}

	/**
	 * Get the command description.
	 */
	public static function get_description(): string {
		return 'Score posts with the decision model.';
	}

	/**
	 * Scores posts with the decision model.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : One or more post IDs.
	 *
	 * [--force]
	 * : Ask the model even if a post is unchanged since it was last scored.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wire-desk score 123
	 *     wp wire-desk score 123 456 --force
	 *
	 * @param array<int, mixed>    $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public static function run( $args = [], $assoc_args = [] ): void {
		$scorer = new Post_Scorer();
		$force  = ! empty( $assoc_args['force'] );
		$labels = Wire_Post::band_labels();
		$failed = 0;

		foreach ( $args as $arg ) {
			$post_id = is_numeric( $arg ) ? (int) $arg : 0;

			if ( $post_id <= 0 ) {
				WP_CLI::warning( sprintf( '%s is not a post ID.', is_scalar( $arg ) ? (string) $arg : '' ) );
				++$failed;
				continue;
			}

			$result = $scorer->score( $post_id, $force );

			if ( is_wp_error( $result ) ) {
				WP_CLI::warning( sprintf( 'Post %d: %s', $post_id, $result->get_error_message() ) );
				++$failed;
				continue;
			}

			WP_CLI::log(
				sprintf(
					'Post %d: %d (%s) by %s, %s',
					$post_id,
					$result->score->score,
					$labels[ $result->score->band ] ?? '',
					$result->model,
					$result->skipped ? 'unchanged, not sent' : sprintf( '$%.6F', $result->cost )
				)
			);
		}

		if ( $failed ) {
			WP_CLI::error( sprintf( '%d of %d posts could not be scored.', $failed, count( $args ) ) );
		}

		WP_CLI::success( sprintf( 'Scored %d posts.', count( $args ) ) );
	}
}
