<?php
/**
 * Integration tests for Try_Controller.
 *
 * @package JDeepD\Wire_Desk\Tests\Integration\Modules\Settings
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk\Tests\Integration\Modules\Settings;

use JDeepD\Wire_Desk\Data\Wire_Post;
use JDeepD\Wire_Desk\Decisions\Decision;
use JDeepD\Wire_Desk\Decisions\Decision_Client;
use JDeepD\Wire_Desk\Modules\Settings\Settings;
use JDeepD\Wire_Desk\Modules\Settings\Try_Controller;
use JDeepD\Wire_Desk\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Class - Try_ControllerTest
 */
#[CoversClass( Try_Controller::class )]
class Try_ControllerTest extends TestCase {
	/**
	 * Route under test.
	 */
	private const ROUTE = '/wire-desk/v1/try';

	/**
	 * Requests sent to the HTTP API.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $requests = [];

	/**
	 * Signs in an administrator.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->requests = [];

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		add_filter( 'wire_desk/openrouter_api_key', static fn (): string => 'sk-or-test' );
	}

	/**
	 * Test that the route is registered.
	 */
	public function test_route_is_registered(): void {
		$this->assertArrayHasKey( self::ROUTE, rest_get_server()->get_routes() );
	}

	/**
	 * Test that editors can't try headlines.
	 */
	public function test_editors_are_forbidden(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->respond_success();

		$response = $this->post( [ 'headline' => 'Quake hits city' ] );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( [], $this->requests );
	}

