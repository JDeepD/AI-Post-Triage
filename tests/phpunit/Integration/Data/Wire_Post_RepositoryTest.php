<?php
/**
 * Integration tests for Wire_Post_Repository.
 *
 * @package JDeepD\Wire_Desk\Tests\Integration\Data
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk\Tests\Integration\Data;

use JDeepD\Wire_Desk\Data\Wire_Post;
use JDeepD\Wire_Desk\Data\Wire_Post_Repository;
use JDeepD\Wire_Desk\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Class - Wire_Post_RepositoryTest
 */
#[CoversClass( Wire_Post_Repository::class )]
#[CoversClass( Wire_Post::class )]
class Wire_Post_RepositoryTest extends TestCase {
	/**
	 * Repository under test.
	 *
	 * @var \JDeepD\Wire_Desk\Data\Wire_Post_Repository
	 */
	private Wire_Post_Repository $repository;

	/**
	 * Set up the repository.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->repository = new Wire_Post_Repository();
	}

	/**
	 * Test that add_queued() adds a queued row.
	 */
	public function test_add_queued_adds_queued_row(): void {
		$this->assertTrue( $this->repository->add_queued( 10, '2026-10-14 09:00:00' ) );

		$post = $this->repository->get( 10 );

		$this->assertInstanceOf( Wire_Post::class, $post );
		$this->assertSame( 10, $post->post_id );
		$this->assertSame( '2026-10-14 09:00:00', $post->post_date );
		$this->assertSame( Wire_Post::STATE_QUEUED, $post->state );
		$this->assertSame( Wire_Post::BAND_NONE, $post->band );
		$this->assertNull( $post->hash );
		$this->assertNull( $post->scored_at );
		$this->assertNull( $post->details );
	}

	/**
	 * Test that add_queued() on an existing row updates the date and keeps the state.
	 */
	public function test_add_queued_existing_row_updates_date_and_keeps_state(): void {
		$this->repository->add_queued( 10, '2026-10-14 09:00:00' );
		$this->repository->set_state( 10, Wire_Post::STATE_DISMISSED );

		$this->repository->add_queued( 10, '2026-10-14 10:30:00' );

		$post = $this->repository->get( 10 );
		$this->assertSame( '2026-10-14 10:30:00', $post?->post_date );
		$this->assertSame( Wire_Post::STATE_DISMISSED, $post?->state );
	}

	/**
	 * Test that save_score() saves every field.
	 */
	public function test_save_score_saves_fields(): void {
		$hash    = md5( 'input' );
		$details = [
			[
				'question' => 'Is it breaking news?',
				'yes'      => 0.91,
			],
		];

		$this->repository->add_queued( 10, '2026-10-14 09:00:00' );

		$this->assertTrue( $this->repository->save_score( 10, Wire_Post::BAND_HIGH, 87, 'typesafe/jev-1.13', $hash, 0.00002134, $details ) );

		$post = $this->repository->get( 10 );
		$this->assertSame( Wire_Post::STATE_SCORED, $post?->state );
		$this->assertSame( Wire_Post::BAND_HIGH, $post?->band );
		$this->assertSame( 87, $post?->score );
		$this->assertSame( 'typesafe/jev-1.13', $post?->model );
		$this->assertSame( $hash, $post?->hash );
		$this->assertSame( 0.00002134, $post?->cost );
		$this->assertSame( $details, $post?->details );
		$this->assertNotNull( $post?->scored_at );
		$this->assertSame( '', $post?->error );
	}

	/**
	 * Test that save_score() clears an earlier error.
	 */
	public function test_save_score_clears_error(): void {
		$this->repository->add_queued( 10, '2026-10-14 09:00:00' );
		$this->repository->save_error( 10, 'Timed out' );

		$this->repository->save_score( 10, Wire_Post::BAND_LOW, 12, 'typesafe/jev-1.13', md5( 'input' ), 0.0, [] );

		$post = $this->repository->get( 10 );
		$this->assertSame( Wire_Post::STATE_SCORED, $post?->state );
		$this->assertSame( '', $post?->error );
	}

