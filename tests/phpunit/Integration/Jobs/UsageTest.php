<?php
/**
 * Integration tests for Usage.
 *
 * @package JDeepD\AI_Post_Triage\Tests\Integration\Jobs
 */

declare( strict_types = 1 );

namespace JDeepD\AI_Post_Triage\Tests\Integration\Jobs;

use JDeepD\AI_Post_Triage\Jobs\Usage;
use JDeepD\AI_Post_Triage\Modules\Settings\Settings;
use JDeepD\AI_Post_Triage\Scoring\Result;
use JDeepD\AI_Post_Triage\Scoring\Score;
use JDeepD\AI_Post_Triage\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Class - UsageTest
 */
#[CoversClass( Usage::class )]
class UsageTest extends TestCase {
	/**
	 * Test that usage starts empty.
	 */
	public function test_get_defaults(): void {
		$this->assertSame(
			[
				'date'         => Usage::today(),
				'cost'         => 0.0,
				'calls'        => 0,
				'failures'     => 0,
				'paused_until' => 0,
				'last_error'   => '',
			],
			Usage::get()
		);
	}

	/**
	 * Test that spend and calls start again on a new day, and failures carry over.
	 */
	public function test_new_day_resets_spend(): void {
		update_option(
			Usage::OPTION,
			[
				'date'         => '2000-01-01',
				'cost'         => 3.5,
				'calls'        => 12,
				'failures'     => 2,
				'paused_until' => 0,
				'last_error'   => 'Server error',
			]
		);

		$usage = Usage::get();

		$this->assertSame( Usage::today(), $usage['date'] );
		$this->assertSame( 0.0, $usage['cost'] );
		$this->assertSame( 0, $usage['calls'] );
		$this->assertSame( 2, $usage['failures'] );
		$this->assertSame( 'Server error', $usage['last_error'] );
	}

	/**
	 * Test that a paid request adds to today's spend and clears failures.
	 */
	public function test_record_success(): void {
		Usage::record_failure( 'Server error' );
		Usage::record_success( 0.25 );
		Usage::record_success( 0.5 );
		Usage::record_success( -1.0 );

		$usage = Usage::get();

		$this->assertSame( 0.75, $usage['cost'] );
		$this->assertSame( 3, $usage['calls'] );
		$this->assertSame( 0, $usage['failures'] );
		$this->assertSame( '', $usage['last_error'] );
	}

	/**
	 * Test that scored posts are recorded.
	 */
	public function test_scored_action_records_cost(): void {
		do_action( 'ai_post_triage/scored', new Result( 1, new Score( 80, 3, [] ), 'typesafe/jev-1.13', 0.00002, false ) );
		do_action( 'ai_post_triage/scored', 'not a result' );

		$this->assertSame( 0.00002, Usage::get()['cost'] );
		$this->assertSame( 1, Usage::get()['calls'] );
	}

	/**
	 * Test that scoring pauses after FAILURE_LIMIT failures in a row.
	 */
	public function test_failures_pause_scoring(): void {
		for ( $i = 1; $i < Usage::FAILURE_LIMIT; $i++ ) {
			Usage::record_failure( 'Server error' );
		}

		$this->assertSame( 0, Usage::paused_until() );

		Usage::record_failure( str_repeat( 'x', 300 ) );

		$this->assertEqualsWithDelta( time() + Usage::COOLDOWN, Usage::paused_until(), 2 );
		$this->assertSame( Usage::FAILURE_LIMIT, Usage::get()['failures'] );
		$this->assertSame( 255, mb_strlen( Usage::get()['last_error'] ) );
	}

	/**
	 * Test that a success between failures starts the count again.
	 */
	public function test_success_breaks_failure_streak(): void {
		for ( $i = 1; $i < Usage::FAILURE_LIMIT; $i++ ) {
			Usage::record_failure( 'Server error' );
		}

		Usage::record_success( 0.0 );
		Usage::record_failure( 'Server error' );

		$this->assertSame( 1, Usage::get()['failures'] );
		$this->assertSame( 0, Usage::paused_until() );
	}

	/**
	 * Test that the pause ends after the cooldown, and one more failure pauses again.
	 */
	public function test_pause_ends_and_reopens(): void {
		update_option(
			Usage::OPTION,
			[
				'date'         => Usage::today(),
				'failures'     => Usage::FAILURE_LIMIT,
				'paused_until' => time() - 1,
			]
		);

		$this->assertSame( 0, Usage::paused_until() );

		Usage::record_failure( 'Server error' );

		$this->assertGreaterThan( time(), Usage::paused_until() );
	}

	/**
	 * Test that resuming clears the pause.
	 */
	public function test_resume(): void {
		for ( $i = 0; $i < Usage::FAILURE_LIMIT; $i++ ) {
			Usage::record_failure( 'Server error' );
		}

		Usage::resume();

		$this->assertSame( 0, Usage::paused_until() );
		$this->assertSame( 0, Usage::get()['failures'] );
		$this->assertSame( '', Usage::get()['last_error'] );
	}

	/**
	 * Test when the budget is reached.
	 *
	 * @param float $budget   Daily budget.
	 * @param float $spent    Spent today.
	 * @param bool  $expected Whether it is reached.
	 */
	#[DataProvider( 'data_budget' )]
	public function test_budget_reached( float $budget, float $spent, bool $expected ): void {
		update_option( Settings::OPTION_KEY, [ 'daily_budget' => $budget ] );
		Usage::record_success( $spent );

		$this->assertSame( $expected, Usage::budget_reached() );
	}

	/**
	 * Budgets and spend.
	 *
	 * @return array<string, array{float, float, bool}>
	 */
	public static function data_budget(): array {
		return [
			'no limit'     => [ 0.0, 100.0, false ],
			'under'        => [ 1.0, 0.99, false ],
			'exactly'      => [ 1.0, 1.0, true ],
			'over'         => [ 1.0, 1.01, true ],
			'nothing used' => [ 5.0, 0.0, false ],
		];
	}

	/**
	 * Test that yesterday's spend doesn't count against today's budget.
	 */
	public function test_budget_resets_next_day(): void {
		update_option( Settings::OPTION_KEY, [ 'daily_budget' => 1.0 ] );
		update_option(
			Usage::OPTION,
			[
				'date' => '2000-01-01',
				'cost' => 5.0,
			]
		);

		$this->assertFalse( Usage::budget_reached() );
	}

	/**
	 * Test that days follow the site's timezone.
	 */
	public function test_days_use_site_timezone(): void {
		update_option( 'timezone_string', 'Pacific/Kiritimati' );

		$tomorrow = Usage::tomorrow();
		$local    = new \DateTimeImmutable( '@' . $tomorrow );
		$local    = $local->setTimezone( wp_timezone() );

		$this->assertSame( '00:00:00', $local->format( 'H:i:s' ) );
		$this->assertGreaterThan( time(), $tomorrow );
		$this->assertLessThanOrEqual( time() + DAY_IN_SECONDS, $tomorrow );
		$this->assertSame( wp_date( 'Y-m-d' ), Usage::today() );
	}

	/**
	 * Test that usage isn't autoloaded.
	 */
	public function test_usage_is_not_autoloaded(): void {
		Usage::record_success( 0.1 );
		wp_cache_delete( 'alloptions', 'options' );

		$this->assertArrayNotHasKey( Usage::OPTION, wp_load_alloptions() );
	}
}
