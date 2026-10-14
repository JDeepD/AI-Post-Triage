<?php
/**
 * Integration tests for Queue_List_Table.
 *
 * @package JDeepD\Wire_Desk\Tests\Integration\Modules\Queue
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk\Tests\Integration\Modules\Queue;

use JDeepD\Wire_Desk\Core\Schema;
use JDeepD\Wire_Desk\Data\Wire_Post;
use JDeepD\Wire_Desk\Data\Wire_Post_Repository;
use JDeepD\Wire_Desk\Editorial\Post_Actions;
use JDeepD\Wire_Desk\Modules\Queue\Queue_List_Table;
use JDeepD\Wire_Desk\Modules\Queue\Queue_Screen;
use JDeepD\Wire_Desk\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Class - Queue_List_TableTest
 */
#[CoversClass( Queue_List_Table::class )]
class Queue_List_TableTest extends TestCase {
	/**
	 * Wire post table.
	 *
	 * @var \JDeepD\Wire_Desk\Data\Wire_Post_Repository
	 */
	private Wire_Post_Repository $repository;

	/**
	 * Loads the admin list table and signs in an editor.
	 */
	protected function setUp(): void {
		parent::setUp();

		require_once ABSPATH . 'wp-admin/includes/admin.php';

		set_current_screen( Queue_Screen::HOOK_SUFFIX );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->repository = new Wire_Post_Repository();
	}

	/**
	 * Resets the current screen.
	 */
	protected function tearDown(): void {
		set_current_screen( 'front' );

		parent::tearDown();
	}

	/**
	 * Test that the default view lists scored posts, newest first.
	 */
	public function test_default_view_lists_posts_needing_review(): void {
		$scored = $this->wire_posts( 3 );
		$queued = $this->wire_posts( 1, Wire_Post::STATE_QUEUED, Wire_Post::BAND_NONE, '2024-02-01 00:00:00' );

		$table = $this->table();

		$this->assertSame( Queue_List_Table::DEFAULT_VIEW, $table->get_view() );
		$this->assertSame( array_reverse( $scored ), $this->ids( $table ) );
		$this->assertNotContains( $queued[0], $this->ids( $table ) );
	}

	/**
	 * Test that each view lists its state, and All lists every post.
	 */
	public function test_views_filter_by_state(): void {
		$scored    = $this->wire_posts( 1, Wire_Post::STATE_SCORED, Wire_Post::BAND_LOW, '2024-01-01 00:00:00' );
		$waiting   = $this->wire_posts( 1, Wire_Post::STATE_QUEUED, Wire_Post::BAND_NONE, '2024-01-02 00:00:00' );
		$failed    = $this->wire_posts( 1, Wire_Post::STATE_FAILED, Wire_Post::BAND_NONE, '2024-01-03 00:00:00' );
		$published = $this->wire_posts( 1, Wire_Post::STATE_PUBLISHED, Wire_Post::BAND_HIGH, '2024-01-04 00:00:00' );
		$dismissed = $this->wire_posts( 1, Wire_Post::STATE_DISMISSED, Wire_Post::BAND_LOW, '2024-01-05 00:00:00' );

		$this->assertSame( $waiting, $this->ids( $this->table( [ 'view' => 'waiting' ] ) ) );
		$this->assertSame( $failed, $this->ids( $this->table( [ 'view' => 'failed' ] ) ) );
		$this->assertSame( $published, $this->ids( $this->table( [ 'view' => 'published' ] ) ) );
		$this->assertSame( $dismissed, $this->ids( $this->table( [ 'view' => 'dismissed' ] ) ) );
		$this->assertSame( [ $dismissed[0], $published[0], $failed[0], $waiting[0], $scored[0] ], $this->ids( $this->table( [ 'view' => 'all' ] ) ) );
		$this->assertSame( $scored, $this->ids( $this->table( [ 'view' => 'unknown' ] ) ) );
	}

	/**
	 * Test that the band filter narrows the view.
	 */
	public function test_band_filter(): void {
		$high = $this->wire_posts( 2, Wire_Post::STATE_SCORED, Wire_Post::BAND_HIGH, '2024-01-01 00:00:00' );
		$low  = $this->wire_posts( 2, Wire_Post::STATE_SCORED, Wire_Post::BAND_LOW, '2024-02-01 00:00:00' );

		$this->assertSame( array_reverse( $high ), $this->ids( $this->table( [ 'band' => 'high' ] ) ) );
		$this->assertSame( array_reverse( $low ), $this->ids( $this->table( [ 'band' => 'low' ] ) ) );
		$this->assertSame( [], $this->ids( $this->table( [ 'band' => 'medium' ] ) ) );
		$this->assertCount( 4, $this->ids( $this->table( [ 'band' => 'bogus' ] ) ) );
	}

