<?php
/**
 * Integration tests for uninstall.php.
 *
 * @package JDeepD\Wire_Desk\Tests\Integration
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk\Tests\Integration;

use JDeepD\Wire_Desk\Core\Schema;
use JDeepD\Wire_Desk\Modules\Settings\Settings;
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

		Schema::install();
		set_transient( Schema::FAILURE_TRANSIENT, 'Access denied', 300 );
	}

	/**
	 * Put the table back for the other tests.
	 */
	protected function tearDown(): void {
		delete_option( Settings::DELETE_DATA_OPTION_KEY );
		delete_option( Settings::OPTION_KEY );
		delete_transient( Schema::FAILURE_TRANSIENT );
		Schema::install();

		self::commit_transaction();

		parent::tearDown();
	}

	/**
	 * Test that uninstalling keeps the data by default.
	 */
	public function test_uninstall_keeps_data_by_default(): void {
		delete_option( Settings::DELETE_DATA_OPTION_KEY );
		update_option( Settings::OPTION_KEY, Settings::defaults() );

		$this->run_uninstall();

		$this->assertTrue( Schema::table_exists() );
		$this->assertSame( Schema::VERSION, (int) get_option( Schema::VERSION_OPTION ) );
		$this->assertIsArray( get_option( Settings::OPTION_KEY ) );
		$this->assertNotFalse( get_transient( Schema::FAILURE_TRANSIENT ) );
	}

	/**
	 * Test that uninstalling drops the table and removes the plugin's options when enabled.
	 */
	public function test_uninstall_deletes_data_when_enabled(): void {
		update_option( Settings::DELETE_DATA_OPTION_KEY, true );
		update_option( Settings::OPTION_KEY, Settings::defaults() );

		$this->run_uninstall();

		$this->assertFalse( Schema::table_exists() );
		$this->assertFalse( get_option( Schema::VERSION_OPTION ) );
		$this->assertFalse( get_option( Settings::DELETE_DATA_OPTION_KEY ) );
		$this->assertFalse( get_option( Settings::OPTION_KEY ) );
		$this->assertFalse( get_transient( Schema::FAILURE_TRANSIENT ) );
	}

	/**
	 * Runs the uninstaller.
	 */
	private function run_uninstall(): void {
		if ( function_exists( 'JDeepD\Wire_Desk\run_uninstaller' ) ) {
			\JDeepD\Wire_Desk\run_uninstaller();
			return;
		}

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', plugin_basename( WIRE_DESK_FILE ) );
		}

		require WIRE_DESK_PATH . 'uninstall.php';
	}
}
