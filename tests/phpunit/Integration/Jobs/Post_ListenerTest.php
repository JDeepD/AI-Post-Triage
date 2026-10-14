<?php
/**
 * Integration tests for Post_Listener and Queue.
 *
 * @package JDeepD\Wire_Desk\Tests\Integration\Jobs
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk\Tests\Integration\Jobs;

use JDeepD\Wire_Desk\Data\Wire_Post;
use JDeepD\Wire_Desk\Data\Wire_Post_Repository;
use JDeepD\Wire_Desk\Jobs\Post_Listener;
use JDeepD\Wire_Desk\Jobs\Queue;
use JDeepD\Wire_Desk\Modules\Settings\Settings;
use JDeepD\Wire_Desk\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Class - Post_ListenerTest
 */
#[CoversClass( Post_Listener::class )]
#[CoversClass( Queue::class )]
class Post_ListenerTest extends TestCase {
	/**
	 * Wire post table.
	 *
	 * @var \JDeepD\Wire_Desk\Data\Wire_Post_Repository
	 */
	private Wire_Post_Repository $repository;

	/**
	 * Wire category ID.
	 *
	 * @var int
	 */
	private int $wires;

	/**
	 * Treats posts in the Wires category as wire content.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->repository = new Wire_Post_Repository();
		$this->wires      = self::factory()->category->create( [ 'name' => 'Wires' ] );

		update_option(
			Settings::OPTION_KEY,
			[
				'terms'       => [ 'category' => [ $this->wires ] ],
				'window_days' => 3,
				'auto_score'  => true,
			]
		);
	}

	/**
	 * Test that the listener hooks into saves, term changes, trashing and deleting.
	 */
	public function test_register_hooks(): void {
		$listener = new Post_Listener();
		$listener->register_hooks();

		$this->assertSame( 10, has_action( 'wp_after_insert_post', [ $listener, 'on_save' ] ) );
		$this->assertSame( 10, has_action( 'set_object_terms', [ $listener, 'on_terms_set' ] ) );
		$this->assertSame( 10, has_action( 'trashed_post', [ $listener, 'on_remove' ] ) );
		$this->assertSame( 10, has_action( 'deleted_post', [ $listener, 'on_remove' ] ) );
	}

	/**
	 * Test that a saved wire post is queued and scheduled about a minute out.
	 */
	public function test_saved_wire_post_is_queued(): void {
		$post_id = $this->wire_post( [ 'post_date' => wp_date( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ] );

		$row = $this->repository->get( $post_id );

		$this->assertNotNull( $row );
		$this->assertSame( Wire_Post::STATE_QUEUED, $row->state );
		$this->assertSame( get_post( $post_id )?->post_date, $row->post_date );

		$jobs = $this->jobs( $post_id );

		$this->assertCount( 1, $jobs );
		$this->assertEqualsWithDelta( time() + Queue::DELAY, $jobs[0], 5 );
	}

	/**
	 * Test that other posts aren't queued.
	 */
	public function test_other_post_is_ignored(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );

		$this->assertNull( $this->repository->get( $post_id ) );
		$this->assertSame( [], $this->jobs( $post_id ) );
	}

	/**
	 * Test that saving a wire post again doesn't add another job.
	 */
	public function test_resaving_keeps_one_job(): void {
		$post_id = $this->wire_post();

		foreach ( [ 'Update one', 'Update two', 'Update three' ] as $title ) {
			wp_update_post(
				[
					'ID'         => $post_id,
					'post_title' => $title,
				]
			);
		}

		$this->assertCount( 1, $this->jobs( $post_id ) );
	}

	/**
	 * Test that a post given a wire term after it was saved is queued.
	 */
	public function test_wire_term_added_later_queues_post(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );

		$this->assertNull( $this->repository->get( $post_id ) );

		wp_set_object_terms( $post_id, [ $this->wires ], 'category', true );

