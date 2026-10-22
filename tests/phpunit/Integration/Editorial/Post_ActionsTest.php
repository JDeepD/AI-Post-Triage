<?php
/**
 * Integration tests for Post_Actions.
 *
 * @package JDeepD\AI_Post_Triage\Tests\Integration\Editorial
 */

declare( strict_types = 1 );

namespace JDeepD\AI_Post_Triage\Tests\Integration\Editorial;

use JDeepD\AI_Post_Triage\Data\Wire_Post;
use JDeepD\AI_Post_Triage\Data\Wire_Post_Repository;
use JDeepD\AI_Post_Triage\Editorial\Post_Actions;
use JDeepD\AI_Post_Triage\Jobs\Queue;
use JDeepD\AI_Post_Triage\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Class - Post_ActionsTest
 */
#[CoversClass( Post_Actions::class )]
class Post_ActionsTest extends TestCase {
	/**
	 * Role that can review wire posts but not publish them.
	 */
	private const REVIEWER = 'ai_post_triage_test_reviewer';

	/**
	 * Wire post table.
	 *
	 * @var \JDeepD\AI_Post_Triage\Data\Wire_Post_Repository
	 */
	private Wire_Post_Repository $repository;

	/**
	 * Service under test.
	 *
	 * @var \JDeepD\AI_Post_Triage\Editorial\Post_Actions
	 */
	private Post_Actions $actions;

	/**
	 * A draft wire post.
	 *
	 * @var int
	 */
	private int $post_id;

	/**
	 * Actions fired.
	 *
	 * @var array<int, array{string, int, int}>
	 */
	private array $fired = [];

	/**
	 * Signs in an editor and adds a draft wire post.
	 */
	protected function setUp(): void {
		parent::setUp();

		add_role(
			self::REVIEWER,
			'Reviewer',
			[
				'read'              => true,
				'edit_posts'        => true,
				'edit_others_posts' => true,
			]
		);

		$this->repository = new Wire_Post_Repository();
		$this->actions    = new Post_Actions( $this->repository );
		$this->post_id    = $this->wire_post();
		$this->fired      = [];

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		add_action( 'ai_post_triage/post_action', [ $this, 'record' ], 10, 3 );
	}

	/**
	 * Removes the test role.
	 */
	protected function tearDown(): void {
		remove_role( self::REVIEWER );

		parent::tearDown();
	}

	/**
	 * Records a fired action.
	 *
	 * @param string $action  Action.
	 * @param int    $post_id Post ID.
	 * @param int    $user_id User ID.
	 */
	public function record( string $action, int $post_id, int $user_id ): void {
		$this->fired[] = [ $action, $post_id, $user_id ];
	}

	/**
	 * Test that publishing publishes the post and marks the row published.
	 */
	public function test_publish(): void {
		$this->assertTrue( $this->actions->publish( $this->post_id ) );
		$this->assertSame( 'publish', get_post_status( $this->post_id ) );
		$this->assertSame( Wire_Post::STATE_PUBLISHED, $this->state() );
	}

	/**
	 * Test that a post dated in the future is scheduled, not published.
	 */
	public function test_publish_future_post_schedules_it(): void {
		$date    = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
		$post_id = $this->wire_post(
			[
				'post_date'     => get_date_from_gmt( $date ),
				'post_date_gmt' => $date,
			]
		);

		$this->assertTrue( $this->actions->publish( $post_id ) );
		$this->assertSame( 'future', get_post_status( $post_id ) );
		$this->assertSame( Wire_Post::STATE_QUEUED, $this->repository->get( $post_id )?->state );
	}

	/**
	 * Test that publishing a published post does nothing.
	 */
	public function test_publish_published_post(): void {
		wp_publish_post( $this->post_id );

		$this->assertTrue( $this->actions->publish( $this->post_id ) );
		$this->assertSame( Wire_Post::STATE_PUBLISHED, $this->state() );
	}

