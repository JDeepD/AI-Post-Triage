<?php
/**
 * Integration tests for OpenRouter_Client.
 *
 * @package JDeepD\Wire_Desk\Tests\Integration\Decisions
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk\Tests\Integration\Decisions;

use JDeepD\Wire_Desk\Decisions\Decision;
use JDeepD\Wire_Desk\Decisions\Decision_Client;
use JDeepD\Wire_Desk\Decisions\OpenRouter_Client;
use JDeepD\Wire_Desk\Modules\Settings\Api_Key;
use JDeepD\Wire_Desk\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use WP_Connector_Registry;

/**
 * Class - OpenRouter_ClientTest
 */
#[CoversClass( OpenRouter_Client::class )]
#[CoversClass( Decision::class )]
class OpenRouter_ClientTest extends TestCase {
	/**
	 * Option holding the test connector's key.
	 */
	private const CONNECTOR_OPTION = 'wire_desk_test_openrouter_key';

	/**
	 * Environment variable holding the test connector's key.
	 */
	private const CONNECTOR_ENV = 'WIRE_DESK_TEST_OPENROUTER_KEY';

	/**
	 * Questions asked in each test.
	 *
	 * @var array<int, array{id: string, question: string, expected: 'yes'|'no', weight: 'high'|'medium'|'low', required: bool}>
	 */
	private const QUESTIONS = [
		[
			'id'       => 'q_breaking',
			'question' => 'Is this breaking news?',
			'expected' => 'yes',
			'weight'   => 'high',
			'required' => false,
		],
		[
			'id'       => 'q_advisory',
			'question' => 'Is this an advisory?',
			'expected' => 'no',
			'weight'   => 'high',
			'required' => true,
		],
	];

	/**
	 * Requests sent to the HTTP API.
	 *
	 * @var array<int, array{url: string, args: array<string, mixed>}>
	 */
	private array $requests = [];

	/**
	 * Connector registered before the test, if any.
	 *
	 * @var ?array<string, mixed>
	 */
	private ?array $original_connector = null;

