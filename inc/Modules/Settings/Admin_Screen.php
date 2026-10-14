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
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'register_screen' ] );
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
