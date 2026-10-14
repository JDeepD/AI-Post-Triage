<?php
/**
 * Integration tests for Post_List_Actions.
 *
 * @package JDeepD\Wire_Desk\Tests\Integration\Modules\Queue
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk\Tests\Integration\Modules\Queue;

use JDeepD\Wire_Desk\Data\Wire_Post_Repository;
use JDeepD\Wire_Desk\Jobs\Queue;
use JDeepD\Wire_Desk\Modules\Queue\Post_List_Actions;
use JDeepD\Wire_Desk\Modules\Settings\Settings;
use JDeepD\Wire_Desk\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Class - Post_List_ActionsTest
 */
#[CoversClass( Post_List_Actions::class )]
class Post_List_ActionsTest extends TestCase {
	/**
	 * Wire post table.
	 *
	 * @var \JDeepD\Wire_Desk\Data\Wire_Post_Repository
	 */
	private Wire_Post_Repository $repository;

	/**
	 * Loads the admin functions, signs in an editor and makes posts a wire post type.
	 */
	protected function setUp(): void {
		parent::setUp();

		require_once ABSPATH . 'wp-admin/includes/admin.php';

		$this->repository = new Wire_Post_Repository();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
	}

	/**
	 * Resets the screen, request and settings.
	 */
	protected function tearDown(): void {
		unset( $_GET['wire_desk_added'], $_GET['wire_desk_add_failed'], $_GET['post'], $_REQUEST['_wpnonce'], $_REQUEST['_wp_http_referer'] );
		delete_option( Settings::OPTION_KEY );
		set_current_screen( 'front' );

		parent::tearDown();
	}

	/**
	 * Test the hooks.
	 */
	public function test_register_hooks(): void {
		$list = new Post_List_Actions();
		$list->register_hooks();

		$this->assertSame( 10, has_action( 'load-edit.php', [ $list, 'load' ] ) );
		$this->assertSame( 10, has_action( 'admin_post_' . Post_List_Actions::ACTION, [ $list, 'handle_row_action' ] ) );
		$this->assertSame( 10, has_filter( 'removable_query_args', [ $list, 'removable_query_args' ] ) );
	}