	/**
	 * Test that logged-out users can't try headlines.
	 */
	public function test_logged_out_users_are_unauthorized(): void {
		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->post( [ 'headline' => 'Quake hits city' ] )->get_status() );
	}

	/**
	 * Test that bad parameters are rejected before any request.
	 *
	 * @param array<string, mixed> $params Request parameters.
	 */
	#[DataProvider( 'data_invalid_params' )]
	public function test_invalid_params_are_rejected( array $params ): void {
		$this->respond_success();

		$this->assertSame( 400, $this->post( $params )->get_status() );
		$this->assertSame( [], $this->requests );
	}

	/**
	 * Bad parameters.
	 *
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function data_invalid_params(): array {
		return [
			'no headline'    => [ [] ],
			'empty headline' => [ [ 'headline' => '' ] ],
			'only markup'    => [ [ 'headline' => '<b></b>' ] ],
			'long headline'  => [ [ 'headline' => str_repeat( 'a', Try_Controller::MAX_HEADLINE_LENGTH + 1 ) ] ],
			'bad model'      => [
				[
					'headline' => 'Quake hits city',
					'model'    => 'not a model',
				],
			],
		];
	}

	/**
	 * Test that the saved questions are answered through the route.
	 */
	public function test_route_returns_answers(): void {
		$this->respond_success();

		$response = $this->post( [ 'headline' => 'Quake hits city' ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $this->requests );

		$data = $response->get_data();

		$this->assertSame( 'typesafe/jev-1.13-20260917', $data['model'] );
		$this->assertCount( count( Settings::questions() ), $data['answers'] );
		$this->assertSame(
			[
				'id'       => 'q_advisory',
				'question' => Settings::questions()[3]['question'],
				'expected' => 'no',
				'yes'      => 0.0,
				'met'      => true,
			],
			$data['answers'][3]
		);
		$this->assertSame( 78, $data['score'] );
		$this->assertSame( Wire_Post::BAND_HIGH, $data['band'] );
	}

	/**
	 * Test that the headline, newsroom context, questions and model are sent.
	 */
	public function test_sends_headline_context_and_selected_model(): void {
		update_option( Settings::OPTION_KEY, [ 'context' => 'A national daily in Colombia.' ] );

		$client = $this->fake_client();

		$response = ( new Try_Controller( $client ) )->create_item( $this->request( [ 'headline' => '<em>Quake</em> hits city' ] + [ 'model' => 'cloudflare/clef' ] ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame(
			[
				'headline' => 'Quake hits city',
				'newsroom' => 'A national daily in Colombia.',
			],
			$client->state
		);
		$this->assertSame( Settings::questions(), $client->questions );
		$this->assertSame( 'cloudflare/clef', $client->model );
	}

	/**
	 * Test that the saved model is used when none is selected.
	 */
	public function test_uses_saved_model_without_selection(): void {
		update_option( Settings::OPTION_KEY, [ 'model' => '~typesafe/jev-latest' ] );

		$client = $this->fake_client();

		( new Try_Controller( $client ) )->create_item( $this->request( [ 'headline' => 'Quake hits city' ] ) );

		$this->assertSame( [ 'headline' => 'Quake hits city' ], $client->state );
		$this->assertSame( '~typesafe/jev-latest', $client->model );
	}

	/**
	 * Test that a decision missing an answer is an error.
	 */
	public function test_missing_answer_is_error(): void {
		$client = $this->fake_client( new Decision( [ 'q_breaking' => 0.8 ], 'typesafe/jev-1.13', 0.0, '' ) );

		$response = ( new Try_Controller( $client ) )->create_item( $this->request( [ 'headline' => 'Quake hits city' ] ) );

		$this->assertWPError( $response );
		$this->assertSame( 'wire_desk_missing_answer', $response->get_error_code() );
		$this->assertSame( [ 'status' => 502 ], $response->get_error_data() );
	}

	/**
	 * Test that the answers are scored against the saved rubric.
	 */
	public function test_scores_answers(): void {
		update_option(
			Settings::OPTION_KEY,
			[
				'questions' => [
					[
						'id'       => 'q_breaking',
						'question' => 'Is this breaking news?',
						'expected' => 'yes',
						'weight'   => 'high',
						'required' => false,
					],
					[
						'id'       => 'q_routine',
						'question' => 'Is this routine?',
						'expected' => 'no',
						'weight'   => 'low',
						'required' => false,
					],
				],
			]
		);

		$client = $this->fake_client(
			new Decision(
				[
					'q_breaking' => 1.0,
					'q_routine'  => 1.0,
				],
				'typesafe/jev-1.13',
				0.0,
				''
			)
		);

		$response = ( new Try_Controller( $client ) )->create_item( $this->request( [ 'headline' => 'Quake hits city' ] ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );

		$data = $response->get_data();

		$this->assertSame( 75, $data['score'] );
		$this->assertSame( Wire_Post::BAND_HIGH, $data['band'] );
		$this->assertSame( 'High', $data['band_label'] );
		$this->assertSame( [ true, false ], array_column( $data['answers'], 'met' ) );
	}

	/**
	 * Test that client errors keep their code and get a REST status.
	 *
	 * @param string $code   Client error code.
	 * @param int    $status Expected REST status.
	 */
	#[DataProvider( 'data_client_errors' )]
	public function test_client_errors_map_to_status( string $code, int $status ): void {
		$client = $this->fake_client( new \WP_Error( $code, 'Failed.' ) );

		$response = ( new Try_Controller( $client ) )->create_item( $this->request( [ 'headline' => 'Quake hits city' ] ) );

		$this->assertWPError( $response );
		$this->assertSame( $code, $response->get_error_code() );
		$this->assertSame( 'Failed.', $response->get_error_message() );
		$this->assertSame( [ 'status' => $status ], $response->get_error_data() );
	}

	/**
	 * Client errors.
	 *
	 * @return array<string, array{string, int}>
	 */
	public static function data_client_errors(): array {
		return [
			'no key'           => [ Decision_Client::ERROR_NO_KEY, 400 ],
			'bad request'      => [ Decision_Client::ERROR_BAD_REQUEST, 400 ],
			'rate limited'     => [ Decision_Client::ERROR_RATE_LIMITED, 429 ],
			'auth'             => [ Decision_Client::ERROR_AUTH, 502 ],
			'credits'          => [ Decision_Client::ERROR_CREDITS, 502 ],
			'server'           => [ Decision_Client::ERROR_SERVER, 502 ],
			'invalid response' => [ Decision_Client::ERROR_INVALID_RESPONSE, 502 ],
		];
	}

	/**
	 * A client that records what it was asked. By default it answers every saved question.
	 *
	 * @param \JDeepD\Wire_Desk\Decisions\Decision|\WP_Error|null $result What the client returns.
	 */
	private function fake_client( Decision|\WP_Error|null $result = null ): Decision_Client {
		$result ??= new Decision( array_fill_keys( array_column( Settings::questions(), 'id' ), 0.5 ), 'typesafe/jev-1.13', 0.0, '' );

		return new class( $result ) implements Decision_Client {
			/**
			 * State it was asked about.
			 *
			 * @var array<string, string>
			 */
			public array $state = [];

			/**
			 * Questions it was asked.
			 *
			 * @var array<mixed>
			 */
			public array $questions = [];

			/**
			 * Model it was asked to use.
			 *
			 * @var string
			 */
			public string $model = '';

			/**
			 * What it returns.
			 *
			 * @var \JDeepD\Wire_Desk\Decisions\Decision|\WP_Error
			 */
			private Decision|\WP_Error $result;

			/**
			 * Constructor.
			 *
			 * @param \JDeepD\Wire_Desk\Decisions\Decision|\WP_Error $result What it returns.
			 */
			public function __construct( Decision|\WP_Error $result ) {
				$this->result = $result;
			}

			/**
			 * {@inheritDoc}
			 */
			public function decide( array $state, array $questions, string $model ): Decision|\WP_Error {
				$this->state     = $state;
				$this->questions = $questions;
				$this->model     = $model;

				return $this->result;
			}
		};
	}

	/**
	 * Responds to requests with a successful System One response.
	 */
	private function respond_success(): void {
		$answers = [];

		foreach ( Settings::questions() as $question ) {
			$answers[ $question['id'] ] = [
				'type' => 'noul',
				'noul' => 'q_advisory' === $question['id'] ? 0 : 0.9,
			];
		}

		$body = (string) wp_json_encode(
			[
				'answers' => $answers,
				'model'   => 'typesafe/jev-1.13-20260917',
				'usage'   => [ 'cost' => 0.00002 ],
			]
		);

		add_filter(
			'pre_http_request',
			function ( $preempt, array $args ) use ( $body ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed, SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- Required by the filter.
				$this->requests[] = $args;

				return [
					'headers'  => [],
					'body'     => $body,
					'response' => [
						'code'    => 200,
						'message' => 'OK',
					],
					'cookies'  => [],
					'filename' => null,
				];
			},
			10,
			2
		);
	}

	/**
	 * Builds a request with sanitized parameters.
	 *
	 * @param array<string, mixed> $params Request parameters.
	 * @return \WP_REST_Request<array<string, mixed>>
	 */
	private function request( array $params ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_body_params( $params );
		$request->set_attributes( rest_get_server()->get_routes()[ self::ROUTE ][0] );
		$request->sanitize_params();

		return $request;
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
