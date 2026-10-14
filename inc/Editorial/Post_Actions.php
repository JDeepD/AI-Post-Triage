<?php
/**
 * The actions editors take on wire posts.
 *
 * @package JDeepD\Wire_Desk
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk\Editorial;

use JDeepD\Wire_Desk\Core\Capabilities;
use JDeepD\Wire_Desk\Data\Wire_Post;
use JDeepD\Wire_Desk\Data\Wire_Post_Repository;
use JDeepD\Wire_Desk\Jobs\Queue;
use WP_Post;

/**
 * Class - Post_Actions
 */
final class Post_Actions {
	/**
	 * Actions.
	 */
	public const PUBLISH = 'publish';
	public const DISMISS = 'dismiss';
	public const RESTORE = 'restore';
	public const RESCORE = 'rescore';

	/**
	 * Wire post table.
	 *
	 * @var \JDeepD\Wire_Desk\Data\Wire_Post_Repository
	 */
	private Wire_Post_Repository $repository;

	/**
	 * Constructor.
	 *
	 * @param \JDeepD\Wire_Desk\Data\Wire_Post_Repository|null $repository Wire post table.
	 */
	public function __construct( ?Wire_Post_Repository $repository = null ) {
		$this->repository = $repository ?? new Wire_Post_Repository();
	}

