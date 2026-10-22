<?php
/**
 * Integration tests for Capabilities.
 *
 * @package JDeepD\AI_Post_Triage\Tests\Integration\Core
 */

declare( strict_types = 1 );

namespace JDeepD\AI_Post_Triage\Tests\Integration\Core;

use JDeepD\AI_Post_Triage\Core\Capabilities;
use JDeepD\AI_Post_Triage\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Class - CapabilitiesTest
 */
#[CoversClass( Capabilities::class )]
class CapabilitiesTest extends TestCase {
	/**
	 * Remove capabilities added to roles.
	 */
	protected function tearDown(): void {
		foreach ( [ 'author', 'editor' ] as $role ) {
			get_role( $role )?->remove_cap( Capabilities::REVIEW );
		}

		parent::tearDown();
	}

	/**
	 * Test that register_hooks adds the user_has_cap filter.
	 */
	public function test_register_hooks_adds_user_has_cap_filter(): void {
		$capabilities = new Capabilities();
		$capabilities->register_hooks();

		$this->assertSame( 10, has_filter( 'user_has_cap', [ $capabilities, 'grant' ] ) );
	}

	/**
	 * Roles and the capabilities they get.
	 *
	 * @return array<string, array{0: string, 1: bool, 2: bool}>
	 */
	public static function roles(): array {
		return [
			'administrator' => [ 'administrator', true, true ],
			'editor'        => [ 'editor', false, true ],
			'author'        => [ 'author', false, false ],
			'contributor'   => [ 'contributor', false, false ],
			'subscriber'    => [ 'subscriber', false, false ],
		];
	}

	/**
	 * Test that each role gets the right capabilities.
	 *
	 * @param string $role   Role.
	 * @param bool   $manage Whether the role can manage settings.
	 * @param bool   $review Whether the role can review the queue.
	 */
	#[DataProvider( 'roles' )]
	public function test_role_capabilities( string $role, bool $manage, bool $review ): void {
		$user_id = self::factory()->user->create( [ 'role' => $role ] );

		$this->assertSame( $manage, user_can( $user_id, Capabilities::MANAGE ) );
		$this->assertSame( $review, user_can( $user_id, Capabilities::REVIEW ) );
	}

	/**
	 * Test that logged-out visitors get neither capability.
	 */
	public function test_logged_out_visitor_has_no_capabilities(): void {
		wp_set_current_user( 0 );

		$this->assertFalse( current_user_can( Capabilities::MANAGE ) );
		$this->assertFalse( current_user_can( Capabilities::REVIEW ) );
	}

	/**
	 * Test that the map can be changed with a filter.
	 */
	public function test_map_filter_changes_granting_capability(): void {
		$author_id = self::factory()->user->create( [ 'role' => 'author' ] );

		add_filter(
			'ai_post_triage/capability_map',
			static fn ( array $map ): array => [ Capabilities::REVIEW => 'edit_posts' ] + $map
		);

		$this->assertTrue( user_can( $author_id, Capabilities::REVIEW ) );
		$this->assertFalse( user_can( $author_id, Capabilities::MANAGE ) );
	}

	/**
	 * Test that invalid filter values fall back to the defaults.
	 */
	public function test_map_ignores_invalid_values(): void {
		add_filter(
			'ai_post_triage/capability_map',
			static fn (): array => [
				Capabilities::MANAGE => '',
				Capabilities::REVIEW => [ 'edit_posts' ],
				'other_capability'   => 'read',
			]
		);

		$this->assertSame(
			[
				Capabilities::MANAGE => 'manage_options',
				Capabilities::REVIEW => 'edit_others_posts',
			],
			Capabilities::map()
		);
	}

	/**
	 * Test that a non-array filter result falls back to the defaults.
	 */
	public function test_map_ignores_non_array_filter_result(): void {
		add_filter( 'ai_post_triage/capability_map', '__return_false' );

		$this->assertSame( 'edit_others_posts', Capabilities::map()[ Capabilities::REVIEW ] );
	}

	/**
	 * Test that a capability added to a role directly takes precedence.
	 */
	public function test_role_capability_takes_precedence(): void {
		get_role( 'author' )?->add_cap( Capabilities::REVIEW );
		get_role( 'editor' )?->add_cap( Capabilities::REVIEW, false );

		$author_id = self::factory()->user->create( [ 'role' => 'author' ] );
		$editor_id = self::factory()->user->create( [ 'role' => 'editor' ] );

		$this->assertTrue( user_can( $author_id, Capabilities::REVIEW ) );
		$this->assertFalse( user_can( $editor_id, Capabilities::REVIEW ) );
	}

	/**
	 * Test that other capability checks are left alone.
	 */
	public function test_grant_ignores_other_capability_checks(): void {
		$allcaps = [ 'edit_others_posts' => true ];

		$this->assertSame( $allcaps, ( new Capabilities() )->grant( $allcaps, [ 'edit_posts' ], [ 'edit_posts' ] ) );
	}
}
