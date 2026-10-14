<?php
/**
 * Integration tests for Rescore_Controller.
 *
 * @package JDeepD\Wire_Desk\Tests\Integration\Modules\Settings
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk\Tests\Integration\Modules\Settings;

use JDeepD\Wire_Desk\Data\Wire_Post_Repository;
use JDeepD\Wire_Desk\Jobs\Rescore;
use JDeepD\Wire_Desk\Modules\Settings\Rescore_Controller;
use JDeepD\Wire_Desk\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Class - Rescore_ControllerTest
 */
#[CoversClass( Rescore_Controller::class )]
class Rescore_ControllerTest extends TestCase {
	/**
	 * Route under test.
	 */
	private const ROUTE = '/wire-desk/v1/rescore';

	/**
	 * Signs in an administrator and adds posts to the table.
	 */
	protected function setUp(): void {
		parent::setUp();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$repository = new Wire_Post_Repository();

		foreach ( self::factory()->post->create_many( 3 ) as $post_id ) {
			$repository->add_queued( $post_id, '2026-10-14 00:00:00' );
		}
	}

	/**
	 * Test that the route is registered.
	 */
	public function test_route_is_registered(): void {
		$this->assertArrayHasKey( self::ROUTE, rest_get_server()->get_routes() );
	}

	/**
	 * Test that the newest posts are queued.
	 */
	public function test_queues_posts(): void {
		$response = $this->post( [ 'limit' => 2 ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'queued' => 2 ], $response->get_data() );
		$this->assertCount( 1, $this->pending_batches() );
	}

	/**
	 * Test that editors can't re-score posts.
	 */
	public function test_editors_are_forbidden(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->assertSame( 403, $this->post( [ 'limit' => 2 ] )->get_status() );
		$this->assertSame( [], $this->pending_batches() );
	}

	/**
	 * Test that bad limits are rejected.
	 *
	 * @param array<string, mixed> $params Request parameters.
	 */
	#[DataProvider( 'data_invalid' )]
	public function test_invalid_limit_is_rejected( array $params ): void {
		$this->assertSame( 400, $this->post( $params )->get_status() );
	}

	/**
	 * Bad limits.
	 *
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function data_invalid(): array {
		return [
			'missing'  => [ [] ],
			'zero'     => [ [ 'limit' => 0 ] ],
			'too many' => [ [ 'limit' => Rescore_Controller::MAX_LIMIT + 1 ] ],
			'text'     => [ [ 'limit' => 'all' ] ],
		];
	}

	/**
	 * IDs of waiting re-score batches.
	 *
	 * @return array<int, mixed>
	 */
	private function pending_batches(): array {
		return as_get_scheduled_actions(
			[
				'hook'   => Rescore::BATCH_HOOK,
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			],
			'ids'
		);
	}

	/**
	 * Sends a request through the REST server.
	 *
	 * @param array<string, mixed> $params Request parameters.
	 */
	private function post( array $params ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_body_params( $params );

		return rest_do_request( $request );
	}
}
