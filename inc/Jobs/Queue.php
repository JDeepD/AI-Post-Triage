<?php
/**
 * Schedules scoring jobs with Action Scheduler.
 *
 * @package JDeepD\Wire_Desk
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk\Jobs;

use ActionScheduler;
use ActionScheduler_Store;

/**
 * Class - Queue
 */
final class Queue {
	/**
	 * Action that scores a post.
	 */
	public const HOOK = 'wire_desk/score';

	/**
	 * Action Scheduler group.
	 */
	public const GROUP = 'wire-desk';

	/**
	 * Seconds to wait after a save before scoring.
	 */
	public const DELAY = 60;

	/**
	 * How long finished jobs are kept, in seconds.
	 */
	public const RETENTION = DAY_IN_SECONDS;

	/**
	 * Schedules a post to be scored about a minute from now, unless a job is already waiting.
	 *
	 * A running job doesn't count, so a post saved while it is being scored is scored again.
	 *
	 * @param int $post_id Post ID.
	 * @return bool Whether a new job was scheduled.
	 */
	public static function schedule( int $post_id ): bool {
		if ( self::is_scheduled( $post_id ) ) {
			return false;
		}

		return self::schedule_at( $post_id, time() + self::DELAY );
	}

	/**
	 * Schedules a post to be scored at a time.
	 *
	 * @param int $post_id   Post ID.
	 * @param int $timestamp When to score it.
	 * @return bool Whether the job was scheduled.
	 */
	public static function schedule_at( int $post_id, int $timestamp ): bool {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return false;
		}

		return (bool) as_schedule_single_action( $timestamp, self::HOOK, [ $post_id ], self::GROUP );
	}

	/**
	 * Whether a post has a job waiting to run.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function is_scheduled( int $post_id ): bool {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return false;
		}

		return (bool) as_get_scheduled_actions(
			[
				'hook'     => self::HOOK,
				'args'     => [ $post_id ],
				'group'    => self::GROUP,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
				'orderby'  => 'none',
			],
			'ids'
		);
	}

	/**
	 * Cancels a post's waiting jobs.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function unschedule( int $post_id ): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, [ $post_id ], self::GROUP );
		}
	}

	/**
	 * Number of scoring jobs running now, counting up to a limit.
	 *
	 * @param int $limit Highest count needed.
	 */
	public static function running( int $limit ): int {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return 0;
		}

		return count(
			as_get_scheduled_actions(
				[
					'hook'     => self::HOOK,
					'group'    => self::GROUP,
					'status'   => ActionScheduler_Store::STATUS_RUNNING,
					'per_page' => $limit,
					'orderby'  => 'none',
				],
				'ids'
			)
		);
	}

	/**
	 * Deletes finished and canceled scoring jobs older than RETENTION, with their logs.
	 *
	 * @param int $limit Most jobs to delete.
	 * @return int Jobs deleted.
	 */
	public static function prune( int $limit = 50 ): int {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( ActionScheduler::class ) ) {
			return 0;
		}

		$ids = as_get_scheduled_actions(
			[
				'hook'         => self::HOOK,
				'group'        => self::GROUP,
				'status'       => [ ActionScheduler_Store::STATUS_COMPLETE, ActionScheduler_Store::STATUS_CANCELED ],
				'date'         => gmdate( 'Y-m-d H:i:s', time() - self::RETENTION ),
				'date_compare' => '<=',
				'per_page'     => $limit,
				'orderby'      => 'none',
			],
			'ids'
		);

		$store = ActionScheduler::store();

		foreach ( $ids as $id ) {
			$store->delete_action( (string) $id );
		}

		return count( $ids );
	}
}
