<?php
/**
 * Integration tests for Queue_Screen.
 *
 * @package JDeepD\AI_Post_Triage\Tests\Integration\Modules\Queue
 */

declare( strict_types = 1 );

namespace JDeepD\AI_Post_Triage\Tests\Integration\Modules\Queue;

use JDeepD\AI_Post_Triage\Core\Assets;
use JDeepD\AI_Post_Triage\Core\Capabilities;
use JDeepD\AI_Post_Triage\Core\Schema;
use JDeepD\AI_Post_Triage\Data\Wire_Post;
use JDeepD\AI_Post_Triage\Data\Wire_Post_Repository;
use JDeepD\AI_Post_Triage\Editorial\Post_Actions;
use JDeepD\AI_Post_Triage\Modules\Queue\Queue_Screen;
use JDeepD\AI_Post_Triage\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Class - Queue_ScreenTest
 */
#[CoversClass( Queue_Screen::class )]
class Queue_ScreenTest extends TestCase {
	/**
	 * Wire post table.
	 *
	 * @var \JDeepD\AI_Post_Triage\Data\Wire_Post_Repository
	 */
	private Wire_Post_Repository $repository;

	/**
	 * Loads the admin functions the screen uses.
	 */
	protected function setUp(): void {
		parent::setUp();

		require_once ABSPATH . 'wp-admin/includes/admin.php';

		$this->repository = new Wire_Post_Repository();
	}

	/**
	 * Resets the admin menu and screen.
	 */
	protected function tearDown(): void {
		foreach ( [ 'menu', 'submenu' ] as $global ) {
			$GLOBALS[ $global ] = []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Reset between tests.
		}

		unset( $_GET['view'], $_GET['ai_post_triage_action'], $_GET['ai_post_triage_done'], $_REQUEST['_wpnonce'], $_REQUEST['_wp_http_referer'] );
		set_current_screen( 'front' );

		parent::tearDown();
	}

	/**
	 * Test that register_hooks adds the menu and the screen option filter.
	 */
	public function test_register_hooks(): void {
		$screen = new Queue_Screen();
		$screen->register_hooks();

		$this->assertSame( 10, has_action( 'admin_menu', [ $screen, 'register_screen' ] ) );
		$this->assertSame( 10, has_filter( 'set_screen_option_' . Queue_Screen::PER_PAGE_OPTION, [ $screen, 'save_per_page' ] ) );
		$this->assertSame( 10, has_filter( 'removable_query_args', [ $screen, 'removable_query_args' ] ) );
	}

