<?php
/**
 * SettingsTest file.
 *
 * @package JDeepD\Wire_Desk\Tests\Integration\Modules\Settings
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk\Tests\Integration\Modules\Settings;

use JDeepD\Wire_Desk\Modules\Settings\Settings;
use JDeepD\Wire_Desk\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Class - SettingsTest
 */
#[CoversClass( Settings::class )]
class SettingsTest extends TestCase {
	/**
	 * Test that register_hooks adds callbacks to admin_init and rest_api_init.
	 */
	public function test_register_hooks_adds_settings_callbacks_to_admin_init_and_rest_api_init(): void {
		$settings = new Settings();

		$settings->register_hooks();

		$this->assertNotFalse( has_action( 'admin_init', [ $settings, 'register_settings' ] ) );
		$this->assertNotFalse( has_action( 'rest_api_init', [ $settings, 'register_settings' ] ) );
	}

	/**
	 * Test that register_settings registers the delete data option.
	 */
	public function test_register_settings_registers_delete_data_option(): void {
		( new Settings() )->register_settings();

		$registered = get_registered_settings();
		$key        = Settings::DELETE_DATA_OPTION_KEY;

		$this->assertArrayHasKey( $key, $registered );
		$this->assertSame( Settings::SETTING_GROUP, $registered[ $key ]['group'] );
		$this->assertSame( 'boolean', $registered[ $key ]['type'] );
		$this->assertFalse( $registered[ $key ]['default'] );
		$this->assertSame( 'rest_sanitize_boolean', $registered[ $key ]['sanitize_callback'] );
	}

	/**
	 * Test that data is kept on uninstall by default.
	 */
	public function test_delete_data_on_uninstall_defaults_to_false(): void {
		delete_option( Settings::DELETE_DATA_OPTION_KEY );

		$this->assertFalse( Settings::delete_data_on_uninstall() );
	}

	/**
	 * Test that delete_data_on_uninstall() reflects the option.
	 */
	public function test_delete_data_on_uninstall_reflects_option(): void {
		update_option( Settings::DELETE_DATA_OPTION_KEY, true );
		$this->assertTrue( Settings::delete_data_on_uninstall() );

		update_option( Settings::DELETE_DATA_OPTION_KEY, false );
		$this->assertFalse( Settings::delete_data_on_uninstall() );
	}
}
