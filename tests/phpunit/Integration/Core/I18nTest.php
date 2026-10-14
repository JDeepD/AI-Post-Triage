<?php
/**
 * Integration tests for I18n.
 *
 * @package JDeepD\Wire_Desk\Tests\Integration\Core
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk\Tests\Integration\Core;

use JDeepD\Wire_Desk\Core\Assets;
use JDeepD\Wire_Desk\Core\I18n;
use JDeepD\Wire_Desk\Tests\TestCase;
use MO;
use PHPUnit\Framework\Attributes\CoversClass;
use Translation_Entry;

/**
 * Class - I18nTest
 */
#[CoversClass( I18n::class )]
class I18nTest extends TestCase {
	/**
	 * Test that the translations load on init.
	 */
	public function test_register_hooks(): void {
		$i18n = new I18n();
		$i18n->register_hooks();

		$this->assertSame( 10, has_action( 'init', [ $i18n, 'load_textdomain' ] ) );
	}

	/**
	 * Test that a translation in the plugin's languages folder is used.
	 */
	public function test_bundled_translation_is_used(): void {
		$file = I18n::languages_path() . '/wire-desk-es_ES.mo';
		$mo   = new MO();

		$mo->add_entry(
			new Translation_Entry(
				[
					'singular'     => 'Review queue',
					'translations' => [ 'Cola de revisión' ],
				]
			)
		);
		$mo->export_to_file( $file );

		add_filter( 'determine_locale', static fn (): string => 'es_ES' );
		unload_textdomain( I18n::DOMAIN );

		try {
			( new I18n() )->load_textdomain();

			$this->assertSame( 'Cola de revisión', __( 'Review queue', 'wire-desk' ) );
			$this->assertSame( WIRE_DESK_PATH . 'languages', I18n::languages_path() );
		} finally {
			unload_textdomain( I18n::DOMAIN );
			wp_delete_file( $file );
		}
	}

	/**
	 * Test that both scripts are set up to load their translations from the languages folder.
	 */
	public function test_scripts_load_translations(): void {
		$assets = new Assets();
		$assets->register_admin_assets();
		$assets->register_editor_assets();

		foreach ( [ Assets::ADMIN_HANDLE, Assets::EDITOR_HANDLE ] as $handle ) {
			$script = wp_scripts()->registered[ $handle ] ?? null;

			$this->assertNotNull( $script );
			$this->assertSame( I18n::DOMAIN, $script->textdomain );
			$this->assertSame( I18n::languages_path(), $script->translations_path );
			$this->assertContains( 'wp-i18n', $script->deps );
		}
	}

	/**
	 * Test that the script files are named so their translation files can be found.
	 */
	public function test_script_names_survive_make_json(): void {
		foreach ( [ Assets::ADMIN_HANDLE, Assets::EDITOR_HANDLE ] as $handle ) {
			( new Assets() )->register_admin_assets();
			( new Assets() )->register_editor_assets();

			$path = (string) wp_parse_url( (string) wp_scripts()->registered[ $handle ]->src, PHP_URL_PATH );

			$this->assertDoesNotMatchRegularExpression( '/.min\.js$/', $path, 'wp i18n make-json renames files ending in "<any character>min.js", so their translations would not load.' );
		}
	}
}
