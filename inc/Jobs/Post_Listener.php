<?php
/**
 * Keeps the wire post table in step with saved, published and deleted posts.
 *
 * @package JDeepD\Wire_Desk
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk\Jobs;

use JDeepD\Wire_Desk\Data\Wire_Post;
use JDeepD\Wire_Desk\Data\Wire_Post_Repository;
use JDeepD\Wire_Desk\Framework\Contracts\Interfaces\Registrable;
use JDeepD\Wire_Desk\Modules\Settings\Settings;
use WP_Post;

/**
 * Class - Post_Listener
 */
final class Post_Listener implements Registrable {
	/**
	 * Statuses that are never wire posts.
	 */
	private const IGNORED_STATUSES = [ 'auto-draft', 'inherit', 'trash' ];

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
		add_action( 'wp_after_insert_post', [ $this, 'on_save' ], 10, 2 );
		add_action( 'set_object_terms', [ $this, 'on_terms_set' ], 10, 6 );
		add_action( 'trashed_post', [ $this, 'on_remove' ] );
		add_action( 'deleted_post', [ $this, 'on_remove' ] );
	}

	/**
	 * Adds a saved wire post to the table and schedules it to be scored.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 */
	public function on_save( $post_id, $post ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed, SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- Required by the action.
		if ( $post instanceof WP_Post ) {
			$this->sync( $post );
		}
	}

	/**
	 * Re-checks a post when its wire terms change after it was saved.
	 *
	 * @param int                    $object_id  Object ID.
	 * @param array<int|string>      $terms      Terms passed in.
	 * @param array<int, int|string> $tt_ids     New term taxonomy IDs.
	 * @param string                 $taxonomy   Taxonomy.
	 * @param bool                   $append     Whether the terms were appended.
	 * @param array<int, int|string> $old_tt_ids Previous term taxonomy IDs.
	 */
	public function on_terms_set( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed, SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- Required by the action.
		if ( ! array_key_exists( (string) $taxonomy, Settings::get()['terms'] ) ) {
			return;
		}

		$new = array_map( 'intval', (array) $tt_ids );
		$old = array_map( 'intval', (array) $old_tt_ids );

		sort( $new );
		sort( $old );

		if ( $new === $old ) {
			return;
		}

		$post = get_post( (int) $object_id );

		if ( $post instanceof WP_Post ) {
			$this->sync( $post );
		}
	}

	/**
	 * Removes a trashed or deleted post from the table.
	 *
	 * @param int $post_id Post ID.
	 */
	public function on_remove( $post_id ): void {
		$post_id = (int) $post_id;

		Queue::unschedule( $post_id );
		$this->repository->delete( $post_id );
	}

	/**
	 * Brings a post's row in step with the post.
	 *
	 * Wire posts inside the scoring window are added and scheduled. Posts already in the table keep
	 * their row, and their state follows whether the post is published.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function sync( WP_Post $post ): void {
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) || in_array( $post->post_status, self::IGNORED_STATUSES, true ) ) {
			return;
		}

		$row = $this->repository->get( $post->ID );

		if ( Wire_Detector::is_wire( $post ) && Wire_Detector::in_window( $post ) ) {
			$this->repository->add_queued( $post->ID, $post->post_date );
			$row = $this->repository->get( $post->ID );

			if ( Settings::get()['auto_score'] ) {
				Queue::schedule( $post->ID );
			}
		}

		if ( $row ) {
			$this->sync_state( $row, $post );
		}
	}

	/**
	 * Marks a row published when its post is published, and back when it is unpublished.
	 *
	 * @param \JDeepD\Wire_Desk\Data\Wire_Post $row  The post's row.
	 * @param \WP_Post                         $post Post.
	 */
	private function sync_state( Wire_Post $row, WP_Post $post ): void {
		$published = 'publish' === $post->post_status;

		if ( $published && Wire_Post::STATE_PUBLISHED !== $row->state ) {
			$this->repository->set_state( $post->ID, Wire_Post::STATE_PUBLISHED );
		} elseif ( ! $published && Wire_Post::STATE_PUBLISHED === $row->state ) {
			$this->repository->set_state( $post->ID, null !== $row->scored_at ? Wire_Post::STATE_SCORED : Wire_Post::STATE_QUEUED );
		}
	}
}
