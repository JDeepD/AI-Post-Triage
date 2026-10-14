<?php
/**
 * Integration tests for Maintenance.
 *
 * @package JDeepD\Wire_Desk\Tests\Integration\Jobs
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk\Tests\Integration\Jobs;

use JDeepD\Wire_Desk\Data\Wire_Post;
use JDeepD\Wire_Desk\Data\Wire_Post_Repository;
use JDeepD\Wire_Desk\Jobs\Maintenance;
use JDeepD\Wire_Desk\Jobs\Queue;
use JDeepD\Wire_Desk\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Class - MaintenanceTest
 */
#[CoversClass( Maintenance::class )]
class MaintenanceTest extends TestCase {
	/**
	 * Wire post table.
	 *
	 * @var \JDeepD\Wire_Desk\Data\Wire_Post_Repository
	 */
	private Wire_Post_Repository $repository;

	/**
	 * Sets up the repository.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->repository = new Wire_Post_Repository();
	}

	/**
	 * Test the hooks.
	 */
	public function test_register_hooks(): void {
		$maintenance = new Maintenance();
		$maintenance->register_hooks();

		$this->assertSame( 10, has_action( 'admin_init', [ $maintenance, 'schedule' ] ) );
		$this->assertSame( 10, has_action( Maintenance::HOOK, [ $maintenance, 'run' ] ) );
	}

	/**
	 * Test that the daily run is scheduled once, in the Wire Desk group.
	 */
	public function test_schedule_once(): void {
		$maintenance = new Maintenance();
		$maintenance->schedule();
		$maintenance->schedule();

		$actions = as_get_scheduled_actions(
			[
				'hook'   => Maintenance::HOOK,
				'group'  => Queue::GROUP,
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			]
		);

		$this->assertCount( 1, $actions );

		$schedule = reset( $actions )->get_schedule();

		$this->assertInstanceOf( \ActionScheduler_IntervalSchedule::class, $schedule );
		$this->assertSame( DAY_IN_SECONDS, $schedule->get_recurrence() );
	}

	/**
	 * Test the retention filter.
	 */
	public function test_retention_days(): void {
		$this->assertSame( Maintenance::DEFAULT_RETENTION_DAYS, Maintenance::retention_days() );

		add_filter( 'wire_desk/details_retention_days', static fn (): int => 30 );
		$this->assertSame( 30, Maintenance::retention_days() );

		add_filter( 'wire_desk/details_retention_days', static fn (): int => -5, 11 );
		$this->assertSame( 0, Maintenance::retention_days() );

		add_filter( 'wire_desk/details_retention_days', static fn (): string => 'soon', 12 );
		$this->assertSame( Maintenance::DEFAULT_RETENTION_DAYS, Maintenance::retention_days() );
	}

	/**
	 * Test that answers of posts older than the retention are cleared and the score is kept.
	 */
	public function test_prune_clears_old_answers(): void {
		$old    = $this->scored( 100 );
		$recent = $this->scored( 10 );

		$this->assertSame(
			[
				'details' => 1,
				'jobs'    => 0,
			],
			( new Maintenance( $this->repository ) )->prune()
		);
		$this->assertNull( $this->repository->get( $old )?->details );
		$this->assertSame( 70, $this->repository->get( $old )?->score );
		$this->assertNotNull( $this->repository->get( $recent )?->details );
	}

	/**
	 * Test that a retention of 0 keeps every answer.
	 */
	public function test_prune_keeps_answers_with_zero_retention(): void {
		$old = $this->scored( 400 );

		add_filter( 'wire_desk/details_retention_days', '__return_zero' );

		$this->assertSame( 0, ( new Maintenance( $this->repository ) )->prune()['details'] );
		$this->assertNotNull( $this->repository->get( $old )?->details );
	}

	/**
	 * Test that prune_details() clears answers older than the days given.
	 */
	public function test_prune_details_counts_days(): void {
		$old    = $this->scored( 40 );
		$recent = $this->scored( 20 );

		$this->assertSame( 1, ( new Maintenance( $this->repository ) )->prune_details( 30 ) );
		$this->assertNull( $this->repository->get( $old )?->details );
		$this->assertNotNull( $this->repository->get( $recent )?->details );
	}

	/**
	 * A scored post dated some days ago.
	 *
	 * @param int $days Days ago.
	 */
	private function scored( int $days ): int {
		static $next = 700000;

		$post_id = ++$next;
		$date    = wp_date( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		$this->repository->add_queued( $post_id, (string) $date );
		$this->repository->save_score( $post_id, Wire_Post::BAND_HIGH, 70, 'typesafe/jev-1.13', md5( (string) $post_id ), 0.0, [ [ 'id' => 'q1' ] ] );

		return $post_id;
	}
}
