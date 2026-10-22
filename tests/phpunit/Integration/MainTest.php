<?php
/**
 * Integration tests for Main.
 *
 * @package JDeepD\AI_Post_Triage\Tests\Integration
 */

declare( strict_types = 1 );

namespace JDeepD\AI_Post_Triage\Tests\Integration;

use JDeepD\AI_Post_Triage\Core\Schema;
use JDeepD\AI_Post_Triage\Main;
use JDeepD\AI_Post_Triage\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Class - MainTest
 */
#[CoversClass( \JDeepD\AI_Post_Triage\Main::class )]
class MainTest extends TestCase {
	/**
	 * Reset the Main singleton instance.
	 */
	private function reset_main_instance(): void {
		$ref  = new \ReflectionClass( Main::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setValue( null, null );
	}

	/**
	 * Clean up after each test.
	 */
	protected function tearDown(): void {
		remove_all_actions( 'init' );
		remove_all_actions( 'admin_enqueue_scripts' );
		remove_all_filters( 'script_loader_tag' );
		delete_option( 'ai_post_triage_version' );

		parent::tearDown();
	}

	/**
	 * Test that get_instance returns the same singleton instance.
	 */
	public function test_get_instance_returns_singleton(): void {
		$this->reset_main_instance();
		$a = Main::get_instance();
		$b = Main::get_instance();
		$this->assertSame( $a, $b );
	}

	/**
	 * Test that activate() sets the version option.
	 */
	public function test_activate_sets_version_option(): void {
		$this->reset_main_instance();

		Main::activate();

		$this->assertEquals( AI_POST_TRIAGE_VERSION, get_option( 'ai_post_triage_version' ) );
	}

	/**
	 * Test that activate() installs the post table.
	 */
	public function test_activate_installs_schema(): void {
		$this->reset_main_instance();
		delete_option( Schema::VERSION_OPTION );

		Main::activate();

		$this->assertTrue( Schema::table_exists() );
		$this->assertTrue( Schema::is_current() );
	}

	/**
	 * Test that deactivate() runs without errors.
	 */
	public function test_deactivate_runs_without_errors(): void {
		$this->reset_main_instance();

		Main::deactivate();

		// Deactivate is currently a no-op, just verify it doesn't throw.
		$this->assertTrue( true );
	}

	/**
	 * Test that setup() registers activation and deactivation hooks.
	 */
	public function test_setup_registers_activation_deactivation_hooks(): void {
		$this->reset_main_instance();

		Main::get_instance();

		$this->assertNotFalse( has_action( 'activate_' . plugin_basename( AI_POST_TRIAGE_FILE ) ) );
		$this->assertNotFalse( has_action( 'deactivate_' . plugin_basename( AI_POST_TRIAGE_FILE ) ) );
	}
}