	/**
	 * Sets a test API key.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->requests = [];

		add_filter( 'wire_desk/openrouter_api_key', [ $this, 'filter_key' ] );
	}

	/**
	 * Restores the connector registry and the environment.
	 */
	protected function tearDown(): void {
		$registry = WP_Connector_Registry::get_instance();

		if ( $registry && $registry->is_registered( OpenRouter_Client::CONNECTOR_ID ) ) {
			$registry->unregister( OpenRouter_Client::CONNECTOR_ID );
		}

		if ( $registry && null !== $this->original_connector ) {
			$registry->register( OpenRouter_Client::CONNECTOR_ID, $this->original_connector );
		}

		putenv( self::CONNECTOR_ENV ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Test cleanup.

		parent::tearDown();
	}

	/**
	 * Returns the test API key.
	 */
	public function filter_key(): string {
		return 'sk-or-test';
	}

	/**
	 * Test that a successful response becomes a decision.
	 */
	public function test_decide_returns_answers(): void {
		$this->respond( 200, 'success' );

		$decision = ( new OpenRouter_Client() )->decide( [ 'headline' => 'Quake hits city' ], self::QUESTIONS, 'typesafe/jev-1.13' );

		$this->assertInstanceOf( Decision::class, $decision );
		$this->assertSame(
			[
				'q_breaking' => 0.96,
				'q_advisory' => 0.0,
			],
			$decision->answers
		);
		$this->assertSame( 'typesafe/jev-1.13-20260917', $decision->model );
		$this->assertSame( 1.9992e-5, $decision->cost );
		$this->assertSame( 'gen-dec-1789738314-X5e5eKGQdvR9rblyX250', $decision->id );
	}

	/**
	 * Test that the request matches the System One schema.
	 */
	public function test_decide_sends_system_one_request(): void {
		$this->respond( 200, 'success' );

		( new OpenRouter_Client() )->decide( [ 'headline' => 'Quake hits city' ], self::QUESTIONS, 'typesafe/jev-1.13' );

		$this->assertCount( 1, $this->requests );

		$request = $this->requests[0];
		$headers = $request['args']['headers'];
		$body    = json_decode( (string) $request['args']['body'], true );

		$this->assertSame( OpenRouter_Client::ENDPOINT, $request['url'] );
		$this->assertSame( 'POST', $request['args']['method'] );
		$this->assertIsArray( $headers );
		$this->assertSame( 'Bearer sk-or-test', $headers['Authorization'] );
		$this->assertSame( 'application/json', $headers['Content-Type'] );
		$this->assertSame(
			[
				'model'     => 'typesafe/jev-1.13',
				'state'     => [ 'headline' => 'Quake hits city' ],
				'questions' => [
					'q_breaking' => [
						'type'         => 'noul',
						'instructions' => 'Is this breaking news?',
						'criteria'     => [
							'true'  => 'Yes',
							'false' => 'No',
						],
					],
					'q_advisory' => [
						'type'         => 'noul',
						'instructions' => 'Is this an advisory?',
						'criteria'     => [
							'true'  => 'Yes',
							'false' => 'No',
						],
					],
				],
			],
			$body
		);
	}

	/**
	 * Test that question IDs that look like list keys are still sent as an object.
	 */
	public function test_decide_sends_questions_as_object(): void {
		$this->respond( 200, 'success' );

		$question       = self::QUESTIONS[0];
		$question['id'] = '0';

		( new OpenRouter_Client() )->decide( [], [ $question ], 'typesafe/jev-1.13' );

		$this->assertStringContainsString( '"questions":{"0":', (string) $this->requests[0]['args']['body'] );
		$this->assertStringContainsString( '"state":{}', (string) $this->requests[0]['args']['body'] );
	}

	/**
	 * Test that no request is sent without an API key.
	 */
	public function test_decide_requires_key(): void {
		remove_filter( 'wire_desk/openrouter_api_key', [ $this, 'filter_key' ] );
		$this->respond( 200, 'success' );

		$result = ( new OpenRouter_Client() )->decide( [ 'headline' => 'x' ], self::QUESTIONS, 'typesafe/jev-1.13' );

		$this->assertWPError( $result );
		$this->assertSame( Decision_Client::ERROR_NO_KEY, $result->get_error_code() );
		$this->assertSame( [], $this->requests );
	}

	/**
	 * Test that no request is sent without questions or a model.
	 */
	public function test_decide_requires_questions_and_model(): void {
		$this->respond( 200, 'success' );

		$client = new OpenRouter_Client();

		$no_questions = $client->decide( [ 'headline' => 'x' ], [], 'typesafe/jev-1.13' );
		$no_model     = $client->decide( [ 'headline' => 'x' ], self::QUESTIONS, '' );

		$this->assertWPError( $no_questions );
		$this->assertSame( Decision_Client::ERROR_BAD_REQUEST, $no_questions->get_error_code() );
		$this->assertWPError( $no_model );
		$this->assertSame( Decision_Client::ERROR_BAD_REQUEST, $no_model->get_error_code() );
		$this->assertSame( [], $this->requests );
	}

	/**
	 * Test that each HTTP failure maps to its error code.
	 *
	 * @param int    $status  HTTP status.
	 * @param string $fixture Fixture name.
	 * @param string $code    Expected error code.
	 */
	#[DataProvider( 'data_http_errors' )]
	public function test_decide_maps_http_errors( int $status, string $fixture, string $code ): void {
		$this->respond( $status, $fixture );

		$result = ( new OpenRouter_Client() )->decide( [ 'headline' => 'x' ], self::QUESTIONS, 'typesafe/jev-1.13' );

		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( $status, $result->get_error_data()['status'] );
	}

	/**
	 * HTTP failures.
	 *
	 * @return array<string, array{int, string, string}>
	 */
	public static function data_http_errors(): array {
		return [
			'bad request'         => [ 400, 'bad-request', Decision_Client::ERROR_BAD_REQUEST ],
			'unauthorized'        => [ 401, 'unauthorized', Decision_Client::ERROR_AUTH ],
			'payment required'    => [ 402, 'payment-required', Decision_Client::ERROR_CREDITS ],
			'forbidden'           => [ 403, 'unauthorized', Decision_Client::ERROR_AUTH ],
			'rate limited'        => [ 429, 'rate-limited', Decision_Client::ERROR_RATE_LIMITED ],
			'internal error'      => [ 500, 'provider-overloaded', Decision_Client::ERROR_SERVER ],
			'edge timeout'        => [ 524, 'provider-overloaded', Decision_Client::ERROR_SERVER ],
			'provider overloaded' => [ 529, 'provider-overloaded', Decision_Client::ERROR_SERVER ],
		];
	}

	/**
	 * Test that the error message comes from the response body.
	 */
	public function test_decide_error_uses_response_message(): void {
		$this->respond( 402, 'payment-required' );

		$result = ( new OpenRouter_Client() )->decide( [ 'headline' => 'x' ], self::QUESTIONS, 'typesafe/jev-1.13' );

		$this->assertWPError( $result );
		$this->assertStringContainsString( 'Insufficient credits', $result->get_error_message() );
		$this->assertStringContainsString( '402', $result->get_error_message() );
	}

	/**
	 * Test that a rate limit carries the Retry-After delay.
	 *
	 * @param string $header   Retry-After header.
	 * @param ?int   $expected Expected delay in seconds.
	 */
	#[DataProvider( 'data_retry_after' )]
	public function test_decide_rate_limit_reads_retry_after( string $header, ?int $expected ): void {
		$this->respond( 429, 'rate-limited', [ 'retry-after' => $header ] );

		$result = ( new OpenRouter_Client() )->decide( [ 'headline' => 'x' ], self::QUESTIONS, 'typesafe/jev-1.13' );

		$this->assertWPError( $result );

		$retry_after = $result->get_error_data()['retry_after'];

		if ( null === $expected ) {
			$this->assertNull( $retry_after );
		} else {
			$this->assertIsInt( $retry_after );
			$this->assertEqualsWithDelta( $expected, $retry_after, 2 );
		}
	}

	/**
	 * Retry-After headers.
	 *
	 * @return array<string, array{string, ?int}>
	 */
	public static function data_retry_after(): array {
		return [
			'seconds'  => [ '30', 30 ],
			'date'     => [ gmdate( 'D, d M Y H:i:s', time() + 120 ) . ' GMT', 120 ],
			'past'     => [ gmdate( 'D, d M Y H:i:s', time() - 120 ) . ' GMT', 0 ],
			'missing'  => [ '', null ],
			'nonsense' => [ 'soon', null ],
		];
	}

	/**
	 * Test that a transport failure is a server error.
	 */
	public function test_decide_maps_transport_error(): void {
		add_filter(
			'pre_http_request',
			static fn () => new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' )
		);

		$result = ( new OpenRouter_Client() )->decide( [ 'headline' => 'x' ], self::QUESTIONS, 'typesafe/jev-1.13' );

		$this->assertWPError( $result );
		$this->assertSame( Decision_Client::ERROR_SERVER, $result->get_error_code() );
		$this->assertSame( 0, $result->get_error_data()['status'] );
		$this->assertStringContainsString( 'timed out', $result->get_error_message() );
	}

	/**
	 * Test that malformed successful responses are rejected.
	 *
	 * @param string $body Response body.
	 */
	#[DataProvider( 'data_invalid_responses' )]
	public function test_decide_rejects_invalid_response( string $body ): void {
		$this->respond_raw( 200, $body );

		$result = ( new OpenRouter_Client() )->decide( [ 'headline' => 'x' ], self::QUESTIONS, 'typesafe/jev-1.13' );

		$this->assertWPError( $result );
		$this->assertSame( Decision_Client::ERROR_INVALID_RESPONSE, $result->get_error_code() );
	}

	/**
	 * Malformed successful responses.
	 *
	 * @return array<string, array{string}>
	 */
	public static function data_invalid_responses(): array {
		$answer = static fn ( $breaking, string $type = 'noul' ): string => (string) wp_json_encode(
			[
				'answers' => [
					'q_breaking' => [
						'type' => $type,
						$type  => $breaking,
					],
					'q_advisory' => [
						'type' => 'noul',
						'noul' => 0.1,
					],
				],
				'model'   => 'typesafe/jev-1.13',
				'usage'   => [ 'cost' => 0.00001 ],
			]
		);

		return [
			'not json'         => [ '<html>Bad gateway</html>' ],
			'empty object'     => [ '{}' ],
			'no usage'         => [ '{"answers":{"q_breaking":{"type":"noul","noul":0.5},"q_advisory":{"type":"noul","noul":0.5}},"model":"m"}' ],
			'missing question' => [ '{"answers":{"q_breaking":{"type":"noul","noul":0.5}},"model":"m","usage":{}}' ],
			'string answer'    => [ $answer( '0.5' ) ],
			'above one'        => [ $answer( 1.5 ) ],
			'below zero'       => [ $answer( -0.1 ) ],
			'wrong type'       => [ $answer( 'payments', 'choice' ) ],
		];
	}

	/**
	 * Test that missing optional fields fall back to safe values.
	 */
	public function test_decide_defaults_optional_fields(): void {
		$this->respond_raw( 200, '{"answers":{"q_breaking":{"noul":1},"q_advisory":{"noul":0}},"usage":{"cost":-1}}' );

		$decision = ( new OpenRouter_Client() )->decide( [ 'headline' => 'x' ], self::QUESTIONS, 'typesafe/jev-1.13' );

		$this->assertInstanceOf( Decision::class, $decision );
		$this->assertSame( 1.0, $decision->answers['q_breaking'] );
		$this->assertSame( 'typesafe/jev-1.13', $decision->model );
		$this->assertSame( 0.0, $decision->cost );
		$this->assertSame( '', $decision->id );
	}

	/**
	 * Test that the key filter can supply the key.
	 */
	public function test_api_key_is_filterable(): void {
		$this->assertSame( 'sk-or-test', OpenRouter_Client::api_key() );
	}

	/**
	 * Test that there is no key when nothing is configured.
	 */
	public function test_api_key_is_empty_without_configuration(): void {
		remove_filter( 'wire_desk/openrouter_api_key', [ $this, 'filter_key' ] );
		$this->register_connector();

		$this->assertSame( '', OpenRouter_Client::api_key() );
	}

	/**
	 * Test that the key saved for the OpenRouter connector is used.
	 */
	public function test_api_key_reads_connector_option(): void {
		remove_filter( 'wire_desk/openrouter_api_key', [ $this, 'filter_key' ] );
		$this->register_connector();
		update_option( self::CONNECTOR_OPTION, 'sk-or-option' );

		$this->assertSame( 'sk-or-option', OpenRouter_Client::api_key() );
	}

	/**
	 * Test that the connector's environment variable wins over its option.
	 */
	public function test_api_key_prefers_connector_env(): void {
		remove_filter( 'wire_desk/openrouter_api_key', [ $this, 'filter_key' ] );
		$this->register_connector();
		update_option( self::CONNECTOR_OPTION, 'sk-or-option' );
		putenv( self::CONNECTOR_ENV . '=sk-or-env' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Test setup.

		$this->assertSame( 'sk-or-env', OpenRouter_Client::api_key() );
	}

	/**
	 * Test that the key saved on the settings screen is used, ahead of the connector.
	 */
	public function test_api_key_prefers_saved_key_over_connector(): void {
		remove_filter( 'wire_desk/openrouter_api_key', [ $this, 'filter_key' ] );
		$this->register_connector();
		update_option( self::CONNECTOR_OPTION, 'sk-or-option' );

		$this->assertSame( OpenRouter_Client::SOURCE_CONNECTOR, OpenRouter_Client::key_source() );

		Api_Key::save( 'sk-or-saved' );

		$this->assertSame( 'sk-or-saved', OpenRouter_Client::api_key() );
		$this->assertSame( OpenRouter_Client::SOURCE_SETTINGS, OpenRouter_Client::key_source() );

		Api_Key::delete();

		$this->assertSame( 'sk-or-option', OpenRouter_Client::api_key() );
	}

	/**
	 * Test that there is no source without a key.
	 */
	public function test_key_source_without_key(): void {
		remove_filter( 'wire_desk/openrouter_api_key', [ $this, 'filter_key' ] );

		$this->assertSame( '', OpenRouter_Client::key_source() );
	}

	/**
	 * Test that the constant wins over the saved key.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_api_key_prefers_constant(): void {
		remove_filter( 'wire_desk/openrouter_api_key', [ $this, 'filter_key' ] );
		Api_Key::save( 'sk-or-saved' );
		define( OpenRouter_Client::KEY_CONSTANT, ' sk-or-constant ' );

		$this->assertSame( 'sk-or-constant', OpenRouter_Client::api_key() );
		$this->assertSame( OpenRouter_Client::SOURCE_CONSTANT, OpenRouter_Client::key_source() );
	}

	/**
	 * Registers a test OpenRouter connector.
	 */
	private function register_connector(): void {
		$registry = WP_Connector_Registry::get_instance();

		$this->assertNotNull( $registry );

		if ( $registry->is_registered( OpenRouter_Client::CONNECTOR_ID ) ) {
			$this->original_connector = $registry->unregister( OpenRouter_Client::CONNECTOR_ID );
		}

		$registry->register(
			OpenRouter_Client::CONNECTOR_ID,
			[
				'name'           => 'OpenRouter',
				'description'    => '',
				'type'           => 'ai_provider',
				'authentication' => [
					'method'       => 'api_key',
					'setting_name' => self::CONNECTOR_OPTION,
					'env_var_name' => self::CONNECTOR_ENV,
				],
			]
		);
	}

	/**
	 * Responds to the next requests with a fixture.
	 *
	 * @param int                   $status  HTTP status.
	 * @param string                $fixture Fixture name.
	 * @param array<string, string> $headers Response headers.
	 */
	private function respond( int $status, string $fixture, array $headers = [] ): void {
		$body = file_get_contents( dirname( __DIR__, 2 ) . '/Fixtures/systemone/' . $fixture . '.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture.

		$this->respond_raw( $status, (string) $body, $headers );
	}

	/**
	 * Responds to the next requests with a body.
	 *
	 * @param int                   $status  HTTP status.
	 * @param string                $body    Response body.
	 * @param array<string, string> $headers Response headers.
	 */
	private function respond_raw( int $status, string $body, array $headers = [] ): void {
		add_filter(
			'pre_http_request',
			function ( $preempt, array $args, string $url ) use ( $status, $body, $headers ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed, SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- Required by the filter.
				$this->requests[] = [
					'url'  => $url,
					'args' => $args,
				];

				return [
					'headers'  => $headers,
					'body'     => $body,
					'response' => [
						'code'    => $status,
						'message' => get_status_header_desc( $status ),
					],
					'cookies'  => [],
					'filename' => null,
				];
			},
			10,
			3
		);
	}
}
