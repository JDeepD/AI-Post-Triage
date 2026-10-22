<?php
/**
 * Integration tests for Score_Job and Queue.
 *
 * @package JDeepD\AI_Post_Triage\Tests\Integration\Jobs
 */

declare( strict_types = 1 );

namespace JDeepD\AI_Post_Triage\Tests\Integration\Jobs;

use ActionScheduler;
use ActionScheduler_Action;
use ActionScheduler_Store;
use JDeepD\AI_Post_Triage\Data\Wire_Post;
use JDeepD\AI_Post_Triage\Data\Wire_Post_Repository;
use JDeepD\AI_Post_Triage\Decisions\Decision;
use JDeepD\AI_Post_Triage\Decisions\Decision_Client;
use JDeepD\AI_Post_Triage\Jobs\Queue;
use JDeepD\AI_Post_Triage\Jobs\Score_Job;
use JDeepD\AI_Post_Triage\Jobs\Usage;
use JDeepD\AI_Post_Triage\Modules\Settings\Settings;
use JDeepD\AI_Post_Triage\Scoring\Post_Scorer;
use JDeepD\AI_Post_Triage\Tests\Fakes\Fake_Decision_Client;
use JDeepD\AI_Post_Triage\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Class - Score_JobTest
 */
#[CoversClass( Score_Job::class )]
#[CoversClass( Queue::class )]
class Score_JobTest extends TestCase {
	/**
	 * Wire post table.
	 *
	 * @var \JDeepD\AI_Post_Triage\Data\Wire_Post_Repository
	 */
	private Wire_Post_Repository $repository;

	/**
	 * A post in the table.
	 *
	 * @var int
	 */
	private int $post_id;

	/**
	 * Saves a one-question rubric and adds a post to the table.
	 */
	protected function setUp(): void {
		parent::setUp();

		update_option(
			Settings::OPTION_KEY,
			[
				'questions'   => [
					[
						'id'       => 'q_breaking',
						'question' => 'Is this breaking news?',
						'expected' => 'yes',
						'weight'   => 'high',
						'required' => false,
					],
				],
				'concurrency' => 1,
			]
		);

		$this->repository = new Wire_Post_Repository();
		$this->post_id    = self::factory()->post->create();

		$this->repository->add_queued( $this->post_id, '2026-10-14 00:00:00' );
	}

	/**
	 * Test that the job runs scoring jobs and raises Action Scheduler's concurrency.
	 */
	public function test_register_hooks(): void {
		$job = new Score_Job();
		$job->register_hooks();

		$this->assertSame( 10, has_action( Queue::HOOK, [ $job, 'run' ] ) );
		$this->assertSame( 10, has_filter( 'action_scheduler_queue_runner_concurrent_batches', [ $job, 'concurrent_batches' ] ) );
	}

	/**
	 * Test that a job scores the post.
	 */
	public function test_run_scores_post(): void {
		$client = new Fake_Decision_Client( $this->decision() );

		$this->job( $client )->run( (string) $this->post_id );

		$row = $this->repository->get( $this->post_id );

		$this->assertCount( 1, $client->calls );
		$this->assertSame( Wire_Post::STATE_SCORED, $row?->state );
		$this->assertSame( 0, $row?->attempts );
		$this->assertSame( [], $this->jobs() );
	}

	/**
	 * Test that a rate limit is retried after the Retry-After delay.
	 */
	public function test_rate_limit_retries_after_delay(): void {
		$before = time();
		$this->job( new Fake_Decision_Client( $this->error( Decision_Client::ERROR_RATE_LIMITED, 120 ) ) )->run( $this->post_id );
		$after = time();

		$this->assertSame( 1, $this->repository->get( $this->post_id )?->attempts );
		$this->assertSame( Wire_Post::STATE_FAILED, $this->repository->get( $this->post_id )?->state );

		$jobs = $this->jobs();

		$this->assertCount( 1, $jobs );
		$this->assertGreaterThanOrEqual( $before + 120, $jobs[0] );
		$this->assertLessThanOrEqual( $after + 130, $jobs[0] );
	}

