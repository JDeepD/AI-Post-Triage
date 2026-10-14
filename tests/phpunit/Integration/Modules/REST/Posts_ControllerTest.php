<?php
/**
 * Integration tests for Posts_Controller.
 *
 * @package JDeepD\Wire_Desk\Tests\Integration\Modules\REST
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk\Tests\Integration\Modules\REST;

use JDeepD\Wire_Desk\Data\Wire_Post;
use JDeepD\Wire_Desk\Data\Wire_Post_Repository;
use JDeepD\Wire_Desk\Modules\REST\Posts_Controller;
use JDeepD\Wire_Desk\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Class - Posts_ControllerTest
 */
#[CoversClass( Posts_Controller::class )]
class Posts_ControllerTest extends TestCase {
	/**
	 * Collection route.
	 */
	private const ROUTE = '/wire-desk/v1/posts';

	/**
	 * Role that can review wire posts but not publish them or edit private posts.
	 */
	private const REVIEWER = 'wire_desk_test_reviewer';

	/**
	 * Wire post table.
	 *
	 * @var \JDeepD\Wire_Desk\Data\Wire_Post_Repository
	 */
	private Wire_Post_Repository $repository;

	/**
	 * Signs in an editor.
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

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
	}

	/**
	 * Removes the test role.
	 */
	protected function tearDown(): void {
		remove_role( self::REVIEWER );

		parent::tearDown();
	}

	/**
	 * Test that the routes are registered.
	 */
	public function test_routes_are_registered(): void {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( self::ROUTE, $routes );
		$this->assertArrayHasKey( self::ROUTE . '/(?P<id>[\d]+)', $routes );
		$this->assertArrayHasKey( self::ROUTE . '/(?P<id>[\d]+)/(?P<action>publish|dismiss|restore|rescore)', $routes );
	}

	/**
	 * Test that the schema lists every field.
	 */
	public function test_schema(): void {
		$schema = ( new Posts_Controller() )->get_public_item_schema();

		$this->assertIsCallable( rest_get_server()->get_route_options( self::ROUTE )['schema'] ?? null );
		$this->assertSame( 'wire-desk-post', $schema['title'] ?? null );
		$this->assertSame(
			[ 'id', 'title', 'post_status', 'edit_link', 'date', 'state', 'band', 'score', 'model', 'cost', 'scored_at', 'error', 'attempts', 'answers', 'actions' ],
			array_keys( $schema['properties'] ?? [] )
		);
	}

