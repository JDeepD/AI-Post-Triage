<?php
/**
 * Admin_ScreenTest file.
 *
 * @package JDeepD\Wire_Desk\Tests\Integration\Modules\Admin
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk\Tests\Integration\Modules\Admin;

use JDeepD\Wire_Desk\Core\Assets;
use JDeepD\Wire_Desk\Core\Capabilities;
use JDeepD\Wire_Desk\Decisions\Model_Catalogue;
use JDeepD\Wire_Desk\Jobs\Usage;
use JDeepD\Wire_Desk\Modules\Settings\Admin_Screen;
use JDeepD\Wire_Desk\Modules\Settings\Settings;
use JDeepD\Wire_Desk\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Class - Admin_ScreenTest
 */
#[CoversClass( Admin_Screen::class )]
class Admin_ScreenTest extends TestCase {
	/**
	 * Load the admin functions the screen uses.
	 */
	protected function setUp(): void {
		parent::setUp();

		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		set_transient(
			Model_Catalogue::TRANSIENT,
			[
				'cloudflare/clef'   => 'Cloudflare: Clef',
				'typesafe/jev-1.13' => 'TypeSafe: Jev 1.13',
			]
		);
	}

	/**
	 * Reset the admin menu and settings globals.
	 */
	protected function tearDown(): void {
		foreach ( [ 'menu', 'submenu', 'wp_settings_sections', 'wp_settings_fields', 'wp_settings_errors' ] as $global ) {
			$GLOBALS[ $global ] = []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Reset between tests.
		}

		parent::tearDown();
	}

	/**
	 * Test that register_hooks adds appropriate actions and filters.
	 */
	public function test_register_hooks_registers_admin_menu_and_plugin_action_links_hooks(): void {
		$screen = new Admin_Screen();

		$screen->register_hooks();

		$this->assertNotFalse( \has_action( 'admin_menu', [ $screen, 'register_screen' ] ) );
		$this->assertNotFalse( \has_action( 'admin_init', [ $screen, 'register_fields' ] ) );
		$this->assertNotFalse( \has_filter( 'option_page_capability_' . Settings::SETTING_GROUP, [ $screen, 'get_capability' ] ) );
		$this->assertNotFalse( \has_filter( 'plugin_action_links_' . \plugin_basename( WIRE_DESK_FILE ), [ $screen, 'add_action_links' ] ) );
	}

	/**
	 * Test that add_action_links appends the settings link.
	 */
	public function test_add_action_links_appends_settings_link(): void {
		$actual = ( new Admin_Screen() )->add_action_links( [ '<a href="plugins.php">Plugins</a>' ] );

		$this->assertCount( 2, $actual );
		$this->assertStringContainsString( 'admin.php?page=wire-desk-settings', $actual[1] );
		$this->assertStringContainsString( 'Settings', $actual[1] );
	}