	/**
	 * Test that server errors and invalid responses are retried with backoff.
	 *
	 * @param string $code Error code.
	 */
	#[DataProvider( 'data_retryable' )]
	public function test_retryable_error_retries_with_backoff( string $code ): void {
		$before = time();
		$this->job( new Fake_Decision_Client( $this->error( $code ) ) )->run( $this->post_id );
		$after = time();

		$jobs = $this->jobs();

		$this->assertCount( 1, $jobs );
		$this->assertGreaterThanOrEqual( $before + Score_Job::BASE_BACKOFF / 2, $jobs[0] );
		$this->assertLessThanOrEqual( $after + Score_Job::BASE_BACKOFF, $jobs[0] );
	}

	/**
	 * Retryable errors.
	 *
	 * @return array<string, array{string}>
	 */
	public static function data_retryable(): array {
		return [
			'rate limited'     => [ Decision_Client::ERROR_RATE_LIMITED ],
			'server'           => [ Decision_Client::ERROR_SERVER ],
			'invalid response' => [ Decision_Client::ERROR_INVALID_RESPONSE ],
		];
	}

	/**
	 * Test that errors retrying can't fix aren't retried.
	 *
	 * @param string $code Error code.
	 */
	#[DataProvider( 'data_final' )]
	public function test_final_error_is_not_retried( string $code ): void {
		$this->job( new Fake_Decision_Client( $this->error( $code ) ) )->run( $this->post_id );

		$this->assertSame( 1, $this->repository->get( $this->post_id )?->attempts );
		$this->assertSame( Wire_Post::STATE_FAILED, $this->repository->get( $this->post_id )?->state );
		$this->assertSame( [], $this->jobs() );
	}

	/**
	 * Errors that aren't retried.
	 *
	 * @return array<string, array{string}>
	 */
	public static function data_final(): array {
		return [
			'auth'        => [ Decision_Client::ERROR_AUTH ],
			'credits'     => [ Decision_Client::ERROR_CREDITS ],
			'no key'      => [ Decision_Client::ERROR_NO_KEY ],
			'bad request' => [ Decision_Client::ERROR_BAD_REQUEST ],
		];
	}

	/**
	 * Test that a post is left as failed after the last attempt.
	 */
	public function test_gives_up_after_max_attempts(): void {
		$this->repository->set_attempts( $this->post_id, Score_Job::MAX_ATTEMPTS - 1 );

		$this->job( new Fake_Decision_Client( $this->error( Decision_Client::ERROR_SERVER ) ) )->run( $this->post_id );

		$this->assertSame( Score_Job::MAX_ATTEMPTS, $this->repository->get( $this->post_id )?->attempts );
		$this->assertSame( Wire_Post::STATE_FAILED, $this->repository->get( $this->post_id )?->state );
		$this->assertSame( [], $this->jobs() );
	}

	/**
	 * Test that retries run until the post is scored, and the attempts are reset.
	 */
	public function test_retries_until_scored(): void {
		$client = new Fake_Decision_Client(
			$this->error( Decision_Client::ERROR_SERVER ),
			$this->error( Decision_Client::ERROR_RATE_LIMITED, 5 ),
			$this->decision()
		);
		$job    = $this->job( $client );

		foreach ( [ 1, 2 ] as $attempts ) {
			$job->run( $this->post_id );

			$this->assertSame( $attempts, $this->repository->get( $this->post_id )?->attempts );
			$this->assertCount( 1, $this->jobs() );

			Queue::unschedule( $this->post_id );
		}

		$job->run( $this->post_id );

		$this->assertCount( 3, $client->calls );
		$this->assertSame( 0, $this->repository->get( $this->post_id )?->attempts );
		$this->assertSame( Wire_Post::STATE_SCORED, $this->repository->get( $this->post_id )?->state );
		$this->assertSame( [], $this->jobs() );
	}

