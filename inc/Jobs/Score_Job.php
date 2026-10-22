<?php
/**
 * Runs scoring jobs.
 *
 * @package JDeepD\AI_Post_Triage
 */

declare( strict_types = 1 );

namespace JDeepD\AI_Post_Triage\Jobs;

use JDeepD\AI_Post_Triage\Data\Wire_Post_Repository;
use JDeepD\AI_Post_Triage\Decisions\Decision_Client;
use JDeepD\AI_Post_Triage\Framework\Contracts\Interfaces\Registrable;
use JDeepD\AI_Post_Triage\Modules\Settings\Settings;
use JDeepD\AI_Post_Triage\Scoring\Post_Scorer;

/**
 * Class - Score_Job
 */
final class Score_Job implements Registrable {
	/**
	 * Attempts before a post is left as failed.
	 */
	public const MAX_ATTEMPTS = 5;

	/**
	 * Delay before the first retry, in seconds. It doubles with each attempt.
	 */
	public const BASE_BACKOFF = 60;

	/**
	 * Longest delay between attempts, in seconds.
	 */
	public const MAX_BACKOFF = HOUR_IN_SECONDS;

	/**
	 * Delay when too many jobs are running, in seconds.
	 */
	public const BUSY_DELAY = 30;

	/**
	 * Most random extra delay after the daily budget resets, in seconds.
	 */
	public const BUDGET_SPREAD = 15 * MINUTE_IN_SECONDS;

	/**
	 * Error codes from the decision model's API.
	 */
	public const CLIENT_ERRORS = [
		Decision_Client::ERROR_NO_KEY,
		Decision_Client::ERROR_AUTH,
		Decision_Client::ERROR_CREDITS,
		Decision_Client::ERROR_RATE_LIMITED,
		Decision_Client::ERROR_SERVER,
		Decision_Client::ERROR_BAD_REQUEST,
		Decision_Client::ERROR_INVALID_RESPONSE,
	];

	/**
	 * Error codes worth retrying.
	 */
	public const RETRYABLE = [
		Decision_Client::ERROR_RATE_LIMITED,
		Decision_Client::ERROR_SERVER,
		Decision_Client::ERROR_INVALID_RESPONSE,
	];

	/**
	 * Scores posts.
	 *
	 * @var \JDeepD\AI_Post_Triage\Scoring\Post_Scorer|null
	 */
	private ?Post_Scorer $scorer;

	/**
	 * Wire post table.
	 *
	 * @var \JDeepD\AI_Post_Triage\Data\Wire_Post_Repository
	 */
	private Wire_Post_Repository $repository;

	/**
	 * Constructor.
	 *
	 * @param \JDeepD\AI_Post_Triage\Scoring\Post_Scorer|null       $scorer     Scores posts.
	 * @param \JDeepD\AI_Post_Triage\Data\Wire_Post_Repository|null $repository Wire post table.
	 */
	public function __construct( ?Post_Scorer $scorer = null, ?Wire_Post_Repository $repository = null ) {
		$this->scorer     = $scorer;
		$this->repository = $repository ?? new Wire_Post_Repository();
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( Queue::HOOK, [ $this, 'run' ] );
		add_filter( 'action_scheduler_queue_runner_concurrent_batches', [ $this, 'concurrent_batches' ] );
	}

	/**
	 * Scores a queued post. Rate limits and server errors are retried with backoff. Waits while scoring
	 * is paused or today's budget is spent.
	 *
	 * @param mixed $post_id Post ID.
	 */
	public function run( $post_id ): void {
		$post_id = is_numeric( $post_id ) ? (int) $post_id : 0;

		if ( $post_id <= 0 ) {
			return;
		}

		Queue::prune();

		$row = $this->repository->get( $post_id );

		if ( null === $row ) {
			return;
		}

		$paused_until = Usage::paused_until();

		if ( $paused_until ) {
			$this->retry( $post_id, $paused_until - time() + wp_rand( 0, MINUTE_IN_SECONDS ) );
			return;
		}

		if ( Usage::budget_reached() ) {
			$this->retry( $post_id, Usage::tomorrow() - time() + wp_rand( 0, self::BUDGET_SPREAD ) );
			return;
		}

		$concurrency = Settings::get()['concurrency'];

		if ( Queue::running( $concurrency + 1 ) > $concurrency ) {
			$this->retry( $post_id, self::BUSY_DELAY + wp_rand( 0, self::BUSY_DELAY ) );
			return;
		}

		$result = ( $this->scorer ?? new Post_Scorer() )->score( $post_id );

		if ( ! is_wp_error( $result ) || 'ai_post_triage_no_post' === $result->get_error_code() ) {
			return;
		}

		if ( in_array( $result->get_error_code(), self::CLIENT_ERRORS, true ) ) {
			Usage::record_failure( $result->get_error_message() );
		}

		$attempts = $row->attempts + 1;

		$this->repository->set_attempts( $post_id, $attempts );

		if ( $attempts >= self::MAX_ATTEMPTS || ! in_array( $result->get_error_code(), self::RETRYABLE, true ) ) {
			return;
		}

		$data        = $result->get_error_data();
		$retry_after = is_array( $data ) && is_int( $data['retry_after'] ?? null ) ? $data['retry_after'] : null;

		$this->retry( $post_id, self::backoff( $attempts, $retry_after ) );
	}

	/**
	 * Seconds to wait before the next attempt.
	 *
	 * Uses the Retry-After delay when there is one. Otherwise doubles from BASE_BACKOFF with each
	 * attempt, with random jitter between half and the full delay.
	 *
	 * @param int      $attempt     Failed attempts so far.
	 * @param int|null $retry_after Seconds the API asked to wait.
	 */
	public static function backoff( int $attempt, ?int $retry_after = null ): int {
		if ( null !== $retry_after && $retry_after > 0 ) {
			return min( self::MAX_BACKOFF, $retry_after ) + wp_rand( 0, 10 );
		}

		$delay = (int) min( self::MAX_BACKOFF, self::BASE_BACKOFF * 2 ** ( max( 1, $attempt ) - 1 ) );

		return wp_rand( intdiv( $delay, 2 ), $delay );
	}

	/**
	 * Raises Action Scheduler's concurrent batches to at least the "Jobs at once" setting.
	 *
	 * @param mixed $batches Concurrent batches.
	 */
	public function concurrent_batches( $batches ): int {
		return max( is_numeric( $batches ) ? (int) $batches : 1, Settings::get()['concurrency'] );
	}

	/**
	 * Schedules a post again, unless a job is already waiting.
	 *
	 * @param int $post_id Post ID.
	 * @param int $delay   Seconds from now.
	 */
	private function retry( int $post_id, int $delay ): void {
		if ( ! Queue::is_scheduled( $post_id ) ) {
			Queue::schedule_at( $post_id, time() + $delay );
		}
	}
}
