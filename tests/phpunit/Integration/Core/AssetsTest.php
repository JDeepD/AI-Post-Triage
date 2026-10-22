<?php
/**
 * Integration tests for Assets.
 *
 * @package JDeepD\AI_Post_Triage\Tests\Integration\Core
 */

declare( strict_types = 1 );

namespace JDeepD\AI_Post_Triage\Tests\Integration\Core;

use JDeepD\AI_Post_Triage\Core\Assets;
use JDeepD\AI_Post_Triage\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Class - AssetsTest
 */
#[CoversClass( \JDeepD\AI_Post_Triage\Core\Assets::class )]
class AssetsTest extends TestCase {
	/**
	 * Ensure no errors are thrown when the Assets class is instantiated.
	 */
	public function test_assets_class_instantiation(): void {
		$assets = new Assets();
		$this->assertInstanceOf( Assets::class, $assets );

		$assets->register_hooks();
		$assets->register_admin_assets();

		// If we reach this point without any exceptions, the test passes.
		$this->assertTrue( true );
	}

	/**
	 * Tests defer attribute is added to block editor script tags.
	 */
	public function test_defer_attribute_added_to_block_editor_scripts(): void {
		$assets = new Assets();

		// Simulate the script tag filter for a script handle that should be deferred.
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript
		$script_tag = '<script src="test.js"></script>';

		// An unallowed handle should not be modified.
		$this->assertSame( $script_tag, $assets->defer_scripts( $script_tag, 'unallowed-handle' ) );

		// Get a handle from the private DEFERRED_ASSETS constant using reflection.
		$reflection     = new \ReflectionClass( Assets::class );
		$defer_handles  = $reflection->getConstant( 'DEFERRED_ASSETS' );
		$handle_to_test = $defer_handles[0];

		// An allowed handle should have the defer attribute added.
		$expected_tag = '<script defer src="test.js"></script>';
		$this->assertSame( $expected_tag, $assets->defer_scripts( $script_tag, $handle_to_test ) );
	}

	/**
	 * Test that the editor panel's script and style are registered for the block editor.
	 */
	public function test_register_editor_assets(): void {
		$assets = new Assets();
		$assets->register_hooks();

		$this->assertSame( 1, has_action( 'enqueue_block_editor_assets', [ $assets, 'register_editor_assets' ] ) );

		$assets->register_editor_assets();

		$this->assertTrue( wp_script_is( Assets::EDITOR_HANDLE, 'registered' ) );
		$this->assertTrue( wp_style_is( Assets::EDITOR_HANDLE, 'registered' ) );
		$this->assertContains( 'wp-plugins', wp_scripts()->registered[ Assets::EDITOR_HANDLE ]->deps );
		$this->assertContains( 'wp-components', wp_styles()->registered[ Assets::EDITOR_HANDLE ]->deps );
	}

	/**
	 * Test that both scripts load their translations, from the language packs.
	 */
	public function test_scripts_load_translations(): void {
		$assets = new Assets();
		$assets->register_admin_assets();
		$assets->register_editor_assets();

		foreach ( [ Assets::ADMIN_HANDLE, Assets::EDITOR_HANDLE ] as $handle ) {
			$script = wp_scripts()->registered[ $handle ] ?? null;

			$this->assertNotNull( $script );
			$this->assertSame( 'ai-post-triage', $script->textdomain );
			$this->assertSame( '', (string) $script->translations_path );
			$this->assertContains( 'wp-i18n', $script->deps );
		}
	}

	/**
	 * Test that the script files are named so their translation files can be found.
	 */
	public function test_script_names_survive_make_json(): void {
		( new Assets() )->register_admin_assets();
		( new Assets() )->register_editor_assets();

		foreach ( [ Assets::ADMIN_HANDLE, Assets::EDITOR_HANDLE ] as $handle ) {
			$path = (string) wp_parse_url( (string) wp_scripts()->registered[ $handle ]->src, PHP_URL_PATH );

			$this->assertDoesNotMatchRegularExpression( '/.min\.js$/', $path, 'wp i18n make-json renames files ending in "<any character>min.js", so their translations would not load.' );
		}
	}
}
