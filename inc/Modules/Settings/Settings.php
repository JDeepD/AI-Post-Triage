<?php
/**
 * Registers the plugin's admin settings.
 *
 * @package JDeepD\Wire_Desk\Modules\Settings
 */

declare(strict_types = 1);

namespace JDeepD\Wire_Desk\Modules\Settings;

use JDeepD\Wire_Desk\Framework\Contracts\Interfaces\Registrable;

/**
 * Class - Settings
 */
final class Settings implements Registrable {
	/**
	 * The setting prefix.
	 */
	private const SETTING_PREFIX = 'wire_desk_';

	/**
	 * The setting group.
	 */
	public const SETTING_GROUP = self::SETTING_PREFIX . 'settings';

	/**
	 * Option for deleting the plugin's data on uninstall.
	 */
	public const DELETE_DATA_OPTION_KEY = self::SETTING_PREFIX . 'delete_data_on_uninstall';

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'rest_api_init', [ $this, 'register_settings' ] );
	}

	/**
	 * Register plugin settings.
	 */
	public function register_settings(): void {
		/**
		 * Add your settings here, keyed by option name.
		 *
		 * @var array<string, array<string, mixed>> $settings
		 */
		$settings = [
			self::DELETE_DATA_OPTION_KEY => [
				'type'              => 'boolean',
				'label'             => __( 'Delete data on uninstall', 'wire-desk' ),
				'description'       => __( 'Delete the Wire Desk table and settings when the plugin is deleted.', 'wire-desk' ),
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'show_in_rest'      => [
					'schema' => [
						'type' => 'boolean',
					],
				],
			],
		];

		foreach ( $settings as $key => $args ) {
			register_setting(
				self::SETTING_GROUP,
				$key,
				$args
			);
		}
	}

	/**
	 * Whether the plugin's data is deleted on uninstall.
	 */
	public static function delete_data_on_uninstall(): bool {
		return (bool) get_option( self::DELETE_DATA_OPTION_KEY, false );
	}
}