	/**
	 * Test that save_score() keeps dismissed and published states.
	 */
	public function test_save_score_keeps_dismissed_and_published_states(): void {
		foreach ( [ Wire_Post::STATE_DISMISSED, Wire_Post::STATE_PUBLISHED ] as $post_id => $state ) {
			$this->repository->add_queued( $post_id + 1, '2026-10-14 09:00:00' );
			$this->repository->set_state( $post_id + 1, $state );

			$this->repository->save_score( $post_id + 1, Wire_Post::BAND_HIGH, 90, 'typesafe/jev-1.13', md5( 'input' ), 0.0, [] );

			$this->assertSame( $state, $this->repository->get( $post_id + 1 )?->state );
		}
	}

	/**
	 * Test that save_error() marks the row failed and truncates the message.
	 */
	public function test_save_error_marks_failed_and_truncates(): void {
		$this->repository->add_queued( 10, '2026-10-14 09:00:00' );

		$this->assertTrue( $this->repository->save_error( 10, str_repeat( 'x', 300 ) ) );

		$post = $this->repository->get( 10 );
		$this->assertSame( Wire_Post::STATE_FAILED, $post?->state );
		$this->assertSame( 255, strlen( (string) $post?->error ) );
	}

	/**
	 * Test that save_error() keeps a dismissed state.
	 */
	public function test_save_error_keeps_dismissed_state(): void {
		$this->repository->add_queued( 10, '2026-10-14 09:00:00' );
		$this->repository->set_state( 10, Wire_Post::STATE_DISMISSED );

		$this->repository->save_error( 10, 'Timed out' );

		$post = $this->repository->get( 10 );
		$this->assertSame( Wire_Post::STATE_DISMISSED, $post?->state );
		$this->assertSame( 'Timed out', $post?->error );
	}

	/**
	 * Test that set_state() rejects unknown states.
	 */
	public function test_set_state_rejects_unknown_state(): void {
		$this->repository->add_queued( 10, '2026-10-14 09:00:00' );

		$this->assertFalse( $this->repository->set_state( 10, 99 ) );
		$this->assertSame( Wire_Post::STATE_QUEUED, $this->repository->get( 10 )?->state );
	}

	/**
	 * Test that delete() removes the row.
	 */
	public function test_delete_removes_row(): void {
		$this->repository->add_queued( 10, '2026-10-14 09:00:00' );
		$this->repository->get( 10 );

		$this->assertTrue( $this->repository->delete( 10 ) );
		$this->assertNull( $this->repository->get( 10 ) );
	}

	/**
	 * Test that get() returns null for a post without a row.
	 */
	public function test_get_returns_null_without_row(): void {
		$this->assertNull( $this->repository->get( 404 ) );
	}

	/**
	 * Test that get_many() keeps the given order and leaves out posts without a row.
	 */
	public function test_get_many_keeps_order_and_skips_missing(): void {
		$this->repository->add_queued( 1, '2026-10-14 09:00:00' );
		$this->repository->add_queued( 2, '2026-10-14 10:00:00' );
		$this->repository->add_queued( 3, '2026-10-14 11:00:00' );

		$posts = $this->repository->get_many( [ 3, 404, 1, 3, 0 ] );

		$this->assertSame( [ 3, 1 ], array_keys( $posts ) );
	}

	/**
	 * Test that get_many() returns nothing for no IDs.
	 */
	public function test_get_many_without_ids_returns_empty(): void {
		$this->assertSame( [], $this->repository->get_many( [] ) );
	}

	/**
	 * Test that rows, including missing ones, are served from the cache.
	 */
	public function test_get_uses_cache(): void {
		global $wpdb;

		$this->repository->add_queued( 10, '2026-10-14 09:00:00' );
		$this->repository->get( 10 );
		$this->repository->get( 404 );

		$queries = $wpdb->num_queries;

		$this->repository->get( 10 );
		$this->repository->get( 404 );
		$this->repository->get_many( [ 10, 404 ] );

		$this->assertSame( $queries, $wpdb->num_queries );
	}