	/**
	 * Test that a retry isn't added when a job is already waiting.
	 */
	public function test_retry_keeps_one_waiting_job(): void {
		Queue::schedule( $this->post_id );

		$this->job( new Fake_Decision_Client( $this->error( Decision_Client::ERROR_SERVER ) ) )->run( $this->post_id );

		$this->assertCount( 1, $this->jobs() );
	}

	/**
	 * Test that posts removed from the table aren't scored.
	 */
	public function test_post_not_in_table_is_skipped(): void {
		$client = new Fake_Decision_Client( $this->decision() );

		$this->repository->delete( $this->post_id );
		$this->job( $client )->run( $this->post_id );
		$this->job( $client )->run( 'abc' );

		$this->assertSame( [], $client->calls );
		$this->assertNull( $this->repository->get( $this->post_id ) );
	}

	/**
	 * Test that a job waits when "Jobs at once" are already running.
	 */
	public function test_waits_when_too_many_jobs_run(): void {
		$client = new Fake_Decision_Client( $this->decision() );

		$this->start_job( self::factory()->post->create() );
		$this->start_job( $this->post_id );
		$before = time();
		$this->job( $client )->run( $this->post_id );
		$after = time();

		$this->assertSame( [], $client->calls );

		$jobs = $this->jobs();

		$this->assertCount( 1, $jobs );
		$this->assertGreaterThanOrEqual( $before + Score_Job::BUSY_DELAY, $jobs[0] );
		$this->assertLessThanOrEqual( $after + 2 * Score_Job::BUSY_DELAY, $jobs[0] );
	}

	/**
	 * Test that a job runs when fewer than "Jobs at once" are running.
	 */
	public function test_runs_when_under_concurrency(): void {
		update_option( Settings::OPTION_KEY, [ 'concurrency' => 2 ] + Settings::get() );

		$client = new Fake_Decision_Client( $this->decision() );

		$this->start_job( self::factory()->post->create() );
		$this->start_job( $this->post_id );
		$this->job( $client )->run( $this->post_id );

		$this->assertCount( 1, $client->calls );
	}

	/**
	 * Test that jobs wait while scoring is paused.
	 */
	public function test_waits_while_paused(): void {
		$client = new Fake_Decision_Client( $this->decision() );

		for ( $i = 0; $i < Usage::FAILURE_LIMIT; $i++ ) {
			Usage::record_failure( 'Server error' );
		}

		$this->job( $client )->run( $this->post_id );

		$this->assertSame( [], $client->calls );
		$this->assertSame( 0, $this->repository->get( $this->post_id )?->attempts );

		$jobs = $this->jobs();

		$this->assertCount( 1, $jobs );
		$this->assertGreaterThanOrEqual( Usage::paused_until(), $jobs[0] );
		$this->assertLessThanOrEqual( Usage::paused_until() + MINUTE_IN_SECONDS, $jobs[0] );
	}

	/**
	 * Test that jobs wait for tomorrow once today's budget is spent.
	 */
	public function test_waits_for_tomorrow_when_budget_spent(): void {
		update_option( Settings::OPTION_KEY, [ 'daily_budget' => 0.01 ] + Settings::get() );
		Usage::record_success( 0.01 );

		$client = new Fake_Decision_Client( $this->decision() );

		$this->job( $client )->run( $this->post_id );

		$this->assertSame( [], $client->calls );
		$this->assertSame( 0, $this->repository->get( $this->post_id )?->attempts );

		$jobs = $this->jobs();

		$this->assertCount( 1, $jobs );
		$this->assertGreaterThanOrEqual( Usage::tomorrow(), $jobs[0] );
		$this->assertLessThanOrEqual( Usage::tomorrow() + Score_Job::BUDGET_SPREAD, $jobs[0] );
	}