	/**
	 * Test that register_screen adds the top-level menu, its Settings page and the load hook.
	 */
	public function test_register_screen_adds_menu_and_settings_page(): void {
		global $menu, $submenu;

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		( new Admin_Screen() )->register_screen();

		( new Assets() )->register_admin_assets();
		do_action( 'load-' . get_plugin_page_hookname( Admin_Screen::SCREEN_ID, '' ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores

		$top_level = array_values( array_filter( (array) $menu, static fn ( $item ): bool => Admin_Screen::SCREEN_ID === ( $item[2] ?? null ) ) );

		$this->assertCount( 1, $top_level );
		$this->assertSame( 'Wire Desk', $top_level[0][0] );
		$this->assertSame( Capabilities::MANAGE, $top_level[0][1] );
		$this->assertSame( 'dashicons-rss', $top_level[0][6] );
		$this->assertSame( 'Settings', $submenu[ Admin_Screen::SCREEN_ID ][0][0] ?? null );
		$this->assertTrue( wp_script_is( Assets::ADMIN_HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( Assets::ADMIN_HANDLE, 'enqueued' ) );
	}

	/**
	 * Test that editors can't open the settings menu.
	 */
	public function test_register_screen_hides_settings_from_editors(): void {
		global $menu;

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		( new Admin_Screen() )->register_screen();

		$top_level = array_values( array_filter( (array) $menu, static fn ( $item ): bool => Admin_Screen::SCREEN_ID === ( $item[2] ?? null ) ) );

		$this->assertFalse( current_user_can( $top_level[0][1] ?? 'do_not_allow' ) );
	}

	/**
	 * Test that the settings screen needs the manage capability.
	 */
	public function test_get_capability_returns_manage_capability(): void {
		$this->assertSame( Capabilities::MANAGE, ( new Admin_Screen() )->get_capability() );
	}

	/**
	 * Test that register_fields adds every section and field.
	 */
	public function test_register_fields_adds_sections_and_fields(): void {
		global $wp_settings_sections, $wp_settings_fields;

		( new Admin_Screen() )->register_fields();

		$this->assertSame( array_keys( Admin_Screen::sections() ), array_keys( $wp_settings_sections[ Admin_Screen::SCREEN_ID ] ) );

		$registered = [];
		foreach ( $wp_settings_fields[ Admin_Screen::SCREEN_ID ] as $fields ) {
			$registered = array_merge( $registered, array_keys( $fields ) );
		}

		$this->assertSame(
			[
				'wire-desk-post-types',
				'wire-desk-terms',
				'wire-desk-auto-score',
				'wire-desk-window-days',
				'wire-desk-input',
				'wire-desk-context',
				'wire-desk-rescore',
				'wire-desk-questions',
				'wire-desk-high-threshold',
				'wire-desk-medium-threshold',
				'wire-desk-model',
				'wire-desk-try',
				'wire-desk-daily-budget',
				'wire-desk-concurrency',
				'wire-desk-usage',
				'wire-desk-delete-data-on-uninstall',
			],
			$registered
		);
	}

	/**
	 * Test that the screen renders the settings form with the saved values.
	 */
	public function test_render_screen_renders_form_with_saved_values(): void {
		update_option(
			Settings::OPTION_KEY,
			[
				'post_types'  => [ 'page' ],
				'window_days' => 7,
				'input'       => 'title_content',
				'context'     => 'Regional newsroom',
				'auto_score'  => false,
			]
		);

		$output = $this->render_screen();

		$this->assertStringContainsString( 'action="options.php"', $output );
		$this->assertStringContainsString( "name='option_page' value='" . Settings::SETTING_GROUP . "'", $output );

		foreach ( Admin_Screen::sections() as $title ) {
			$this->assertStringContainsString( '>' . esc_html( $title ) . '</h2>', $output );
		}

		$this->assertMatchesRegularExpression( '/name="wire_desk_settings\[post_types\]\[\]" value="page"\s+checked/', $output );
		$this->assertStringContainsString( 'name="wire_desk_settings[window_days]"', $output );
		$this->assertStringContainsString( 'value="7"', $output );
		$this->assertMatchesRegularExpression( '/value="title_content"\s+checked/', $output );
		$this->assertStringContainsString( 'Regional newsroom</textarea>', $output );
		$this->assertStringContainsString( '<input type="hidden" name="wire_desk_settings[auto_score]" value="0" />', $output );
		$this->assertStringContainsString( 'name="wire_desk_settings[model]"', $output );
		$this->assertStringContainsString( 'value="typesafe/jev-1.13"', $output );
		$this->assertStringContainsString( 'name="' . Settings::DELETE_DATA_OPTION_KEY . '"', $output );
		$this->assertSame( 4 + 1, substr_count( $output, 'data-wire-desk-remove-question' ) );
		$this->assertStringContainsString( 'wire_desk_settings[questions][__INDEX__][question]', $output );
	}

	/**
	 * Test that the model field lists the models by name.
	 */
	public function test_render_screen_lists_models_by_name(): void {
		$output = $this->render_screen();

		$this->assertMatchesRegularExpression( '#<select id="wire-desk-model" name="wire_desk_settings\[model\]">#', $output );
		$this->assertMatchesRegularExpression( '#<option value="cloudflare/clef" >Cloudflare: Clef</option>#', $output );
		$this->assertMatchesRegularExpression( "#<option value=\"typesafe/jev-1.13\"  selected='selected'>TypeSafe: Jev 1.13</option>#", $output );
		$this->assertStringNotContainsString( 'not in the list', $output );
	}

	/**
	 * Test that a saved model missing from the list stays selected.
	 */
	public function test_render_screen_keeps_unlisted_model(): void {
		update_option( Settings::OPTION_KEY, [ 'model' => 'acme/decider-2' ] );

		$this->assertMatchesRegularExpression( '#<option value="acme/decider-2" selected>\s*acme/decider-2 \(not in the list\)\s*</option>#', $this->render_screen() );
	}

	/**
	 * Test that the model field falls back to a text box when the list can't be loaded.
	 */
	public function test_render_screen_falls_back_to_text_model_field(): void {
		set_transient( Model_Catalogue::TRANSIENT, [], Model_Catalogue::FAILURE_TTL );

		$output = $this->render_screen();

		$this->assertStringNotContainsString( '<select id="wire-desk-model"', $output );
		$this->assertMatchesRegularExpression( '#<input\s+type="text"\s+class="regular-text"\s+id="wire-desk-model"\s+name="wire_desk_settings\[model\]"\s+value="typesafe/jev-1.13"#', $output );
		$this->assertStringContainsString( 'The model list could not be loaded from OpenRouter.', $output );
	}

	/**
	 * Test that the try box isn't submitted with the settings.
	 */
	public function test_render_screen_try_box_has_no_setting(): void {
		$output = $this->render_screen();

		$this->assertMatchesRegularExpression( '#<textarea\s+id="wire-desk-try"\s+class="large-text"\s+rows="2"\s+maxlength="500"#', $output );
		$this->assertStringNotContainsString( 'wire_desk_settings[try]', $output );
		$this->assertStringContainsString( 'data-wire-desk-try-submit', $output );
	}

	/**
	 * Test that the re-score box isn't submitted with the settings.
	 */
	public function test_render_screen_rescore_box_has_no_setting(): void {
		$output = $this->render_screen();

		$this->assertMatchesRegularExpression( '#id="wire-desk-rescore"\s+min="1"\s+max="1000"#', $output );
		$this->assertStringNotContainsString( 'wire_desk_settings[rescore]', $output );
		$this->assertStringContainsString( 'data-wire-desk-rescore-submit', $output );
	}

	/**
	 * Test that today's spend is shown against the budget.
	 */
	public function test_render_screen_shows_usage(): void {
		update_option( Settings::OPTION_KEY, [ 'daily_budget' => 5 ] );
		Usage::record_success( 0.0123 );

		$output = $this->render_screen();

		$this->assertStringContainsString( '$0.0123 of $5.00 spent, 1 post scored.', $output );
		$this->assertStringNotContainsString( 'wire-desk-paused', $output );

		update_option( Settings::OPTION_KEY, [ 'daily_budget' => 0 ] );

		$this->assertStringContainsString( '$0.0123 spent, 1 post scored.', $this->render_screen() );
	}

	/**
	 * Test that a pause is shown with a resume button.
	 */
	public function test_render_screen_shows_pause(): void {
		for ( $i = 0; $i < Usage::FAILURE_LIMIT; $i++ ) {
			Usage::record_failure( 'Server error' );
		}

		$output = $this->render_screen();

		$this->assertStringContainsString( 'wire-desk-paused', $output );
		$this->assertStringContainsString( 'Last error: Server error', $output );
		$this->assertStringContainsString( 'action=wire_desk_resume', $output );
	}

	/**
	 * Test that the terms field shows the saved terms for their taxonomy.
	 */
	public function test_render_screen_shows_saved_terms(): void {
		$category = self::factory()->category->create( [ 'name' => 'Wire Feed' ] );

		update_option( Settings::OPTION_KEY, [ 'terms' => [ 'category' => [ $category ] ] ] );

		$output = $this->render_screen();

		$this->assertStringContainsString( 'data-taxonomy="category"', $output );
		$this->assertMatchesRegularExpression( '/name="wire_desk_settings\[terms\]\[category\]\[\]" value="' . $category . '" checked \/>\s+Wire Feed/', $output );
	}

	/**
	 * Test that settings errors are shown on the screen.
	 */
	public function test_render_screen_shows_settings_errors(): void {
		add_settings_error( Settings::OPTION_KEY, 'model', 'The model ID is not valid.' );

		$this->assertStringContainsString( 'The model ID is not valid.', $this->render_screen() );
	}

	/**
	 * Test that submitting the rendered form saves the same settings.
	 */
	public function test_submitting_rendered_form_keeps_settings(): void {
		$category = self::factory()->category->create();

		update_option(
			Settings::OPTION_KEY,
			[
				'post_types'       => [ 'post' ],
				'terms'            => [ 'category' => [ $category ] ],
				'auto_score'       => false,
				'window_days'      => 0,
				'input'            => 'title_excerpt',
				'context'          => "National daily\nBogotá",
				'high_threshold'   => 80,
				'medium_threshold' => 30,
				'model'            => 'typesafe/clef',
				'daily_budget'     => 12.5,
				'concurrency'      => 2,
			]
		);
		update_option( Settings::DELETE_DATA_OPTION_KEY, true );

		$saved     = Settings::get();
		$submitted = $this->submit( $this->render_screen() );

		$this->assertSame( $saved, Settings::sanitize( $submitted[ Settings::OPTION_KEY ] ?? null ) );
		$this->assertSame( '1', $submitted[ Settings::DELETE_DATA_OPTION_KEY ] ?? null );
	}

	/**
	 * Test that unticking every checkbox saves them as off.
	 */
	public function test_submitting_form_with_checkboxes_unticked_turns_them_off(): void {
		update_option( Settings::OPTION_KEY, [ 'post_types' => [ 'post' ] ] );
		update_option( Settings::DELETE_DATA_OPTION_KEY, true );

		$submitted = $this->submit( $this->render_screen(), false );
		$values    = Settings::sanitize( $submitted[ Settings::OPTION_KEY ] ?? null );

		$this->assertSame( [], $values['post_types'] );
		$this->assertFalse( $values['auto_score'] );
		$this->assertFalse( $values['questions'][3]['required'] );
		$this->assertSame( '0', $submitted[ Settings::DELETE_DATA_OPTION_KEY ] ?? null );
	}

	/**
	 * Test that removing every question saves no questions.
	 */
	public function test_submitting_form_without_questions_removes_them(): void {
		$html = (string) preg_replace( '#<tbody data-wire-desk-questions>.*?</tbody>#s', '<tbody data-wire-desk-questions></tbody>', $this->render_screen() );

		$values = Settings::sanitize( $this->submit( $html )[ Settings::OPTION_KEY ] ?? null );

		$this->assertSame( [], $values['questions'] );
	}

	/**
	 * Renders the settings screen with its fields.
	 */
	private function render_screen(): string {
		$screen = new Admin_Screen();
		$screen->register_fields();

		ob_start();
		$screen->render_screen();
		return (string) ob_get_clean();
	}

	/**
	 * Builds the data a browser would submit for a rendered form.
	 *
	 * @param string $html    Rendered screen.
	 * @param bool   $checked Whether to submit checkboxes as rendered, or all unticked.
	 * @return array<string, mixed>
	 */
	private function submit( string $html, bool $checked = true ): array {
		$document = new \DOMDocument();
		libxml_use_internal_errors( true );
		$document->loadHTML( '<?xml encoding="utf-8"?>' . $html );
		libxml_clear_errors();

		$xpath = new \DOMXPath( $document );
		$pairs = [];

		foreach ( $xpath->query( '//form//*[(self::input or self::select or self::textarea) and @name and not(ancestor::template)]' ) ?: [] as $element ) {
			if ( ! $element instanceof \DOMElement ) {
				continue;
			}

			$name = $element->getAttribute( 'name' );
			$type = $element->getAttribute( 'type' );

			if ( 'select' === $element->nodeName ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM property.
				$selected = $xpath->query( './/option[@selected]', $element );
				$option   = $selected && $selected->length ? $selected->item( 0 ) : $xpath->query( './/option', $element )?->item( 0 );
				$value    = $option instanceof \DOMElement ? $option->getAttribute( 'value' ) : '';
			} elseif ( 'textarea' === $element->nodeName ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM property.
				$value = $element->textContent; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM property.
			} elseif ( in_array( $type, [ 'checkbox', 'radio' ], true ) ) {
				if ( ! $element->hasAttribute( 'checked' ) || ( 'checkbox' === $type && ! $checked ) ) {
					continue;
				}
				$value = $element->getAttribute( 'value' );
			} elseif ( in_array( $type, [ 'submit', 'button', 'search' ], true ) ) {
				continue;
			} else {
				$value = $element->getAttribute( 'value' );
			}

			$pairs[] = rawurlencode( $name ) . '=' . rawurlencode( $value );
		}

		$data = [];
		parse_str( implode( '&', $pairs ), $data );

		return $data;
	}
}
