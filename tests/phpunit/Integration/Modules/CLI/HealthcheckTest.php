<?php
/**
 * Integration tests for the health-check CLI command.
 *
 * @package JDeepD\Wire_Desk\Tests\Integration\Modules\CLI
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk\Tests\Integration\Modules\CLI;

use ActionScheduler;
use ActionScheduler_Store;
use JDeepD\Wire_Desk\Jobs\Maintenance;
use JDeepD\Wire_Desk\Jobs\Queue;
use JDeepD\Wire_Desk\Jobs\Usage;
use JDeepD\Wire_Desk\Modules\CLI\Healthcheck;
use JDeepD\Wire_Desk\Modules\Settings\Api_Key;
use JDeepD\Wire_Desk\Modules\Settings\Settings;
use JDeepD\Wire_Desk\Tests\Fakes\Fake_WP_CLI;
use JDeepD\Wire_Desk\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Class - HealthcheckTest
 */
#[CoversClass( Healthcheck::class )]
class HealthcheckTest extends TestCase {
	/**
	 * Requests sent to OpenRouter.
	 *
	 * @var string[]
	 */
	private array $requests = [];

	/**
	 * Clears the recorded requests and any failed Wire Desk jobs left in the shared tables.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->requests = [];

		foreach ( as_get_scheduled_actions(
			[
				'group'    => Queue::GROUP,
				'status'   => ActionScheduler_Store::STATUS_FAILED,
				'per_page' => -1,
			],
			'ids'
		) as $id ) {
			ActionScheduler::store()->delete_action( (string) $id );
		}
	}

	/**
	 * Test the command name and description.
	 */
	public function test_name_and_description(): void {
		$this->assertSame( 'health-check', Healthcheck::get_name() );
		$this->assertNotEmpty( Healthcheck::get_description() );
	}

	/**
	 * Test the local checks on a working site without a key.
	 */
	public function test_checks_without_key(): void {
		$checks = Healthcheck::checks( false );

		$this->assertSame( [ 'plugin_active', 'constants', 'composer_autoload', 'table', 'api_key', 'action_scheduler', 'backlog', 'failed_jobs', 'prune_job', 'scoring' ], array_keys( $checks ) );
		$this->assertSame( Healthcheck::PASS, $checks['table']['status'] );
		$this->assertSame( Healthcheck::FAIL, $checks['api_key']['status'] );
		$this->assertStringContainsString( 'WIRE_DESK_OPENROUTER_API_KEY', $checks['api_key']['message'] );
		$this->assertSame( Healthcheck::PASS, $checks['action_scheduler']['status'] );
		$this->assertSame( Healthcheck::PASS, $checks['backlog']['status'] );
		$this->assertSame( Healthcheck::PASS, $checks['scoring']['status'] );
		$this->assertSame( [], $this->requests );
	}

	/**
	 * Test that the key and the daily prune pass once they are set up.
	 */
	public function test_checks_with_key_and_prune(): void {
		add_filter( 'wire_desk/openrouter_api_key', static fn (): string => 'sk-test' );
		( new Maintenance() )->schedule();

		$checks = Healthcheck::checks( false );

		$this->assertSame( Healthcheck::PASS, $checks['api_key']['status'] );
		$this->assertSame( Healthcheck::PASS, $checks['prune_job']['status'] );

		Api_Key::save( 'sk-or-v1-saved00000' );

		$this->assertSame( 'An OpenRouter API key is set on the settings screen.', Healthcheck::checks( false )['api_key']['message'] );
	}

	/**
	 * Test that a pause and a spent budget are warnings.
	 */
	public function test_checks_warn_when_scoring_stops(): void {
		for ( $i = 0; $i < Usage::FAILURE_LIMIT; $i++ ) {
			Usage::record_failure( 'Server error' );
		}

		$this->assertSame( Healthcheck::WARN, Healthcheck::checks( false )['scoring']['status'] );

		Usage::resume();
		update_option( Settings::OPTION_KEY, [ 'daily_budget' => 1 ] + Settings::defaults() );
		Usage::record_success( 2.0 );

		$checks = Healthcheck::checks( false );

		$this->assertSame( Healthcheck::WARN, $checks['scoring']['status'] );
		$this->assertStringContainsString( 'budget', $checks['scoring']['message'] );
	}

