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
}
