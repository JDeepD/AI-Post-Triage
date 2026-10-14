<?php
/**
 * Integration tests for Model_Catalogue.
 *
 * @package JDeepD\Wire_Desk\Tests\Integration\Decisions
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk\Tests\Integration\Decisions;

use JDeepD\Wire_Desk\Decisions\Model_Catalogue;
use JDeepD\Wire_Desk\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Class - Model_CatalogueTest
 */
#[CoversClass( Model_Catalogue::class )]
class Model_CatalogueTest extends TestCase {
	/**
	 * URLs requested from the HTTP API.
	 *
	 * @var string[]
	 */
	private array $requests = [];

	/**
	 * Clears the cached list.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->requests = [];

		delete_transient( Model_Catalogue::TRANSIENT );
	}

	/**
	 * Test that the list holds the supported decision models by name.
	 */
	public function test_get_lists_supported_decision_models(): void {
		$this->respond( 200, $this->fixture() );

		$this->assertSame(
			[
				'acme/decider-2'       => 'acme/decider-2',
				'cloudflare/clef'      => 'Cloudflare: Clef',
				'typesafe/jev-1.13'    => 'TypeSafe: Jev 1.13',
				'~typesafe/jev-latest' => 'TypeSafe: Jev Latest',
			],
			Model_Catalogue::get()
		);
		$this->assertSame( [ Model_Catalogue::ENDPOINT ], $this->requests );
	}

	/**
	 * Test that the list is cached for a day.
	 */
	public function test_get_caches_list(): void {
		$this->respond( 200, $this->fixture() );

		$first  = Model_Catalogue::get();
		$second = Model_Catalogue::get();

		$this->assertSame( $first, $second );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( $first, get_transient( Model_Catalogue::TRANSIENT ) );

		$timeout = (int) get_option( '_transient_timeout_' . Model_Catalogue::TRANSIENT );

		if ( $timeout ) {
			$this->assertEqualsWithDelta( time() + Model_Catalogue::TTL, $timeout, 5 );
		}
	}

	/**
	 * Test that refreshing fetches the list again.
	 */
	public function test_get_refresh_bypasses_cache(): void {
		$this->respond( 200, $this->fixture() );

		Model_Catalogue::get();
		Model_Catalogue::get( true );

		$this->assertCount( 2, $this->requests );
	}

	/**
	 * Test that a failed fetch returns an error and isn't retried straight away.
	 *
	 * @param int    $status HTTP status.
	 * @param string $body   Response body.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'data_failures' )]
	public function test_get_remembers_failure( int $status, string $body ): void {
		$this->respond( $status, $body );

		$first  = Model_Catalogue::get();
		$second = Model_Catalogue::get();

		$this->assertWPError( $first );
		$this->assertSame( 'wire_desk_models_unavailable', $first->get_error_code() );
		$this->assertWPError( $second );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( [], get_transient( Model_Catalogue::TRANSIENT ) );

		$timeout = (int) get_option( '_transient_timeout_' . Model_Catalogue::TRANSIENT );

		if ( $timeout ) {
			$this->assertEqualsWithDelta( time() + Model_Catalogue::FAILURE_TTL, $timeout, 5 );
		}
	}

	/**
	 * Failed fetches.
	 *
	 * @return array<string, array{int, string}>
	 */
	public static function data_failures(): array {
		return [
			'server error' => [ 500, '{"error":{"code":500,"message":"Internal Server Error"}}' ],
			'not json'     => [ 200, '<html></html>' ],
			'no data'      => [ 200, '{"models":[]}' ],
			'no decisions' => [ 200, '{"data":[{"id":"openai/gpt-6","architecture":{"output_modalities":["text"]}}]}' ],
			'empty data'   => [ 200, '{"data":[]}' ],
		];
	}

	/**
	 * Test that a transport failure returns an error.
	 */
	public function test_get_handles_transport_error(): void {
		add_filter(
			'pre_http_request',
			function () {
				$this->requests[] = Model_Catalogue::ENDPOINT;

				return new \WP_Error( 'http_request_failed', 'cURL error 28' );
			}
		);

		$this->assertWPError( Model_Catalogue::get() );
		$this->assertCount( 1, $this->requests );
	}

	/**
	 * Test that the unsupported prefixes can be filtered.
	 */
	public function test_get_unsupported_models_are_filterable(): void {
		$this->respond( 200, $this->fixture() );

		add_filter( 'wire_desk/unsupported_models', static fn (): array => [ 'typesafe/', '~typesafe/', 'acme/' ] );

		$this->assertSame(
			[
				'cloudflare/clef'      => 'Cloudflare: Clef',
				'respan/span-01'       => 'Respan: Span-01',
				'scaledown/classify-1' => 'ScaleDown: Classify-1',
			],
			Model_Catalogue::get()
		);
	}

	/**
	 * Test that model names are stripped of markup.
	 */
	public function test_get_sanitizes_names(): void {
		$this->respond( 200, '{"data":[{"id":"acme/x","name":"<b>Acme</b> X","architecture":{"output_modalities":["decisions"]}}]}' );

		$this->assertSame( [ 'acme/x' => 'Acme X' ], Model_Catalogue::get() );
	}

	/**
	 * The decision model list fixture.
	 */
	private function fixture(): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/Fixtures/models/decisions.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture.
	}

	/**
	 * Responds to requests with a body.
	 *
	 * @param int    $status HTTP status.
	 * @param string $body   Response body.
	 */
	private function respond( int $status, string $body ): void {
		add_filter(
			'pre_http_request',
			function ( $preempt, array $args, string $url ) use ( $status, $body ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed, SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- Required by the filter.
				$this->requests[] = $url;

				return [
					'headers'  => [],
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
