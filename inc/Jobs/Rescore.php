<?php
/**
 * Queues the newest wire posts to be scored again.
 *
 * @package JDeepD\Wire_Desk
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk\Jobs;

use JDeepD\Wire_Desk\Data\Wire_Post_Repository;
use JDeepD\Wire_Desk\Framework\Contracts\Interfaces\Registrable;

/**
 * Class - Rescore
 */
final class Rescore implements Registrable {
	/**
	 * Action that schedules a batch of posts.
	 */
	public const BATCH_HOOK = 'wire_desk/rescore_batch';

	/**
	 * Posts per batch.
	 */
	public const BATCH_SIZE = 25;

	/**
	 * Most posts queued at once.
	 */
	public const MAX_LIMIT = 10000;

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
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( self::BATCH_HOOK, [ $this, 'run_batch' ] );
	}

	/**
	 * Queues the newest posts in the table, in batches of BATCH_SIZE.
	 *
	 * Posts unchanged since they were scored aren't sent to the model again, so they cost nothing.
	 *
	 * @param int $limit Posts to queue, up to MAX_LIMIT.
	 * @return int Posts queued.
	 */
	public function queue( int $limit ): int {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return 0;
		}

		$post_ids = $this->repository->newest_ids( max( 1, min( self::MAX_LIMIT, $limit ) ) );

		foreach ( array_chunk( $post_ids, self::BATCH_SIZE ) as $batch ) {
			as_enqueue_async_action( self::BATCH_HOOK, [ $batch ], Queue::GROUP );
		}

		return count( $post_ids );
	}

	/**
	 * Schedules each post in a batch to be scored, unless it already is.
	 *
	 * @param mixed $post_ids Post IDs.
	 */
	public function run_batch( $post_ids ): void {
		foreach ( is_array( $post_ids ) ? $post_ids : [] as $post_id ) {
			$post_id = is_numeric( $post_id ) ? (int) $post_id : 0;

			if ( $post_id > 0 && Queue::schedule( $post_id ) ) {
				$this->repository->set_attempts( $post_id, 0 );
			}
		}
	}
}