	/**
	 * Test that only reviewers can list posts.
	 */
	public function test_list_needs_review_capability(): void {
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->get()->get_status() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );
		$this->assertSame( 403, $this->get()->get_status() );
	}

	/**
	 * Test that posts are listed newest first with their fields.
	 */
	public function test_list_returns_posts_newest_first(): void {
		$older = $this->wire_post( '2024-01-01 09:00:00' );
		$newer = $this->wire_post( '2024-01-02 09:00:00', Wire_Post::BAND_HIGH, 91 );

		$response = $this->get();
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ $newer, $older ], array_column( $data, 'id' ) );

		$item = $data[0];

		$this->assertSame( 'Wire post ' . $newer, $item['title'] );
		$this->assertSame( 'draft', $item['post_status'] );
		$this->assertStringContainsString( 'post.php?post=' . $newer . '&action=edit', (string) $item['edit_link'] );
		$this->assertSame( '2024-01-02T09:00:00', $item['date'] );
		$this->assertSame( 'scored', $item['state'] );
		$this->assertSame( 'high', $item['band'] );
		$this->assertSame( 91, $item['score'] );
		$this->assertSame( 'typesafe/jev-1.13', $item['model'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', (string) $item['scored_at'] );
		$this->assertSame( '', $item['error'] );
		$this->assertSame( [ 'publish', 'dismiss', 'rescore' ], $item['actions'] );
		$this->assertSame(
			[
				[
					'id'       => 'q1',
					'question' => 'Is it breaking news?',
					'expected' => 'yes',
					'weight'   => 'high',
					'required' => true,
					'yes'      => 0.9,
					'met'      => true,
				],
			],
			$item['answers']
		);
		$this->assertArrayNotHasKey( 'cost', $item );
		$this->assertArrayNotHasKey( 'attempts', $item );
	}

	/**
	 * Test that the edit context adds cost and attempts.
	 */
	public function test_edit_context_adds_cost_and_attempts(): void {
		$this->wire_post( '2024-01-01 09:00:00' );

		$item = $this->get( [ 'context' => 'edit' ] )->get_data()[0];

		$this->assertSame( 0.0012, $item['cost'] );
		$this->assertSame( 0, $item['attempts'] );
	}

	/**
	 * Test that a post waiting to be scored has no score or answers.
	 */
	public function test_unscored_post(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->repository->add_queued( $post_id, '2024-01-01 00:00:00' );

		$item = $this->get()->get_data()[0];

		$this->assertSame( 'queued', $item['state'] );
		$this->assertSame( 'not-scored', $item['band'] );
		$this->assertNull( $item['score'] );
		$this->assertNull( $item['scored_at'] );
		$this->assertNull( $item['answers'] );
	}

	/**
	 * Test that the state and band filters narrow the list.
	 */
	public function test_list_filters(): void {
		$high      = $this->wire_post( '2024-01-01 00:00:00', Wire_Post::BAND_HIGH );
		$low       = $this->wire_post( '2024-01-02 00:00:00', Wire_Post::BAND_LOW );
		$dismissed = $this->wire_post( '2024-01-03 00:00:00', Wire_Post::BAND_HIGH );
		$this->repository->set_state( $dismissed, Wire_Post::STATE_DISMISSED );

		$this->assertSame( [ $low, $high ], array_column( $this->get( [ 'state' => 'scored' ] )->get_data(), 'id' ) );
		$this->assertSame( [ $dismissed, $high ], array_column( $this->get( [ 'band' => 'high' ] )->get_data(), 'id' ) );
		$this->assertSame(
			[ $high ],
			array_column(
				$this->get(
					[
						'state' => 'scored',
						'band'  => 'high',
					]
				)->get_data(),
				'id'
			)
		);
	}

	/**
	 * Test that bad parameters are rejected.
	 *
	 * @param array<string, mixed> $params Query parameters.
	 */
	#[DataProvider( 'data_invalid_params' )]
	public function test_list_rejects_invalid_params( array $params ): void {
		$this->assertSame( 400, $this->get( $params )->get_status() );
	}

	/**
	 * Bad list parameters.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function data_invalid_params(): array {
		return [
			'unknown state'   => [ [ 'state' => 'archived' ] ],
			'unknown band'    => [ [ 'band' => 'urgent' ] ],
			'zero per page'   => [ [ 'per_page' => 0 ] ],
			'too many'        => [ [ 'per_page' => 101 ] ],
			'unknown cursor'  => [ [ 'before' => 999999 ] ],
			'negative cursor' => [ [ 'after' => -1 ] ],
		];
	}

	/**
	 * Test that pages link to the next and previous pages, and following them walks the list.
	 */
	public function test_list_pages_with_link_headers(): void {
		$ids = [];
		for ( $day = 1; $day <= 5; $day++ ) {
			$ids[] = $this->wire_post( sprintf( '2024-01-%02d 00:00:00', $day ) );
		}

		$first = $this->get(
			[
				'per_page' => 2,
				'state'    => 'scored',
			]
		);

		$this->assertSame( [ $ids[4], $ids[3] ], array_column( $first->get_data(), 'id' ) );
		$this->assertNull( $this->link( $first, 'prev' ) );

		$second = $this->follow( (string) $this->link( $first, 'next' ) );

		$this->assertSame( [ $ids[2], $ids[1] ], array_column( $second->get_data(), 'id' ) );
		$this->assertStringContainsString( 'state=scored', (string) $this->link( $second, 'next' ) );

		$last = $this->follow( (string) $this->link( $second, 'next' ) );

		$this->assertSame( [ $ids[0] ], array_column( $last->get_data(), 'id' ) );
		$this->assertNull( $this->link( $last, 'next' ) );

		$back = $this->follow( (string) $this->link( $last, 'prev' ) );

		$this->assertSame( [ $ids[2], $ids[1] ], array_column( $back->get_data(), 'id' ) );
	}

	/**
	 * Test that an empty list has no links.
	 */
	public function test_empty_list(): void {
		$response = $this->get();

		$this->assertSame( [], $response->get_data() );
		$this->assertArrayNotHasKey( 'Link', $response->get_headers() );
	}

	/**
	 * Test that a single post is returned with its links.
	 */
	public function test_get_item(): void {
		$post_id = $this->wire_post( '2024-01-01 00:00:00' );

		$response = $this->request( 'GET', self::ROUTE . '/' . $post_id );
		$links    = $response->get_links();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $post_id, $response->get_data()['id'] );
		$this->assertSame( rest_url( 'wire-desk/v1/posts/' . $post_id ), $links['self'][0]['href'] ?? null );
		$this->assertSame( rest_url( 'wp/v2/posts/' . $post_id ), $links['https://api.w.org/post'][0]['href'] ?? null );
	}

	/**
	 * Test that missing and deleted posts are not found.
	 */
	public function test_get_item_not_found(): void {
		$this->assertSame( 404, $this->request( 'GET', self::ROUTE . '/999999' )->get_status() );

		$this->repository->add_queued( 999998, '2024-01-01 00:00:00' );

		$this->assertSame( 404, $this->request( 'GET', self::ROUTE . '/999998' )->get_status() );
		$this->assertSame( 404, $this->request( 'GET', self::ROUTE . '/' . self::factory()->post->create() )->get_status() );
	}

	/**
	 * Test that a reviewer can't read a post they can't edit.
	 */
	public function test_get_item_needs_edit_capability(): void {
		$post_id = self::factory()->post->create(
			[
				'post_status' => 'private',
				'post_author' => self::factory()->user->create( [ 'role' => 'editor' ] ),
			]
		);
		$this->repository->add_queued( $post_id, '2024-01-01 00:00:00' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => self::REVIEWER ] ) );

		$this->assertSame( 403, $this->request( 'GET', self::ROUTE . '/' . $post_id )->get_status() );
	}

	/**
	 * Test that an action runs and returns the updated post.
	 */
	public function test_action_returns_updated_post(): void {
		$post_id = $this->wire_post( '2024-01-01 00:00:00' );

		$response = $this->request( 'POST', self::ROUTE . '/' . $post_id . '/dismiss' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'dismissed', $response->get_data()['state'] );
		$this->assertSame( [ 'publish', 'restore', 'rescore' ], $response->get_data()['actions'] );
		$this->assertSame( Wire_Post::STATE_DISMISSED, $this->repository->get( $post_id )?->state );
	}

	/**
	 * Test that publishing publishes the post.
	 */
	public function test_publish_action(): void {
		$post_id = $this->wire_post( '2024-01-01 00:00:00' );

		$response = $this->request( 'POST', self::ROUTE . '/' . $post_id . '/publish' );

		$this->assertSame( 'published', $response->get_data()['state'] );
		$this->assertSame( 'publish', $response->get_data()['post_status'] );
	}

	/**
	 * Test that action errors keep their status.
	 */
	public function test_action_errors(): void {
		$post_id = $this->wire_post( '2024-01-01 00:00:00' );

		$restore = $this->request( 'POST', self::ROUTE . '/' . $post_id . '/restore' );

		$this->assertSame( 409, $restore->get_status() );
		$this->assertSame( 'wire_desk_not_dismissed', $restore->get_data()['code'] );

		$this->assertSame( 404, $this->request( 'POST', self::ROUTE . '/999999/dismiss' )->get_status() );
		$this->assertSame( 404, $this->request( 'POST', self::ROUTE . '/' . $post_id . '/delete' )->get_status() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => self::REVIEWER ] ) );

		$publish = $this->request( 'POST', self::ROUTE . '/' . $post_id . '/publish' );

		$this->assertSame( 403, $publish->get_status() );
		$this->assertSame( 'wire_desk_forbidden', $publish->get_data()['code'] );

		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->request( 'POST', self::ROUTE . '/' . $post_id . '/dismiss' )->get_status() );
		$this->assertSame( Wire_Post::STATE_SCORED, $this->repository->get( $post_id )?->state );
	}

	/**
	 * Lists posts.
	 *
	 * @param array<string, mixed> $params Query parameters.
	 */
	private function get( array $params = [] ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', self::ROUTE );
		$request->set_query_params( $params );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Sends a request.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route.
	 */
	private function request( string $method, string $route ): WP_REST_Response {
		return rest_get_server()->dispatch( new WP_REST_Request( $method, $route ) );
	}

	/**
	 * Follows a link header.
	 *
	 * @param string $url Link.
	 */
	private function follow( string $url ): WP_REST_Response {
		$query = [];
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		return $this->get( $query );
	}

	/**
	 * A link from a response's Link header.
	 *
	 * @param \WP_REST_Response $response Response.
	 * @param string            $rel      Relation.
	 */
	private function link( WP_REST_Response $response, string $rel ): ?string {
		$header = (string) ( $response->get_headers()['Link'] ?? '' );

		foreach ( explode( ',', $header ) as $link ) {
			if ( preg_match( '/<([^>]+)>;\s*rel="' . preg_quote( $rel, '/' ) . '"/', trim( $link ), $match ) ) {
				return $match[1];
			}
		}

		return null;
	}

	/**
	 * A scored draft wire post.
	 *
	 * @param string $date  Post date.
	 * @param int    $band  Band.
	 * @param int    $score Score.
	 */
	private function wire_post( string $date, int $band = Wire_Post::BAND_MEDIUM, int $score = 50 ): int {
		$post_id = self::factory()->post->create(
			[
				'post_status' => 'draft',
				'post_date'   => $date,
			]
		);

		wp_update_post(
			[
				'ID'         => $post_id,
				'post_title' => 'Wire post ' . $post_id,
			]
		);

		$this->repository->add_queued( $post_id, $date );
		$this->repository->save_score(
			$post_id,
			$band,
			$score,
			'typesafe/jev-1.13',
			md5( (string) $post_id ),
			0.0012,
			[
				[
					'id'       => 'q1',
					'question' => 'Is it breaking news?',
					'expected' => 'yes',
					'weight'   => 'high',
					'required' => true,
					'yes'      => 0.9,
					'met'      => true,
				],
			]
		);

		return $post_id;
	}
}