	/**
	 * Test that writes clear the cached row.
	 */
	public function test_writes_clear_cached_row(): void {
		$this->repository->get( 10 );
		$this->repository->add_queued( 10, '2026-10-14 09:00:00' );
		$this->assertNotNull( $this->repository->get( 10 ) );

		$this->repository->save_error( 10, 'Timed out' );
		$this->assertSame( Wire_Post::STATE_FAILED, $this->repository->get( 10 )?->state );

		$this->repository->save_score( 10, Wire_Post::BAND_MEDIUM, 55, 'typesafe/jev-1.13', md5( 'input' ), 0.0, [] );
		$this->assertSame( 55, $this->repository->get( 10 )?->score );

		$this->repository->set_state( 10, Wire_Post::STATE_PUBLISHED );
		$this->assertSame( Wire_Post::STATE_PUBLISHED, $this->repository->get( 10 )?->state );
	}

	/**
	 * Test that page() returns rows newest first, with ties broken by post ID.
	 */
	public function test_page_orders_newest_first(): void {
		$this->add_rows();

		$this->assertSame( [ 5, 4, 3, 2, 1 ], $this->ids( $this->repository->page() ) );
	}

	/**
	 * Test that page() pages older and newer from a cursor.
	 */
	public function test_page_uses_cursor(): void {
		$this->add_rows();

		$first = $this->repository->page( [], null, 2 );
		$this->assertSame( [ 5, 4 ], $this->ids( $first ) );

		$second = $this->repository->page( [], $this->cursor( $first[1] ), 2 );
		$this->assertSame( [ 3, 2 ], $this->ids( $second ) );

		$back = $this->repository->page( [], $this->cursor( $second[0] ), 2, true );
		$this->assertSame( [ 5, 4 ], $this->ids( $back ) );
	}

	/**
	 * Test that page() filters by state and band.
	 */
	public function test_page_filters_by_state_and_band(): void {
		$this->add_rows();
		$this->repository->save_score( 2, Wire_Post::BAND_HIGH, 90, 'typesafe/jev-1.13', md5( '2' ), 0.0, [] );
		$this->repository->save_score( 4, Wire_Post::BAND_HIGH, 80, 'typesafe/jev-1.13', md5( '4' ), 0.0, [] );
		$this->repository->save_score( 5, Wire_Post::BAND_LOW, 10, 'typesafe/jev-1.13', md5( '5' ), 0.0, [] );

		$this->assertSame( [ 5, 4, 2 ], $this->ids( $this->repository->page( [ 'state' => Wire_Post::STATE_SCORED ] ) ) );
		$this->assertSame(
			[ 4, 2 ],
			$this->ids(
				$this->repository->page(
					[
						'state' => Wire_Post::STATE_SCORED,
						'band'  => Wire_Post::BAND_HIGH,
					]
				)
			)
		);
	}

	/**
	 * Test that page() limits the page size.
	 */
	public function test_page_limits_page_size(): void {
		$this->add_rows();

		$this->assertCount( 1, $this->repository->page( [], null, 0 ) );
		$this->assertCount( 5, $this->repository->page( [], null, Wire_Post_Repository::MAX_PAGE_SIZE + 50 ) );
	}

	/**
	 * Test that a cached page is refreshed after a write.
	 */
	public function test_page_cache_is_cleared_by_writes(): void {
		global $wpdb;

		$this->add_rows();
		$this->repository->page();

		$queries = $wpdb->num_queries;
		$this->repository->page();
		$this->assertSame( $queries, $wpdb->num_queries );

		$this->repository->delete( 5 );

		$this->assertSame( [ 4, 3, 2, 1 ], $this->ids( $this->repository->page() ) );
	}

