<?php
/**
 * Reads and writes the wire post table.
 *
 * @package JDeepD\AI_Post_Triage
 */

declare( strict_types = 1 );

namespace JDeepD\AI_Post_Triage\Data;

use JDeepD\AI_Post_Triage\Core\Schema;

/**
 * Class - Wire_Post_Repository
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, cached in the object cache.
 * phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Queries are built from placeholders and prepared before running.
 */
final class Wire_Post_Repository {
	/**
	 * Object cache group.
	 */
	public const CACHE_GROUP = 'ai-post-triage';

	/**
	 * Maximum rows per page.
	 */
	public const MAX_PAGE_SIZE = 100;

	/**
	 * Columns selected for a Wire_Post.
	 */
	private const COLUMNS = 'post_id, post_date, state, band, score, model, HEX(hash) AS hash, cost, scored_at, error, attempts, details';

	/**
	 * Cached value for a post with no row.
	 */
	private const NO_ROW = 0;

	/**
	 * Adds a post to the table as queued, or updates its date if it is already there.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $post_date Post date.
	 * @return bool False on a database error.
	 */
	public function add_queued( int $post_id, string $post_date ): bool {
		global $wpdb;

		$result = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i ( post_id, post_date, state ) VALUES ( %d, %s, %d ) ON DUPLICATE KEY UPDATE post_date = %s',
				Schema::table_name(),
				$post_id,
				$post_date,
				Wire_Post::STATE_QUEUED,
				$post_date
			)
		);

		$this->invalidate( [ $post_id ] );

		return false !== $result;
	}

	/**
	 * Saves a score. Dismissed and published posts keep their state.
	 *
	 * @param int                      $post_id Post ID.
	 * @param int                      $band    One of the Wire_Post::BAND_* constants.
	 * @param int                      $score   Score from 0 to 100.
	 * @param string                   $model   Model ID.
	 * @param string                   $hash    Input fingerprint, as 32 hex characters.
	 * @param float                    $cost    Cost in US dollars.
	 * @param array<int|string, mixed> $details Per-question answers.
	 * @return bool False on a database error.
	 */
	public function save_score( int $post_id, int $band, int $score, string $model, string $hash, float $cost, array $details ): bool {
		global $wpdb;

		$result = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET state = IF( state IN ( %d, %d ), state, %d ), band = %d, score = %d, model = %s, hash = UNHEX( %s ), cost = %s, scored_at = %s, error = %s, attempts = 0, details = %s WHERE post_id = %d',
				Schema::table_name(),
				Wire_Post::STATE_DISMISSED,
				Wire_Post::STATE_PUBLISHED,
				Wire_Post::STATE_SCORED,
				$band,
				$score,
				$model,
				$hash,
				sprintf( '%.8F', $cost ),
				current_time( 'mysql', true ),
				'',
				(string) wp_json_encode( $details ),
				$post_id
			)
		);

		$this->invalidate( [ $post_id ] );

		return false !== $result;
	}

	/**
	 * Saves a scoring error. Dismissed and published posts keep their state.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $error   Error message.
	 * @return bool False on a database error.
	 */
	public function save_error( int $post_id, string $error ): bool {
		global $wpdb;

		$result = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET state = IF( state IN ( %d, %d ), state, %d ), error = %s WHERE post_id = %d',
				Schema::table_name(),
				Wire_Post::STATE_DISMISSED,
				Wire_Post::STATE_PUBLISHED,
				Wire_Post::STATE_FAILED,
				mb_substr( $error, 0, 255 ),
				$post_id
			)
		);

		$this->invalidate( [ $post_id ] );

		return false !== $result;
	}

	/**
	 * Records how many times in a row scoring a post has failed.
	 *
	 * @param int $post_id  Post ID.
	 * @param int $attempts Failed attempts.
	 * @return bool False on a database error.
	 */
	public function set_attempts( int $post_id, int $attempts ): bool {
		global $wpdb;

		$result = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET attempts = %d WHERE post_id = %d',
				Schema::table_name(),
				min( 255, max( 0, $attempts ) ),
				$post_id
			)
		);

		$this->invalidate( [ $post_id ] );

		return false !== $result;
	}

	/**
	 * Changes a post's state.
	 *
	 * @param int $post_id Post ID.
	 * @param int $state   One of the Wire_Post::STATE_* constants.
	 * @return bool False for an unknown state or a database error.
	 */
	public function set_state( int $post_id, int $state ): bool {
		global $wpdb;

		if ( ! in_array( $state, self::states(), true ) ) {
			return false;
		}

		$result = $wpdb->query(
			$wpdb->prepare( 'UPDATE %i SET state = %d WHERE post_id = %d', Schema::table_name(), $state, $post_id )
		);

		$this->invalidate( [ $post_id ] );

		return false !== $result;
	}

	/**
	 * Deletes a post's row.
	 *
	 * @param int $post_id Post ID.
	 * @return bool False on a database error.
	 */
	public function delete( int $post_id ): bool {
		global $wpdb;

		$result = $wpdb->query(
			$wpdb->prepare( 'DELETE FROM %i WHERE post_id = %d', Schema::table_name(), $post_id )
		);

		$this->invalidate( [ $post_id ] );

		return false !== $result;
	}

	/**
	 * Clears the per-question details of posts older than a date.
	 *
	 * @param string $before Post date to clear details before.
	 * @param int    $limit  Maximum rows to clear.
	 * @return int Rows cleared.
	 */
	public function prune_details( string $before, int $limit = 1000 ): int {
		return $this->prune_details_batch( $before, '', $limit )['cleared'];
	}

	/**
	 * Clears the per-question details of the oldest posts in a date range, and says how far it got.
	 *
	 * Rows are read in post date order from `$after`, so the next batch can start from `through`
	 * instead of reading the rows this one cleared again.
	 *
	 * @param string $before Post date to clear details before.
	 * @param string $after  Post date to start from, or an empty string for the oldest post.
	 * @param int    $limit  Maximum rows to clear.
	 * @return array{cleared: int, through: string} Rows cleared, and the post date of the last one, or `$after` when none.
	 */
	public function prune_details_batch( string $before, string $after, int $limit = 1000 ): array {
		global $wpdb;

		$rows = (array) $wpdb->get_results(
			'' === $after
				? $wpdb->prepare( 'SELECT post_id, post_date FROM %i WHERE post_date < %s AND details IS NOT NULL ORDER BY post_date LIMIT %d', Schema::table_name(), $before, max( 1, $limit ) )
				: $wpdb->prepare( 'SELECT post_id, post_date FROM %i WHERE post_date >= %s AND post_date < %s AND details IS NOT NULL ORDER BY post_date LIMIT %d', Schema::table_name(), $after, $before, max( 1, $limit ) ),
			ARRAY_A
		);

		if ( ! $rows ) {
			return [
				'cleared' => 0,
				'through' => $after,
			];
		}

		$post_ids = array_map( static fn ( $row ): int => (int) $row['post_id'], $rows );

		$result = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET details = NULL WHERE post_id IN ( ' . implode( ', ', array_fill( 0, count( $post_ids ), '%d' ) ) . ' )',
				Schema::table_name(),
				...$post_ids
			)
		);

		$this->invalidate( $post_ids );

		return [
			'cleared' => (int) $result,
			'through' => (string) $rows[ count( $rows ) - 1 ]['post_date'],
		];
	}

	/**
	 * Gets a post's row.
	 *
	 * @param int $post_id Post ID.
	 */
	public function get( int $post_id ): ?Wire_Post {
		return $this->get_many( [ $post_id ] )[ $post_id ] ?? null;
	}

	/**
	 * Gets several rows, in the order given. Posts without a row are left out.
	 *
	 * @param int[] $post_ids Post IDs.
	 * @return array<int, \JDeepD\AI_Post_Triage\Data\Wire_Post> Keyed by post ID.
	 */
	public function get_many( array $post_ids ): array {
		$post_ids = array_values( array_unique( array_filter( array_map( 'intval', $post_ids ), static fn ( int $id ): bool => $id > 0 ) ) );

		if ( ! $post_ids ) {
			return [];
		}

		$keys   = array_map( [ $this, 'row_key' ], $post_ids );
		$cached = wp_cache_get_multiple( $keys, self::CACHE_GROUP );
		$found  = [];

		foreach ( $post_ids as $index => $post_id ) {
			if ( false !== ( $cached[ $keys[ $index ] ] ?? false ) ) {
				$found[ $post_id ] = $cached[ $keys[ $index ] ];
			}
		}

		$missing = array_values( array_diff( $post_ids, array_keys( $found ) ) );

		if ( $missing ) {
			$found += $this->fetch( $missing );
		}

		$posts = [];
		foreach ( $post_ids as $post_id ) {
			if ( ( $found[ $post_id ] ?? null ) instanceof Wire_Post ) {
				$posts[ $post_id ] = $found[ $post_id ];
			}
		}

		return $posts;
	}

	/**
	 * Gets a page of rows, newest first.
	 *
	 * @param array{state?: int, band?: int}              $filters Filters.
	 * @param array{post_date: string, post_id: int}|null $cursor  Row to page from.
	 * @param int                                         $limit   Rows per page, up to MAX_PAGE_SIZE.
	 * @param bool                                        $newer   Get rows newer than the cursor instead of older.
	 * @return \JDeepD\AI_Post_Triage\Data\Wire_Post[]
	 */
	public function page( array $filters = [], ?array $cursor = null, int $limit = 20, bool $newer = false ): array {
		global $wpdb;

		$args = [
			Schema::table_name(),
			...self::filter_args( $filters ),
			null === $cursor ? 0 : 1,
			$cursor['post_date'] ?? '',
			$cursor['post_date'] ?? '',
			$cursor['post_id'] ?? 0,
			max( 1, min( self::MAX_PAGE_SIZE, $limit ) ),
		];

		$key      = 'page:' . md5( (string) wp_json_encode( [ $newer, $args ] ) ) . ':' . wp_cache_get_last_changed( self::CACHE_GROUP );
		$post_ids = wp_cache_get( $key, self::CACHE_GROUP );

		if ( ! is_array( $post_ids ) ) {
			$post_ids = array_map(
				'intval',
				$wpdb->get_col(
					$newer
						? $wpdb->prepare( 'SELECT post_id FROM %i WHERE ( %d = -1 OR state = %d ) AND ( %d = -1 OR band = %d ) AND ( %d = 0 OR post_date > %s OR ( post_date = %s AND post_id > %d ) ) ORDER BY post_date ASC, post_id ASC LIMIT %d', ...$args )
						: $wpdb->prepare( 'SELECT post_id FROM %i WHERE ( %d = -1 OR state = %d ) AND ( %d = -1 OR band = %d ) AND ( %d = 0 OR post_date < %s OR ( post_date = %s AND post_id < %d ) ) ORDER BY post_date DESC, post_id DESC LIMIT %d', ...$args )
				)
			);
			wp_cache_set( $key, $post_ids, self::CACHE_GROUP );
		}

		if ( $newer ) {
			$post_ids = array_reverse( $post_ids );
		}

		return array_values( $this->get_many( $post_ids ) );
	}

	/**
	 * Counts rows, stopping at a limit when one is given.
	 *
	 * @param array{state?: int, band?: int} $filters Filters.
	 * @param int                            $max     Highest count needed, or 0 to count every row.
	 */
	public function count( array $filters = [], int $max = 0 ): int {
		global $wpdb;

		$args  = [ Schema::table_name(), ...self::filter_args( $filters ) ];
		$key   = 'count:' . md5( (string) wp_json_encode( [ $max, $args ] ) ) . ':' . wp_cache_get_last_changed( self::CACHE_GROUP );
		$count = wp_cache_get( $key, self::CACHE_GROUP );

		if ( ! is_int( $count ) ) {
			$count = (int) $wpdb->get_var(
				$max > 0
					? $wpdb->prepare( 'SELECT COUNT(*) FROM ( SELECT 1 FROM %i WHERE ( %d = -1 OR state = %d ) AND ( %d = -1 OR band = %d ) LIMIT %d ) AS capped', ...[ ...$args, $max ] )
					: $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ( %d = -1 OR state = %d ) AND ( %d = -1 OR band = %d )', ...$args )
			);
			wp_cache_set( $key, $count, self::CACHE_GROUP );
		}

		return $count;
	}

	/**
	 * Gets the IDs of the newest posts.
	 *
	 * @param int $limit Maximum IDs.
	 * @return int[]
	 */
	public function newest_ids( int $limit ): array {
		global $wpdb;

		return array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					'SELECT post_id FROM %i ORDER BY post_date DESC, post_id DESC LIMIT %d',
					Schema::table_name(),
					max( 1, $limit )
				)
			)
		);
	}

	/**
	 * Counts posts by state and band.
	 *
	 * @param string|null $since Only posts dated on or after this.
	 * @return array<int, array{state: int, band: int, posts: int}>
	 */
	public function counts_by_state_and_band( ?string $since = null ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			null === $since
				? $wpdb->prepare( 'SELECT state, band, COUNT(*) AS posts FROM %i GROUP BY state, band', Schema::table_name() )
				: $wpdb->prepare( 'SELECT state, band, COUNT(*) AS posts FROM %i WHERE post_date >= %s GROUP BY state, band', Schema::table_name(), $since ),
			ARRAY_A
		);

		return array_map(
			static fn ( $row ): array => [
				'state' => (int) $row['state'],
				'band'  => (int) $row['band'],
				'posts' => (int) $row['posts'],
			],
			(array) $rows
		);
	}

	/**
	 * Counts scored posts and adds up their latest cost, by model.
	 *
	 * @param string|null $since Only posts dated on or after this.
	 * @return array<int, array{model: string, posts: int, cost: float}>
	 */
	public function totals_by_model( ?string $since = null ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			null === $since
				? $wpdb->prepare( 'SELECT model, COUNT(*) AS posts, SUM(cost) AS cost FROM %i WHERE scored_at IS NOT NULL GROUP BY model ORDER BY posts DESC', Schema::table_name() )
				: $wpdb->prepare( 'SELECT model, COUNT(*) AS posts, SUM(cost) AS cost FROM %i WHERE scored_at IS NOT NULL AND post_date >= %s GROUP BY model ORDER BY posts DESC', Schema::table_name(), $since ),
			ARRAY_A
		);

		return array_map(
			static fn ( $row ): array => [
				'model' => (string) $row['model'],
				'posts' => (int) $row['posts'],
				'cost'  => (float) $row['cost'],
			],
			(array) $rows
		);
	}

	/**
	 * All states.
	 *
	 * @return int[]
	 */
	public static function states(): array {
		return [
			Wire_Post::STATE_QUEUED,
			Wire_Post::STATE_SCORED,
			Wire_Post::STATE_FAILED,
			Wire_Post::STATE_DISMISSED,
			Wire_Post::STATE_PUBLISHED,
		];
	}

	/**
	 * Fetches rows from the database and caches them, including posts with no row.
	 *
	 * @param int[] $post_ids Post IDs.
	 * @return array<int, \JDeepD\AI_Post_Triage\Data\Wire_Post|int> Keyed by post ID.
	 */
	private function fetch( array $post_ids ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT ' . self::COLUMNS . ' FROM %i WHERE post_id IN ( ' . implode( ', ', array_fill( 0, count( $post_ids ), '%d' ) ) . ' )',
				Schema::table_name(),
				...$post_ids
			),
			ARRAY_A
		);

		$found = array_fill_keys( $post_ids, self::NO_ROW );

		foreach ( (array) $rows as $row ) {
			$post = Wire_Post::from_row( (array) $row );

			$found[ $post->post_id ] = $post;
		}

		$values = [];
		foreach ( $found as $post_id => $value ) {
			$values[ $this->row_key( $post_id ) ] = $value;
		}

		wp_cache_set_multiple( $values, self::CACHE_GROUP );

		return $found;
	}

	/**
	 * Arguments for the state and band conditions, with -1 for a filter that isn't set.
	 *
	 * @param array{state?: int, band?: int} $filters Filters.
	 * @return int[]
	 */
	private static function filter_args( array $filters ): array {
		$state = isset( $filters['state'] ) ? (int) $filters['state'] : -1;
		$band  = isset( $filters['band'] ) ? (int) $filters['band'] : -1;

		return [ $state, $state, $band, $band ];
	}

	/**
	 * Cache key for a row.
	 *
	 * @param int $post_id Post ID.
	 */
	private function row_key( int $post_id ): string {
		return 'post:' . $post_id;
	}

	/**
	 * Clears cached rows, pages and counts.
	 *
	 * @param int[] $post_ids Post IDs.
	 */
	private function invalidate( array $post_ids ): void {
		wp_cache_delete_multiple( array_map( [ $this, 'row_key' ], $post_ids ), self::CACHE_GROUP );
		wp_cache_set_last_changed( self::CACHE_GROUP );
	}
}