	/**
	 * Action names and labels.
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return [
			self::PUBLISH => __( 'Publish', 'wire-desk' ),
			self::DISMISS => __( 'Dismiss', 'wire-desk' ),
			self::RESTORE => __( 'Restore', 'wire-desk' ),
			self::RESCORE => __( 'Re-score', 'wire-desk' ),
		];
	}

	/**
	 * The actions the current user can take on a wire post.
	 *
	 * @param \JDeepD\Wire_Desk\Data\Wire_Post $item Row.
	 * @return string[]
	 */
	public static function available( Wire_Post $item ): array {
		$post = get_post( $item->post_id );

		if ( ! $post instanceof WP_Post || ! current_user_can( Capabilities::REVIEW ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return [];
		}

		$actions = [];

		if ( Wire_Post::STATE_PUBLISHED !== $item->state && 'publish' !== $post->post_status && current_user_can( 'publish_post', $post->ID ) ) {
			$actions[] = self::PUBLISH;
		}

		if ( Wire_Post::STATE_DISMISSED === $item->state ) {
			$actions[] = self::RESTORE;
		} elseif ( Wire_Post::STATE_PUBLISHED !== $item->state ) {
			$actions[] = self::DISMISS;
		}

		$actions[] = self::RESCORE;

		return $actions;
	}

	/**
	 * Messages for the errors actions return, keyed by error code.
	 *
	 * @return array<string, string>
	 */
	public static function error_messages(): array {
		return [
			'wire_desk_unknown_action' => __( 'Unknown action.', 'wire-desk' ),
			'wire_desk_forbidden'      => __( 'Sorry, you are not allowed to do that.', 'wire-desk' ),
			'wire_desk_not_found'      => __( 'The wire post does not exist.', 'wire-desk' ),
			'wire_desk_published'      => __( 'Published posts can\'t be dismissed.', 'wire-desk' ),
			'wire_desk_not_dismissed'  => __( 'Only dismissed posts can be restored.', 'wire-desk' ),
			'wire_desk_db_error'       => __( 'The wire post could not be updated.', 'wire-desk' ),
		];
	}

	/**
	 * Runs an action on a wire post as the current user.
	 *
	 * @param string $action  One of the action constants.
	 * @param int    $post_id Post ID.
	 * @return true|\WP_Error
	 */
	public function run( string $action, int $post_id ): bool|\WP_Error {
		$result = match ( $action ) {
			self::PUBLISH => $this->publish( $post_id ),
			self::DISMISS => $this->dismiss( $post_id ),
			self::RESTORE => $this->restore( $post_id ),
			self::RESCORE => $this->rescore( $post_id ),
			default       => self::error( 'wire_desk_unknown_action', 400 ),
		};

		if ( true === $result ) {
			/**
			 * Fires after an editor publishes, dismisses, restores or re-scores a wire post.
			 *
			 * @param string $action  One of publish, dismiss, restore and rescore.
			 * @param int    $post_id Post ID.
			 * @param int    $user_id User who took the action.
			 */
			do_action( 'wire_desk/post_action', $action, $post_id, get_current_user_id() );
		}

		return $result;
	}

	/**
	 * Publishes a wire post. A post dated in the future is scheduled instead.
	 *
	 * @param int $post_id Post ID.
	 * @return true|\WP_Error
	 */
	public function publish( int $post_id ): bool|\WP_Error {
		$found = $this->find( $post_id );

		if ( is_wp_error( $found ) ) {
			return $found;
		}

		[ $post ] = $found;

		if ( ! current_user_can( 'publish_post', $post->ID ) ) {
			return self::forbidden();
		}

		if ( 'publish' !== $post->post_status ) {
			$updated = wp_update_post(
				[
					'ID'          => $post->ID,
					'post_status' => 'publish',
				],
				true
			);

			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
		}

		if ( 'publish' === get_post_status( $post->ID ) ) {
			$this->repository->set_state( $post->ID, Wire_Post::STATE_PUBLISHED );
		}

		return true;
	}

	/**
	 * Dismisses a wire post from the review queue and cancels its waiting job.
	 *
	 * @param int $post_id Post ID.
	 * @return true|\WP_Error
	 */
	public function dismiss( int $post_id ): bool|\WP_Error {
		$found = $this->find( $post_id );

		if ( is_wp_error( $found ) ) {
			return $found;
		}

		[ , $row ] = $found;

		if ( Wire_Post::STATE_PUBLISHED === $row->state ) {
			return self::error( 'wire_desk_published', 409 );
		}

		Queue::unschedule( $post_id );

		return $this->repository->set_state( $post_id, Wire_Post::STATE_DISMISSED ) ? true : self::db_error();
	}

	/**
	 * Puts a dismissed wire post back in the review queue.
	 *
	 * @param int $post_id Post ID.
	 * @return true|\WP_Error
	 */
	public function restore( int $post_id ): bool|\WP_Error {
		$found = $this->find( $post_id );

		if ( is_wp_error( $found ) ) {
			return $found;
		}

		[ , $row ] = $found;

		if ( Wire_Post::STATE_DISMISSED !== $row->state ) {
			return self::error( 'wire_desk_not_dismissed', 409 );
		}

		if ( null !== $row->scored_at ) {
			$state = Wire_Post::STATE_SCORED;
		} elseif ( '' !== $row->error ) {
			$state = Wire_Post::STATE_FAILED;
		} else {
			$state = Wire_Post::STATE_QUEUED;
		}

		return $this->repository->set_state( $post_id, $state ) ? true : self::db_error();
	}

	/**
	 * Schedules a wire post to be scored again. An unchanged post isn't sent to the model.
	 *
	 * @param int $post_id Post ID.
	 * @return true|\WP_Error
	 */
	public function rescore( int $post_id ): bool|\WP_Error {
		$found = $this->find( $post_id );

		if ( is_wp_error( $found ) ) {
			return $found;
		}

		if ( Queue::schedule( $post_id ) ) {
			$this->repository->set_attempts( $post_id, 0 );
		}

		return true;
	}

	/**
	 * Finds a wire post the current user can review.
	 *
	 * @param int $post_id Post ID.
	 * @return array{0: \WP_Post, 1: \JDeepD\Wire_Desk\Data\Wire_Post}|\WP_Error
	 */
	private function find( int $post_id ): array|\WP_Error {
		if ( ! current_user_can( Capabilities::REVIEW ) ) {
			return self::forbidden();
		}

		$post = get_post( $post_id );
		$row  = $this->repository->get( $post_id );

		if ( ! $post instanceof WP_Post || null === $row ) {
			return self::error( 'wire_desk_not_found', 404 );
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return self::forbidden();
		}

		return [ $post, $row ];
	}

	/**
	 * The error for a failed database write.
	 */
	private static function db_error(): \WP_Error {
		return self::error( 'wire_desk_db_error', 500 );
	}

	/**
	 * The error for a user who isn't allowed to act.
	 */
	private static function forbidden(): \WP_Error {
		return self::error( 'wire_desk_forbidden', 403 );
	}

	/**
	 * An action error.
	 *
	 * @param string $code   One of the codes in error_messages().
	 * @param int    $status HTTP status.
	 */
	private static function error( string $code, int $status ): \WP_Error {
		return new \WP_Error( $code, self::error_messages()[ $code ], [ 'status' => $status ] );
	}
}
