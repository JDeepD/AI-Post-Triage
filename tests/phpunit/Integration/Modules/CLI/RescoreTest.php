<?php
/**
 * Integration tests for the Rescore CLI command.
 *
 * @package JDeepD\Wire_Desk\Tests\Integration\Modules\CLI
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk\Tests\Integration\Modules\CLI;

use JDeepD\Wire_Desk\Data\Wire_Post_Repository;
use JDeepD\Wire_Desk\Modules\CLI\Rescore;
use JDeepD\Wire_Desk\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Class - RescoreTest
 */
#[CoversClass( Rescore::class )]
class RescoreTest extends TestCase {
	/**
	 * Test the command name and description.
	 */
	public function test_name_and_description(): void {
		$this->assertSame( 'rescore', Rescore::get_name() );
		$this->assertNotEmpty( Rescore::get_description() );
	}

	/**
	 * Test that posts are queued and reported.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_run_queues_posts(): void {
		$this->mock_wp_cli();

		$repository = new Wire_Post_Repository();

		foreach ( self::factory()->post->create_many( 30 ) as $post_id ) {
			$repository->add_queued( $post_id, '2026-10-14 00:00:00' );
		}

		Rescore::run( [], [ 'limit' => '27' ] );

		$this->assertSame( [ 'Queued 27 posts in 2 batches.' ], Rescore_Mock_WP_CLI::$successes );
	}

	/**
	 * Test that one post is reported in the singular.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_run_reports_singular(): void {
		$this->mock_wp_cli();

		( new Wire_Post_Repository() )->add_queued( self::factory()->post->create(), '2026-10-14 00:00:00' );

		Rescore::run( [], [ 'limit' => '1' ] );

		$this->assertSame( [ 'Queued 1 post in 1 batch.' ], Rescore_Mock_WP_CLI::$successes );
	}

	/**
	 * Test that a bad limit is an error.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_run_rejects_bad_limit(): void {
		$this->mock_wp_cli();

		Rescore::run( [], [ 'limit' => '0' ] );

		$this->assertSame( [ '--limit must be between 1 and 10000.' ], Rescore_Mock_WP_CLI::$errors );
		$this->assertSame( [], Rescore_Mock_WP_CLI::$successes );
	}

	/**
	 * Mocks the WP_CLI class.
	 */
	private function mock_wp_cli(): void {
		Rescore_Mock_WP_CLI::reset();

		if ( ! class_exists( 'WP_CLI' ) ) {
			class_alias( Rescore_Mock_WP_CLI::class, 'WP_CLI' );
		}
	}
}

/**
 * Mock WP_CLI that records output.
 */
class Rescore_Mock_WP_CLI {
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
		self::$errors    = [];
		self::$successes = [];
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