	/**
	 * Test that older and newer pages follow the first and last rows shown.
	 */
	public function test_pages_from_first_and_last_rows(): void {
		[ $one, $two, $three, $four, $five ] = $this->wire_posts( 5 );

		update_user_option( get_current_user_id(), Queue_Screen::PER_PAGE_OPTION, 2 );

		$this->assertSame( [ $five, $four ], $this->ids( $this->table() ) );
		$this->assertSame( [ $three, $two ], $this->ids( $this->table( [ 'before' => $four ] ) ) );
		$this->assertSame( [ $one ], $this->ids( $this->table( [ 'before' => $two ] ) ) );
		$this->assertSame( [ $three, $two ], $this->ids( $this->table( [ 'after' => $one ] ) ) );
		$this->assertSame( [ $four, $three ], $this->ids( $this->table( [ 'after' => $two ] ) ) );
		$this->assertSame( [ $five, $four ], $this->ids( $this->table( [ 'after' => $four ] ) ) );
		$this->assertSame( [ $five, $four ], $this->ids( $this->table( [ 'before' => 999999 ] ) ) );
	}

	/**
	 * Test that the paging links point at the rows around the page.
	 */
	public function test_pagination_links(): void {
		[ , $two, $three, $four ] = $this->wire_posts( 5 );

		update_user_option( get_current_user_id(), Queue_Screen::PER_PAGE_OPTION, 2 );

		$first = $this->render( $this->table() );

		$this->assertStringContainsString( 'before=' . $four, $first );
		$this->assertStringNotContainsString( 'after=', $first );
		$this->assertStringContainsString( 'tablenav-pages-navspan button disabled', $first );

		$middle = $this->render( $this->table( [ 'before' => $four ] ) );

		$this->assertStringContainsString( 'after=' . $three, $middle );
		$this->assertStringContainsString( 'before=' . $two, $middle );
		$this->assertStringContainsString( 'first-page button', $middle );
	}

	/**
	 * Test that one page of posts has no paging links.
	 */
	public function test_single_page_has_no_pagination(): void {
		$this->wire_posts( 2 );

		$this->assertStringNotContainsString( 'pagination-links', $this->render( $this->table() ) );
	}

	/**
	 * Test that paging links keep the view and band.
	 */
	public function test_pagination_keeps_view_and_band(): void {
		$this->wire_posts( 3, Wire_Post::STATE_DISMISSED, Wire_Post::BAND_HIGH );

		update_user_option( get_current_user_id(), Queue_Screen::PER_PAGE_OPTION, 2 );

		$this->assertMatchesRegularExpression(
			'#page=wire-desk&(amp;|\#038;)view=dismissed&(amp;|\#038;)band=high&(amp;|\#038;)before=\d+#',
			$this->render(
				$this->table(
					[
						'view' => 'dismissed',
						'band' => 'high',
					]
				)
			)
		);
	}

