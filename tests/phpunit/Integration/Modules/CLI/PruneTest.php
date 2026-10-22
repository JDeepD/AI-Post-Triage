<?php
/**
 * Integration tests for the Prune CLI command.
 *
 * @package JDeepD\AI_Post_Triage\Tests\Integration\Modules\CLI
 */

declare( strict_types = 1 );

namespace JDeepD\AI_Post_Triage\Tests\Integration\Modules\CLI;

use JDeepD\AI_Post_Triage\Data\Wire_Post;
use JDeepD\AI_Post_Triage\Data\Wire_Post_Repository;
use JDeepD\AI_Post_Triage\Modules\CLI\Prune;
use JDeepD\AI_Post_Triage\Tests\Fakes\Fake_WP_CLI;
use JDeepD\AI_Post_Triage\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Class - PruneTest
 */
#[CoversClass( Prune::class )]
class PruneTest extends TestCase {
	/**
	 * Test the command name and description.
	 */
	public function test_name_and_description(): void {
		$this->assertSame( 'prune', Prune::get_name() );
		$this->assertNotEmpty( Prune::get_description() );
	}

	/**
	 * Test that answers older than the retention are cleared.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_run_default_retention(): void {
		Fake_WP_CLI::install();
		$repository = $this->scored( [ 100, 10 ] );

		Prune::run( [], [] );

		$this->assertSame( [ 'Cleared answers from 1 post older than 90 days. Deleted 0 finished jobs.' ], Fake_WP_CLI::$successes );
		$this->assertNull( $repository->get( 500100 )?->details );
		$this->assertNotNull( $repository->get( 500010 )?->details );
	}

	/**
	 * Test --days.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_run_with_days(): void {
		Fake_WP_CLI::install();
		$this->scored( [ 100, 10 ] );

		Prune::run( [], [ 'days' => '5' ] );

		$this->assertSame( [ 'Cleared answers from 2 posts older than 5 days. Deleted 0 finished jobs.' ], Fake_WP_CLI::$successes );
	}

	/**
	 * Test that a retention of 0 keeps the answers.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_run_with_zero_retention(): void {
		Fake_WP_CLI::install();
		$repository = $this->scored( [ 400 ] );
		add_filter( 'ai_post_triage/details_retention_days', '__return_zero' );

		Prune::run( [], [] );

		$this->assertSame( [ 'Answers are kept, because the retention is 0 days. Deleted 0 finished jobs.' ], Fake_WP_CLI::$successes );
		$this->assertNotNull( $repository->get( 500400 )?->details );
	}

	/**
	 * Test that a bad --days is an error.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_run_rejects_bad_days(): void {
		Fake_WP_CLI::install();

		Prune::run( [], [ 'days' => '0' ] );

		$this->assertSame( [ '--days must be a positive number.' ], Fake_WP_CLI::$errors );
		$this->assertSame( [], Fake_WP_CLI::$successes );
	}

	/**
	 * Scored posts dated some days ago, with IDs 500000 + days.
	 *
	 * @param int[] $days Days ago.
	 */
	private function scored( array $days ): Wire_Post_Repository {
		$repository = new Wire_Post_Repository();

		foreach ( $days as $ago ) {
			$post_id = 500000 + $ago;

			$repository->add_queued( $post_id, (string) wp_date( 'Y-m-d H:i:s', time() - $ago * DAY_IN_SECONDS ) );
			$repository->save_score( $post_id, Wire_Post::BAND_LOW, 20, 'typesafe/jev-1.13', md5( (string) $post_id ), 0.0, [ [ 'id' => 'q1' ] ] );
		}

		return $repository;
	}
}
