<?php
/**
 * Integration tests for Queue_Screen.
 *
 * @package JDeepD\Wire_Desk\Tests\Integration\Modules\Queue
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk\Tests\Integration\Modules\Queue;

use JDeepD\Wire_Desk\Core\Assets;
use JDeepD\Wire_Desk\Core\Capabilities;
use JDeepD\Wire_Desk\Core\Schema;
use JDeepD\Wire_Desk\Data\Wire_Post;
use JDeepD\Wire_Desk\Data\Wire_Post_Repository;
use JDeepD\Wire_Desk\Modules\Queue\Queue_Screen;
use JDeepD\Wire_Desk\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Class - Queue_ScreenTest
 */
#[CoversClass( Queue_Screen::class )]
class Queue_ScreenTest extends TestCase {
	/**
	 * Wire post table.
	 *
	 * @var \JDeepD\Wire_Desk\Data\Wire_Post_Repository
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

		unset( $_GET['view'] );
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
	}

	/**
	 * Test that editors get the Wire Desk menu with the queue as its first page.
	 */
	public function test_register_screen_adds_menu_for_reviewers(): void {
		global $submenu;

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$screen = new Queue_Screen( $this->repository );
		$screen->register_screen();

		$item = $this->menu_item();

		$this->assertSame( 'Wire Desk', $item[0] ?? null );
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
		$this->assertSame( 'wire-desk', $admin_page_hooks[ Queue_Screen::SCREEN_ID ] ?? null );
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

		$this->assertSame( 'Wire Desk', $this->menu_item()[0] ?? null );

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
		$this->assertStringContainsString( '<input type="hidden" name="page" value="wire-desk" />', $output );
		$this->assertStringNotContainsString( 'name="view"', $output );
		$this->assertStringContainsString( 'id="wire-desk-band-filter"', $output );
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
	 * The Wire Desk top-level menu item.
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
