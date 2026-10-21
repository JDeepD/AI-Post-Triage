<?php
/**
 * Integration tests for Usage_Notice.
 *
 * @package JDeepD\Wire_Desk\Tests\Integration\Modules\Settings
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk\Tests\Integration\Modules\Settings;

use JDeepD\Wire_Desk\Jobs\Usage;
use JDeepD\Wire_Desk\Modules\Settings\Settings;
use JDeepD\Wire_Desk\Modules\Settings\Usage_Notice;
use JDeepD\Wire_Desk\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Class - Usage_NoticeTest
 */
#[CoversClass( Usage_Notice::class )]
class Usage_NoticeTest extends TestCase {
	/**
	 * Signs in an administrator.
	 */
	protected function setUp(): void {
		parent::setUp();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	/**
	 * Test that the notice and resume handler are hooked.
	 */
	public function test_register_hooks(): void {
		$notice = new Usage_Notice();
		$notice->register_hooks();

		$this->assertSame( 10, has_action( 'admin_notices', [ $notice, 'render' ] ) );
		$this->assertSame( 10, has_action( 'admin_post_' . Usage_Notice::RESUME_ACTION, [ $notice, 'handle_resume' ] ) );
	}

	/**
	 * Test that nothing is shown while scoring runs normally.
	 */
	public function test_render_nothing_by_default(): void {
		$this->assertSame( '', $this->render() );
	}

	/**
	 * Test that a pause is shown with its last error and a resume link.
	 */
	public function test_render_pause(): void {
		$this->pause( 'OpenRouter returned 401: <b>Bad key</b>' );

		$output = $this->render();

		$this->assertStringContainsString( 'notice-error', $output );
		$this->assertStringContainsString( sprintf( 'paused scoring after %d failed requests in a row', Usage::FAILURE_LIMIT ), $output );
		$this->assertStringContainsString( 'Last error: OpenRouter returned 401: &lt;b&gt;Bad key&lt;/b&gt;', $output );
		$this->assertStringContainsString( 'action=' . Usage_Notice::RESUME_ACTION, $output );
		$this->assertStringContainsString( '_wpnonce=', $output );
	}

	/**
	 * Test that a spent budget is shown.
	 */
	public function test_render_budget(): void {
		update_option( Settings::OPTION_KEY, [ 'daily_budget' => 2.5 ] );
		Usage::record_success( 2.5 );

		$output = $this->render();

		$this->assertStringContainsString( 'notice-warning', $output );
		$this->assertStringContainsString( 'spent today&#039;s budget of $2.50', $output );
		$this->assertStringContainsString( 'page=wire-desk-settings', $output );
	}

	/**
	 * Test that editors don't see the notice.
	 */
	public function test_render_hidden_from_editors(): void {
		$this->pause( 'Server error' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->assertSame( '', $this->render() );
	}

	/**
	 * Test that the notice shows on the settings screen too.
	 */
	public function test_render_on_settings_screen(): void {
		$this->pause( 'Server error' );

		$this->assertStringContainsString( 'notice-error', $this->render( 'wire-desk_page_wire-desk-settings' ) );
	}

	/**
	 * Test that the notice isn't shown on other admin screens.
	 */
	public function test_render_hidden_on_other_screens(): void {
		$this->pause( 'Server error' );

		$this->assertSame( '', $this->render( 'dashboard' ) );
		$this->assertSame( '', $this->render( 'edit-post' ) );
	}

	/**
	 * Test that resuming clears the pause and redirects back.
	 */
	public function test_handle_resume(): void {
		$this->pause( 'Server error' );

		$redirect = '';

		add_filter(
			'wp_redirect',
			static function ( $location ) use ( &$redirect ) {
				$redirect = $location;
				return false;
			}
		);

		$_REQUEST['_wpnonce'] = wp_create_nonce( Usage_Notice::RESUME_ACTION );

		( new Usage_Notice() )->handle_resume();

		$this->assertSame( 0, Usage::paused_until() );
		$this->assertStringContainsString( 'page=wire-desk-settings', $redirect );
	}

	/**
	 * Test that resuming needs a valid nonce.
	 */
	public function test_handle_resume_requires_nonce(): void {
		$this->pause( 'Server error' );

		$_REQUEST['_wpnonce'] = 'invalid';

		try {
			( new Usage_Notice() )->handle_resume();
			$this->fail( 'Expected wp_die().' );
		} catch ( \WPDieException $e ) {
			$this->assertGreaterThan( time(), Usage::paused_until() );
		}
	}

	/**
	 * Test that editors can't resume scoring.
	 */
	public function test_handle_resume_requires_capability(): void {
		$this->pause( 'Server error' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$_REQUEST['_wpnonce'] = wp_create_nonce( Usage_Notice::RESUME_ACTION );

		$this->expectException( \WPDieException::class );

		( new Usage_Notice() )->handle_resume();
	}

	/**
	 * Clears the request.
	 */
	protected function tearDown(): void {
		unset( $_REQUEST['_wpnonce'], $GLOBALS['current_screen'] );

		parent::tearDown();
	}

	/**
	 * Pauses scoring.
	 *
	 * @param string $error Last error.
	 */
	private function pause( string $error ): void {
		for ( $i = 0; $i < Usage::FAILURE_LIMIT; $i++ ) {
			Usage::record_failure( $error );
		}
	}

	/**
	 * Renders the notice on a screen.
	 *
	 * @param string $screen Screen ID.
	 */
	private function render( string $screen = 'toplevel_page_wire-desk' ): string {
		set_current_screen( $screen );

		ob_start();
		( new Usage_Notice() )->render();
		return (string) ob_get_clean();
	}
}