	/**
	 * Test that count() counts with filters and is refreshed after a write.
	 */
	public function test_count_filters_and_refreshes(): void {
		$this->add_rows();
		$this->repository->save_score( 2, Wire_Post::BAND_HIGH, 90, 'typesafe/jev-1.13', md5( '2' ), 0.0, [] );

		$this->assertSame( 5, $this->repository->count() );
		$this->assertSame( 4, $this->repository->count( [ 'state' => Wire_Post::STATE_QUEUED ] ) );
		$this->assertSame(
			1,
			$this->repository->count(
				[
					'state' => Wire_Post::STATE_SCORED,
					'band'  => Wire_Post::BAND_HIGH,
				]
			)
		);

		$this->repository->save_score( 3, Wire_Post::BAND_HIGH, 85, 'typesafe/jev-1.13', md5( '3' ), 0.0, [] );

		$this->assertSame( 3, $this->repository->count( [ 'state' => Wire_Post::STATE_QUEUED ] ) );
	}

	/**
	 * Test that count() stops at the limit given.
	 */
	public function test_count_stops_at_max(): void {
		$this->add_rows();

		$this->assertSame( 3, $this->repository->count( [], 3 ) );
		$this->assertSame( 5, $this->repository->count( [], 10 ) );
		$this->assertSame( 2, $this->repository->count( [ 'state' => Wire_Post::STATE_QUEUED ], 2 ) );
	}

	/**
	 * Test the counts by state and band, with and without a start date.
	 */
	public function test_counts_by_state_and_band(): void {
		$this->add_rows();
		$this->repository->save_score( 2, Wire_Post::BAND_HIGH, 90, 'typesafe/jev-1.13', md5( '2' ), 0.002, [] );
		$this->repository->save_score( 4, Wire_Post::BAND_HIGH, 85, 'typesafe/jev-1.13', md5( '4' ), 0.003, [] );

		$counts = $this->repository->counts_by_state_and_band();

		usort( $counts, static fn ( array $a, array $b ): int => [ $a['state'], $a['band'] ] <=> [ $b['state'], $b['band'] ] );

		$this->assertSame(
			[
				[
					'state' => Wire_Post::STATE_QUEUED,
					'band'  => Wire_Post::BAND_NONE,
					'posts' => 3,
				],
				[
					'state' => Wire_Post::STATE_SCORED,
					'band'  => Wire_Post::BAND_HIGH,
					'posts' => 2,
				],
			],
			$counts
		);

		$this->assertSame( 3, array_sum( array_column( $this->repository->counts_by_state_and_band( '2026-10-12 00:00:00' ), 'posts' ) ) );
	}

	/**
	 * Test the totals by model, with and without a start date.
	 */
	public function test_totals_by_model(): void {
		$this->add_rows();
		$this->repository->save_score( 2, Wire_Post::BAND_HIGH, 90, 'typesafe/jev-1.13', md5( '2' ), 0.002, [] );
		$this->repository->save_score( 4, Wire_Post::BAND_LOW, 10, 'typesafe/jev-1.13', md5( '4' ), 0.003, [] );
		$this->repository->save_score( 5, Wire_Post::BAND_LOW, 10, 'cloudflare/clef', md5( '5' ), 0.01, [] );

		$totals = $this->repository->totals_by_model();

		$this->assertSame( [ 'typesafe/jev-1.13', 'cloudflare/clef' ], array_column( $totals, 'model' ) );
		$this->assertSame( 2, $totals[0]['posts'] );
		$this->assertEqualsWithDelta( 0.005, $totals[0]['cost'], 0.000001 );
		$this->assertSame( [ 'cloudflare/clef' ], array_column( $this->repository->totals_by_model( '2026-10-13 00:00:00' ), 'model' ) );
	}

	/**
	 * Test that a prune batch starts from a date and says how far it got.
	 */
	public function test_prune_details_batch(): void {
		$this->add_rows();

		foreach ( [ 1, 2, 3, 4, 5 ] as $post_id ) {
			$this->repository->save_score( $post_id, Wire_Post::BAND_LOW, 10, 'typesafe/jev-1.13', md5( (string) $post_id ), 0.0, [ [ 'id' => 'q1' ] ] );
		}

		$this->assertSame(
			[
				'cleared' => 2,
				'through' => '2026-10-11 09:00:00',
			],
			$this->repository->prune_details_batch( '2026-10-13 00:00:00', '', 2 )
		);
		$this->assertSame(
			[
				'cleared' => 2,
				'through' => '2026-10-12 09:00:00',
			],
			$this->repository->prune_details_batch( '2026-10-13 00:00:00', '2026-10-11 09:00:00', 5 )
		);
		$this->assertSame(
			[
				'cleared' => 0,
				'through' => '2026-10-12 09:00:00',
			],
			$this->repository->prune_details_batch( '2026-10-13 00:00:00', '2026-10-12 09:00:00', 5 )
		);
		$this->assertNotNull( $this->repository->get( 5 )?->details );
	}

