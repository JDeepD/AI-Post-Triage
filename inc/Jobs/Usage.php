<?php
/**
 * Tracks today's scoring spend and pauses scoring after repeated failures.
 *
 * @package JDeepD\Wire_Desk
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk\Jobs;

use JDeepD\Wire_Desk\Framework\Contracts\Interfaces\Registrable;
use JDeepD\Wire_Desk\Modules\Settings\Settings;
use JDeepD\Wire_Desk\Scoring\Result;

/**
 * Class - Usage
 *
 * @phpstan-type Values array{date: string, cost: float, calls: int, failures: int, paused_until: int, last_error: string}
 */
final class Usage implements Registrable {
	/**
	 * Option holding the usage.
	 */
	public const OPTION = 'wire_desk_usage';

	/**
	 * Failed requests in a row that pause scoring.
	 */
	public const FAILURE_LIMIT = 10;

	/**
	 * How long scoring pauses, in seconds.
	 */
	public const COOLDOWN = 15 * MINUTE_IN_SECONDS;

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'wire_desk/scored', [ $this, 'on_scored' ] );
	}

	/**
	 * Records a scored post.
	 *
	 * @param mixed $result Result.
	 */
	public function on_scored( $result ): void {
		if ( $result instanceof Result ) {
			self::record_success( $result->cost );
		}
	}

	/**
	 * Today's usage. Spend and calls start again each day, in the site's timezone.
	 *
	 * @return Values
	 */
	public static function get(): array {
		$saved = get_option( self::OPTION, [] );
		$saved = is_array( $saved ) ? $saved : [];

		$usage = [
			'date'         => is_string( $saved['date'] ?? null ) ? $saved['date'] : '',
			'cost'         => is_numeric( $saved['cost'] ?? null ) ? (float) $saved['cost'] : 0.0,
			'calls'        => is_numeric( $saved['calls'] ?? null ) ? (int) $saved['calls'] : 0,
			'failures'     => is_numeric( $saved['failures'] ?? null ) ? (int) $saved['failures'] : 0,
			'paused_until' => is_numeric( $saved['paused_until'] ?? null ) ? (int) $saved['paused_until'] : 0,
			'last_error'   => is_string( $saved['last_error'] ?? null ) ? $saved['last_error'] : '',
		];

		if ( self::today() !== $usage['date'] ) {
			$usage['date']  = self::today();
			$usage['cost']  = 0.0;
			$usage['calls'] = 0;
		}

		return $usage;
	}

	/**
	 * Adds a paid request to today's spend and closes the breaker.
	 *
	 * @param float $cost Cost in US dollars.
	 */
	public static function record_success( float $cost ): void {
		$usage = self::get();

		$usage['cost']        += max( 0.0, $cost );
		$usage['calls']       += 1;
		$usage['failures']     = 0;
		$usage['paused_until'] = 0;
		$usage['last_error']   = '';

		self::save( $usage );
	}

	/**
	 * Counts a failed request. Pauses scoring once FAILURE_LIMIT fail in a row.
	 *
	 * @param string $error Error message.
	 */
	public static function record_failure( string $error ): void {
		$usage = self::get();

		$usage['failures']  += 1;
		$usage['last_error'] = mb_substr( $error, 0, 255 );

		if ( $usage['failures'] >= self::FAILURE_LIMIT ) {
			$usage['paused_until'] = time() + self::COOLDOWN;
		}

		self::save( $usage );
	}

	/**
	 * Clears the failures and resumes scoring.
	 */
	public static function resume(): void {
		$usage = self::get();

		$usage['failures']     = 0;
		$usage['paused_until'] = 0;
		$usage['last_error']   = '';

		self::save( $usage );
	}

	/**
	 * When the pause after repeated failures ends, or 0 when scoring isn't paused.
	 */
	public static function paused_until(): int {
		$until = self::get()['paused_until'];

		return $until > time() ? $until : 0;
	}

	/**
	 * Whether today's spend has reached the daily budget.
	 */
	public static function budget_reached(): bool {
		$budget = Settings::get()['daily_budget'];

		return $budget > 0 && self::get()['cost'] >= $budget;
	}

	/**
	 * Today's date in the site's timezone.
	 */
	public static function today(): string {
		return wp_date( 'Y-m-d' ) ?: gmdate( 'Y-m-d' );
	}

	/**
	 * Start of tomorrow in the site's timezone, as a timestamp.
	 */
	public static function tomorrow(): int {
		return ( new \DateTimeImmutable( 'tomorrow', wp_timezone() ) )->getTimestamp();
	}

	/**
	 * Saves the usage.
	 *
	 * @param array<string, mixed> $usage Usage.
	 * @phpstan-param Values $usage
	 */
	private static function save( array $usage ): void {
		update_option( self::OPTION, $usage, false );
	}
}
