<?php
/**
 * Integration tests for Schema.
 *
 * @package JDeepD\AI_Post_Triage\Tests\Integration\Core
 */

declare( strict_types = 1 );

namespace JDeepD\AI_Post_Triage\Tests\Integration\Core;

use JDeepD\AI_Post_Triage\Core\Schema;
use JDeepD\AI_Post_Triage\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Class - SchemaTest
 */
#[CoversClass( Schema::class )]
class SchemaTest extends TestCase {
	/**
	 * Start each test without the table.
	 */
	protected function setUp(): void {
		parent::setUp();

		// Use real tables instead of the test suite's temporary ones.
		remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );

		$this->drop_table();
		delete_option( Schema::VERSION_OPTION );
		delete_transient( Schema::FAILURE_TRANSIENT );
	}

	/**
	 * Put the table back for the other tests.
	 */
	protected function tearDown(): void {
		delete_transient( Schema::FAILURE_TRANSIENT );
		Schema::install();

		self::commit_transaction();

		parent::tearDown();
	}

	/**
	 * Test that the schema is checked early on init.
	 */
	public function test_register_hooks_checks_schema_early_on_init(): void {
		$schema = new Schema();
		$schema->register_hooks();

		$this->assertSame( 0, has_action( 'init', [ $schema, 'maybe_upgrade' ] ) );
	}

	/**
	 * Test that the table name uses the site prefix.
	 */
	public function test_table_name_uses_site_prefix(): void {
		global $wpdb;

		$this->assertSame( $wpdb->prefix . 'ai_post_triage_post', Schema::table_name() );
	}

	/**
	 * Test that install() creates the table with every column.
	 */
	public function test_install_creates_table_with_columns(): void {
		$this->assertFalse( Schema::table_exists() );

		$this->assertTrue( Schema::install() );
		$this->assertTrue( Schema::table_exists() );
		$this->assertSame(
			[ 'post_id', 'post_date', 'state', 'band', 'score', 'model', 'hash', 'cost', 'scored_at', 'error', 'attempts', 'details' ],
			$this->get_columns()
		);
	}

	/**
	 * Test that install() creates the indexes the queue relies on.
	 */
	public function test_install_creates_indexes(): void {
		Schema::install();

		$this->assertSame(
			[
				'PRIMARY'         => [ 'post_id' ],
				'state_date'      => [ 'state', 'post_date' ],
				'state_band_date' => [ 'state', 'band', 'post_date' ],
				'post_date'       => [ 'post_date' ],
			],
			$this->get_indexes()
		);
	}

	/**
	 * Test that install() records the schema version.
	 */
	public function test_install_records_schema_version(): void {
		$this->assertFalse( Schema::is_current() );

		Schema::install();

		$this->assertSame( Schema::VERSION, (int) get_option( Schema::VERSION_OPTION ) );
		$this->assertTrue( Schema::is_current() );
	}

	/**
	 * Test that running the schema again on an up-to-date table changes nothing.
	 */
	public function test_install_is_idempotent(): void {
		Schema::install();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$this->assertSame( [], dbDelta( Schema::get_table_sql() ) );
		$this->assertTrue( Schema::install() );
	}

	/**
	 * Test that an upgrade adds columns missing from an older table.
	 */
	public function test_maybe_upgrade_adds_missing_columns(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Simulates an older table.
		$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i ( post_id bigint(20) unsigned NOT NULL, PRIMARY KEY  (post_id) )', Schema::table_name() ) );
		update_option( Schema::VERSION_OPTION, Schema::VERSION - 1 );

		( new Schema() )->maybe_upgrade();

		$this->assertContains( 'details', $this->get_columns() );
		$this->assertContains( 'attempts', $this->get_columns() );
		$this->assertArrayHasKey( 'state_band_date', $this->get_indexes() );
		$this->assertTrue( Schema::is_current() );
	}

	/**
	 * Test that maybe_upgrade() installs the table when no version is stored.
	 */
	public function test_maybe_upgrade_installs_when_version_is_missing(): void {
		( new Schema() )->maybe_upgrade();

		$this->assertTrue( Schema::table_exists() );
		$this->assertTrue( Schema::is_current() );
	}

	/**
	 * Test that maybe_upgrade() does nothing when the version is current.
	 */
	public function test_maybe_upgrade_skips_when_version_is_current(): void {
		update_option( Schema::VERSION_OPTION, Schema::VERSION );

		( new Schema() )->maybe_upgrade();

		$this->assertFalse( Schema::table_exists() );
	}

	/**
	 * Test that maybe_upgrade() waits after a failed install.
	 */
	public function test_maybe_upgrade_skips_after_recent_failure(): void {
		set_transient( Schema::FAILURE_TRANSIENT, 'Access denied', 300 );

		( new Schema() )->maybe_upgrade();

		$this->assertFalse( Schema::table_exists() );
	}

	/**
	 * Test that a failed install is recorded and the version is left alone.
	 */
	public function test_install_records_failure(): void {
		global $wpdb;

		$break_create = static fn ( string $query ): string => str_starts_with( trim( $query ), 'CREATE TABLE' ) ? 'CREATE TABLE_BROKEN' : $query;
		add_filter( 'query', $break_create );
		$suppress     = $wpdb->suppress_errors();

		$installed = Schema::install();

		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $break_create );

		$this->assertFalse( $installed );
		$this->assertNotFalse( get_transient( Schema::FAILURE_TRANSIENT ) );
		$this->assertFalse( Schema::is_current() );
	}

	/**
	 * Test that install() skips while another connection is installing.
	 */
	public function test_install_skips_while_another_connection_holds_the_lock(): void {
		global $wpdb;

		[ $host, $port, $socket ] = $wpdb->parse_db_host( DB_HOST );

		// phpcs:disable WordPress.DB.RestrictedClasses.mysql__mysqli
		$other = new \mysqli( (string) $host, DB_USER, DB_PASSWORD, DB_NAME, $port ? (int) $port : 3306, $socket ? (string) $socket : null );
		$other->query( "SELECT GET_LOCK( 'ai_post_triage_schema_{$wpdb->prefix}', 0 )" );
		// phpcs:enable

		$installed = Schema::install();

		$other->close();

		$this->assertFalse( $installed );
		$this->assertFalse( Schema::table_exists() );
	}

	/**
	 * Drops the table.
	 */
	private function drop_table(): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', Schema::table_name() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Test setup.
	}

	/**
	 * Column names, in table order.
	 *
	 * @return string[]
	 */
	private function get_columns(): array {
		global $wpdb;

		return $wpdb->get_col( $wpdb->prepare( 'DESCRIBE %i', Schema::table_name() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test assertion.
	}

	/**
	 * Index columns, keyed by index name, in index order.
	 *
	 * @return array<string, string[]>
	 */
	private function get_indexes(): array {
		global $wpdb;

		$rows    = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', Schema::table_name() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test assertion.
		$indexes = [];

		foreach ( $rows as $row ) {
			$indexes[ $row->Key_name ][ (int) $row->Seq_in_index - 1 ] = $row->Column_name; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- MySQL column names.
		}

		return array_map(
			static function ( array $columns ): array {
				ksort( $columns );
				return array_values( $columns );
			},
			$indexes
		);
	}
}