		$this->assertSame( Wire_Post::STATE_QUEUED, $this->repository->get( $post_id )?->state );
		$this->assertCount( 1, $this->jobs( $post_id ) );
	}

	/**
	 * Test that term changes are ignored unless wire terms changed.
	 */
	public function test_other_taxonomy_change_is_ignored(): void {
		$post_id = $this->wire_post();

		$this->repository->delete( $post_id );
		Queue::unschedule( $post_id );

		$listener = new Post_Listener( $this->repository );
		$listener->on_terms_set( $post_id, [ 'tag' ], [ 1 ], 'post_tag', false, [] );
		$listener->on_terms_set( $post_id, [ $this->wires ], [ 5, 3 ], 'category', false, [ 3, 5 ] );

		$this->assertNull( $this->repository->get( $post_id ) );
		$this->assertSame( [], $this->jobs( $post_id ) );

		$listener->on_terms_set( $post_id, [ $this->wires ], [ 5 ], 'category', false, [] );

		$this->assertNotNull( $this->repository->get( $post_id ) );
	}

	/**
	 * Test that revisions, autosaves and auto-drafts aren't queued.
	 */
	public function test_revisions_autosaves_and_auto_drafts_are_ignored(): void {
		$post_id = $this->wire_post();

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'Changed.',
			]
		);

		$revisions  = wp_get_post_revisions( $post_id );
		$autosave   = _wp_put_post_revision(
			[
				'ID'           => $post_id,
				'post_title'   => 'Autosave',
				'post_content' => 'Autosaved.',
				'post_type'    => 'post',
			],
			true
		);
		$auto_draft = self::factory()->post->create(
			[
				'post_status'   => 'auto-draft',
				'post_category' => [ $this->wires ],
			]
		);

		$this->assertNotEmpty( $revisions );

		foreach ( array_merge( array_keys( $revisions ), [ (int) $autosave, $auto_draft ] ) as $id ) {
			( new Post_Listener() )->sync( get_post( $id ) ?? self::factory()->post->create_and_get() );
			$this->assertNull( $this->repository->get( $id ), "Post {$id} was queued." );
		}
	}

	/**
	 * Test that wire posts older than the window aren't queued.
	 */
	public function test_post_outside_window_is_ignored(): void {
		$post_id = $this->wire_post( [ 'post_date' => wp_date( 'Y-m-d H:i:s', time() - 4 * DAY_IN_SECONDS ) ] );

		$this->assertNull( $this->repository->get( $post_id ) );
		$this->assertSame( [], $this->jobs( $post_id ) );
	}

	/**
	 * Test that wire posts are added without a job when automatic scoring is off.
	 */
	public function test_auto_score_off_adds_row_without_job(): void {
		update_option( Settings::OPTION_KEY, [ 'auto_score' => false ] + Settings::get() );

		$post_id = $this->wire_post();

		$this->assertSame( Wire_Post::STATE_QUEUED, $this->repository->get( $post_id )?->state );
		$this->assertSame( [], $this->jobs( $post_id ) );
	}

	/**
	 * Test that publishing marks the row published, and unpublishing restores it.
	 */
	public function test_publish_and_unpublish_update_state(): void {
		$post_id = $this->wire_post();

		wp_publish_post( $post_id );

		$this->assertSame( Wire_Post::STATE_PUBLISHED, $this->repository->get( $post_id )?->state );

		wp_update_post(
			[
				'ID'          => $post_id,
				'post_status' => 'draft',
			]
		);

		$this->assertSame( Wire_Post::STATE_QUEUED, $this->repository->get( $post_id )?->state );
	}

	/**
	 * Test that an unpublished post that was scored goes back to scored.
	 */
	public function test_unpublish_scored_post_restores_scored(): void {
		$post_id = $this->wire_post();

		$this->repository->save_score( $post_id, Wire_Post::BAND_HIGH, 80, 'typesafe/jev-1.13', str_repeat( 'a', 32 ), 0.0, [] );
		wp_publish_post( $post_id );
		wp_update_post(
			[
				'ID'          => $post_id,
				'post_status' => 'pending',
			]
		);

		$this->assertSame( Wire_Post::STATE_SCORED, $this->repository->get( $post_id )?->state );
	}

	/**
	 * Test that a wire post published straight away is added as published.
	 */
	public function test_wire_post_saved_as_published_is_published(): void {
		$post_id = $this->wire_post( [ 'post_status' => 'publish' ] );

		$this->assertSame( Wire_Post::STATE_PUBLISHED, $this->repository->get( $post_id )?->state );
	}

	/**
	 * Test that a post that stops being wire content keeps its row.
	 */
	public function test_post_leaving_wire_keeps_row(): void {
		$post_id = $this->wire_post();
		$other   = self::factory()->category->create();

		wp_set_post_categories( $post_id, [ $other ] );
		wp_publish_post( $post_id );

		$this->assertSame( Wire_Post::STATE_PUBLISHED, $this->repository->get( $post_id )?->state );
	}

	/**
	 * Test that publishing updates the row's date.
	 */
	public function test_publish_updates_date(): void {
		$draft   = wp_date( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
		$post_id = $this->wire_post( [ 'post_date' => $draft ] );

		wp_update_post(
			[
				'ID'          => $post_id,
				'post_status' => 'publish',
			]
		);

		$date = get_post( $post_id )?->post_date;

		$this->assertNotSame( $draft, $date );
		$this->assertSame( $date, $this->repository->get( $post_id )?->post_date );
	}

	/**
	 * Test that trashing removes the row and the job.
	 */
	public function test_trash_removes_row_and_job(): void {
		$post_id = $this->wire_post();

		wp_trash_post( $post_id );

		$this->assertNull( $this->repository->get( $post_id ) );
		$this->assertSame( [], $this->jobs( $post_id ) );
	}

	/**
	 * Test that restoring a trashed wire post queues it again.
	 */
	public function test_untrash_queues_again(): void {
		$post_id = $this->wire_post();

		wp_trash_post( $post_id );
		wp_untrash_post( $post_id );

		$this->assertNotNull( $this->repository->get( $post_id ) );
		$this->assertCount( 1, $this->jobs( $post_id ) );
	}

	/**
	 * Test that deleting removes the row and the job.
	 */
	public function test_delete_removes_row_and_job(): void {
		$post_id = $this->wire_post();

		wp_delete_post( $post_id, true );

		$this->assertNull( $this->repository->get( $post_id ) );
		$this->assertSame( [], $this->jobs( $post_id ) );
	}

	/**
	 * Test that a post with a waiting job isn't scheduled again.
	 */
	public function test_schedule_is_idempotent(): void {
		$this->assertTrue( Queue::schedule( 4242 ) );
		$this->assertFalse( Queue::schedule( 4242 ) );
		$this->assertTrue( Queue::is_scheduled( 4242 ) );
		$this->assertCount( 1, $this->jobs( 4242 ) );

		Queue::unschedule( 4242 );

		$this->assertFalse( Queue::is_scheduled( 4242 ) );
	}

	/**
	 * Creates a draft wire post.
	 *
	 * @param array<string, mixed> $args Post arguments.
	 */
	private function wire_post( array $args = [] ): int {
		return self::factory()->post->create(
			$args + [
				'post_status'   => 'draft',
				'post_category' => [ $this->wires ],
			]
		);
	}

	/**
	 * Run times of a post's pending scoring jobs.
	 *
	 * @param int $post_id Post ID.
	 * @return int[]
	 */
	private function jobs( int $post_id ): array {
		$actions = as_get_scheduled_actions(
			[
				'hook'     => Queue::HOOK,
				'args'     => [ $post_id ],
				'group'    => Queue::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			]
		);

		return array_values(
			array_map(
				static fn ( \ActionScheduler_Action $action ): int => (int) $action->get_schedule()->get_date()?->getTimestamp(),
				$actions
			)
		);
	}
}
