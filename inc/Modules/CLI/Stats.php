<?php
/**
 * Stats CLI command for AI Post Triage.
 *
 * @package JDeepD\AI_Post_Triage\Modules\CLI
 */

declare( strict_types = 1 );

namespace JDeepD\AI_Post_Triage\Modules\CLI;

use JDeepD\AI_Post_Triage\Data\Wire_Post;
use JDeepD\AI_Post_Triage\Data\Wire_Post_Repository;
use JDeepD\AI_Post_Triage\Framework\Contracts\Interfaces\CLI_Command;
use JDeepD\AI_Post_Triage\Jobs\Usage;
use WP_CLI;
use function WP_CLI\Utils\format_items;

/**
 * Class - Stats
 *
 * Implements the `ai-post-triage stats` WP-CLI command.
 */
final class Stats implements CLI_Command {
	/**
	 * Output formats.
	 */
	public const FORMATS = [ 'table', 'csv', 'json', 'yaml' ];

	/**
	 * Get the command name.
	 *
	 * @return lowercase-string&non-empty-string
	 */
	public static function get_name(): string {
		return 'stats';
	}

	/**
	 * Get the command description.
	 */
	public static function get_description(): string {
		return 'Show wire post counts by status and priority, and cost by model.';
	}

	/**
	 * Shows wire post counts by status and priority, and the cost of the latest scores by model.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<number>]
	 * : Only posts from the last this many days.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp ai-post-triage stats
	 *     wp ai-post-triage stats --days=7 --format=json
	 *
	 * @param array<int, mixed>    $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public static function run( $args = [], $assoc_args = [] ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed, SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- Required by the interface.
		$days   = is_numeric( $assoc_args['days'] ?? null ) ? (int) $assoc_args['days'] : 0;
		$format = is_string( $assoc_args['format'] ?? null ) ? $assoc_args['format'] : 'table';

		if ( isset( $assoc_args['days'] ) && $days < 1 ) {
			WP_CLI::error( '--days must be a positive number.' );
			return;
		}

		if ( ! in_array( $format, self::FORMATS, true ) ) {
			WP_CLI::error( sprintf( '--format must be one of: %s.', implode( ', ', self::FORMATS ) ) );
			return;
		}

		$since      = $days ? ( wp_date( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ?: null ) : null;
		$repository = new Wire_Post_Repository();
		$states     = self::state_rows( $repository->counts_by_state_and_band( $since ) );
		$models     = self::model_rows( $repository->totals_by_model( $since ) );

		if ( 'table' !== $format ) {
			WP_CLI::print_value(
				[
					'states' => $states,
					'models' => $models,
				],
				[ 'format' => $format ]
			);
			return;
		}

		WP_CLI::log( $days ? sprintf( 'Wire posts from the last %d %s', $days, 1 === $days ? 'day' : 'days' ) : 'All wire posts' );
		format_items( 'table', $states, array_keys( $states[0] ) );

		if ( $models ) {
			WP_CLI::log( 'Latest scores by model' );
			format_items( 'table', $models, [ 'model', 'posts', 'cost' ] );
		}

		$usage = Usage::get();

		WP_CLI::log( sprintf( 'Today: $%s spent on %d %s.', number_format( $usage['cost'], 4 ), $usage['calls'], 1 === $usage['calls'] ? 'request' : 'requests' ) );
	}

	/**
	 * One row per state, with a column per band and a total, plus a total row.
	 *
	 * @param array<int, array{state: int, band: int, posts: int}> $counts Counts by state and band.
	 * @return array<int, array<string, int|string>>
	 */
	public static function state_rows( array $counts ): array {
		$bands  = array_flip( Wire_Post::band_slugs() );
		$states = Wire_Post::state_slugs();
		$zero   = array_fill_keys( array_values( $bands ), 0 );
		$posts  = array_fill_keys( array_values( $states ), $zero );

		foreach ( $counts as $count ) {
			if ( isset( $posts[ $count['state'] ], $bands[ $count['band'] ] ) ) {
				$posts[ $count['state'] ][ $bands[ $count['band'] ] ] += $count['posts'];
			}
		}

		$rows   = [];
		$totals = $zero;

		foreach ( $states as $slug => $state ) {
			$rows[] = [ 'status' => $slug ] + $posts[ $state ] + [ 'total' => array_sum( $posts[ $state ] ) ];

			foreach ( $posts[ $state ] as $band => $number ) {
				$totals[ $band ] += $number;
			}
		}

		$rows[] = [ 'status' => 'total' ] + $totals + [ 'total' => array_sum( $totals ) ];

		return $rows;
	}

	/**
	 * Rows of posts and cost by model, with the cost rounded for display.
	 *
	 * @param array<int, array{model: string, posts: int, cost: float}> $totals Totals by model.
	 * @return array<int, array{model: string, posts: int, cost: string}>
	 */
	public static function model_rows( array $totals ): array {
		return array_map(
			static fn ( array $total ): array => [
				'model' => '' !== $total['model'] ? $total['model'] : '(none)',
				'posts' => $total['posts'],
				'cost'  => number_format( $total['cost'], 4, '.', '' ),
			],
			$totals
		);
	}
}
