<?php
/**
 * Integration tests for uninstall.php.
 *
 * @package JDeepD\Wire_Desk\Tests\Integration
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk\Tests\Integration;

use JDeepD\Wire_Desk\Core\Schema;
use JDeepD\Wire_Desk\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Class - UninstallTest
 */
#[CoversNothing]
class UninstallTest extends TestCase {
	/**
	 * Use real tables, so the drop can be checked.
	 */
	protected function setUp(): void {
		parent::setUp();

		remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );
	}

	/**
	 * Put the table back for the other tests.
	 */
	protected function tearDown(): void {
		Schema::install();

		self::commit_transaction();

		parent::tearDown();
	}

	/**
	 * Test that uninstalling drops the table and removes its option and transient.
	 */
	public function test_uninstall_drops_table_and_schema_data(): void {
		Schema::install();
		set_transient( Schema::FAILURE_TRANSIENT, 'Access denied', 300 );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', plugin_basename( WIRE_DESK_FILE ) );
		}

		require WIRE_DESK_PATH . 'uninstall.php';

		$this->assertFalse( Schema::table_exists() );
		$this->assertFalse( get_option( Schema::VERSION_OPTION ) );
		$this->assertFalse( get_transient( Schema::FAILURE_TRANSIENT ) );
	}
}