	/**
	 * Test that the actions are offered only for wire post types, to reviewers.
	 */
	public function test_is_enabled(): void {
		$this->assertFalse( Post_List_Actions::is_enabled( 'post' ) );

		$this->wire_types( [ 'post' ] );

		$this->assertTrue( Post_List_Actions::is_enabled( 'post' ) );
		$this->assertFalse( Post_List_Actions::is_enabled( 'page' ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );

		$this->assertFalse( Post_List_Actions::is_enabled( 'post' ) );
	}

	/**
	 * Test that loading a wire post type's list adds the row and bulk actions, and other lists get nothing.
	 */
	public function test_load(): void {
		$this->wire_types( [ 'post' ] );

		$list = new Post_List_Actions( $this->repository );

		set_current_screen( 'edit-page' );
		$list->load();

		$this->assertFalse( has_filter( 'bulk_actions-edit-page', [ $list, 'bulk_actions' ] ) );

		set_current_screen( 'edit-post' );
		$list->load();

		$this->assertSame( 10, has_filter( 'post_row_actions', [ $list, 'row_actions' ] ) );
		$this->assertSame( 10, has_filter( 'page_row_actions', [ $list, 'row_actions' ] ) );
		$this->assertSame( 10, has_filter( 'bulk_actions-edit-post', [ $list, 'bulk_actions' ] ) );
		$this->assertSame( 10, has_filter( 'handle_bulk_actions-edit-post', [ $list, 'handle_bulk_action' ] ) );
		$this->assertSame( 10, has_action( 'admin_notices', [ $list, 'notice' ] ) );
	}

	/**
	 * Test the row action for a post not yet in Wire Desk, with a nonce for that post.
	 */
	public function test_row_action_adds(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->wire_types( [ 'post' ] );

		$actions = ( new Post_List_Actions( $this->repository ) )->row_actions( [ 'edit' => 'Edit' ], get_post( $post_id ) );
		$link    = $actions[ Post_List_Actions::ACTION ] ?? '';

		$this->assertSame( 'Edit', $actions['edit'] );
		$this->assertStringContainsString( '>Add to review queue</a>', $link );
		$this->assertStringContainsString( 'admin-post.php?action=wire_desk_add&#038;post=' . $post_id, $link );

		preg_match( '#_wpnonce=([a-f0-9]+)#', $link, $nonce );

		$this->assertSame( 1, wp_verify_nonce( $nonce[1] ?? '', Post_List_Actions::nonce_action( $post_id ) ) );
	}

	/**
	 * Test that a post in Wire Desk links to the queue instead.
	 */
	public function test_row_action_links_to_queue(): void {
		$this->wire_types( [ 'post' ] );
		$post_id = self::factory()->post->create();
		$this->repository->add_queued( $post_id, '2024-01-01 00:00:00' );

		$actions = ( new Post_List_Actions( $this->repository ) )->row_actions( [], get_post( $post_id ) );

		$this->assertStringContainsString( 'page=wire-desk&#038;view=all">In review queue</a>', $actions[ Post_List_Actions::ACTION ] ?? '' );
	}

	/**
	 * Test that other post types, trashed posts and other users get no row action.
	 */
	public function test_row_action_skipped(): void {
		$this->wire_types( [ 'post' ] );
		$list = new Post_List_Actions( $this->repository );

		$this->assertSame( [], $list->row_actions( [], get_post( self::factory()->post->create( [ 'post_type' => 'page' ] ) ) ) );
		$this->assertSame( [], $list->row_actions( [], get_post( self::factory()->post->create( [ 'post_status' => 'trash' ] ) ) ) );
		$this->assertSame( [], $list->row_actions( [], null ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );

		$this->assertSame( [], $list->row_actions( [], get_post( self::factory()->post->create() ) ) );
	}

	/**
	 * Test the bulk action.
	 */
	public function test_bulk_action(): void {
		$list = new Post_List_Actions( $this->repository );

		$this->assertSame(
			[
				'edit'                    => 'Edit',
				Post_List_Actions::ACTION => 'Add to review queue',
			],
			$list->bulk_actions( [ 'edit' => 'Edit' ] )
		);

		$posts   = self::factory()->post->create_many( 2, [ 'post_status' => 'draft' ] );
		$trashed = self::factory()->post->create( [ 'post_status' => 'trash' ] );

		$url = $list->handle_bulk_action( admin_url( 'edit.php?paged=2&wire_desk_added=9' ), Post_List_Actions::ACTION, [ ...$posts, $trashed ] );

		$this->assertSame( admin_url( 'edit.php?paged=2&wire_desk_added=2&wire_desk_add_failed=1' ), $url );
		$this->assertCount( 2, $this->repository->get_many( $posts ) );
		$this->assertTrue( Queue::is_scheduled( $posts[0] ) );
		$this->assertSame( 'edit.php', $list->handle_bulk_action( 'edit.php', 'trash', $posts ) );
	}

	/**
	 * Test that the row action needs the post's own nonce.
	 */
	public function test_row_action_handler_checks_nonce(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );

		$_GET['post']         = (string) $post_id;
		$_REQUEST['_wpnonce'] = wp_create_nonce( Post_List_Actions::nonce_action( $post_id + 1 ) );

		$this->expectException( \WPDieException::class );

		( new Post_List_Actions( $this->repository ) )->handle_row_action();
	}

	/**
	 * Test the notices.
	 */
	public function test_notice(): void {
		$list = new Post_List_Actions( $this->repository );

		$_GET['wire_desk_added']      = '3';
		$_GET['wire_desk_add_failed'] = '1';

		ob_start();
		$list->notice();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( '3 posts added to the review queue. They are scored in the background.', $output );
		$this->assertStringContainsString( 'page=wire-desk&amp;view=waiting', $output );
		$this->assertStringContainsString( 'No OpenRouter API key is set, so scoring will fail.', $output );
		$this->assertStringContainsString( 'notice-warning', $output );
		$this->assertStringContainsString( '1 post could not be added to the review queue.', $output );

		add_filter( 'wire_desk/openrouter_api_key', static fn (): string => 'sk-or-test' );
		unset( $_GET['wire_desk_add_failed'] );

		ob_start();
		$list->notice();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice-success', $output );
		$this->assertStringNotContainsString( 'No OpenRouter API key', $output );
	}

	/**
	 * Test that the result arguments are removed from the address bar.
	 */
	public function test_removable_query_args(): void {
		$this->assertSame( [ 'message', 'wire_desk_added', 'wire_desk_add_failed' ], ( new Post_List_Actions() )->removable_query_args( [ 'message' ] ) );
	}

	/**
	 * Sets the wire post types.
	 *
	 * @param string[] $post_types Post types.
	 */
	private function wire_types( array $post_types ): void {
		update_option( Settings::OPTION_KEY, [ 'post_types' => $post_types ] + Settings::defaults() );
	}
}