	/**
	 * Test that newest_ids() returns the newest IDs.
	 */
	public function test_newest_ids(): void {
		$this->add_rows();

		$this->assertSame( [ 5, 4, 3 ], $this->repository->newest_ids( 3 ) );
	}

	/**
	 * Test that prune_details() clears details of older posts only.
	 */
	public function test_prune_details_clears_older_posts(): void {
		$this->add_rows();
		foreach ( [ 1, 2, 5 ] as $post_id ) {
			$this->repository->save_score( $post_id, Wire_Post::BAND_LOW, 10, 'typesafe/jev-1.13', md5( (string) $post_id ), 0.0, [ 'yes' => 0.1 ] );
			$this->repository->get( $post_id );
		}

		$this->assertSame( 2, $this->repository->prune_details( '2026-10-12 00:00:00' ) );

		$this->assertNull( $this->repository->get( 1 )?->details );
		$this->assertNull( $this->repository->get( 2 )?->details );
		$this->assertSame( [ 'yes' => 0.1 ], $this->repository->get( 5 )?->details );
		$this->assertSame( 0, $this->repository->prune_details( '2026-10-12 00:00:00' ) );
	}

	/**
	 * Test that prune_details() respects the limit.
	 */
	public function test_prune_details_respects_limit(): void {
		$this->add_rows();
		foreach ( [ 1, 2 ] as $post_id ) {
			$this->repository->save_score( $post_id, Wire_Post::BAND_LOW, 10, 'typesafe/jev-1.13', md5( (string) $post_id ), 0.0, [ 'yes' => 0.1 ] );
		}

		$this->assertSame( 1, $this->repository->prune_details( '2026-10-12 00:00:00', 1 ) );
		$this->assertNull( $this->repository->get( 1 )?->details );
		$this->assertNotNull( $this->repository->get( 2 )?->details );
	}

	/**
	 * Test that states() lists every state.
	 */
	public function test_states_lists_every_state(): void {
		$this->assertSame(
			[
				Wire_Post::STATE_QUEUED,
				Wire_Post::STATE_SCORED,
				Wire_Post::STATE_FAILED,
				Wire_Post::STATE_DISMISSED,
				Wire_Post::STATE_PUBLISHED,
			],
			Wire_Post_Repository::states()
		);
	}

	/**
	 * Adds five queued rows. Posts 3 and 4 share a date.
	 */
	private function add_rows(): void {
		$this->repository->add_queued( 1, '2026-10-10 09:00:00' );
		$this->repository->add_queued( 2, '2026-10-11 09:00:00' );
		$this->repository->add_queued( 3, '2026-10-12 09:00:00' );
		$this->repository->add_queued( 4, '2026-10-12 09:00:00' );
		$this->repository->add_queued( 5, '2026-10-13 09:00:00' );
	}

	/**
	 * Post IDs of rows.
	 *
	 * @param \JDeepD\Wire_Desk\Data\Wire_Post[] $posts Rows.
	 * @return int[]
	 */
	private function ids( array $posts ): array {
		return array_map( static fn ( Wire_Post $post ): int => $post->post_id, $posts );
	}

	/**
	 * Cursor for a row.
	 *
	 * @param \JDeepD\Wire_Desk\Data\Wire_Post $post Row.
	 * @return array{post_date: string, post_id: int}
	 */
	private function cursor( Wire_Post $post ): array {
		return [
			'post_date' => $post->post_date,
			'post_id'   => $post->post_id,
		];
	}
}
