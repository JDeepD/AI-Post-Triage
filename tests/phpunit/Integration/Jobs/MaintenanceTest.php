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
use JDeepD\Wire_Desk\Scoring\Result;
use JDeepD\Wire_Desk\Scoring\Score;
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
		$this->assertSame( 10, has_action( 'wire_desk/scored', [ $maintenance, 'on_scored' ] ) );
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
	 * Test that a finished prune remembers its cutoff, so the next one doesn't read cleared history again.
	 */
	public function test_prune_remembers_where_it_stopped(): void {
		global $wpdb;

		$this->scored( 100 );

		$this->assertSame( '', Maintenance::pruned_before() );

		( new Maintenance( $this->repository ) )->prune_details( 30 );

		$mark = Maintenance::pruned_before();

		$this->assertEqualsWithDelta( time() - 30 * DAY_IN_SECONDS, (int) strtotime( $mark . ' ' . wp_timezone_string() ), 5 );

		$queries = [];
		add_filter(
			'query',
			static function ( $query ) use ( &$queries ) {
				$queries[] = $query;
				return $query;
			}
		);

		( new Maintenance( $this->repository ) )->prune_details( 30 );

		$this->assertStringContainsString( $wpdb->prepare( 'post_date >= %s', $mark ), implode( "\n", $queries ) );
	}

	/**
	 * Test that answers saved on a post older than the mark move the mark back, so they are cleared.
	 */
	public function test_old_post_scored_again_is_pruned(): void {
		$maintenance = new Maintenance( $this->repository );
		$maintenance->prune_details( 30 );

		$old = $this->scored( 200 );

		$this->assertSame( 0, $maintenance->prune_details( 30 ) );
		$this->assertNotNull( $this->repository->get( $old )?->details );

		$maintenance->on_scored( new Result( $old, new Score( 70, Wire_Post::BAND_HIGH, [] ), 'typesafe/jev-1.13', 0.0, false ) );

		$this->assertSame( $this->repository->get( $old )?->post_date, Maintenance::pruned_before() );
		$this->assertSame( 1, $maintenance->prune_details( 30 ) );
		$this->assertNull( $this->repository->get( $old )?->details );
	}

	/**
	 * Test that a longer retention than the mark clears nothing and keeps the mark.
	 */
	public function test_prune_with_longer_retention_keeps_mark(): void {
		$maintenance = new Maintenance( $this->repository );
		$maintenance->prune_details( 30 );
		$mark = Maintenance::pruned_before();

		$this->assertSame( 0, $maintenance->prune_details( 60 ) );
		$this->assertSame( $mark, Maintenance::pruned_before() );
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