	/**
	 * Test that editors get the AI Post Triage menu with the queue as its first page.
	 */
	public function test_register_screen_adds_menu_for_reviewers(): void {
		global $submenu;

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$screen = new Queue_Screen( $this->repository );
		$screen->register_screen();

		$item = $this->menu_item();

		$this->assertSame( 'AI Post Triage', $item[0] ?? null );
		$this->assertSame( Capabilities::REVIEW, $item[1] ?? null );
		$this->assertSame( 'dashicons-rss', $item[6] ?? null );
		$this->assertSame( 'Review queue', $submenu[ Queue_Screen::SCREEN_ID ][0][0] ?? null );
		$this->assertNotFalse( has_action( 'load-' . Queue_Screen::HOOK_SUFFIX, [ $screen, 'load' ] ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
	}

	/**
	 * Test that authors can't open the queue.
	 */
	public function test_register_screen_hides_menu_from_authors(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );

		( new Queue_Screen( $this->repository ) )->register_screen();

		$this->assertFalse( current_user_can( $this->menu_item()[1] ?? 'do_not_allow' ) );
	}

	/**
	 * Test that the menu shows how many high-priority posts need review, without changing the hook name.
	 */
	public function test_badge_counts_high_posts_needing_review(): void {
		global $admin_page_hooks;

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->insert_rows( 3, Wire_Post::STATE_SCORED, Wire_Post::BAND_HIGH );
		$this->insert_rows( 2, Wire_Post::STATE_SCORED, Wire_Post::BAND_MEDIUM );
		$this->insert_rows( 2, Wire_Post::STATE_DISMISSED, Wire_Post::BAND_HIGH );

		( new Queue_Screen( $this->repository ) )->register_screen();

		$title = (string) ( $this->menu_item()[0] ?? '' );

		$this->assertStringContainsString( '<span class="awaiting-mod count-3"><span class="pending-count" aria-hidden="true">3</span>', $title );
		$this->assertStringContainsString( '3 high-priority posts need review', $title );
		$this->assertSame( 'ai-post-triage', $admin_page_hooks[ Queue_Screen::SCREEN_ID ] ?? null );
	}

	/**
	 * Test that the badge stops at BADGE_MAX.
	 */
	public function test_badge_is_capped(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->insert_rows( Queue_Screen::BADGE_MAX + 3, Wire_Post::STATE_SCORED, Wire_Post::BAND_HIGH );

		$screen = new Queue_Screen( $this->repository );

		$this->assertSame( Queue_Screen::BADGE_MAX + 1, $screen->badge_count() );

		$screen->register_screen();

		$title = (string) ( $this->menu_item()[0] ?? '' );

		$this->assertStringContainsString( 'aria-hidden="true">99+</span>', $title );
		$this->assertStringContainsString( 'More than 99 high-priority posts need review', $title );
	}

	/**
	 * Test that the badge is gone once nothing high needs review, and comes back after a score.
	 */
	public function test_badge_follows_writes(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->repository->add_queued( 42, '2024-01-01 00:00:00' );
		$this->repository->save_score( 42, Wire_Post::BAND_HIGH, 90, 'typesafe/jev-1.13', md5( '42' ), 0.0, [] );

		$screen = new Queue_Screen( $this->repository );

		$this->assertSame( 1, $screen->badge_count() );

		$this->repository->set_state( 42, Wire_Post::STATE_DISMISSED );

		$this->assertSame( 0, $screen->badge_count() );

		$screen->register_screen();

		$this->assertSame( 'AI Post Triage', $this->menu_item()[0] ?? null );

		$this->repository->set_state( 42, Wire_Post::STATE_SCORED );

		$this->assertSame( 1, $screen->badge_count() );
	}

	/**
	 * Test that rows per page are kept between 1 and the largest page.
	 */
	public function test_save_per_page_clamps(): void {
		$screen = new Queue_Screen();

		$this->assertSame( 50, $screen->save_per_page( false, Queue_Screen::PER_PAGE_OPTION, '50' ) );
		$this->assertSame( 1, $screen->save_per_page( false, Queue_Screen::PER_PAGE_OPTION, '0' ) );
		$this->assertSame( Wire_Post_Repository::MAX_PAGE_SIZE, $screen->save_per_page( false, Queue_Screen::PER_PAGE_OPTION, '5000' ) );
		$this->assertFalse( $screen->save_per_page( false, Queue_Screen::PER_PAGE_OPTION, 'lots' ) );
	}

	/**
	 * Test that the screen renders the views, the filter form and the table.
	 */
	public function test_render_screen(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		set_current_screen( Queue_Screen::HOOK_SUFFIX );

		( new Assets() )->register_admin_assets();

		$screen = new Queue_Screen( $this->repository );
		$screen->load();

		ob_start();
		$screen->render_screen();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( '<h1 class="wp-heading-inline">Review queue</h1>', $output );
		$this->assertStringContainsString( "class='subsubsub'", $output );
		$this->assertStringContainsString( '<input type="hidden" name="page" value="ai-post-triage" />', $output );
		$this->assertStringNotContainsString( 'name="view"', $output );
		$this->assertStringContainsString( 'id="ai-post-triage-band-filter"', $output );
		$this->assertStringContainsString( 'No wire posts found.', $output );
		$this->assertTrue( wp_style_is( Assets::ADMIN_HANDLE, 'enqueued' ) );
	}

	/**
	 * Test that the form keeps a view other than the default.
	 */
	public function test_render_screen_keeps_view(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		set_current_screen( Queue_Screen::HOOK_SUFFIX );

		$_GET['view'] = 'dismissed';

		ob_start();
		( new Queue_Screen( $this->repository ) )->render_screen();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( '<input type="hidden" name="view" value="dismissed" />', $output );
	}

	/**
	 * Test that a request without an action does nothing.
	 */
	public function test_handle_action_without_action(): void {
		$this->assertNull( ( new Queue_Screen( $this->repository ) )->handle_action( [ 'view' => 'failed' ] ) );
		$this->assertNull(
			( new Queue_Screen( $this->repository ) )->handle_action(
				[
					'action' => 'delete',
					'post'   => 1,
				]
			)
		);
	}

	/**
	 * Test that submitting the filter drops the form's nonce, referer and paging from the address.
	 */
	public function test_handle_action_cleans_filter_submission(): void {
		$url = ( new Queue_Screen( $this->repository ) )->handle_action(
			[
				'page'             => Queue_Screen::SCREEN_ID,
				'view'             => 'failed',
				'band'             => 'high',
				'filter_action'    => 'Filter',
				'action'           => Post_Actions::DISMISS,
				'_wpnonce'         => 'abc',
				'_wp_http_referer' => '/wp-admin/admin.php?page=ai-post-triage',
				'post'             => [ 1, 2 ],
			]
		);

		$this->assertSame( admin_url( 'admin.php?page=ai-post-triage&view=failed&band=high' ), $url );
	}

	/**
	 * Test that a row action runs with its nonce and goes back with the result.
	 */
	public function test_handle_action_runs_row_action(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$post_id = $this->scored_post();

		$_REQUEST['_wpnonce']         = wp_create_nonce( Queue_Screen::nonce_action( Post_Actions::DISMISS, $post_id ) );
		$_REQUEST['_wp_http_referer'] = '/wp-admin/admin.php?page=ai-post-triage&band=high&before=12&ai_post_triage_action=publish&ai_post_triage_done=4';

		$url = ( new Queue_Screen( $this->repository ) )->handle_action(
			[
				'action' => Post_Actions::DISMISS,
				'post'   => (string) $post_id,
			]
		);

		$this->assertSame( Wire_Post::STATE_DISMISSED, $this->repository->get( $post_id )?->state );
		$this->assertSame( '/wp-admin/admin.php?page=ai-post-triage&band=high&before=12&ai_post_triage_action=dismiss&ai_post_triage_done=1', $url );
	}

	/**
	 * Test that a row action with another post's nonce is refused.
	 */
	public function test_handle_action_checks_row_nonce(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$post_id = $this->scored_post();

		$_REQUEST['_wpnonce'] = wp_create_nonce( Queue_Screen::nonce_action( Post_Actions::DISMISS, $post_id + 1 ) );

		try {
			( new Queue_Screen( $this->repository ) )->handle_action(
				[
					'action' => Post_Actions::DISMISS,
					'post'   => $post_id,
				]
			);
			$this->fail( 'The action ran without a valid nonce.' );
		} catch ( \WPDieException $e ) {
			$this->assertSame( Wire_Post::STATE_SCORED, $this->repository->get( $post_id )?->state );
		}
	}

	/**
	 * Test that a bulk action from the bottom menu runs on each post and counts failures.
	 */
	public function test_handle_action_runs_bulk_action(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$first     = $this->scored_post();
		$second    = $this->scored_post();
		$published = $this->scored_post();
		$this->repository->set_state( $published, Wire_Post::STATE_PUBLISHED );

		$_REQUEST['_wpnonce'] = wp_create_nonce( 'bulk-wire-posts' );

		$url = ( new Queue_Screen( $this->repository ) )->handle_action(
			[
				'action'  => '-1',
				'action2' => Post_Actions::DISMISS,
				'post'    => [ (string) $first, (string) $second, (string) $published ],
			]
		);

		$this->assertSame( Wire_Post::STATE_DISMISSED, $this->repository->get( $first )?->state );
		$this->assertSame( Wire_Post::STATE_DISMISSED, $this->repository->get( $second )?->state );
		$this->assertSame( Wire_Post::STATE_PUBLISHED, $this->repository->get( $published )?->state );
		$this->assertStringContainsString( 'ai_post_triage_action=dismiss&ai_post_triage_done=2&ai_post_triage_failed=1&ai_post_triage_error=ai_post_triage_published', (string) $url );
	}

	/**
	 * Test that a bulk action needs the bulk nonce.
	 */
	public function test_handle_action_checks_bulk_nonce(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$post_id = $this->scored_post();

		$_REQUEST['_wpnonce'] = wp_create_nonce( Queue_Screen::nonce_action( Post_Actions::DISMISS, $post_id ) );

		$this->expectException( \WPDieException::class );

		( new Queue_Screen( $this->repository ) )->handle_action(
			[
				'action' => Post_Actions::DISMISS,
				'post'   => [ $post_id ],
			]
		);
	}

	/**
	 * Test the notices for each action's result.
	 */
	public function test_notices(): void {
		$this->assertSame(
			[
				[
					'type'    => 'success',
					'message' => '1 post published.',
				],
			],
			Queue_Screen::notices(
				[
					'ai_post_triage_action' => 'publish',
					'ai_post_triage_done'   => '1',
				]
			)
		);
		$this->assertSame(
			'3 posts dismissed.',
			Queue_Screen::notices(
				[
					'ai_post_triage_action' => 'dismiss',
					'ai_post_triage_done'   => '3',
				]
			)[0]['message']
		);
		$this->assertSame(
			'2 posts restored to the queue.',
			Queue_Screen::notices(
				[
					'ai_post_triage_action' => 'restore',
					'ai_post_triage_done'   => '2',
				]
			)[0]['message']
		);
		$this->assertSame(
			'1 post will be scored again shortly.',
			Queue_Screen::notices(
				[
					'ai_post_triage_action' => 'rescore',
					'ai_post_triage_done'   => '1',
				]
			)[0]['message']
		);
		$this->assertSame(
			[
				[
					'type'    => 'error',
					'message' => '1 post could not be updated. Published posts can\'t be dismissed.',
				],
			],
			Queue_Screen::notices(
				[
					'ai_post_triage_action' => 'dismiss',
					'ai_post_triage_failed' => '1',
					'ai_post_triage_error'  => 'ai_post_triage_published',
				]
			)
		);
		$this->assertSame(
			'2 posts could not be updated.',
			Queue_Screen::notices(
				[
					'ai_post_triage_action' => 'publish',
					'ai_post_triage_failed' => '2',
					'ai_post_triage_error'  => 'other',
				]
			)[0]['message']
		);
		$this->assertSame(
			[],
			Queue_Screen::notices(
				[
					'ai_post_triage_action' => 'delete',
					'ai_post_triage_done'   => '1',
				]
			)
		);
		$this->assertSame( [], Queue_Screen::notices( [] ) );
	}

	/**
	 * Test that the result arguments are removed from the address bar.
	 */
	public function test_removable_query_args(): void {
		$this->assertSame( array_merge( [ 'message' ], Queue_Screen::RESULT_ARGS ), ( new Queue_Screen() )->removable_query_args( [ 'message' ] ) );
	}

	/**
	 * Test that the screen shows the last action's result.
	 */
	public function test_render_screen_shows_notice(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		set_current_screen( Queue_Screen::HOOK_SUFFIX );

		$_GET['ai_post_triage_action'] = 'dismiss';
		$_GET['ai_post_triage_done']   = '2';

		ob_start();
		( new Queue_Screen( $this->repository ) )->render_screen();
		$output = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '#notice-success is-dismissible">\s*<p>2 posts dismissed.</p>#', $output );
	}