	/**
	 * Test that the views show their counts and mark the current one.
	 */
	public function test_views_show_counts(): void {
		$this->wire_posts( 2 );
		$this->wire_posts( 1, Wire_Post::STATE_FAILED, Wire_Post::BAND_NONE );

		ob_start();
		$this->table( [ 'view' => 'failed' ] )->views();
		$views = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '#page=wire-desk"[^>]*>Needs review <span class="count">\(2\)</span>#', $views );
		$this->assertMatchesRegularExpression( '#view=failed" class="current" aria-current="page">Failed <span class="count">\(1\)</span>#', $views );
		$this->assertMatchesRegularExpression( '#view=all"[^>]*>All <span class="count">\(3\)</span>#', $views );
	}

	/**
	 * Test that view counts stop at COUNT_MAX.
	 */
	public function test_view_counts_are_capped(): void {
		$this->insert_rows( Queue_List_Table::COUNT_MAX + 5, Wire_Post::STATE_SCORED, Wire_Post::BAND_LOW );

		ob_start();
		$this->table()->views();
		$views = (string) ob_get_clean();

		$this->assertStringContainsString( 'Needs review <span class="count">(1,000+)</span>', $views );
	}

	/**
	 * Test that rows show the title, priority, status, model and date.
	 */
	public function test_renders_row(): void {
		$post_id = self::factory()->post->create(
			[
				'post_title'  => 'Quake hits coast',
				'post_status' => 'draft',
				'post_date'   => '2024-03-05 14:30:00',
			]
		);
		$this->repository->add_queued( $post_id, '2024-03-05 14:30:00' );
		$this->repository->save_score( $post_id, Wire_Post::BAND_HIGH, 87, 'typesafe/jev-1.13', md5( 'x' ), 0.0012, [] );

		$html = $this->render( $this->table() );

		$this->assertMatchesRegularExpression( '#<a class="row-title" href="[^"]*post=' . $post_id . '&(amp;|\#038;)action=edit">Quake hits coast</a>#', $html );
		$this->assertStringContainsString( 'Draft', $html );
		$this->assertStringContainsString( '<span class="wire-desk-band wire-desk-band-high">High</span> <span class="wire-desk-score">87</span>', $html );
		$this->assertStringContainsString( 'Needs review', $html );
		$this->assertStringContainsString( 'typesafe/jev-1.13<br /><span class="wire-desk-muted">$0.0012</span>', $html );
		$this->assertStringContainsString( 'March 5, 2024 at 2:30 pm', $html );
		$this->assertMatchesRegularExpression( '#Scored \\d+ seconds? ago#', $html );
	}

	/**
	 * Test that a failed post shows its error and no score.
	 */
	public function test_renders_failed_row(): void {
		[ $post_id ] = $this->wire_posts( 1, Wire_Post::STATE_QUEUED, Wire_Post::BAND_NONE );
		$this->repository->save_error( $post_id, 'The model is overloaded.' );

		$html = $this->render( $this->table( [ 'view' => 'failed' ] ) );

		$this->assertStringContainsString( 'Scoring failed<br /><span class="wire-desk-error">The model is overloaded.</span>', $html );
		$this->assertStringContainsString( '<span class="wire-desk-band wire-desk-band-not-scored">Not scored</span>', $html );
		$this->assertStringNotContainsString( 'wire-desk-score', $html );
	}

	/**
	 * Test that a row whose post is gone still renders.
	 */
	public function test_renders_row_without_post(): void {
		$this->insert_rows( 1, Wire_Post::STATE_SCORED, Wire_Post::BAND_LOW );

		$this->assertStringContainsString( '<strong>(deleted)</strong>', $this->render( $this->table() ) );
	}

	/**
	 * Test that the page's posts are loaded in one go.
	 */
	public function test_primes_post_cache(): void {
		$post_ids = $this->wire_posts( 3 );

		foreach ( $post_ids as $post_id ) {
			clean_post_cache( $post_id );
		}

		$this->table();

		foreach ( $post_ids as $post_id ) {
			$this->assertNotFalse( wp_cache_get( $post_id, 'posts' ) );
		}
	}

	/**
	 * Test that each row has a checkbox.
	 */
	public function test_renders_checkbox(): void {
		[ $post_id ] = $this->wire_posts( 1 );

		$html = $this->render( $this->table() );

		$this->assertStringContainsString( '<input id="cb-select-' . $post_id . '" type="checkbox" name="post[]" value="' . $post_id . '" />', $html );
		$this->assertMatchesRegularExpression( '#<input id="cb-select-all-\d+" type="checkbox" />#', $html );
	}

	/**
	 * Test that each view offers its bulk actions.
	 */
	public function test_bulk_actions_per_view(): void {
		$this->wire_posts( 1 );

		$needs_review = $this->render( $this->table() );

		$this->assertStringContainsString( '<option value="publish">Publish</option>', $needs_review );
		$this->assertStringContainsString( '<option value="dismiss">Dismiss</option>', $needs_review );
		$this->assertStringContainsString( '<option value="rescore">Re-score</option>', $needs_review );
		$this->assertStringNotContainsString( '<option value="restore">', $needs_review );

		$dismissed = $this->render( $this->table( [ 'view' => 'dismissed' ] ) );

		$this->assertStringContainsString( '<option value="restore">Restore</option>', $dismissed );
		$this->assertStringNotContainsString( '<option value="dismiss">', $dismissed );

		$published = $this->render( $this->table( [ 'view' => 'published' ] ) );

		$this->assertStringContainsString( '<option value="rescore">Re-score</option>', $published );
		$this->assertStringNotContainsString( '<option value="publish">', $published );

		foreach ( Queue_List_Table::view_actions() as $actions ) {
			$this->assertSame( [], array_diff( $actions, array_keys( Post_Actions::labels() ) ) );
		}
	}

	/**
	 * Test that row action links carry the page, the action and a nonce for that post.
	 */
	public function test_renders_row_actions(): void {
		[ $post_id ] = $this->wire_posts( 1 );

		$html = $this->render( $this->table( [ 'band' => 'medium' ] ) );

		$this->assertStringContainsString( '<div class="row-actions">', $html );
		$this->assertMatchesRegularExpression( '#<span class=\'edit\'><a href="[^"]*post=' . $post_id . '&(amp;|\#038;)action=edit"#', $html );
		$this->assertStringContainsString( 'aria-label="Dismiss &#8220;Post title', $html );

		preg_match( '#href="([^"]*action=dismiss[^"]*)"#', $html, $match );
		$url   = html_entity_decode( $match[1] ?? '' );
		$query = [];
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertSame( 'wire-desk', $query['page'] ?? null );
		$this->assertSame( 'medium', $query['band'] ?? null );
		$this->assertSame( (string) $post_id, $query['post'] ?? null );
		$this->assertSame( 1, wp_verify_nonce( (string) ( $query['_wpnonce'] ?? '' ), Queue_Screen::nonce_action( 'dismiss', $post_id ) ) );
	}

	/**
	 * Test that the empty message is shown.
	 */
	public function test_no_items(): void {
		$this->assertStringContainsString( 'No wire posts found.', $this->render( $this->table() ) );
	}

	/**
	 * Builds and prepares a table.
	 *
	 * @param array<string, mixed> $query Query arguments.
	 */
	private function table( array $query = [] ): Queue_List_Table {
		$table = new Queue_List_Table( $query, $this->repository );
		$table->prepare_items();

		return $table;
	}

	/**
	 * Renders a table.
	 *
	 * @param \JDeepD\Wire_Desk\Modules\Queue\Queue_List_Table $table Table.
	 */
	private function render( Queue_List_Table $table ): string {
		ob_start();
		$table->display();
		return (string) ob_get_clean();
	}

	/**
	 * Post IDs of a table's rows.
	 *
	 * @param \JDeepD\Wire_Desk\Modules\Queue\Queue_List_Table $table Table.
	 * @return int[]
	 */
	private function ids( Queue_List_Table $table ): array {
		return array_map( static fn ( Wire_Post $item ): int => $item->post_id, $table->items );
	}

	/**
	 * Adds wire posts a day apart, oldest first.
	 *
	 * @param int    $count Posts.
	 * @param int    $state State.
	 * @param int    $band  Band.
	 * @param string $start Date of the first post.
	 * @return int[]
	 */
	private function wire_posts( int $count, int $state = Wire_Post::STATE_SCORED, int $band = Wire_Post::BAND_MEDIUM, string $start = '2024-01-01 00:00:00' ): array {
		$post_ids = [];

		for ( $i = 0; $i < $count; $i++ ) {
			$date    = gmdate( 'Y-m-d H:i:s', (int) strtotime( $start . ' UTC' ) + $i * DAY_IN_SECONDS );
			$post_id = self::factory()->post->create(
				[
					'post_status' => 'draft',
					'post_date'   => $date,
				]
			);

			$this->repository->add_queued( $post_id, $date );

			if ( Wire_Post::BAND_NONE !== $band ) {
				$this->repository->save_score( $post_id, $band, 50, 'typesafe/jev-1.13', md5( (string) $post_id ), 0.0, [] );
			}

			$this->repository->set_state( $post_id, $state );

			$post_ids[] = $post_id;
		}

		return $post_ids;
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

		$values = [];
		for ( $i = 1; $i <= $count; $i++ ) {
			$values[] = $wpdb->prepare( '( %d, %s, %d, %d )', 900000 + $i, '2024-01-01 00:00:00', $state, $band );
		}

		$wpdb->query( 'INSERT INTO ' . Schema::table_name() . ' ( post_id, post_date, state, band ) VALUES ' . implode( ', ', $values ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Test rows, prepared above.
		wp_cache_set_last_changed( Wire_Post_Repository::CACHE_GROUP );
	}
}
