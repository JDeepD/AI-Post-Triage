<?php
/**
 * Integration tests for the Backfill CLI command.
 *
 * @package JDeepD\Wire_Desk\Tests\Integration\Modules\CLI
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk\Tests\Integration\Modules\CLI;

use JDeepD\Wire_Desk\Jobs\Backfill as Backfill_Job;
use JDeepD\Wire_Desk\Modules\CLI\Backfill;
use JDeepD\Wire_Desk\Modules\Settings\Settings;
use JDeepD\Wire_Desk\Tests\Fakes\Fake_WP_CLI;
use JDeepD\Wire_Desk\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Class - BackfillTest
 */
#[CoversClass( Backfill::class )]
class BackfillTest extends TestCase {
	/**
	 * Test the command name and description.
	 */
	public function test_name_and_description(): void {
		$this->assertSame( 'backfill', Backfill::get_name() );
		$this->assertNotEmpty( Backfill::get_description() );
	}

	/**
	 * Test that the backfill runs to the end and reports what it added.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_run_to_completion(): void {
		Fake_WP_CLI::install();
		$this->wire_posts( 3 );

		Backfill::run( [], [ 'batch' => '2' ] );

		$this->assertSame( [], Fake_WP_CLI::$errors );
		$this->assertCount( 2, Fake_WP_CLI::$logs );
		$this->assertStringStartsWith( 'Read 2 posts, added 2.', Fake_WP_CLI::$logs[0] );
		$this->assertSame( [ 'Read 3 posts and added 3 to Wire Desk. The backfill is complete.' ], Fake_WP_CLI::$successes );
	}

	/**
	 * Test that --limit stops early, and the next run carries on and then reports it finished.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_run_with_limit_resumes(): void {
		Fake_WP_CLI::install();
		$this->wire_posts( 5 );

		Backfill::run(
			[],
			[
				'batch' => '2',
				'limit' => '2',
			]
		);

		$this->assertSame( [ 'Read 2 posts and added 2 to Wire Desk. Run the command again to carry on.' ], Fake_WP_CLI::$successes );

		Fake_WP_CLI::install();
		Backfill::run( [], [ 'batch' => '10' ] );

		$this->assertSame( [ 'Read 3 posts and added 3 to Wire Desk. The backfill is complete.' ], Fake_WP_CLI::$successes );

		Fake_WP_CLI::install();
		Backfill::run( [], [] );

		$this->assertSame( [ 'The backfill already finished after reading 5 posts. Use --restart to run it again.' ], Fake_WP_CLI::$successes );

		Fake_WP_CLI::install();
		Backfill::run( [], [ 'restart' => true ] );

		$this->assertSame( [ 'Read 5 posts and added 0 to Wire Desk. The backfill is complete.' ], Fake_WP_CLI::$successes );
	}

	/**
	 * Test that added posts wait for a re-score when automatic scoring is off.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_run_mentions_auto_score_off(): void {
		Fake_WP_CLI::install();
		$this->wire_posts( 1, [ 'auto_score' => false ] );

		Backfill::run( [], [] );

		$this->assertSame( [ 'Read 1 post and added 1 to Wire Desk. Automatic scoring is off, so they wait until you re-score them. The backfill is complete.' ], Fake_WP_CLI::$successes );
	}

	/**
	 * Test the errors.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_run_errors(): void {
		Fake_WP_CLI::install();

		Backfill::run( [], [ 'batch' => '0' ] );
		Backfill::run( [], [ 'batch' => '5000' ] );
		Backfill::run( [], [ 'limit' => '-1' ] );
		Backfill::run( [], [] );

		$this->assertSame(
			[
				'--batch must be between 1 and 2000.',
				'--batch must be between 1 and 2000.',
				'--limit must be a positive number.',
				'No wire post types or terms are set. Choose them on the Wire Desk settings screen first.',
			],
			Fake_WP_CLI::$errors
		);
		$this->assertSame( [], Fake_WP_CLI::$successes );
		$this->assertFalse( Backfill_Job::progress()['done'] );
	}

	/**
	 * Creates draft posts, then makes posts wire content.
	 *
	 * @param int                  $count    Posts.
	 * @param array<string, mixed> $settings Other settings.
	 */
	private function wire_posts( int $count, array $settings = [] ): void {
		self::factory()->post->create_many( $count, [ 'post_status' => 'draft' ] );

		update_option( Settings::OPTION_KEY, $settings + [ 'post_types' => [ 'post' ] ] + Settings::defaults() );
	}
}
