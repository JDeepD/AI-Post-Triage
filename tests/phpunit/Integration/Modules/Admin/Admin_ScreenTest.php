<?php
/**
 * Admin_ScreenTest file.
 *
 * @package JDeepD\Wire_Desk\Tests\Integration\Modules\Admin
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk\Tests\Integration\Modules\Admin;

use JDeepD\Wire_Desk\Core\Assets;
use JDeepD\Wire_Desk\Modules\Settings\Admin_Screen;
use JDeepD\Wire_Desk\Modules\Settings\Settings;
use JDeepD\Wire_Desk\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Class - Admin_ScreenTest
 */
#[CoversClass( Admin_Screen::class )]
class Admin_ScreenTest extends TestCase {
	/**
	 * Test that register_hooks adds appropriate actions and filters.
	 */
	public function test_register_hooks_registers_admin_menu_and_plugin_action_links_hooks(): void {
		$screen = new Admin_Screen();

		$screen->register_hooks();

		$this->assertNotFalse( \has_action( 'admin_menu', [ $screen, 'register_screen' ] ) );
		$this->assertNotFalse( \has_action( 'admin_init', [ $screen, 'register_fields' ] ) );
		$this->assertNotFalse( \has_filter( 'plugin_action_links_' . \plugin_basename( WIRE_DESK_FILE ), [ $screen, 'add_action_links' ] ) );
	}

	/**
	 * Test that add_action_links appends the settings link.
	 */
	public function test_add_action_links_appends_settings_link(): void {
		$screen = new Admin_Screen();

		$actual = $screen->add_action_links( [ '<a href="plugins.php">Plugins</a>' ] );

		$this->assertCount( 2, $actual );
		$this->assertStringContainsString( 'admin.php?page=wire-desk', $actual[1] );
		$this->assertStringContainsString( 'Settings', $actual[1] );
	}

	/**
	 * Test that register_screen adds the settings page and its load hook.
	 */
	public function test_register_screen_adds_settings_page_and_load_hook(): void {
		$screen = new Admin_Screen();

		// Mock current user as administrator so the screen is registered.
		$user = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user );

		$screen->register_screen();
		$hook_suffix = get_plugin_page_hookname( Admin_Screen::SCREEN_ID, 'options-general.php' );

		// Register the admin scripts so they exist when enqueue_scripts is called.
		$assets = new Assets();
		$assets->register_admin_assets();

		do_action( 'load-' . $hook_suffix ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores

		global $submenu;

		$this->assertIsArray( $submenu['options-general.php'] ?? null );
		$this->assertContains(
			[ 'Wire Desk', 'manage_options', Admin_Screen::SCREEN_ID, 'Wire Desk' ],
			$submenu['options-general.php'],
			'Settings page should be added to the admin menu'
		);
		$this->assertTrue( wp_script_is( Assets::ADMIN_HANDLE, 'enqueued' ), 'Admin script should be enqueued' );
	}

	/**
	 * Test that render_screen outputs the correct template.
	 */
	public function test_render_screen_outputs_admin_template_markup(): void {
		$screen = new Admin_Screen();

		ob_start();
		$screen->render_screen();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( '<div class="wrap">', $output );
		$this->assertStringContainsString( 'wire-desk-content', $output );
		$this->assertStringContainsString( 'action="options.php"', $output );
		$this->assertStringContainsString( "name='option_page' value='" . Settings::SETTING_GROUP . "'", $output );
	}

	/**
	 * Test that register_fields adds the delete data field.
	 */
	public function test_register_fields_adds_delete_data_field(): void {
		global $wp_settings_sections, $wp_settings_fields;

		require_once ABSPATH . 'wp-admin/includes/template.php';

		( new Admin_Screen() )->register_fields();

		$this->assertArrayHasKey( Admin_Screen::DATA_SECTION, $wp_settings_sections[ Admin_Screen::SCREEN_ID ] );
		$this->assertArrayHasKey( Settings::DELETE_DATA_OPTION_KEY, $wp_settings_fields[ Admin_Screen::SCREEN_ID ][ Admin_Screen::DATA_SECTION ] );
	}

	/**
	 * Test that the delete data field reflects the saved option.
	 */
	public function test_render_delete_data_field_reflects_option(): void {
		$screen = new Admin_Screen();

		update_option( Settings::DELETE_DATA_OPTION_KEY, false );
		ob_start();
		$screen->render_delete_data_field();
		$unchecked = (string) ob_get_clean();

		update_option( Settings::DELETE_DATA_OPTION_KEY, true );
		ob_start();
		$screen->render_delete_data_field();
		$checked = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="' . Settings::DELETE_DATA_OPTION_KEY . '"', $unchecked );
		$this->assertStringNotContainsString( "checked='checked'", $unchecked );
		$this->assertStringContainsString( "checked='checked'", $checked );
	}
}