	/**
	 * Test that a scored post counts toward today's spend.
	 */
	public function test_scored_post_counts_toward_spend(): void {
		$this->job( new Fake_Decision_Client( $this->decision() ) )->run( $this->post_id );

		$this->assertSame( 0.00002, Usage::get()['cost'] );
		$this->assertSame( 1, Usage::get()['calls'] );
	}

	/**
	 * Test that failed requests count toward the pause, and other errors don't.
	 */
	public function test_failures_count_toward_pause(): void {
		$this->job( new Fake_Decision_Client( $this->error( Decision_Client::ERROR_AUTH ) ) )->run( $this->post_id );

		$this->assertSame( 1, Usage::get()['failures'] );
		$this->assertSame( 'Failed.', Usage::get()['last_error'] );

		$this->job( new Fake_Decision_Client( new Decision( [], 'typesafe/jev-1.13', 0.0, '' ) ) )->run( $this->post_id );

		$this->assertSame( 1, Usage::get()['failures'] );
	}

	/**
	 * Test that failed requests across posts pause scoring.
	 */
	public function test_failures_across_posts_pause_scoring(): void {
		$client = new Fake_Decision_Client( $this->error( Decision_Client::ERROR_SERVER ) );

		for ( $i = 0; $i < Usage::FAILURE_LIMIT; $i++ ) {
			$post_id = self::factory()->post->create();

			$this->repository->add_queued( $post_id, '2026-10-14 00:00:00' );
			$this->job( $client )->run( $post_id );
		}

		$this->assertGreaterThan( time(), Usage::paused_until() );
	}

	/**
	 * Test the backoff delays.
	 */
	public function test_backoff(): void {
		$retry = Score_Job::backoff( 1, 300 );

		$this->assertGreaterThanOrEqual( 300, $retry );
		$this->assertLessThanOrEqual( 310, $retry );

		foreach ( range( 1, 10 ) as $attempt ) {
			$full  = (int) min( Score_Job::MAX_BACKOFF, Score_Job::BASE_BACKOFF * 2 ** ( $attempt - 1 ) );
			$delay = Score_Job::backoff( $attempt );

			$this->assertGreaterThanOrEqual( intdiv( $full, 2 ), $delay );
			$this->assertLessThanOrEqual( $full, $delay );
		}

		$this->assertLessThanOrEqual( Score_Job::MAX_BACKOFF + 10, Score_Job::backoff( 1, 10 * DAY_IN_SECONDS ) );
		$this->assertGreaterThanOrEqual( Score_Job::MAX_BACKOFF, Score_Job::backoff( 1, 10 * DAY_IN_SECONDS ) );
		$this->assertLessThanOrEqual( Score_Job::BASE_BACKOFF, Score_Job::backoff( 1, 0 ) );
	}

	/**
	 * Test that Action Scheduler's concurrency is raised to "Jobs at once", never lowered.
	 */
	public function test_concurrent_batches(): void {
		update_option( Settings::OPTION_KEY, [ 'concurrency' => 3 ] + Settings::get() );

		$job = new Score_Job();

		$this->assertSame( 3, $job->concurrent_batches( 1 ) );
		$this->assertSame( 5, $job->concurrent_batches( 5 ) );
		$this->assertSame( 3, $job->concurrent_batches( 'x' ) );
	}

	/**
	 * Test that a post being scored can be scheduled again.
	 */
	public function test_running_job_doesnt_block_scheduling(): void {
		$this->start_job( $this->post_id );

		$this->assertFalse( Queue::is_scheduled( $this->post_id ) );
		$this->assertTrue( Queue::schedule( $this->post_id ) );
		$this->assertTrue( Queue::is_scheduled( $this->post_id ) );
	}

