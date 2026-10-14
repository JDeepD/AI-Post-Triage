<?php
/**
 * Integration tests for Term_Search_Controller.
 *
 * @package JDeepD\Wire_Desk\Tests\Integration\Modules\Settings
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk\Tests\Integration\Modules\Settings;

use JDeepD\Wire_Desk\Modules\Settings\Term_Search_Controller;
use JDeepD\Wire_Desk\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Class - Term_Search_ControllerTest
 */
#[CoversClass( Term_Search_Controller::class )]
class Term_Search_ControllerTest extends TestCase {
	/**
	 * Route under test.
	 */
	private const ROUTE = '/wire-desk/v1/terms';

	/**
	 * Test that the route is registered.
	 */
	public function test_route_is_registered(): void {
		$this->assertArrayHasKey( self::ROUTE, rest_get_server()->get_routes() );
	}

	/**
	 * Test that an administrator finds terms by name.
	 */
	public function test_search_returns_matching_terms(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$wires = self::factory()->category->create( [ 'name' => 'Wires' ] );
		self::factory()->category->create( [ 'name' => 'Sports' ] );

		$response = $this->search(
			[
				'taxonomy' => 'category',
				'search'   => 'wir',
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			[
				[
					'id'   => $wires,
					'name' => 'Wires',
				],
			],
			$response->get_data()
		);
	}

	/**
	 * Test that taxonomies outside the REST API can be searched.
	 */
	public function test_search_works_for_taxonomy_without_rest_support(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		register_taxonomy(
			'wire_source',
			'post',
			[
				'show_ui'      => true,
				'show_in_rest' => false,
			]
		);
		$term = self::factory()->term->create(
			[
				'taxonomy' => 'wire_source',
				'name'     => 'Agency feed',
			]
		);

		$response = $this->search(
			[
				'taxonomy' => 'wire_source',
				'search'   => 'agency',
			]
		);

		$this->assertSame( [ $term ], wp_list_pluck( (array) $response->get_data(), 'id' ) );

		unregister_taxonomy( 'wire_source' );
	}

	/**
	 * Test that per_page limits the results.
	 */
	public function test_search_respects_per_page(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		foreach ( [ 'Wire A', 'Wire B', 'Wire C' ] as $name ) {
			self::factory()->category->create( [ 'name' => $name ] );
		}

		$response = $this->search(
			[
				'taxonomy' => 'category',
				'search'   => 'wire',
				'per_page' => 2,
			]
		);

		$this->assertCount( 2, (array) $response->get_data() );
	}

	/**
	 * Test that editors can't search.
	 */
	public function test_editor_is_forbidden(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$response = $this->search(
			[
				'taxonomy' => 'category',
				'search'   => 'wire',
			]
		);

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Test that logged-out visitors can't search.
	 */
	public function test_logged_out_visitor_is_unauthorized(): void {
		wp_set_current_user( 0 );

		$response = $this->search(
			[
				'taxonomy' => 'category',
				'search'   => 'wire',
			]
		);

		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * Test that bad parameters are rejected.
	 */
	public function test_invalid_parameters_are_rejected(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->assertSame(
			400,
			$this->search(
				[
					'taxonomy' => 'missing',
					'search'   => 'wire',
				]
			)->get_status()
		);
		$this->assertSame( 400, $this->search( [ 'taxonomy' => 'category' ] )->get_status() );
		$this->assertSame(
			400,
			$this->search(
				[
					'taxonomy' => 'category',
					'search'   => 'wire',
					'per_page' => Term_Search_Controller::MAX_RESULTS + 1,
				]
			)->get_status()
		);
	}

	/**
	 * Runs a search request.
	 *
	 * @param array<string, mixed> $params Query parameters.
	 */
	private function search( array $params ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', self::ROUTE );
		$request->set_query_params( $params );

		return rest_get_server()->dispatch( $request );
	}
}
