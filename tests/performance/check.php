<?php
/**
 * Seeds about a million wire posts into separate tables and checks that Wire Desk's queries hold up.
 *
 * Run it with `npm run perf -- <command>` from the plugin directory:
 *
 *   seed [rows]  Fill the wdperf_ tables with this many posts, wire rows and postmeta rows. Default 1,000,000.
 *   check        Explain and time every query that has to scale, and fail if one misses its index or target.
 *   drop         Drop the wdperf_ tables.
 *
 * Only the Wire Desk table, the posts table and the postmeta table are redirected to the wdperf_ tables.
 * Options, users and Action Scheduler stay on the site's own tables, so the test suite is not affected.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.ValidPostTypeSlug.NotStringLiteral -- A dev-only tool that builds its own tables from integers and fixed strings.
 *
 * @package JDeepD\Wire_Desk\Tests\Performance
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk\Tests\Performance;

use JDeepD\Wire_Desk\Core\Schema;
use JDeepD\Wire_Desk\Data\Wire_Post;
use JDeepD\Wire_Desk\Data\Wire_Post_Repository;
use JDeepD\Wire_Desk\Jobs\Backfill;
use JDeepD\Wire_Desk\Jobs\Maintenance;
use JDeepD\Wire_Desk\Jobs\Post_Listener;
use JDeepD\Wire_Desk\Jobs\Queue;
use JDeepD\Wire_Desk\Modules\Queue\Queue_List_Table;
use JDeepD\Wire_Desk\Modules\Queue\Queue_Screen;
use JDeepD\Wire_Desk\Modules\Settings\Settings;
use ReflectionMethod;
use WP_CLI;
use WP_REST_Request;
use function WP_CLI\Utils\format_items;

/**
 * Class - Perf_Check
 */
final class Perf_Check {
	/**
	 * Prefix of the tables the check uses.
	 */
	public const PREFIX = 'wdperf_';

	/**
	 * Post type of the seeded wire posts.
	 */
	public const POST_TYPE = 'wdperf_item';

	/**
	 * Post type of the seeded posts that aren't wire posts.
	 */
	public const OTHER_TYPE = 'wdperf_other';

	/**
	 * Rows per INSERT while seeding.
	 */
	private const INSERT_BATCH = 2000;

	/**
	 * Queries seen while a scenario runs.
	 *
	 * @var string[]
	 */
	private array $queries = [];

	/**
	 * Failures found by the check.
	 *
	 * @var string[]
	 */
	private array $failures = [];

	/**
	 * Runs a command.
	 *
	 * @param array<int, string> $args Command and its arguments.
	 */
	public static function main( array $args ): void {
		$command = $args[0] ?? 'check';
		$check   = new self();

		match ( $command ) {
			'seed'  => $check->seed( isset( $args[1] ) ? max( 1, (int) str_replace( [ ',', '_' ], '', $args[1] ) ) : 1000000 ),
			'check' => $check->check(),
			'drop'  => $check->drop(),
			default => WP_CLI::error( sprintf( 'Unknown command "%s". Use seed, check or drop.', $command ) ),
		};
	}

	/**
	 * Points Wire Desk's table, posts and postmeta at the wdperf_ tables.
	 */
	private function redirect(): void {
		global $wpdb;

		$wpdb->prefix   = self::PREFIX;
		$wpdb->posts    = self::PREFIX . 'posts';
		$wpdb->postmeta = self::PREFIX . 'postmeta';

		wp_cache_flush();
	}

