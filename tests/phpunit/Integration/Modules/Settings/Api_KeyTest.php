<?php
/**
 * Integration tests for Api_Key.
 *
 * @package JDeepD\AI_Post_Triage\Tests\Integration\Modules\Settings
 */

declare( strict_types = 1 );

namespace JDeepD\AI_Post_Triage\Tests\Integration\Modules\Settings;

use JDeepD\AI_Post_Triage\Framework\Encryptor;
use JDeepD\AI_Post_Triage\Modules\Settings\Api_Key;
use JDeepD\AI_Post_Triage\Modules\Settings\Settings;
use JDeepD\AI_Post_Triage\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Class - Api_KeyTest
 */
#[CoversClass( Api_Key::class )]
class Api_KeyTest extends TestCase {
	/**
	 * Clears the settings errors.
	 */
	protected function tearDown(): void {
		$GLOBALS['wp_settings_errors'] = []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Reset between tests.

		parent::tearDown();
	}

	/**
	 * Test that the key is registered with the settings form.
	 */
	public function test_register_setting(): void {
		$api_key = new Api_Key();
		$api_key->register_hooks();

		$this->assertSame( 10, has_action( 'admin_init', [ $api_key, 'register_setting' ] ) );

		$api_key->register_setting();

		$this->assertSame( Settings::SETTING_GROUP, get_registered_settings()[ Api_Key::OPTION ]['group'] ?? null );
	}

	/**
	 * Test that a submitted key is stored encrypted and read back.
	 */
	public function test_submitted_key_is_encrypted(): void {
		$stored = Api_Key::sanitize( [ 'key' => '  sk-or-v1-abcdef123456  ' ] );

		$this->assertStringNotContainsString( 'sk-or', $stored );
		$this->assertSame( 'sk-or-v1-abcdef123456', Encryptor::decrypt( $stored ) );

		update_option( Api_Key::OPTION, $stored );

		$this->assertSame( 'sk-or-v1-abcdef123456', Api_Key::get() );
		$this->assertTrue( Api_Key::is_saved() );
	}

	/**
	 * Test that an empty key, or no field, keeps the saved key.
	 */
	public function test_empty_submission_keeps_saved_key(): void {
		Api_Key::save( 'sk-or-v1-kept000000' );
		$stored = (string) get_option( Api_Key::OPTION );

		$this->assertSame( $stored, Api_Key::sanitize( [ 'key' => '' ] ) );
		$this->assertSame( $stored, Api_Key::sanitize( [] ) );
		$this->assertSame( $stored, Api_Key::sanitize( null ) );
	}

	/**
	 * Test that a stored value passed back in is kept as it is.
	 */
	public function test_stored_value_passes_through(): void {
		$this->assertSame( 'already-encrypted', Api_Key::sanitize( 'already-encrypted' ) );
	}

	/**
	 * Test that ticking Remove clears the key, even with a new one typed in.
	 */
	public function test_remove_clears_key(): void {
		Api_Key::save( 'sk-or-v1-gone000000' );

		$this->assertSame(
			'',
			Api_Key::sanitize(
				[
					'key'    => 'sk-or-v1-new0000000',
					'remove' => '1',
				]
			)
		);
	}

	/**
	 * Test that a key with spaces inside is refused and the saved key kept.
	 */
	public function test_invalid_key_is_refused(): void {
		Api_Key::save( 'sk-or-v1-kept000000' );
		$stored = (string) get_option( Api_Key::OPTION );

		$this->assertSame( $stored, Api_Key::sanitize( [ 'key' => 'sk-or v1 pasted wrong' ] ) );
		$this->assertSame( $stored, Api_Key::sanitize( [ 'key' => str_repeat( 'a', Api_Key::MAX_LENGTH + 1 ) ] ) );
		$this->assertSame( 'invalid_key', get_settings_errors( Api_Key::OPTION )[0]['code'] ?? null );
	}

	/**
	 * Test save() and delete().
	 */
	public function test_save_and_delete(): void {
		$this->assertFalse( Api_Key::is_saved() );
		$this->assertSame( '', Api_Key::get() );

		$this->assertTrue( Api_Key::save( 'sk-or-v1-saved00000' ) );
		$this->assertSame( 'sk-or-v1-saved00000', Api_Key::get() );

		Api_Key::delete();

		$this->assertFalse( Api_Key::is_saved() );
	}

	/**
	 * Test that a value that can't be decrypted is saved but unreadable.
	 *
	 * @expectedIncorrectUsage JDeepD\AI_Post_Triage\Framework\Encryptor::decrypt
	 */
	public function test_unreadable_key(): void {
		update_option( Api_Key::OPTION, 'not base64 at all!' );

		$this->assertTrue( Api_Key::is_saved() );
		$this->assertSame( '', Api_Key::get() );
	}

	/**
	 * Test the hint shows only the last characters.
	 */
	public function test_hint(): void {
		$this->assertSame( '…abcd', Api_Key::hint( 'sk-or-v1-0000abcd' ) );
		$this->assertSame( '…', Api_Key::hint( 'short' ) );
	}
}
