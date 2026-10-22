<?php
/**
 * Clears old answers and finished jobs once a day.
 *
 * @package JDeepD\AI_Post_Triage
 */

declare( strict_types = 1 );

namespace JDeepD\AI_Post_Triage\Jobs;

use JDeepD\AI_Post_Triage\Data\Wire_Post_Repository;
use JDeepD\AI_Post_Triage\Framework\Contracts\Interfaces\Registrable;
use JDeepD\AI_Post_Triage\Scoring\Result;

/**
 * Class - Maintenance
 */
final class Maintenance implements Registrable {
	/**
	 * Daily action.
	 */
	public const HOOK = 'ai_post_triage/prune';

	/**
	 * Option holding the post date before which answers are already cleared.
	 */
	public const PRUNED_OPTION = 'ai_post_triage_pruned_before';

	/**
	 * Days the per-question answers are kept by default.
	 */
	public const DEFAULT_RETENTION_DAYS = 90;

	/**
	 * Rows cleared per query.
	 */
	public const BATCH_SIZE = 1000;

	/**
	 * Most queries per daily run.
	 */
	public const MAX_BATCHES = 20;

	/**
	 * Wire post table.
	 *
	 * @var \JDeepD\AI_Post_Triage\Data\Wire_Post_Repository
	 */
	private Wire_Post_Repository $repository;

	/**
	 * Constructor.
	 *
	 * @param \JDeepD\AI_Post_Triage\Data\Wire_Post_Repository|null $repository Wire post table.
	 */
	public function __construct( ?Wire_Post_Repository $repository = null ) {
		$this->repository = $repository ?? new Wire_Post_Repository();
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'admin_init', [ $this, 'schedule' ] );
		add_action( self::HOOK, [ $this, 'run' ] );
		add_action( 'ai_post_triage/scored', [ $this, 'on_scored' ] );
	}

	/**
	 * Moves the pruned mark back when a post older than it gets new answers, so they are cleared too.
	 *
	 * @param mixed $result Result.
	 */
	public function on_scored( $result ): void {
		$pruned = self::pruned_before();

		if ( ! $result instanceof Result || '' === $pruned ) {
			return;
		}

		$row = $this->repository->get( $result->post_id );

		if ( null !== $row && null !== $row->details && $row->post_date < $pruned ) {
			update_option( self::PRUNED_OPTION, $row->post_date );
		}
	}

	/**
	 * The post date before which answers are already cleared, or an empty string before the first full prune.
	 */
	public static function pruned_before(): string {
		$pruned = get_option( self::PRUNED_OPTION, '' );

		return is_string( $pruned ) ? $pruned : '';
	}

	/**
	 * Schedules the daily run, unless it already is.
	 */
	public function schedule(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || as_has_scheduled_action( self::HOOK, [], Queue::GROUP ) ) {
			return;
		}

		as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::HOOK, [], Queue::GROUP );
	}

	/**
	 * Days the per-question answers are kept, or 0 to keep them.
	 */
	public static function retention_days(): int {
		/**
		 * Filters how many days the model's per-question answers are kept. 0 keeps them.
		 *
		 * @param int $days Days, counted from the post date.
		 */
		$days = apply_filters( 'ai_post_triage/details_retention_days', self::DEFAULT_RETENTION_DAYS );

		return is_numeric( $days ) ? max( 0, (int) $days ) : self::DEFAULT_RETENTION_DAYS;
	}

	/**
	 * The daily run.
	 */
	public function run(): void {
		$this->prune();
	}

	/**
	 * Clears answers past their retention and deletes finished jobs, in a bounded number of queries.
	 *
	 * @return array{details: int, jobs: int} Rows cleared and jobs deleted.
	 */
	public function prune(): array {
		$days = self::retention_days();

		return [
			'details' => $days > 0 ? $this->prune_details( $days, self::MAX_BATCHES ) : 0,
			'jobs'    => $this->prune_jobs( self::MAX_BATCHES ),
		];
	}

	/**
	 * Clears the per-question answers of posts older than a number of days.
	 *
	 * @param int $days    Days, counted from the post date.
	 * @param int $batches Most queries, or 0 for no limit.
	 * @return int Rows cleared.
	 */
	public function prune_details( int $days, int $batches = 0 ): int {
		$before  = wp_date( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS ) ?: gmdate( 'Y-m-d H:i:s' );
		$after   = min( self::pruned_before(), $before );
		$cleared = 0;
		$run     = 0;

		do {
			$batch    = $this->repository->prune_details_batch( $before, $after, self::BATCH_SIZE );
			$cleared += $batch['cleared'];
			$after    = $batch['through'];
			++$run;

			if ( $after > self::pruned_before() ) {
				update_option( self::PRUNED_OPTION, $after );
			}
		} while ( self::BATCH_SIZE === $batch['cleared'] && ( 0 === $batches || $run < $batches ) );

		if ( self::BATCH_SIZE !== $batch['cleared'] && $before > self::pruned_before() ) {
			update_option( self::PRUNED_OPTION, $before );
		}

		return $cleared;
	}

	/**
	 * Deletes finished AI Post Triage jobs past their retention.
	 *
	 * @param int $batches Most queries, or 0 for no limit.
	 * @return int Jobs deleted.
	 */
	public function prune_jobs( int $batches = 0 ): int {
		$deleted = 0;
		$run     = 0;

		do {
			$count    = Queue::prune( self::BATCH_SIZE );
			$deleted += $count;
			++$run;
		} while ( self::BATCH_SIZE === $count && ( 0 === $batches || $run < $batches ) );

		return $deleted;
	}
}
