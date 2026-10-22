<?php
/**
 * Integration tests for Backfill.
 *
 * @package JDeepD\AI_Post_Triage\Tests\Integration\Jobs
 */

declare( strict_types = 1 );

namespace JDeepD\AI_Post_Triage\Tests\Integration\Jobs;

use JDeepD\AI_Post_Triage\Data\Wire_Post;
use JDeepD\AI_Post_Triage\Data\Wire_Post_Repository;
use JDeepD\AI_Post_Triage\Jobs\Backfill;
use JDeepD\AI_Post_Triage\Jobs\Queue;
use JDeepD\AI_Post_Triage\Modules\Settings\Settings;
use JDeepD\AI_Post_Triage\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Class - BackfillTest
 */
#[CoversClass( Backfill::class )]
class BackfillTest extends TestCase {
	/**
	 * Wire post table.
	 *
	 * @var \JDeepD\AI_Post_Triage\Data\Wire_Post_Repository
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
	 * Clears the progress and settings.
	 */
	protected function tearDown(): void {
		Backfill::reset();
		delete_option( Settings::OPTION_KEY );

		parent::tearDown();
	}

	/**
	 * Test that the post types read are the wire post types and those of the wire terms' taxonomies.
	 */
	public function test_post_types(): void {
		$this->assertSame( [], Backfill::post_types() );

		register_post_type( 'wire_item' );
		$category = self::factory()->category->create();

		$this->settings(
			[
				'post_types' => [ 'wire_item', 'gone' ],
				'terms'      => [ 'category' => [ $category ] ],
			]
		);

		$this->assertSame( [ 'wire_item', 'post' ], Backfill::post_types() );

		unregister_post_type( 'wire_item' );
	}

	/**
	 * Test that a batch adds the wire posts, newest first, and saves where it stopped.
	 */
	public function test_run_batch_adds_wire_posts_and_saves_progress(): void {
		$posts = $this->posts( 5 );
		$page  = self::factory()->post->create( [ 'post_type' => 'page' ] );

		$this->settings( [ 'post_types' => [ 'post' ] ] );

		$progress = ( new Backfill( $this->repository ) )->run_batch( 3 );

		$this->assertSame(
			[
				'cursor'  => $posts[2],
				'scanned' => 3,
				'added'   => 3,
				'done'    => false,
			],
			$progress
		);
		$this->assertSame( $progress, Backfill::progress() );
		$this->assertCount( 3, $this->repository->get_many( $posts ) );
		$this->assertNull( $this->repository->get( $page ) );
		$this->assertTrue( Queue::is_scheduled( $posts[4] ) );
	}

	/**
	 * Test that a new run carries on below the saved post and finishes on a short batch.
	 */
	public function test_run_batch_resumes_and_finishes(): void {
		$posts = $this->posts( 5 );

		$this->settings( [ 'post_types' => [ 'post' ] ] );

		( new Backfill( $this->repository ) )->run_batch( 3 );

		$progress = ( new Backfill( $this->repository ) )->run_batch( 3 );

		$this->assertSame(
			[
				'cursor'  => $posts[0],
				'scanned' => 5,
				'added'   => 5,
				'done'    => true,
			],
			$progress
		);
		$this->assertCount( 5, $this->repository->get_many( $posts ) );
		$this->assertSame( $progress, ( new Backfill( $this->repository ) )->run_batch( 3 ) );
	}

	/**
	 * Test that posts already in AI Post Triage are left as they are.
	 */
	public function test_run_batch_skips_known_posts(): void {
		[ $known, $new ] = $this->posts( 2 );

		$this->repository->add_queued( $known, '2024-01-01 00:00:00' );
		$this->repository->set_state( $known, Wire_Post::STATE_DISMISSED );

		$this->settings( [ 'post_types' => [ 'post' ] ] );

		$progress = ( new Backfill( $this->repository ) )->run_batch( 10 );

		$this->assertSame( 1, $progress['added'] );
		$this->assertSame( 2, $progress['scanned'] );
		$this->assertSame( Wire_Post::STATE_DISMISSED, $this->repository->get( $known )?->state );
		$this->assertFalse( Queue::is_scheduled( $known ) );
		$this->assertNotNull( $this->repository->get( $new ) );
	}

	/**
	 * Test that the backfill stops at a batch that is all older than the scoring window.
	 */
	public function test_run_batch_stops_outside_window(): void {
		$old    = $this->posts( 2, gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) );
		$recent = $this->posts( 2 );

		$this->settings(
			[
				'post_types'  => [ 'post' ],
				'window_days' => 3,
			]
		);

		$backfill = new Backfill( $this->repository );
		$first    = $backfill->run_batch( 2 );
		$second   = $backfill->run_batch( 2 );

		$this->assertFalse( $first['done'] );
		$this->assertSame( 2, $first['added'] );
		$this->assertTrue( $second['done'] );
		$this->assertSame( 2, $second['added'] );
		$this->assertSame( [], $this->repository->get_many( $old ) );
		$this->assertCount( 2, $this->repository->get_many( $recent ) );
	}

	/**
	 * Test that added posts aren't scheduled when automatic scoring is off.
	 */
	public function test_run_batch_without_auto_score(): void {
		[ $post_id ] = $this->posts( 1 );

		$this->settings(
			[
				'post_types' => [ 'post' ],
				'auto_score' => false,
			]
		);

		( new Backfill( $this->repository ) )->run_batch();

		$this->assertSame( Wire_Post::STATE_QUEUED, $this->repository->get( $post_id )?->state );
		$this->assertFalse( Queue::is_scheduled( $post_id ) );
	}

	/**
	 * Test that nothing is read without wire post types or terms.
	 */
	public function test_run_batch_without_post_types(): void {
		$this->posts( 2 );

		$progress = ( new Backfill( $this->repository ) )->run_batch();

		$this->assertTrue( $progress['done'] );
		$this->assertSame( 0, $progress['scanned'] );
	}

	/**
	 * Test that reset() starts again from the newest post.
	 */
	public function test_reset(): void {
		$this->posts( 1 );
		$this->settings( [ 'post_types' => [ 'post' ] ] );

		( new Backfill( $this->repository ) )->run_batch();
		Backfill::reset();

		$this->assertSame(
			[
				'cursor'  => 0,
				'scanned' => 0,
				'added'   => 0,
				'done'    => false,
			],
			Backfill::progress()
		);
	}

	/**
	 * Creates draft posts, oldest first, before the settings make them wire posts.
	 *
	 * @param int         $count Posts.
	 * @param string|null $date  Post date in GMT, or now.
	 * @return int[]
	 */
	private function posts( int $count, ?string $date = null ): array {
		$args = [ 'post_status' => 'draft' ];

		if ( null !== $date ) {
			$args['post_date_gmt'] = $date;
			$args['post_date']     = get_date_from_gmt( $date );
		}

		return array_map( 'intval', self::factory()->post->create_many( $count, $args ) );
	}

	/**
	 * Saves settings.
	 *
	 * @param array<string, mixed> $settings Settings.
	 */
	private function settings( array $settings ): void {
		update_option( Settings::OPTION_KEY, $settings + Settings::defaults() );
	}
}
