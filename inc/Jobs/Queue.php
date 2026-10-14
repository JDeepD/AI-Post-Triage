<?php
/**
 * Schedules scoring jobs with Action Scheduler.
 *
 * @package JDeepD\Wire_Desk
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk\Jobs;

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
	 * Schedules a post to be scored, unless it already is.
	 *
	 * @param int $post_id Post ID.
	 * @return bool Whether a job is now scheduled for the post.
	 */
	public static function schedule( int $post_id ): bool {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return false;
		}

		if ( self::is_scheduled( $post_id ) ) {
			return true;
		}

		return (bool) as_schedule_single_action( time() + self::DELAY, self::HOOK, [ $post_id ], self::GROUP, true );
	}

	/**
	 * Whether a post has a pending scoring job.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function is_scheduled( int $post_id ): bool {
		return function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::HOOK, [ $post_id ], self::GROUP );
	}

	/**
	 * Cancels a post's pending scoring job.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function unschedule( int $post_id ): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, [ $post_id ], self::GROUP );
		}
	}
}