	/**
	 * Creates the tables and fills them up to a number of posts.
	 *
	 * @param int $rows Posts wanted.
	 */
	private function seed( int $rows ): void {
		global $wpdb;

		$base = $wpdb->base_prefix;

		$wpdb->query( 'CREATE TABLE IF NOT EXISTS ' . self::PREFIX . "posts LIKE {$base}posts" );
		$wpdb->query( 'CREATE TABLE IF NOT EXISTS ' . self::PREFIX . "postmeta LIKE {$base}postmeta" );

		$this->redirect();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( Schema::get_table_sql() );

		$existing = (int) $wpdb->get_var( 'SELECT COALESCE( MAX( ID ), 0 ) FROM ' . $wpdb->posts );

		if ( $existing >= $rows ) {
			WP_CLI::success( sprintf( 'Already seeded with %s posts.', number_format( $existing ) ) );
			return;
		}

		$started = microtime( true );
		$now     = time();

		for ( $from = $existing + 1; $from <= $rows; $from += self::INSERT_BATCH ) {
			$to    = min( $rows, $from + self::INSERT_BATCH - 1 );
			$posts = [];
			$meta  = [];
			$wire  = [];

			for ( $id = $from; $id <= $to; $id++ ) {
				$date   = gmdate( 'Y-m-d H:i:s', $now - ( $rows - $id ) * 30 );
				$type   = 0 === $id % 10 ? self::OTHER_TYPE : self::POST_TYPE;
				$status = $id % 5 < 2 ? 'publish' : 'draft';

				$posts[] = sprintf( "(%d, 1, '%s', '%s', '', 'Wire post %d', '', '%s', 'wire-post-%d', '', '', '%s', '%s', '', '%s')", $id, $date, $date, $id, $status, $id, $date, $date, $type );
				$meta[]  = sprintf( "(%d, '_wdperf_source', 'agency-%d')", $id, $id % 20 );

				if ( self::POST_TYPE === $type ) {
					$wire[] = $this->wire_row( $id, $date, 'publish' === $status );
				}
			}

			$wpdb->query( "INSERT INTO {$wpdb->posts} ( ID, post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, post_type ) VALUES " . implode( ',', $posts ) );
			$wpdb->query( "INSERT INTO {$wpdb->postmeta} ( post_id, meta_key, meta_value ) VALUES " . implode( ',', $meta ) );
			$wpdb->query( 'INSERT INTO ' . Schema::table_name() . ' ( post_id, post_date, state, band, score, model, cost, scored_at, details ) VALUES ' . implode( ',', $wire ) );

			if ( 0 === $to % 100000 || $to === $rows ) {
				WP_CLI::log( sprintf( '%s posts seeded, %.0fs.', number_format( $to ), microtime( true ) - $started ) );
			}
		}

		$wpdb->query( 'ANALYZE TABLE ' . Schema::table_name() . ", {$wpdb->posts}, {$wpdb->postmeta}" );

		WP_CLI::success( sprintf( 'Seeded %s posts in %.0fs.', number_format( $rows - $existing ), microtime( true ) - $started ) );
	}

	/**
	 * One Wire Desk row for a seeded post: mostly dismissed or published, a third waiting for review.
	 *
	 * @param int    $id        Post ID.
	 * @param string $date      Post date.
	 * @param bool   $published Whether the post is published.
	 */
	private function wire_row( int $id, string $date, bool $published ): string {
		$slot = $id % 100;

		if ( $published ) {
			$state = Wire_Post::STATE_PUBLISHED;
		} elseif ( $slot < 5 ) {
			$state = Wire_Post::STATE_QUEUED;
		} elseif ( $slot < 7 ) {
			$state = Wire_Post::STATE_FAILED;
		} elseif ( $slot < 50 ) {
			$state = Wire_Post::STATE_DISMISSED;
		} else {
			$state = Wire_Post::STATE_SCORED;
		}

		if ( in_array( $state, [ Wire_Post::STATE_QUEUED, Wire_Post::STATE_FAILED ], true ) ) {
			return sprintf( "(%d, '%s', %d, 0, 0, '', 0, NULL, NULL)", $id, $date, $state );
		}

		$pick  = ( $id * 7 ) % 10;
		$band  = $pick < 2 ? Wire_Post::BAND_HIGH : ( $pick < 5 ? Wire_Post::BAND_MEDIUM : Wire_Post::BAND_LOW );
		$score = [
			Wire_Post::BAND_HIGH   => 85,
			Wire_Post::BAND_MEDIUM => 55,
			Wire_Post::BAND_LOW    => 20,
		][ $band ];

		return sprintf(
			"(%d, '%s', %d, %d, %d, 'typesafe/jev-1.13', 0.0011, '%s', %s)",
			$id,
			$date,
			$state,
			$band,
			$score,
			$date,
			0 === $id % 10 ? "'[{\"id\":\"q_breaking\",\"yes\":0.9,\"met\":true}]'" : 'NULL'
		);
	}

