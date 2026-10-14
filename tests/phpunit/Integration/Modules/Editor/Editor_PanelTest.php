<?php
/**
 * Integration tests for Editor_Panel.
 *
 * @package JDeepD\Wire_Desk\Tests\Integration\Modules\Editor
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk\Tests\Integration\Modules\Editor;

use JDeepD\Wire_Desk\Core\Assets;
use JDeepD\Wire_Desk\Data\Wire_Post_Repository;
use JDeepD\Wire_Desk\Modules\Editor\Editor_Panel;
use JDeepD\Wire_Desk\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Class - Editor_PanelTest
 */
#[CoversClass( Editor_Panel::class )]
class Editor_PanelTest extends TestCase {
	/**
	 * Wire post table.
	 *
	 * @var \JDeepD\Wire_Desk\Data\Wire_Post_Repository
	 */
	private Wire_Post_Repository $repository;

	/**
	 * Registers the editor assets and signs in an editor.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->repository = new Wire_Post_Repository();

		( new Assets() )->register_editor_assets();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
	}

	/**
	 * Clears the editor script and the current post.
	 */
	protected function tearDown(): void {
		wp_dequeue_script( Assets::EDITOR_HANDLE );
		wp_dequeue_style( Assets::EDITOR_HANDLE );
		wp_scripts()->registered[ Assets::EDITOR_HANDLE ]->extra = [];
		unset( $GLOBALS['post'] );

		parent::tearDown();
	}

	/**
	 * Test that register_hooks loads the panel with the block editor.
	 */
	public function test_register_hooks(): void {
		$panel = new Editor_Panel();
		$panel->register_hooks();

		$this->assertSame( 10, has_action( 'enqueue_block_editor_assets', [ $panel, 'enqueue' ] ) );
	}

	/**
	 * Test that the panel loads for a wire post and says it is one.
	 */
	public function test_enqueue_for_wire_post(): void {
		$post_id = self::factory()->post->create();
		$this->repository->add_queued( $post_id, '2024-01-01 00:00:00' );

		$data = $this->enqueue_for( $post_id );

		$this->assertTrue( wp_script_is( Assets::EDITOR_HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( Assets::EDITOR_HANDLE, 'enqueued' ) );
		$this->assertTrue( $data['isWire'] ?? null );
		$this->assertSame( admin_url( 'admin.php?page=wire-desk' ), $data['queueUrl'] ?? null );
	}

	/**
	 * Test that the panel also loads for a post not yet in Wire Desk, so it can appear after a save.
	 */
	public function test_enqueue_for_other_post(): void {
		$data = $this->enqueue_for( self::factory()->post->create() );

		$this->assertTrue( wp_script_is( Assets::EDITOR_HANDLE, 'enqueued' ) );
		$this->assertFalse( $data['isWire'] ?? null );
	}

	/**
	 * Test that the panel doesn't load for users who can't review, or outside a post.
	 */
	public function test_not_enqueued_without_review_or_post(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );
		$this->enqueue_for( self::factory()->post->create() );

		$this->assertFalse( wp_script_is( Assets::EDITOR_HANDLE, 'enqueued' ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		unset( $GLOBALS['post'] );
		( new Editor_Panel( $this->repository ) )->enqueue();

		$this->assertFalse( wp_script_is( Assets::EDITOR_HANDLE, 'enqueued' ) );
	}

	/**
	 * Test that the panel gets the band and state names by slug.
	 */
	public function test_data_labels(): void {
		$data = Editor_Panel::data( true );

		$this->assertSame(
			[
				'high'       => 'High',
				'medium'     => 'Medium',
				'low'        => 'Low',
				'not-scored' => 'Not scored',
			],
			$data['bands']
		);
		$this->assertSame( 'Needs review', $data['states']['scored'] );
		$this->assertSame( 'Waiting to be scored', $data['states']['queued'] );
		$this->assertCount( 5, $data['states'] );
	}

	/**
	 * Loads the panel while editing a post, and returns the data given to the script.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>
	 */
	private function enqueue_for( int $post_id ): array {
		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The editor's current post.

		( new Editor_Panel( $this->repository ) )->enqueue();

		$inline = implode( '', (array) wp_scripts()->get_data( Assets::EDITOR_HANDLE, 'before' ) );

		if ( ! preg_match( '/^window\.wireDeskEditor = (.*);$/s', $inline, $match ) ) {
			return [];
		}

		$data = json_decode( $match[1], true );

		return is_array( $data ) ? $data : [];
	}
}
