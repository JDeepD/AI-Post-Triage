<?php
/**
 * Adds existing wire posts to AI Post Triage, newest first, a batch at a time.
 *
 * @package JDeepD\AI_Post_Triage
 */

declare( strict_types = 1 );

namespace JDeepD\AI_Post_Triage\Jobs;

use JDeepD\AI_Post_Triage\Data\Wire_Post_Repository;
use JDeepD\AI_Post_Triage\Modules\Settings\Settings;
use WP_Post;

/**
 * Class - Backfill
 *
 * @phpstan-type Progress array{cursor: int, scanned: int, added: int, done: bool}
 */
final class Backfill {
	/**
	 * Option holding the progress, so a stopped backfill can carry on.
	 */
	public const OPTION = 'ai_post_triage_backfill';

	/**
	 * Posts read per batch by default.
	 */
	public const BATCH_SIZE = 500;

	/**
	 * Most posts read per batch.
	 */
	public const MAX_BATCH_SIZE = 2000;

	/**
	 * Wire post table.
	 *
	 * @var \JDeepD\AI_Post_Triage\Data\Wire_Post_Repository
	 */
	private Wire_Post_Repository $repository;

	/**
	 * Adds posts the way a save does.
	 *
	 * @var \JDeepD\AI_Post_Triage\Jobs\Post_Listener
	 */
	private Post_Listener $listener;

	/**
	 * Constructor.
	 *
	 * @param \JDeepD\AI_Post_Triage\Data\Wire_Post_Repository|null $repository Wire post table.
	 */
	public function __construct( ?Wire_Post_Repository $repository = null ) {
		$this->repository = $repository ?? new Wire_Post_Repository();
		$this->listener   = new Post_Listener( $this->repository );
	}

	/**
	 * The saved progress.
	 *
	 * @return Progress
	 */
	public static function progress(): array {
		$saved = get_option( self::OPTION, [] );
		$saved = is_array( $saved ) ? $saved : [];

		return [
			'cursor'  => is_numeric( $saved['cursor'] ?? null ) ? (int) $saved['cursor'] : 0,
			'scanned' => is_numeric( $saved['scanned'] ?? null ) ? (int) $saved['scanned'] : 0,
			'added'   => is_numeric( $saved['added'] ?? null ) ? (int) $saved['added'] : 0,
			'done'    => ! empty( $saved['done'] ),
		];
	}

	/**
	 * Forgets the progress, so the next batch starts from the newest post.
	 */
	public static function reset(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Post types that can hold wire posts: the wire post types, and those of the wire terms' taxonomies.
	 *
	 * @return string[]
	 */
	public static function post_types(): array {
		$settings = Settings::get();
		$types    = $settings['post_types'];

		foreach ( $settings['terms'] as $taxonomy => $term_ids ) {
			$object = $term_ids ? get_taxonomy( $taxonomy ) : false;

			if ( $object ) {
				$types = array_merge( $types, $object->object_type );
			}
		}

		return array_values( array_unique( array_filter( $types, 'post_type_exists' ) ) );
	}

	/**
	 * Reads the next batch of posts, older than the last one read, and adds the wire posts among them.
	 *
	 * Stops once a whole batch is older than the scoring window, or there are no older posts.
	 *
	 * @param int $size Posts to read, up to MAX_BATCH_SIZE.
	 * @return Progress
	 */
	public function run_batch( int $size = self::BATCH_SIZE ): array {
		$progress = self::progress();
		$types    = self::post_types();

		if ( $progress['done'] ) {
			return $progress;
		}

		$size     = max( 1, min( self::MAX_BATCH_SIZE, $size ) );
		$post_ids = $types ? $this->next_ids( $progress['cursor'], $types, $size ) : [];

		if ( ! $post_ids ) {
			$progress['done'] = true;

			return $this->save( $progress );
		}

		_prime_post_caches( $post_ids, true, false );

		$known     = $this->repository->get_many( $post_ids );
		$in_window = false;

		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );

			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$in_window = $in_window || Wire_Detector::in_window( $post );

			if ( isset( $known[ $post_id ] ) ) {
				continue;
			}

			$this->listener->sync( $post );

			if ( null !== $this->repository->get( $post_id ) ) {
				++$progress['added'];
			}
		}

		$progress['cursor']   = (int) min( $post_ids );
		$progress['scanned'] += count( $post_ids );
		$progress['done']     = count( $post_ids ) < $size || ! $in_window;

		return $this->save( $progress );
	}

	/**
	 * IDs of the next posts to read, newest first.
	 *
	 * @param int      $cursor Read posts with a lower ID than this, or 0 to start from the newest.
	 * @param string[] $types  Post types.
	 * @param int      $size   Posts to read.
	 * @return int[]
	 */
	private function next_ids( int $cursor, array $types, int $size ): array {
		global $wpdb;

		$types_sql = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		$cursor    = $cursor > 0 ? $cursor : PHP_INT_MAX;

		return array_map(
			'intval',
			$wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A one-off walk over the posts table.
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE ID < %d AND post_type IN ( {$types_sql} ) AND post_status NOT IN ( 'auto-draft', 'inherit', 'trash' ) ORDER BY ID DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The table name and placeholders are built above.
					$cursor,
					...[ ...$types, $size ]
				)
			)
		);
	}

	/**
	 * Saves the progress.
	 *
	 * @param array<string, mixed> $progress Progress.
	 * @phpstan-param Progress $progress
	 * @return Progress
	 */
	private function save( array $progress ): array {
		update_option( self::OPTION, $progress, false );

		return $progress;
	}
}
