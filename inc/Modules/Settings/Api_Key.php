<?php
/**
 * The OpenRouter API key saved on the settings screen.
 *
 * @package JDeepD\Wire_Desk\Modules\Settings
 */

declare(strict_types = 1);

namespace JDeepD\Wire_Desk\Modules\Settings;

use JDeepD\Wire_Desk\Framework\Contracts\Interfaces\Registrable;
use JDeepD\Wire_Desk\Framework\Encryptor;

/**
 * Class - Api_Key
 */
final class Api_Key implements Registrable {
	/**
	 * Option holding the encrypted key.
	 */
	public const OPTION = 'wire_desk_openrouter_api_key';

	/**
	 * Longest key accepted.
	 */
	public const MAX_LENGTH = 256;

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'admin_init', [ $this, 'register_setting' ] );
	}

	/**
	 * Registers the key with the settings form.
	 */
	public function register_setting(): void {
		register_setting(
			Settings::SETTING_GROUP,
			self::OPTION,
			[
				'type'              => 'string',
				'label'             => __( 'OpenRouter API key', 'wire-desk' ),
				'default'           => '',
				'sanitize_callback' => [ self::class, 'sanitize' ],
				'show_in_rest'      => false,
			]
		);
	}

	/**
	 * Turns the submitted field into the value to store.
	 *
	 * The form sends `key` and `remove`. An empty key, or no field at all, keeps the saved one.
	 * A stored value passed back in, as update_option() does, is kept as it is.
	 *
	 * @param mixed $value Submitted or stored value.
	 * @return string Encrypted key, or an empty string for none.
	 */
	public static function sanitize( $value ): string {
		if ( is_string( $value ) ) {
			return $value;
		}

		if ( ! is_array( $value ) ) {
			return self::stored();
		}

		if ( ! empty( $value['remove'] ) ) {
			return '';
		}

		$key = is_string( $value['key'] ?? null ) ? trim( $value['key'] ) : '';

		if ( '' === $key ) {
			return self::stored();
		}

		if ( strlen( $key ) > self::MAX_LENGTH || preg_match( '/\s/', $key ) ) {
			add_settings_error( self::OPTION, 'invalid_key', __( 'The OpenRouter API key is not valid, so it was not saved.', 'wire-desk' ) );

			return self::stored();
		}

		$encrypted = Encryptor::encrypt( $key );

		if ( false === $encrypted ) {
			add_settings_error( self::OPTION, 'encrypt_failed', __( 'The OpenRouter API key could not be encrypted, so it was not saved.', 'wire-desk' ) );

			return self::stored();
		}

		return $encrypted;
	}

	/**
	 * Saves a key, encrypted.
	 *
	 * @param string $key API key.
	 * @return bool Whether it was saved.
	 */
	public static function save( string $key ): bool {
		$encrypted = '' !== trim( $key ) ? Encryptor::encrypt( trim( $key ) ) : '';

		return false !== $encrypted && update_option( self::OPTION, $encrypted );
	}

	/**
	 * Removes the saved key.
	 */
	public static function delete(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Whether a key is saved, whether or not it can be read.
	 */
	public static function is_saved(): bool {
		return '' !== self::stored();
	}

	/**
	 * The saved key, or an empty string when none is saved or it can't be decrypted.
	 */
	public static function get(): string {
		$stored = self::stored();

		if ( '' === $stored ) {
			return '';
		}

		$key = Encryptor::decrypt( $stored );

		return is_string( $key ) ? trim( $key ) : '';
	}

	/**
	 * The stored, encrypted value.
	 */
	private static function stored(): string {
		$stored = get_option( self::OPTION, '' );

		return is_string( $stored ) ? $stored : '';
	}

	/**
	 * The last characters of a key, for showing which one is saved.
	 *
	 * @param string $key API key.
	 */
	public static function hint( string $key ): string {
		return strlen( $key ) > 8 ? '…' . substr( $key, -4 ) : '…';
	}
}