	/**
	 * A draft wire post scored Medium.
	 */
	private function scored_post(): int {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );

		$this->repository->add_queued( $post_id, (string) get_post( $post_id )?->post_date );
		$this->repository->save_score( $post_id, Wire_Post::BAND_MEDIUM, 50, 'typesafe/jev-1.13', md5( (string) $post_id ), 0.0, [] );

		return $post_id;
	}

	/**
	 * The AI Post Triage top-level menu item.
	 *
	 * @return array<int, string>|null
	 */
	private function menu_item(): ?array {
		global $menu;

		foreach ( (array) $menu as $item ) {
			if ( Queue_Screen::SCREEN_ID === ( $item[2] ?? null ) ) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * Inserts rows without posts.
	 *
	 * @param int $count Rows.
	 * @param int $state State.
	 * @param int $band  Band.
	 */
	private function insert_rows( int $count, int $state, int $band ): void {
		global $wpdb;

		static $next = 800000;

		$values = [];
		for ( $i = 0; $i < $count; $i++ ) {
			$values[] = $wpdb->prepare( '( %d, %s, %d, %d )', ++$next, '2024-01-01 00:00:00', $state, $band );
		}

		$wpdb->query( 'INSERT INTO ' . Schema::table_name() . ' ( post_id, post_date, state, band ) VALUES ' . implode( ', ', $values ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Test rows, prepared above.
		wp_cache_set_last_changed( Wire_Post_Repository::CACHE_GROUP );
	}
}