	/**
	 * Test that old finished jobs are deleted and recent ones kept.
	 */
	public function test_prune_deletes_old_finished_jobs(): void {
		$store = ActionScheduler::store();
		$old   = as_schedule_single_action( time() - 2 * Queue::RETENTION, Queue::HOOK, [ 1 ], Queue::GROUP );
		$new   = as_schedule_single_action( time() - HOUR_IN_SECONDS, Queue::HOOK, [ 2 ], Queue::GROUP );
		$other = as_schedule_single_action( time() - 2 * Queue::RETENTION, 'other_plugin/job', [ 1 ], 'other' );
		$wait  = as_schedule_single_action( time() - 2 * Queue::RETENTION, Queue::HOOK, [ 3 ], Queue::GROUP );

		foreach ( [ $old, $new, $other ] as $id ) {
			$store->mark_complete( $id );
		}

		$this->assertSame( 1, Queue::prune() );
		$this->assertSame( ActionScheduler_Store::STATUS_COMPLETE, $store->get_status( (string) $new ) );
		$this->assertSame( ActionScheduler_Store::STATUS_COMPLETE, $store->get_status( (string) $other ) );
		$this->assertSame( ActionScheduler_Store::STATUS_PENDING, $store->get_status( (string) $wait ) );
		$this->assertNotContains( $old, as_get_scheduled_actions( [ 'per_page' => -1 ], 'ids' ) );
	}

	/**
	 * Test that saving a post with no waiting job starts its attempts again.
	 */
	public function test_new_save_resets_attempts(): void {
		$wires = self::factory()->category->create();

		update_option( Settings::OPTION_KEY, [ 'terms' => [ 'category' => [ $wires ] ] ] + Settings::get() );
		wp_set_post_categories( $this->post_id, [ $wires ] );
		Queue::unschedule( $this->post_id );
		$this->repository->set_attempts( $this->post_id, Score_Job::MAX_ATTEMPTS );

		wp_update_post(
			[
				'ID'         => $this->post_id,
				'post_title' => 'Updated',
			]
		);

		$this->assertSame( 0, $this->repository->get( $this->post_id )?->attempts );
		$this->assertCount( 1, $this->jobs() );
	}

	/**
	 * A job using the given client.
	 *
	 * @param \JDeepD\AI_Post_Triage\Tests\Fakes\Fake_Decision_Client $client Client.
	 */
	private function job( Fake_Decision_Client $client ): Score_Job {
		return new Score_Job( new Post_Scorer( $client, $this->repository ), $this->repository );
	}

	/**
	 * A decision answering the question.
	 */
	private function decision(): Decision {
		return new Decision( [ 'q_breaking' => 0.9 ], 'typesafe/jev-1.13', 0.00002, '' );
	}

	/**
	 * A client error.
	 *
	 * @param string   $code        Error code.
	 * @param int|null $retry_after Seconds to wait.
	 */
	private function error( string $code, ?int $retry_after = null ): \WP_Error {
		$data = [ 'status' => null === $retry_after ? 500 : 429 ];

		if ( null !== $retry_after ) {
			$data['retry_after'] = $retry_after;
		}

		return new \WP_Error( $code, 'Failed.', $data );
	}

	/**
	 * Marks a scoring job for a post as running.
	 *
	 * @param int $post_id Post ID.
	 */
	private function start_job( int $post_id ): void {
		$id = as_schedule_single_action( time(), Queue::HOOK, [ $post_id ], Queue::GROUP );

		ActionScheduler::store()->log_execution( $id );
	}

	/**
	 * Run times of the post's waiting jobs.
	 *
	 * @return int[]
	 */
	private function jobs(): array {
		$actions = as_get_scheduled_actions(
			[
				'hook'     => Queue::HOOK,
				'args'     => [ $this->post_id ],
				'group'    => Queue::GROUP,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			]
		);

		return array_values(
			array_map(
				static fn ( ActionScheduler_Action $action ): int => (int) $action->get_schedule()->get_date()?->getTimestamp(),
				$actions
			)
		);
	}
}