	/**
	 * Test that reviewers who can't publish posts can't publish wire posts.
	 */
	public function test_publish_needs_publish_capability(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => self::REVIEWER ] ) );

		$this->assertError( 'ai_post_triage_forbidden', 403, $this->actions->publish( $this->post_id ) );
		$this->assertSame( 'draft', get_post_status( $this->post_id ) );
		$this->assertTrue( $this->actions->dismiss( $this->post_id ) );
	}

	/**
	 * Test that dismissing marks the row dismissed and cancels its waiting job.
	 */
	public function test_dismiss(): void {
		Queue::schedule( $this->post_id );

		$this->assertTrue( $this->actions->dismiss( $this->post_id ) );
		$this->assertSame( Wire_Post::STATE_DISMISSED, $this->state() );
		$this->assertFalse( Queue::is_scheduled( $this->post_id ) );
	}

	/**
	 * Test that published posts can't be dismissed.
	 */
	public function test_dismiss_published_post(): void {
		$this->actions->publish( $this->post_id );

		$this->assertError( 'ai_post_triage_published', 409, $this->actions->dismiss( $this->post_id ) );
		$this->assertSame( Wire_Post::STATE_PUBLISHED, $this->state() );
	}

	/**
	 * Test that restoring puts a dismissed post back in its earlier state.
	 *
	 * @param string $setup    How the post was left before it was dismissed.
	 * @param int    $expected Expected state.
	 */
	#[DataProvider( 'data_restore' )]
	public function test_restore( string $setup, int $expected ): void {
		if ( 'scored' === $setup ) {
			$this->repository->save_score( $this->post_id, Wire_Post::BAND_HIGH, 80, 'typesafe/jev-1.13', str_repeat( 'a', 32 ), 0.0, [] );
		} elseif ( 'failed' === $setup ) {
			$this->repository->save_error( $this->post_id, 'Server error' );
		}

		$this->actions->dismiss( $this->post_id );

		$this->assertTrue( $this->actions->restore( $this->post_id ) );
		$this->assertSame( $expected, $this->state() );
	}

	/**
	 * Earlier states.
	 *
	 * @return array<string, array{string, int}>
	 */
	public static function data_restore(): array {
		return [
			'scored' => [ 'scored', Wire_Post::STATE_SCORED ],
			'failed' => [ 'failed', Wire_Post::STATE_FAILED ],
			'queued' => [ 'queued', Wire_Post::STATE_QUEUED ],
		];
	}

	/**
	 * Test that only dismissed posts can be restored.
	 */
	public function test_restore_needs_dismissed_post(): void {
		$this->assertError( 'ai_post_triage_not_dismissed', 409, $this->actions->restore( $this->post_id ) );
	}

	/**
	 * Test that re-scoring schedules the post and starts its attempts again.
	 */
	public function test_rescore(): void {
		$this->repository->set_attempts( $this->post_id, 5 );

		$this->assertTrue( $this->actions->rescore( $this->post_id ) );
		$this->assertTrue( Queue::is_scheduled( $this->post_id ) );
		$this->assertSame( 0, $this->repository->get( $this->post_id )?->attempts );
	}

	/**
	 * Test that re-scoring a post with a waiting job keeps that job.
	 */
	public function test_rescore_waiting_post(): void {
		Queue::schedule( $this->post_id );
		$this->repository->set_attempts( $this->post_id, 2 );

		$this->assertTrue( $this->actions->rescore( $this->post_id ) );
		$this->assertSame( 2, $this->repository->get( $this->post_id )?->attempts );
		$this->assertCount(
			1,
			as_get_scheduled_actions(
				[
					'hook'   => Queue::HOOK,
					'args'   => [ $this->post_id ],
					'status' => \ActionScheduler_Store::STATUS_PENDING,
				],
				'ids'
			)
		);
	}

	/**
	 * Test that users who can't review wire posts can't take any action.
	 *
	 * @param string $action Action.
	 */
	#[DataProvider( 'data_actions' )]
	public function test_actions_need_review_capability( string $action ): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );

		$this->assertError( 'ai_post_triage_forbidden', 403, $this->actions->run( $action, $this->post_id ) );
		$this->assertSame( Wire_Post::STATE_QUEUED, $this->state() );
		$this->assertFalse( Queue::is_scheduled( $this->post_id ) );
		$this->assertSame( [], $this->fired );
	}

	/**
	 * Test that logged-out users can't take any action.
	 *
	 * @param string $action Action.
	 */
	#[DataProvider( 'data_actions' )]
	public function test_actions_need_user( string $action ): void {
		wp_set_current_user( 0 );

		$this->assertError( 'ai_post_triage_forbidden', 403, $this->actions->run( $action, $this->post_id ) );
	}

	/**
	 * Test that actions on posts outside the table fail.
	 *
	 * @param string $action Action.
	 */
	#[DataProvider( 'data_actions' )]
	public function test_actions_need_wire_post( string $action ): void {
		$other = self::factory()->post->create( [ 'post_status' => 'draft' ] );

		$this->assertError( 'ai_post_triage_not_found', 404, $this->actions->run( $action, $other ) );
		$this->assertError( 'ai_post_triage_not_found', 404, $this->actions->run( $action, PHP_INT_MAX ) );
		$this->assertSame( 'draft', get_post_status( $other ) );
	}

	/**
	 * Every action.
	 *
	 * @return array<string, array{string}>
	 */
	public static function data_actions(): array {
		return [
			'publish' => [ Post_Actions::PUBLISH ],
			'dismiss' => [ Post_Actions::DISMISS ],
			'restore' => [ Post_Actions::RESTORE ],
			'rescore' => [ Post_Actions::RESCORE ],
		];
	}

	/**
	 * Test that run() takes the action and announces it.
	 */
	public function test_run_fires_action(): void {
		$this->assertTrue( $this->actions->run( Post_Actions::DISMISS, $this->post_id ) );
		$this->assertSame( [ [ Post_Actions::DISMISS, $this->post_id, get_current_user_id() ] ], $this->fired );

		$this->actions->run( Post_Actions::DISMISS, PHP_INT_MAX );

		$this->assertCount( 1, $this->fired );
	}

	/**
	 * Test that unknown actions are rejected.
	 */
	public function test_run_unknown_action(): void {
		$this->assertError( 'ai_post_triage_unknown_action', 400, $this->actions->run( 'delete', $this->post_id ) );
	}

	/**
	 * Test that every action has a label.
	 */
	public function test_labels(): void {
		$this->assertSame( [ 'publish', 'dismiss', 'restore', 'rescore' ], array_keys( Post_Actions::labels() ) );
	}

	/**
	 * Asserts a result is an error with a code and status.
	 *
	 * @param string $code   Error code.
	 * @param int    $status HTTP status.
	 * @param mixed  $result Result.
	 */
	private function assertError( string $code, int $status, $result ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Matches PHPUnit's assertion names.
		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( [ 'status' => $status ], $result->get_error_data() );
	}

	/**
	 * The test post's state.
	 */
	private function state(): ?int {
		return $this->repository->get( $this->post_id )?->state;
	}

	/**
	 * Test the actions offered for each state.
	 */
	public function test_available_by_state(): void {
		$this->assertSame( [ 'publish', 'dismiss', 'rescore' ], Post_Actions::available( $this->row( $this->post_id ) ) );

		$this->repository->set_state( $this->post_id, Wire_Post::STATE_DISMISSED );
		$this->assertSame( [ 'publish', 'restore', 'rescore' ], Post_Actions::available( $this->row( $this->post_id ) ) );

		$published = $this->wire_post( [ 'post_status' => 'publish' ] );
		$this->repository->set_state( $published, Wire_Post::STATE_PUBLISHED );
		$this->assertSame( [ 'rescore' ], Post_Actions::available( $this->row( $published ) ) );
	}

	/**
	 * Test that the actions offered follow the user's capabilities.
	 */
	public function test_available_follows_capabilities(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => self::REVIEWER ] ) );
		$this->assertSame( [ 'dismiss', 'rescore' ], Post_Actions::available( $this->row( $this->post_id ) ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );
		$this->assertSame( [], Post_Actions::available( $this->row( $this->post_id ) ) );
	}

	/**
	 * Test that a row whose post is gone offers nothing.
	 */
	public function test_available_without_post(): void {
		$this->repository->add_queued( 999999, '2024-01-01 00:00:00' );

		$this->assertSame( [], Post_Actions::available( $this->row( 999999 ) ) );
	}

	/**
	 * Test that add() adds a post that isn't in AI Post Triage and schedules it, whatever its age.
	 */
	public function test_add_adds_and_schedules(): void {
		$post_id = self::factory()->post->create(
			[
				'post_status' => 'draft',
				'post_date'   => '2020-01-01 00:00:00',
			]
		);

		$this->assertNull( $this->repository->get( $post_id ) );
		$this->assertTrue( $this->actions->add( $post_id ) );

		$row = $this->repository->get( $post_id );

		$this->assertSame( Wire_Post::STATE_QUEUED, $row?->state );
		$this->assertSame( '2020-01-01 00:00:00', $row?->post_date );
		$this->assertTrue( Queue::is_scheduled( $post_id ) );
		$this->assertSame( [ [ Post_Actions::ADD, $post_id, get_current_user_id() ] ], $this->fired );
	}

	/**
	 * Test that a published post is added as published.
	 */
	public function test_add_published_post(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		$this->assertTrue( $this->actions->add( $post_id ) );
		$this->assertSame( Wire_Post::STATE_PUBLISHED, $this->repository->get( $post_id )?->state );
	}

	/**
	 * Test that adding a post already in AI Post Triage keeps its state and schedules it again.
	 */
	public function test_add_existing_post(): void {
		$this->repository->set_state( $this->post_id, Wire_Post::STATE_DISMISSED );

		$this->assertTrue( $this->actions->add( $this->post_id ) );
		$this->assertSame( Wire_Post::STATE_DISMISSED, $this->repository->get( $this->post_id )?->state );
		$this->assertTrue( Queue::is_scheduled( $this->post_id ) );
	}

	/**
	 * Test the errors.
	 */
	public function test_add_errors(): void {
		$trashed = self::factory()->post->create( [ 'post_status' => 'trash' ] );

		$this->assertSame( 'ai_post_triage_cannot_add', $this->error_code( $this->actions->add( $trashed ) ) );
		$this->assertSame( 'ai_post_triage_not_found', $this->error_code( $this->actions->add( 999999 ) ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );

		$this->assertSame( 'ai_post_triage_forbidden', $this->error_code( $this->actions->add( self::factory()->post->create() ) ) );
		$this->assertSame( [], $this->fired );
	}

	/**
	 * The error code of a result.
	 *
	 * @param true|\WP_Error $result Result.
	 */
	private function error_code( bool|\WP_Error $result ): string {
		return is_wp_error( $result ) ? (string) $result->get_error_code() : '';
	}

	/**
	 * A post's row.
	 *
	 * @param int $post_id Post ID.
	 */
	private function row( int $post_id ): Wire_Post {
		$row = $this->repository->get( $post_id );

		$this->assertInstanceOf( Wire_Post::class, $row );

		return $row;
	}

	/**
	 * Creates a draft post in the table.
	 *
	 * @param array<string, mixed> $args Post arguments.
	 */
	private function wire_post( array $args = [] ): int {
		$post_id = self::factory()->post->create( $args + [ 'post_status' => 'draft' ] );

		$this->repository->add_queued( $post_id, (string) get_post( $post_id )?->post_date );

		return $post_id;
	}
}
