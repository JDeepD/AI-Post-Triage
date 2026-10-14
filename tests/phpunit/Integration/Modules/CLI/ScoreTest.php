<?php
/**
 * Integration tests for the Score CLI command.
 *
 * @package JDeepD\Wire_Desk\Tests\Integration\Modules\CLI
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk\Tests\Integration\Modules\CLI;

use JDeepD\Wire_Desk\Data\Wire_Post;
use JDeepD\Wire_Desk\Data\Wire_Post_Repository;
use JDeepD\Wire_Desk\Modules\CLI\Score;
use JDeepD\Wire_Desk\Modules\Settings\Settings;
use JDeepD\Wire_Desk\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Class - ScoreTest
 */
#[CoversClass( Score::class )]
class ScoreTest extends TestCase {
	/**
	 * Requests sent to the HTTP API.
	 *
	 * @var int
	 */
	private int $requests = 0;

	/**
	 * Saves questions matching the System One fixture and mocks OpenRouter.
	 */
	protected function setUp(): void {
		parent::setUp();

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
						'id'       => 'q_advisory',
						'question' => 'Is this an advisory?',
						'expected' => 'no',
						'weight'   => 'high',
						'required' => true,
					],
				],
			]
		);

		add_filter( 'wire_desk/openrouter_api_key', static fn (): string => 'sk-or-test' );
		add_filter(
			'pre_http_request',
			function () {
				++$this->requests;

				return [
					'headers'  => [],
					'body'     => (string) file_get_contents( dirname( __DIR__, 3 ) . '/Fixtures/systemone/success.json' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture.
					'response' => [
						'code'    => 200,
						'message' => 'OK',
					],
					'cookies'  => [],
					'filename' => null,
				];
			}
		);
	}

	/**
	 * Test the command name and description.
	 */
	public function test_name_and_description(): void {
		$this->assertSame( 'score', Score::get_name() );
		$this->assertNotEmpty( Score::get_description() );
	}

	/**
	 * Test that each post is scored and reported.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_run_scores_posts(): void {
		$this->mock_wp_cli();

		$first  = self::factory()->post->create();
		$second = self::factory()->post->create();

		Score::run( [ (string) $first, (string) $second ], [] );

		$this->assertSame( 2, $this->requests );
		$this->assertSame(
			[
				sprintf( 'Post %d: 98 (High) by typesafe/jev-1.13-20260917, $0.000020', $first ),
				sprintf( 'Post %d: 98 (High) by typesafe/jev-1.13-20260917, $0.000020', $second ),
			],
			Score_Mock_WP_CLI::$logs
		);
		$this->assertSame( [ 'Scored 2 posts.' ], Score_Mock_WP_CLI::$successes );
		$this->assertSame( [], Score_Mock_WP_CLI::$errors );
		$this->assertSame( Wire_Post::STATE_SCORED, ( new Wire_Post_Repository() )->get( $first )?->state );
	}

	/**
	 * Test that an unchanged post is reported as not sent, unless forced.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_run_skips_unchanged_unless_forced(): void {
		$this->mock_wp_cli();

		$post_id = self::factory()->post->create();

		Score::run( [ (string) $post_id ], [] );
		Score::run( [ (string) $post_id ], [] );

		$this->assertSame( 1, $this->requests );
		$this->assertStringEndsWith( 'unchanged, not sent', Score_Mock_WP_CLI::$logs[1] );

		Score::run( [ (string) $post_id ], [ 'force' => true ] );

		$this->assertSame( 2, $this->requests );
	}

	/**
	 * Test that bad IDs and failures are warned about and fail the command.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_run_reports_failures(): void {
		$this->mock_wp_cli();

		$post_id = self::factory()->post->create();

		Score::run( [ 'abc', (string) PHP_INT_MAX, (string) $post_id ], [] );

		$this->assertSame(
			[
				'abc is not a post ID.',
				sprintf( 'Post %d: The post does not exist.', PHP_INT_MAX ),
			],
			Score_Mock_WP_CLI::$warnings
		);
		$this->assertCount( 1, Score_Mock_WP_CLI::$logs );
		$this->assertSame( [ '2 of 3 posts could not be scored.' ], Score_Mock_WP_CLI::$errors );
	}

	/**
	 * Mocks the WP_CLI class.
	 */
	private function mock_wp_cli(): void {
		Score_Mock_WP_CLI::reset();

		if ( ! class_exists( 'WP_CLI' ) ) {
			class_alias( Score_Mock_WP_CLI::class, 'WP_CLI' );
		}
	}
}

/**
 * Mock WP_CLI that records output.
 */
class Score_Mock_WP_CLI {
	/**
	 * Logged lines.
	 *
	 * @var string[]
	 */
	public static array $logs = [];

	/**
	 * Warnings.
	 *
	 * @var string[]
	 */
	public static array $warnings = [];

	/**
	 * Errors.
	 *
	 * @var string[]
	 */
	public static array $errors = [];

	/**
	 * Success messages.
	 *
	 * @var string[]
	 */
	public static array $successes = [];

	/**
	 * Clears the recorded output.
	 */
	public static function reset(): void {
		self::$logs      = [];
		self::$warnings  = [];
		self::$errors    = [];
		self::$successes = [];
	}

	/**
	 * Records a line.
	 *
	 * @param string $message Message.
	 */
	public static function log( string $message ): void {
		self::$logs[] = $message;
	}

	/**
	 * Records a warning.
	 *
	 * @param string $message Message.
	 */
	public static function warning( string $message ): void {
		self::$warnings[] = $message;
	}

	/**
	 * Records an error.
	 *
	 * @param string $message Message.
	 */
	public static function error( string $message ): void {
		self::$errors[] = $message;
	}

	/**
	 * Records a success message.
	 *
	 * @param string $message Message.
	 */
	public static function success( string $message ): void {
		self::$successes[] = $message;
	}
}
