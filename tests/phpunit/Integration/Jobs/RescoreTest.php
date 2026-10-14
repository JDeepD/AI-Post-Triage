<?php
/**
 * Integration tests for Rescore.
 *
 * @package JDeepD\Wire_Desk\Tests\Integration\Jobs
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk\Tests\Integration\Jobs;

use ActionScheduler_Action;
use ActionScheduler_Store;
use JDeepD\Wire_Desk\Data\Wire_Post_Repository;
use JDeepD\Wire_Desk\Decisions\Decision;
use JDeepD\Wire_Desk\Jobs\Queue;
use JDeepD\Wire_Desk\Jobs\Rescore;
use JDeepD\Wire_Desk\Jobs\Score_Job;
use JDeepD\Wire_Desk\Modules\Settings\Settings;
use JDeepD\Wire_Desk\Scoring\Post_Scorer;
use JDeepD\Wire_Desk\Tests\Fakes\Fake_Decision_Client;
use JDeepD\Wire_Desk\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Class - RescoreTest
 */
#[CoversClass( Rescore::class )]
class RescoreTest extends TestCase {
	/**
	 * Wire post table.
	 *
	 * @var \JDeepD\Wire_Desk\Data\Wire_Post_Repository
	 */
	private Wire_Post_Repository $repository;

	/**
	 * Sets up the table.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->repository = new Wire_Post_Repository();
	}

	/**
	 * Test that batches are run.
	 */
	public function test_register_hooks(): void {
		$rescore = new Rescore();
		$rescore->register_hooks();

		$this->assertSame( 10, has_action( Rescore::BATCH_HOOK, [ $rescore, 'run_batch' ] ) );
	}

	/**
	 * Test that the newest posts are queued in batches.
	 */
	public function test_queue_batches_newest_posts(): void {
		$post_ids = $this->add_rows( 30 );

		$this->assertSame( 27, ( new Rescore( $this->repository ) )->queue( 27 ) );

		$batches = $this->batches();

		$this->assertCount( 2, $batches );
		$this->assertSame( array_slice( $post_ids, 0, 25 ), $batches[0] );
		$this->assertSame( array_slice( $post_ids, 25, 2 ), $batches[1] );
	}

	/**
	 * Test that the limit is kept between 1 and MAX_LIMIT.
	 */
	public function test_queue_limits(): void {
		$this->add_rows( 3 );

		$rescore = new Rescore( $this->repository );

		$this->assertSame( 1, $rescore->queue( 0 ) );
		$this->assertSame( 3, $rescore->queue( PHP_INT_MAX ) );
	}

	/**
	 * Test that an empty table queues nothing.
	 */
	public function test_queue_empty_table(): void {
		$this->assertSame( 0, ( new Rescore( $this->repository ) )->queue( 100 ) );
		$this->assertSame( [], $this->batches() );
	}

	/**
	 * Test that a batch schedules each post and starts its attempts again.
	 */
	public function test_run_batch_schedules_posts(): void {
		[ $first, $second, $waiting ] = $this->add_rows( 3 );

		$this->repository->set_attempts( $first, Score_Job::MAX_ATTEMPTS );
		$this->repository->set_attempts( $waiting, 2 );
		Queue::schedule( $waiting );

		( new Rescore( $this->repository ) )->run_batch( [ $first, (string) $second, $waiting, 'abc', -1 ] );

		foreach ( [ $first, $second, $waiting ] as $post_id ) {
			$this->assertCount( 1, as_get_scheduled_actions( $this->pending( $post_id ), 'ids' ) );
		}

		$this->assertSame( 0, $this->repository->get( $first )?->attempts );
		$this->assertSame( 2, $this->repository->get( $waiting )?->attempts );
	}

	/**
	 * Test that bad batch arguments are ignored.
	 */
	public function test_run_batch_ignores_bad_input(): void {
		( new Rescore( $this->repository ) )->run_batch( 'not an array' );

		$this->assertSame(
			[],
			as_get_scheduled_actions(
				[
					'hook'   => Queue::HOOK,
					'status' => ActionScheduler_Store::STATUS_PENDING,
				],
				'ids'
			)
		);
	}

	/**
	 * Test that re-scoring an unchanged post doesn't ask the model again.
	 */
	public function test_unchanged_post_costs_nothing(): void {
		update_option(
			Settings::OPTION_KEY,
			[
				'questions' => [
					[
						'id'       => 'q_breaking',
						'question' => 'Is this breaking news?',
						'expected' => 'yes',
						'weight'   => 'high',
						'required' => false,
					],
				],
			]
		);

		[ $post_id ] = $this->add_rows( 1 );

		$client = new Fake_Decision_Client( new Decision( [ 'q_breaking' => 0.9 ], 'typesafe/jev-1.13', 0.00002, '' ) );
		$job    = new Score_Job( new Post_Scorer( $client, $this->repository ), $this->repository );

		$job->run( $post_id );
		( new Rescore( $this->repository ) )->run_batch( [ $post_id ] );
		$job->run( $post_id );

		$this->assertCount( 1, $client->calls );
	}

	/**
	 * Adds posts to the table, newest first.
	 *
	 * @param int $count Posts to add.
	 * @return int[] Post IDs, newest first.
	 */
	private function add_rows( int $count ): array {
		$post_ids = [];

		for ( $i = 0; $i < $count; $i++ ) {
			$post_id    = self::factory()->post->create();
			$post_ids[] = $post_id;

			$this->repository->add_queued( $post_id, gmdate( 'Y-m-d H:i:s', time() - $i * HOUR_IN_SECONDS ) );
		}

		return $post_ids;
	}

	/**
	 * Post IDs in each waiting batch, in order.
	 *
	 * @return array<int, array<int, mixed>>
	 */
	private function batches(): array {
		$actions = as_get_scheduled_actions(
			[
				'hook'     => Rescore::BATCH_HOOK,
				'group'    => Queue::GROUP,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'orderby'  => 'action_id',
				'order'    => 'ASC',
				'per_page' => -1,
			]
		);

		return array_values( array_map( static fn ( ActionScheduler_Action $action ): array => (array) ( $action->get_args()[0] ?? [] ), $actions ) );
	}

	/**
	 * Query for a post's waiting scoring jobs.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>
	 */
	private function pending( int $post_id ): array {
		return [
			'hook'     => Queue::HOOK,
			'args'     => [ $post_id ],
			'group'    => Queue::GROUP,
			'status'   => ActionScheduler_Store::STATUS_PENDING,
			'per_page' => -1,
		];
	}
}
