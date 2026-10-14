<?php
/**
 * Integration tests for the Stats CLI command.
 *
 * @package JDeepD\Wire_Desk\Tests\Integration\Modules\CLI
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk\Tests\Integration\Modules\CLI;

use JDeepD\Wire_Desk\Data\Wire_Post;
use JDeepD\Wire_Desk\Data\Wire_Post_Repository;
use JDeepD\Wire_Desk\Modules\CLI\Stats;
use JDeepD\Wire_Desk\Tests\Fakes\Fake_WP_CLI;
use JDeepD\Wire_Desk\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Class - StatsTest
 */
#[CoversClass( Stats::class )]
class StatsTest extends TestCase {
	/**
	 * Test the command name and description.
	 */
	public function test_name_and_description(): void {
		$this->assertSame( 'stats', Stats::get_name() );
		$this->assertNotEmpty( Stats::get_description() );
	}

	/**
	 * Test that counts become a row per state with band columns, plus totals.
	 */
	public function test_state_rows(): void {
		$rows = Stats::state_rows(
			[
				[
					'state' => Wire_Post::STATE_SCORED,
					'band'  => Wire_Post::BAND_HIGH,
					'posts' => 4,
				],
				[
					'state' => Wire_Post::STATE_SCORED,
					'band'  => Wire_Post::BAND_LOW,
					'posts' => 2,
				],
				[
					'state' => Wire_Post::STATE_QUEUED,
					'band'  => Wire_Post::BAND_NONE,
					'posts' => 3,
				],
				[
					'state' => 99,
					'band'  => Wire_Post::BAND_HIGH,
					'posts' => 50,
				],
			]
		);

		$this->assertSame( [ 'queued', 'scored', 'failed', 'dismissed', 'published', 'total' ], array_column( $rows, 'status' ) );
		$this->assertSame(
			[
				'status'     => 'scored',
				'high'       => 4,
				'medium'     => 0,
				'low'        => 2,
				'not-scored' => 0,
				'total'      => 6,
			],
			$rows[1]
		);
		$this->assertSame(
			[
				'status'     => 'total',
				'high'       => 4,
				'medium'     => 0,
				'low'        => 2,
				'not-scored' => 3,
				'total'      => 9,
			],
			$rows[5]
		);
	}

	/**
	 * Test the model rows.
	 */
	public function test_model_rows(): void {
		$this->assertSame(
			[
				[
					'model' => 'typesafe/jev-1.13',
					'posts' => 3,
					'cost'  => '0.0123',
				],
				[
					'model' => '(none)',
					'posts' => 1,
					'cost'  => '0.0000',
				],
			],
			Stats::model_rows(
				[
					[
						'model' => 'typesafe/jev-1.13',
						'posts' => 3,
						'cost'  => 0.012345,
					],
					[
						'model' => '',
						'posts' => 1,
						'cost'  => 0.0,
					],
				]
			)
		);
	}

	/**
	 * Test that the tables are printed.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_run_prints_tables(): void {
		Fake_WP_CLI::install();
		$this->scored_posts();

		Stats::run( [], [] );

		$this->assertSame( [], Fake_WP_CLI::$errors );
		$this->assertSame( 'All wire posts', Fake_WP_CLI::$logs[0] );
		$this->assertCount( 2, Fake_WP_CLI::$tables );
		$this->assertSame( [ 'status', 'high', 'medium', 'low', 'not-scored', 'total' ], Fake_WP_CLI::$tables[0]['fields'] );
		$this->assertSame(
			[
				[
					'model' => 'typesafe/jev-1.13',
					'posts' => 2,
					'cost'  => '0.0030',
				],
			],
			Fake_WP_CLI::$tables[1]['items']
		);
		$this->assertStringStartsWith( 'Today: $', (string) end( Fake_WP_CLI::$logs ) );
	}

	/**
	 * Test the other formats and --days.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_run_json_with_days(): void {
		Fake_WP_CLI::install();
		$this->scored_posts();

		Stats::run(
			[],
			[
				'days'   => '7',
				'format' => 'json',
			]
		);

		$value = Fake_WP_CLI::$values[0]['value'] ?? [];

		$this->assertSame( 'json', Fake_WP_CLI::$values[0]['format'] ?? null );
		$this->assertIsArray( $value );
		$this->assertSame( 1, $value['states'][5]['total'] ?? null );
		$this->assertSame( 1, $value['models'][0]['posts'] ?? null );
	}

	/**
	 * Test the errors.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_run_errors(): void {
		Fake_WP_CLI::install();

		Stats::run( [], [ 'days' => '0' ] );
		Stats::run( [], [ 'format' => 'xml' ] );

		$this->assertSame( [ '--days must be a positive number.', '--format must be one of: table, csv, json, yaml.' ], Fake_WP_CLI::$errors );
		$this->assertSame( [], Fake_WP_CLI::$tables );
	}

	/**
	 * A recent and an old scored post.
	 */
	private function scored_posts(): void {
		$repository = new Wire_Post_Repository();

		foreach ( [
			1  => 0.001,
			30 => 0.002,
		] as $days => $cost ) {
			$post_id = 600000 + $days;

			$repository->add_queued( $post_id, (string) wp_date( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) );
			$repository->save_score( $post_id, Wire_Post::BAND_HIGH, 80, 'typesafe/jev-1.13', md5( (string) $post_id ), $cost, [] );
		}
	}
}
