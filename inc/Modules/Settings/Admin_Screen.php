<?php
/**
 * Registers the Settings Screen
 *
 * @package JDeepD\Wire_Desk\Modules\Settings
 */

declare(strict_types = 1);

namespace JDeepD\Wire_Desk\Modules\Settings;

use JDeepD\Wire_Desk\Core\Assets;
use JDeepD\Wire_Desk\Core\Templates;
use JDeepD\Wire_Desk\Framework\Contracts\Interfaces\Registrable;

/**
 * Class - Admin_Screen
 */
final class Admin_Screen implements Registrable {
	/**
	 * The screen ID for the settings page.
	 */
	public const SCREEN_ID = 'wire-desk';

	/**
	 * The settings section for plugin data.
	 */
	public const DATA_SECTION = 'wire_desk_data';

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'register_screen' ] );
		add_action( 'admin_init', [ $this, 'register_fields' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( WIRE_DESK_FILE ), [ $this, 'add_action_links' ], 2 );
	}

	/**
	 * Add action links to the settings on the plugins page.
	 *
	 * @param string[] $links Existing links.
	 *
	 * @return string[]
	 */
	public function add_action_links( $links ): array {
		$links[] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( sprintf( 'admin.php?page=%s', self::SCREEN_ID ) ) ),
			__( 'Settings', 'wire-desk' )
		);

		return $links;
	}

	/**
	 * Hook the settings screen into the Admin menu.
	 */
	public function register_screen(): void {
		// First, the page.
		$hook_suffix = add_options_page(
			__( 'Wire Desk', 'wire-desk' ),
			__( 'Wire Desk', 'wire-desk' ),
			'manage_options',
			self::SCREEN_ID,
			[ $this, 'render_screen' ]
		);

		// Then, load the screen.
		if ( false !== $hook_suffix ) {
			add_action( "load-{$hook_suffix}", [ $this, 'enqueue_scripts' ], 10, 0 );
		}
	}

	/**
	 * Register the settings sections and fields.
	 */
	public function register_fields(): void {
		add_settings_section(
			self::DATA_SECTION,
			__( 'Data', 'wire-desk' ),
			'__return_null',
			self::SCREEN_ID
		);

		add_settings_field(
			Settings::DELETE_DATA_OPTION_KEY,
			__( 'Uninstall', 'wire-desk' ),
			[ $this, 'render_delete_data_field' ],
			self::SCREEN_ID,
			self::DATA_SECTION
		);
	}

	/**
	 * Render the delete data on uninstall field.
	 *
	 * @internal Used by register_fields().
	 */
	public function render_delete_data_field(): void {
		printf(
			'<label for="%1$s"><input type="checkbox" id="%1$s" name="%1$s" value="1" %2$s /> %3$s</label><p class="description">%4$s</p>',
			esc_attr( Settings::DELETE_DATA_OPTION_KEY ),
			checked( Settings::delete_data_on_uninstall(), true, false ),
			esc_html__( 'Delete all Wire Desk data when the plugin is deleted', 'wire-desk' ),
			esc_html__( 'Drops the Wire Desk table and deletes its settings. This can\'t be undone.', 'wire-desk' )
		);
	}

	/**
	 * Render the admin screen.
	 *
	 * @internal Used by register_screen().
	 */
	public function render_screen(): void {
		Templates::get_template_part( 'admin-screen' );
	}

	/**
	 * Enqueue and localize scripts.
	 *
	 * @internal Used by register_screen().
	 */
	public function enqueue_scripts(): void {
		wp_localize_script( Assets::ADMIN_HANDLE, 'wireDeskAdmin', self::get_localized_data() );
		wp_enqueue_script( Assets::ADMIN_HANDLE );
	}

	/**
	 * Localize plugin data for script access.
	 *
	 * Will be available via window.wireDeskAdmin.
	 *
	 * @return array<string, mixed>
	 */
	private function get_localized_data(): array {
		return [
			'pluginVersion' => WIRE_DESK_VERSION,
		];
	}
}
