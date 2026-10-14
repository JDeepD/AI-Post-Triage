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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

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
	 * Bundled locales, with a PHP string and a script string in each.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public static function locales(): array {
		return [
			'Spanish' => [ 'es_ES', 'Cola de revisión', 'Introduce un titular.' ],
			'French'  => [ 'fr_FR', 'File de relecture', 'Saisissez un titre.' ],
		];
	}

	/**
	 * Test that the bundled translations are used.
	 *
	 * @param string $locale Locale.
	 * @param string $queue  "Review queue" in that locale.
	 */
	#[DataProvider( 'locales' )]
	public function test_bundled_translation_is_used( string $locale, string $queue ): void {
		add_filter( 'determine_locale', static fn (): string => $locale );
		unload_textdomain( I18n::DOMAIN, true );

		try {
			( new I18n() )->load_textdomain();

			$this->assertSame( $queue, __( 'Review queue', 'wire-desk' ) );
			$this->assertSame( WIRE_DESK_PATH . 'languages', I18n::languages_path() );
		} finally {
			unload_textdomain( I18n::DOMAIN, true );
		}
	}

	/**
	 * Test that each locale has its compiled files, and that both scripts find their translations.
	 *
	 * @param string $locale   Locale.
	 * @param string $queue    "Review queue" in that locale.
	 * @param string $headline "Enter a headline." in that locale.
	 */
	#[DataProvider( 'locales' )]
	public function test_bundled_files_are_compiled( string $locale, string $queue, string $headline ): void {
		$base = I18n::languages_path() . '/wire-desk-' . $locale;

		$this->assertFileExists( $base . '.mo' );
		$this->assertFileExists( $base . '.l10n.php' );

		add_filter( 'determine_locale', static fn (): string => $locale );

		$assets = new Assets();
		$assets->register_admin_assets();
		$assets->register_editor_assets();

		$admin  = json_decode( (string) load_script_textdomain( Assets::ADMIN_HANDLE, I18n::DOMAIN, I18n::languages_path() ), true );
		$editor = json_decode( (string) load_script_textdomain( Assets::EDITOR_HANDLE, I18n::DOMAIN, I18n::languages_path() ), true );

		$this->assertSame( [ $headline ], $admin['locale_data']['messages']['Enter a headline.'] ?? null );
		$this->assertSame( [ $queue ], $editor['locale_data']['messages']['Review queue'] ?? null );
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