	/**
	 * Test that a failed job is a warning.
	 */
	public function test_checks_warn_about_failed_jobs(): void {
		$this->assertSame( Healthcheck::PASS, Healthcheck::checks( false )['failed_jobs']['status'] );

		$id = as_schedule_single_action( time() + HOUR_IN_SECONDS, Queue::HOOK, [ 42 ], Queue::GROUP );
		ActionScheduler::store()->mark_failure( $id );

		$checks = Healthcheck::checks( false );

		$this->assertSame( Healthcheck::WARN, $checks['failed_jobs']['status'] );
		$this->assertSame( '1 Wire Desk job failed. See Tools > Scheduled Actions.', $checks['failed_jobs']['message'] );
	}

	/**
	 * Test that the remote checks list the models and look for the chosen one.
	 */
	public function test_remote_checks(): void {
		$this->respond( (string) file_get_contents( dirname( __DIR__, 3 ) . '/Fixtures/models/decisions.json' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture.

		$checks = Healthcheck::checks();

		$this->assertSame( Healthcheck::PASS, $checks['endpoint']['status'] );
		$this->assertSame( Healthcheck::PASS, $checks['model']['status'] );
		$this->assertCount( 1, $this->requests );

		update_option( Settings::OPTION_KEY, [ 'model' => 'acme/retired-1' ] + Settings::defaults() );

		$this->assertSame( Healthcheck::WARN, Healthcheck::checks()['model']['status'] );
	}

	/**
	 * Test that an unreachable OpenRouter fails.
	 */
	public function test_remote_checks_fail_when_unreachable(): void {
		add_filter( 'pre_http_request', static fn () => new \WP_Error( 'http_request_failed', 'cURL error 28' ) );

		$checks = Healthcheck::checks();

		$this->assertSame( Healthcheck::FAIL, $checks['endpoint']['status'] );
		$this->assertArrayNotHasKey( 'model', $checks );
	}

	/**
	 * Test that a failed check is an error.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_run_fails_without_key(): void {
		Fake_WP_CLI::install();

		Healthcheck::run( [], [ 'skip-remote' => true ] );

		$this->assertSame( 'Wire Desk Health Check', Fake_WP_CLI::$logs[0] );
		$this->assertContains( '  api_key: ✗ FAIL  No OpenRouter API key. Save one on the Wire Desk settings screen, define WIRE_DESK_OPENROUTER_API_KEY, or connect OpenRouter.', Fake_WP_CLI::$logs );
		$this->assertSame( [ 'One or more health checks failed. Please investigate the issues above.' ], Fake_WP_CLI::$errors );
		$this->assertSame( [], Fake_WP_CLI::$successes );
	}

	/**
	 * Test that warnings still pass, and are counted.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_run_passes_with_warnings(): void {
		Fake_WP_CLI::install();
		add_filter( 'wire_desk/openrouter_api_key', static fn (): string => 'sk-test' );

		Healthcheck::run( [], [ 'skip-remote' => true ] );

		$this->assertSame( [], Fake_WP_CLI::$errors );
		$this->assertSame( [ 'Health checks passed with 1 warning.' ], Fake_WP_CLI::$successes );
	}

	/**
	 * Test a clean run.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_run_passes(): void {
		Fake_WP_CLI::install();
		add_filter( 'wire_desk/openrouter_api_key', static fn (): string => 'sk-test' );
		( new Maintenance() )->schedule();

		Healthcheck::run( [], [ 'skip-remote' => true ] );

		$this->assertSame( [ 'All health checks passed!' ], Fake_WP_CLI::$successes );
	}

	/**
	 * Responds to requests with a body.
	 *
	 * @param string $body Response body.
	 */
	private function respond( string $body ): void {
		add_filter(
			'pre_http_request',
			function ( $preempt, array $args, string $url ) use ( $body ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed, SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- Required by the filter.
				$this->requests[] = $url;

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
			3
		);
	}
}