	/**
	 * Drops the wdperf_ tables.
	 */
	private function drop(): void {
		global $wpdb;

		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::PREFIX . 'posts, ' . self::PREFIX . 'postmeta, ' . self::PREFIX . Schema::TABLE );

		WP_CLI::success( 'Dropped the wdperf_ tables.' );
	}

	/**
	 * Explains and times the queries that have to scale.
	 */
	private function check(): void {
		global $wpdb;

		$this->redirect();

		$rows = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table_name() );

		if ( ! $rows ) {
			WP_CLI::error( 'Nothing to check. Run `npm run perf -- seed` first.' );
		}

		$this->set_up();

		$pruned = get_option( Maintenance::PRUNED_OPTION );

		WP_CLI::log( sprintf( 'Checking against %s wire posts and %s posts, with a cold and a warm object cache.', number_format( $rows ), number_format( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ) ) ) );

		$repository = new Wire_Post_Repository();
		$newest     = $repository->page( [ 'state' => Wire_Post::STATE_SCORED ], null, 1 )[0] ?? null;
		$older      = $repository->get( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT post_id FROM %i WHERE state = %d ORDER BY post_date ASC LIMIT 1 OFFSET 1000', Schema::table_name(), Wire_Post::STATE_DISMISSED ) ) );
		$cursor     = static fn ( ?Wire_Post $row ): ?array => $row ? [
			'post_date' => $row->post_date,
			'post_id'   => $row->post_id,
		] : null;
		$page_ids   = array_map( static fn ( Wire_Post $row ): int => $row->post_id, $repository->page( [], null, 20 ) );

		$results = [
			$this->scenario( 'Queue: Needs review, newest 20', static fn () => $repository->page( [ 'state' => Wire_Post::STATE_SCORED ], null, 20 ), 50 ),
			$this->scenario(
				'Queue: Needs review, High only',
				static fn () => $repository->page(
					[
						'state' => Wire_Post::STATE_SCORED,
						'band'  => Wire_Post::BAND_HIGH,
					],
					null,
					20
				),
				50
			),
			$this->scenario( 'Queue: older page from a cursor', static fn () => $repository->page( [ 'state' => Wire_Post::STATE_SCORED ], $cursor( $newest ), 20 ), 50 ),
			$this->scenario( 'Queue: newer page from a cursor', static fn () => $repository->page( [ 'state' => Wire_Post::STATE_DISMISSED ], $cursor( $older ), 20, true ), 50 ),
			$this->scenario( 'Queue: All, newest 20', static fn () => $repository->page( [], null, 20 ), 50 ),
			$this->scenario( 'Queue: All, High only', static fn () => $repository->page( [ 'band' => Wire_Post::BAND_HIGH ], null, 20 ), 50 ),
			$this->scenario(
				'Menu badge (capped count)',
				static fn () => $repository->count(
					[
						'state' => Wire_Post::STATE_SCORED,
						'band'  => Wire_Post::BAND_HIGH,
					],
					Queue_Screen::BADGE_MAX + 1
				),
				50
			),
			$this->scenario( 'View counts (6 capped counts)', fn () => $this->view_counts( $repository ), 100 ),
			$this->scenario( 'Re-score: newest 1,000 IDs', static fn () => $repository->newest_ids( 1000 ), 50 ),
			$this->scenario( 'Post list: status of 20 posts', static fn () => $repository->get_many( $page_ids ), 20 ),
			$this->scenario( 'Backfill: next batch of 500', fn () => $this->backfill_batch(), 100 ),
			$this->scenario( 'Prune: first run over all history', fn () => $this->first_prune(), 0, 0, true ),
			$this->scenario( 'Prune: a daily run after that', static fn () => ( new Maintenance() )->prune(), 100 ),
			$this->scenario( 'REST: GET /posts?state=scored', fn () => $this->rest_list(), 150 ),
			$this->scenario( 'Queue screen: prepare and render', fn () => $this->render_queue( $repository ), 250, 100 ),
			$this->scenario( 'Stats: counts by state and band', static fn () => $repository->counts_by_state_and_band(), 0, 0, true ),
			$this->scenario( 'Stats: totals by model', static fn () => $repository->totals_by_model(), 0, 0, true ),
		];

		$results[] = $this->save_hook( 'Save hook: post that is not wire content', self::OTHER_TYPE );
		$results[] = $this->save_hook( 'Save hook: wire post already in Wire Desk', self::POST_TYPE );

		format_items( 'table', $results, [ 'check', 'cold_ms', 'warm_ms', 'target_ms', 'indexes', 'result' ] );

		if ( false === $pruned ) {
			delete_option( Maintenance::PRUNED_OPTION );
		} else {
			update_option( Maintenance::PRUNED_OPTION, $pruned );
		}

		if ( $this->failures ) {
			foreach ( $this->failures as $failure ) {
				WP_CLI::warning( $failure );
			}

			WP_CLI::error( sprintf( '%d %s failed.', count( $this->failures ), 1 === count( $this->failures ) ? 'check' : 'checks' ) );
		}

		WP_CLI::success( 'Every query used an index and met its target.' );
	}

	/**
	 * Sets up an administrator, the wire post type and settings that only match it.
	 */
	private function set_up(): void {
		require_once ABSPATH . 'wp-admin/includes/admin.php';

		register_post_type(
			self::POST_TYPE,
			[
				'label'   => 'Perf wire posts',
				'show_ui' => true,
			]
		);
		register_post_type( self::OTHER_TYPE, [ 'label' => 'Perf other posts' ] );

		$settings = array_merge(
			Settings::defaults(),
			[
				'post_types'  => [ self::POST_TYPE ],
				'auto_score'  => false,
				'window_days' => 0,
			]
		);

		add_filter( 'pre_option_' . Settings::OPTION_KEY, static fn (): array => $settings );

		$_SERVER['HTTP_HOST']   = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=' . Queue_Screen::SCREEN_ID;

		$admins = get_users(
			[
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ID',
			]
		);

		wp_set_current_user( (int) ( $admins[0] ?? 1 ) );
		set_current_screen( Queue_Screen::HOOK_SUFFIX );
	}

	/**
	 * Runs a scenario with a cold and a warm cache, and explains its queries.
	 *
	 * @param string   $name          Name.
	 * @param callable $run           What to run.
	 * @param int      $cold_target   Most milliseconds with a cold cache, or 0 to only report.
	 * @param int      $warm_target   Most milliseconds with a warm cache, or 0 to only report.
	 * @param bool     $may_scan      Whether reading the whole table or index is expected.
	 * @return array<string, string>
	 */
	private function scenario( string $name, callable $run, int $cold_target, int $warm_target = 0, bool $may_scan = false ): array {
		wp_cache_flush();

		$this->queries = [];
		add_filter( 'query', [ $this, 'capture' ] );

		$started = microtime( true );
		$run();
		$cold = ( microtime( true ) - $started ) * 1000;

		remove_filter( 'query', [ $this, 'capture' ] );

		$queries = $this->queries;
		$started = microtime( true );
		$run();
		$warm = ( microtime( true ) - $started ) * 1000;

		$plans  = array_map( [ $this, 'explain' ], array_values( array_unique( $queries ) ) );
		$plans  = array_merge( ...$plans );
		$issues = $may_scan ? [] : $this->plan_issues( $plans );

		if ( $cold_target && $cold > $cold_target ) {
			$issues[] = sprintf( '%.1f ms cold, over %d ms', $cold, $cold_target );
		}

		if ( $warm_target && $warm > $warm_target ) {
			$issues[] = sprintf( '%.1f ms warm, over %d ms', $warm, $warm_target );
		}

		foreach ( $issues as $issue ) {
			$this->failures[] = $name . ': ' . $issue;
		}

		return [
			'check'     => $name,
			'cold_ms'   => number_format( $cold, 1 ),
			'warm_ms'   => number_format( $warm, 1 ),
			'target_ms' => $warm_target ? $cold_target . ' / ' . $warm_target . ' warm' : ( $cold_target ? (string) $cold_target : 'report' ),
			'indexes'   => implode( ', ', array_unique( array_map( static fn ( array $plan ): string => $plan['table'] . ':' . ( $plan['key'] ?: 'none' ) . ( '' !== $plan['extra'] ? ' (' . $plan['extra'] . ')' : '' ), $plans ) ) ) ?: '-',
			'result'    => $issues ? 'FAIL' : 'ok',
		];
	}

	/**
	 * Records a query on the wdperf_ tables.
	 *
	 * @param mixed $query Query.
	 * @return mixed
	 */
	public function capture( $query ) {
		if ( is_string( $query ) && 0 === stripos( ltrim( $query ), 'SELECT' ) && false !== strpos( $query, self::PREFIX ) ) {
			$this->queries[] = $query;
		}

		return $query;
	}

	/**
	 * The plan of a query, for the wdperf_ tables.
	 *
	 * @param string $query Query.
	 * @return array<int, array{table: string, type: string, key: string, rows: int, extra: string}>
	 */
	private function explain( string $query ): array {
		global $wpdb;

		$plans = [];

		foreach ( (array) $wpdb->get_results( 'EXPLAIN ' . $query, ARRAY_A ) as $row ) {
			$row   = (array) $row;
			$table = (string) ( $row['table'] ?? '' );

			if ( 0 !== strpos( $table, self::PREFIX ) ) {
				continue;
			}

			$extra = array_values(
				array_filter(
					array_map( 'trim', explode( ';', (string) ( $row['Extra'] ?? '' ) ) ),
					static fn ( string $part ): bool => in_array( $part, [ 'Using filesort', 'Using temporary', 'Using index', 'Backward index scan' ], true )
				)
			);

			$plans[] = [
				'table' => substr( $table, strlen( self::PREFIX ) ),
				'type'  => (string) ( $row['type'] ?? '' ),
				'key'   => (string) ( $row['key'] ?? '' ),
				'rows'  => (int) ( $row['rows'] ?? 0 ),
				'extra' => implode( ', ', $extra ),
			];
		}

		return $plans;
	}

	/**
	 * What is wrong with a scenario's plans: full table scans, no index, or sorting on disk.
	 *
	 * @param array<int, array{table: string, type: string, key: string, rows: int, extra: string}> $plans Plans.
	 * @return string[]
	 */
	private function plan_issues( array $plans ): array {
		$issues = [];

		foreach ( $plans as $plan ) {
			if ( 'ALL' === $plan['type'] || '' === $plan['key'] ) {
				$issues[] = sprintf( 'reads all of %s (about %s rows)', $plan['table'], number_format( $plan['rows'] ) );
			}

			if ( false !== strpos( $plan['extra'], 'Using filesort' ) ) {
				$issues[] = sprintf( 'sorts %s without an index', $plan['table'] );
			}
		}

		return array_values( array_unique( $issues ) );
	}

	/**
	 * The six view counts on the queue screen.
	 *
	 * @param \JDeepD\Wire_Desk\Data\Wire_Post_Repository $repository Repository.
	 */
	private function view_counts( Wire_Post_Repository $repository ): void {
		foreach ( Queue_List_Table::view_states() as $state ) {
			$repository->count( null === $state ? [] : [ 'state' => $state ], Queue_List_Table::COUNT_MAX + 1 );
		}
	}

	/**
	 * A first, full prune: forgets where earlier runs stopped, puts answers back on a slice of old posts, and clears them.
	 */
	private function first_prune(): void {
		global $wpdb;

		static $done = false;

		if ( $done ) {
			return;
		}

		$done = true;

		delete_option( Maintenance::PRUNED_OPTION );
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET details = '[]' WHERE MOD( post_id, 10 ) = 0 AND scored_at IS NOT NULL", Schema::table_name() ) );
		wp_cache_flush();

		( new Maintenance() )->prune_details( Maintenance::retention_days() );
	}

	/**
	 * Reads one backfill batch, without adding anything.
	 */
	private function backfill_batch(): void {
		$next = new ReflectionMethod( Backfill::class, 'next_ids' );

		$next->invoke( new Backfill(), 500000, Backfill::post_types(), 500 );
	}

	/**
	 * Lists scored posts over REST.
	 */
	private function rest_list(): void {
		$request = new WP_REST_Request( 'GET', '/wire-desk/v1/posts' );
		$request->set_query_params( [ 'state' => 'scored' ] );

		rest_do_request( $request );
	}

	/**
	 * Prepares and renders the queue screen's table.
	 *
	 * @param \JDeepD\Wire_Desk\Data\Wire_Post_Repository $repository Repository.
	 */
	private function render_queue( Wire_Post_Repository $repository ): void {
		$table = new Queue_List_Table( [], $repository );
		$table->prepare_items();

		ob_start();
		$table->views();
		$table->display();
		ob_end_clean();
	}

	/**
	 * Times Wire Desk's save hook on a seeded post, averaged over many saves.
	 *
	 * @param string $name      Name.
	 * @param string $post_type Post type of the post to save.
	 * @return array<string, string>
	 */
	private function save_hook( string $name, string $post_type ): array {
		global $wpdb;

		$post = get_post( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT ID FROM %i WHERE post_type = %s ORDER BY ID DESC LIMIT 1', $wpdb->posts, $post_type ) ) );

		if ( ! $post ) {
			$this->failures[] = $name . ': no seeded post to save';

			return [
				'check'     => $name,
				'cold_ms'   => '-',
				'warm_ms'   => '-',
				'target_ms' => '1',
				'indexes'   => '-',
				'result'    => 'FAIL',
			];
		}

		$listener = new Post_Listener();
		$runs     = 200;

		wp_cache_flush();
		$started = microtime( true );
		$listener->sync( $post );
		$cold = ( microtime( true ) - $started ) * 1000;

		$started = microtime( true );
		for ( $i = 0; $i < $runs; $i++ ) {
			$listener->sync( $post );
		}
		$warm = ( microtime( true ) - $started ) * 1000 / $runs;

		Queue::unschedule( $post->ID );

		if ( $warm > 1 ) {
			$this->failures[] = sprintf( '%s: %.2f ms per save, over 1 ms', $name, $warm );
		}

		return [
			'check'     => $name,
			'cold_ms'   => number_format( $cold, 2 ),
			'warm_ms'   => number_format( $warm, 2 ),
			'target_ms' => '1 per save',
			'indexes'   => 'PRIMARY',
			'result'    => $warm > 1 ? 'FAIL' : 'ok',
		];
	}
}

Perf_Check::main( array_values( array_map( 'strval', (array) ( $args ?? [] ) ) ) );
