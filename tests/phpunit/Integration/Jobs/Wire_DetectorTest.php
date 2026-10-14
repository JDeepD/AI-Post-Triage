<?php
/**
 * Integration tests for Wire_Detector.
 *
 * @package JDeepD\Wire_Desk\Tests\Integration\Jobs
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk\Tests\Integration\Jobs;

use JDeepD\Wire_Desk\Jobs\Wire_Detector;
use JDeepD\Wire_Desk\Modules\Settings\Settings;
use JDeepD\Wire_Desk\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_Post;

/**
 * Class - Wire_DetectorTest
 */
#[CoversClass( Wire_Detector::class )]
class Wire_DetectorTest extends TestCase {
	/**
	 * Registers a wire post type and agency taxonomy.
	 */
	protected function setUp(): void {
		parent::setUp();

		register_post_type( 'wire_item', [ 'public' => true ] );
		register_taxonomy( 'agency', [ 'post' ] );
	}

	/**
	 * Unregisters the test post type and taxonomy.
	 */
	protected function tearDown(): void {
		unregister_taxonomy( 'agency' );
		unregister_post_type( 'wire_item' );

		parent::tearDown();
	}

	/**
	 * Test that nothing is wire content by default.
	 */
	public function test_nothing_is_wire_by_default(): void {
		$this->assertFalse( Wire_Detector::is_wire( self::factory()->post->create_and_get() ) );
	}

	/**
	 * Test that posts of a wire post type are wire content.
	 */
	public function test_wire_post_type_is_wire(): void {
		$this->save( [ 'post_types' => [ 'wire_item' ] ] );

		$this->assertTrue( Wire_Detector::is_wire( self::factory()->post->create_and_get( [ 'post_type' => 'wire_item' ] ) ) );
		$this->assertFalse( Wire_Detector::is_wire( self::factory()->post->create_and_get() ) );
	}

	/**
	 * Test that posts with a wire term are wire content.
	 */
	public function test_wire_term_is_wire(): void {
		$wires = self::factory()->category->create();
		$other = self::factory()->category->create();

		$this->save( [ 'terms' => [ 'category' => [ $wires ] ] ] );

		$this->assertTrue( Wire_Detector::is_wire( self::factory()->post->create_and_get( [ 'post_category' => [ $other, $wires ] ] ) ) );
		$this->assertFalse( Wire_Detector::is_wire( self::factory()->post->create_and_get( [ 'post_category' => [ $other ] ] ) ) );
	}

	/**
	 * Test that a term from a custom taxonomy makes a post wire content.
	 */
	public function test_custom_taxonomy_term_is_wire(): void {
		$agency = self::factory()->term->create( [ 'taxonomy' => 'agency' ] );
		$post   = self::factory()->post->create_and_get();

		wp_set_object_terms( $post->ID, [ $agency ], 'agency' );
		$this->save( [ 'terms' => [ 'agency' => [ $agency ] ] ] );

		$this->assertTrue( Wire_Detector::is_wire( $post ) );
	}

	/**
	 * Test that wire terms of a taxonomy the post type doesn't use are ignored.
	 */
	public function test_term_of_unrelated_taxonomy_is_ignored(): void {
		$agency = self::factory()->term->create( [ 'taxonomy' => 'agency' ] );

		$this->save( [ 'terms' => [ 'agency' => [ $agency ] ] ] );

		$this->assertFalse( Wire_Detector::is_wire( self::factory()->post->create_and_get( [ 'post_type' => 'page' ] ) ) );
	}

	/**
	 * Test that the decision is filterable.
	 */
	public function test_is_wire_is_filterable(): void {
		$post = self::factory()->post->create_and_get();

		add_filter(
			'wire_desk/is_wire_content',
			static fn ( bool $is_wire, WP_Post $filtered ): bool => $is_wire || $filtered->ID === $post->ID,
			10,
			2
		);

		$this->assertTrue( Wire_Detector::is_wire( $post ) );
	}

	/**
	 * Test that posts inside the scoring window are recent.
	 */
	public function test_in_window(): void {
		$this->save( [ 'window_days' => 3 ] );

		$this->assertTrue( Wire_Detector::in_window( $this->dated( '-2 days' ) ) );
		$this->assertFalse( Wire_Detector::in_window( $this->dated( '-4 days' ) ) );
	}

	/**
	 * Test that a window of 0 days has no limit.
	 */
	public function test_no_window_has_no_limit(): void {
		$this->save( [ 'window_days' => 0 ] );

		$this->assertTrue( Wire_Detector::in_window( $this->dated( '-10 years' ) ) );
	}

	/**
	 * Test that an undated draft is in the window.
	 */
	public function test_undated_draft_is_in_window(): void {
		$this->save( [ 'window_days' => 3 ] );

		$post                = self::factory()->post->create_and_get( [ 'post_status' => 'draft' ] );
		$post->post_date     = '0000-00-00 00:00:00';
		$post->post_date_gmt = '0000-00-00 00:00:00';

		$this->assertTrue( Wire_Detector::in_window( $post ) );
	}

	/**
	 * A post dated relative to now.
	 *
	 * @param string $relative Relative date, e.g. "-2 days".
	 */
	private function dated( string $relative ): WP_Post {
		return self::factory()->post->create_and_get( [ 'post_date' => wp_date( 'Y-m-d H:i:s', (int) strtotime( $relative ) ) ] );
	}

	/**
	 * Saves settings.
	 *
	 * @param array<string, mixed> $settings Settings.
	 */
	private function save( array $settings ): void {
		update_option( Settings::OPTION_KEY, $settings );
	}
}
