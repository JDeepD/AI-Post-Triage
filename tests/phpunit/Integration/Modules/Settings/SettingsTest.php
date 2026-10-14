<?php
/**
 * SettingsTest file.
 *
 * @package JDeepD\Wire_Desk\Tests\Integration\Modules\Settings
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk\Tests\Integration\Modules\Settings;

use JDeepD\Wire_Desk\Modules\Settings\Settings;
use JDeepD\Wire_Desk\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Class - SettingsTest
 */
#[CoversClass( Settings::class )]
class SettingsTest extends TestCase {
	/**
	 * Load the settings error functions.
	 */
	protected function setUp(): void {
		parent::setUp();

		require_once ABSPATH . 'wp-admin/includes/template.php';
	}

	/**
	 * Clear settings errors.
	 */
	protected function tearDown(): void {
		global $wp_settings_errors;

		$wp_settings_errors = []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Reset between tests.

		parent::tearDown();
	}

	/**
	 * Test that register_hooks adds callbacks to admin_init and rest_api_init.
	 */
	public function test_register_hooks_adds_settings_callbacks_to_admin_init_and_rest_api_init(): void {
		$settings = new Settings();

		$settings->register_hooks();

		$this->assertNotFalse( has_action( 'admin_init', [ $settings, 'register_settings' ] ) );
		$this->assertNotFalse( has_action( 'rest_api_init', [ $settings, 'register_settings' ] ) );
	}

	/**
	 * Test that register_settings registers the delete data option.
	 */
	public function test_register_settings_registers_delete_data_option(): void {
		( new Settings() )->register_settings();

		$registered = get_registered_settings();
		$key        = Settings::DELETE_DATA_OPTION_KEY;

		$this->assertArrayHasKey( $key, $registered );
		$this->assertSame( Settings::SETTING_GROUP, $registered[ $key ]['group'] );
		$this->assertSame( 'boolean', $registered[ $key ]['type'] );
		$this->assertFalse( $registered[ $key ]['default'] );
		$this->assertSame( 'rest_sanitize_boolean', $registered[ $key ]['sanitize_callback'] );
	}

	/**
	 * Test that data is kept on uninstall by default.
	 */
	public function test_delete_data_on_uninstall_defaults_to_false(): void {
		delete_option( Settings::DELETE_DATA_OPTION_KEY );

		$this->assertFalse( Settings::delete_data_on_uninstall() );
	}

	/**
	 * Test that delete_data_on_uninstall() reflects the option.
	 */
	public function test_delete_data_on_uninstall_reflects_option(): void {
		update_option( Settings::DELETE_DATA_OPTION_KEY, true );
		$this->assertTrue( Settings::delete_data_on_uninstall() );

		update_option( Settings::DELETE_DATA_OPTION_KEY, false );
		$this->assertFalse( Settings::delete_data_on_uninstall() );
	}

	/**
	 * Test that register_settings registers the settings option.
	 */
	public function test_register_settings_registers_settings_option(): void {
		( new Settings() )->register_settings();

		$registered = get_registered_settings()[ Settings::OPTION_KEY ] ?? null;

		$this->assertIsArray( $registered );
		$this->assertSame( Settings::SETTING_GROUP, $registered['group'] );
		$this->assertSame( 'object', $registered['type'] );
		$this->assertSame( Settings::defaults(), $registered['default'] );
		$this->assertSame( [ Settings::class, 'sanitize' ], $registered['sanitize_callback'] );
		$this->assertFalse( $registered['show_in_rest'] );
	}

	/**
	 * Test that get() returns the defaults when nothing is saved.
	 */
	public function test_get_returns_defaults_when_nothing_is_saved(): void {
		delete_option( Settings::OPTION_KEY );

		$settings = Settings::get();

		$this->assertSame( Settings::defaults(), $settings );
		$this->assertSame( 'typesafe/jev-1.13', $settings['model'] );
		$this->assertSame( 3, $settings['window_days'] );
		$this->assertSame( 5.0, $settings['daily_budget'] );
		$this->assertSame( 1, $settings['concurrency'] );
		$this->assertCount( 4, $settings['questions'] );
	}

	/**
	 * Test that get() merges saved values over the defaults and drops unknown keys.
	 */
	public function test_get_merges_saved_values_over_defaults(): void {
		update_option(
			Settings::OPTION_KEY,
			[
				'window_days' => 7,
				'unknown'     => 'value',
			]
		);

		$settings = Settings::get();

		$this->assertSame( 7, $settings['window_days'] );
		$this->assertSame( 'typesafe/jev-1.13', $settings['model'] );
		$this->assertArrayNotHasKey( 'unknown', $settings );
	}

	/**
	 * Test that get() ignores a corrupt option.
	 */
	public function test_get_ignores_corrupt_option(): void {
		update_option( Settings::OPTION_KEY, 'corrupt' );

		$this->assertSame( Settings::defaults(), Settings::get() );
	}

	/**
	 * Test that questions() returns the saved questions.
	 */
	public function test_questions_returns_saved_questions(): void {
		$this->assertSame( Settings::defaults()['questions'], Settings::questions() );
	}

	/**
	 * Test the weights and input modes.
	 */
	public function test_weights_and_input_modes(): void {
		$this->assertSame(
			[
				'high'   => 3,
				'medium' => 2,
				'low'    => 1,
			],
			Settings::weights()
		);
		$this->assertSame( [ 'title', 'title_excerpt', 'title_content' ], array_keys( Settings::input_modes() ) );
	}

	/**
	 * Test that sanitize() keeps saved values for fields that weren't submitted.
	 */
	public function test_sanitize_keeps_fields_not_submitted(): void {
		update_option( Settings::OPTION_KEY, [ 'window_days' => 9 ] );

		$output = Settings::sanitize( [ 'context' => 'National newsroom in Bogotá.' ] );

		$this->assertSame( 9, $output['window_days'] );
		$this->assertSame( 'National newsroom in Bogotá.', $output['context'] );
	}

	/**
	 * Test that sanitize() treats non-array input as an empty submission.
	 */
	public function test_sanitize_handles_non_array_input(): void {
		$this->assertSame( Settings::defaults(), Settings::sanitize( 'not an array' ) );
	}

	/**
	 * Test that sanitize() keeps only registered post types.
	 */
	public function test_sanitize_post_types(): void {
		register_post_type( 'wire_item' );

		$output = Settings::sanitize( [ 'post_types' => [ 'post', 'wire_item', 'missing', 'post', [ 'array' ] ] ] );

		$this->assertSame( [ 'post', 'wire_item' ], $output['post_types'] );

		unregister_post_type( 'wire_item' );
	}

	/**
	 * Test that sanitize() keeps only existing terms of existing taxonomies.
	 */
	public function test_sanitize_terms(): void {
		register_taxonomy( 'wire_source', 'post' );

		$category = self::factory()->category->create();
		$source   = self::factory()->term->create( [ 'taxonomy' => 'wire_source' ] );
		$tag      = self::factory()->tag->create();

		$output = Settings::sanitize(
			[
				'terms' => [
					'category'    => [ $category, (string) $category, 999999, 0 ],
					'wire_source' => [ $source, $tag ],
					'missing'     => [ $category ],
					'post_tag'    => [],
				],
			]
		);

		$this->assertSame(
			[
				'category'    => [ $category ],
				'wire_source' => [ $source ],
			],
			$output['terms']
		);

		unregister_taxonomy( 'wire_source' );
	}

	/**
	 * Test that sanitize() converts booleans.
	 */
	public function test_sanitize_auto_score(): void {
		$this->assertFalse( Settings::sanitize( [ 'auto_score' => '0' ] )['auto_score'] );
		$this->assertFalse( Settings::sanitize( [ 'auto_score' => [ '1' ] ] )['auto_score'] );
		$this->assertTrue( Settings::sanitize( [ 'auto_score' => '1' ] )['auto_score'] );
		$this->assertTrue( Settings::sanitize( [ 'auto_score' => 'on' ] )['auto_score'] );
	}

	/**
	 * Test that sanitize() limits the scoring window.
	 */
	public function test_sanitize_window_days(): void {
		$this->assertSame( 0, Settings::sanitize( [ 'window_days' => '0' ] )['window_days'] );
		$this->assertSame( 14, Settings::sanitize( [ 'window_days' => '14' ] )['window_days'] );
		$this->assertSame( Settings::MAX_WINDOW_DAYS, Settings::sanitize( [ 'window_days' => 5000 ] )['window_days'] );
		$this->assertSame( 0, Settings::sanitize( [ 'window_days' => 'abc' ] )['window_days'] );
	}

	/**
	 * Test that sanitize() accepts only known input modes.
	 */
	public function test_sanitize_input(): void {
		$this->assertSame( 'title_content', Settings::sanitize( [ 'input' => 'title_content' ] )['input'] );
		$this->assertSame( 'title', Settings::sanitize( [ 'input' => 'everything' ] )['input'] );
		$this->assertSame( 'title', Settings::sanitize( [ 'input' => [ 'title_content' ] ] )['input'] );
	}

	/**
	 * Test that sanitize() strips tags from the context and limits its length.
	 */
	public function test_sanitize_context(): void {
		$this->assertSame( "Line one\nLine two", Settings::sanitize( [ 'context' => "<b>Line one</b>\nLine two" ] )['context'] );
		$this->assertSame( Settings::MAX_CONTEXT_LENGTH, mb_strlen( Settings::sanitize( [ 'context' => str_repeat( 'á', 2000 ) ] )['context'] ) );
	}

	/**
	 * Test that sanitize() cleans questions.
	 */
	public function test_sanitize_questions(): void {
		$output = Settings::sanitize(
			[
				'questions' => [
					[
						'id'       => 'q_breaking',
						'question' => '<em>Is it breaking?</em>',
						'expected' => 'no',
						'weight'   => 'low',
						'required' => '1',
					],
					[
						'question' => 'Is it local?',
						'expected' => 'maybe',
						'weight'   => 'huge',
					],
					[
						'id'       => 'q_empty',
						'question' => '   ',
					],
					'not a question',
				],
			]
		);

		$this->assertCount( 2, $output['questions'] );
		$this->assertSame(
			[
				'id'       => 'q_breaking',
				'question' => 'Is it breaking?',
				'expected' => 'no',
				'weight'   => 'low',
				'required' => true,
			],
			$output['questions'][0]
		);
		$this->assertMatchesRegularExpression( '/^q_[a-f0-9]{8}$/', $output['questions'][1]['id'] );
		$this->assertSame( 'yes', $output['questions'][1]['expected'] );
		$this->assertSame( 'medium', $output['questions'][1]['weight'] );
		$this->assertFalse( $output['questions'][1]['required'] );
	}

	/**
	 * Test that sanitize() gives duplicate question IDs a new ID.
	 */
	public function test_sanitize_questions_replaces_duplicate_ids(): void {
		$output = Settings::sanitize(
			[
				'questions' => [
					[
						'id'       => 'q_same',
						'question' => 'First?',
					],
					[
						'id'       => 'q_same',
						'question' => 'Second?',
					],
				],
			]
		);

		$this->assertSame( 'q_same', $output['questions'][0]['id'] );
		$this->assertNotSame( 'q_same', $output['questions'][1]['id'] );
	}

	/**
	 * Test that sanitize() limits the number and length of questions.
	 */
	public function test_sanitize_questions_limits(): void {
		$questions = [];
		for ( $i = 1; $i <= Settings::MAX_QUESTIONS + 5; $i++ ) {
			$questions[] = [ 'question' => str_repeat( 'q', 400 ) . $i ];
		}

		$output = Settings::sanitize( [ 'questions' => $questions ] );

		$this->assertCount( Settings::MAX_QUESTIONS, $output['questions'] );
		$this->assertSame( Settings::MAX_QUESTION_LENGTH, mb_strlen( $output['questions'][0]['question'] ) );
		$this->assertSame( [ 'questions' ], $this->error_codes() );
	}

	/**
	 * Test that sanitize() saves valid thresholds.
	 */
	public function test_sanitize_thresholds(): void {
		$output = Settings::sanitize(
			[
				'high_threshold'   => '80',
				'medium_threshold' => '50',
			]
		);

		$this->assertSame( 80, $output['high_threshold'] );
		$this->assertSame( 50, $output['medium_threshold'] );
		$this->assertSame( 100, Settings::sanitize( [ 'high_threshold' => 250 ] )['high_threshold'] );
	}

	/**
	 * Test that sanitize() rejects a High threshold that isn't above Medium.
	 */
	public function test_sanitize_rejects_thresholds_out_of_order(): void {
		$output = Settings::sanitize(
			[
				'high_threshold'   => '40',
				'medium_threshold' => '60',
			]
		);

		$this->assertSame( 70, $output['high_threshold'] );
		$this->assertSame( 40, $output['medium_threshold'] );
		$this->assertSame( [ 'thresholds' ], $this->error_codes() );
	}

	/**
	 * Test that sanitize() checks a single threshold against the saved one.
	 */
	public function test_sanitize_checks_single_threshold_against_saved(): void {
		$this->assertSame( 40, Settings::sanitize( [ 'medium_threshold' => 70 ] )['medium_threshold'] );
		$this->assertSame( [ 'thresholds' ], $this->error_codes() );

		$this->assertSame( 65, Settings::sanitize( [ 'medium_threshold' => 65 ] )['medium_threshold'] );
	}

	/**
	 * Test that sanitize() accepts valid model IDs only.
	 */
	public function test_sanitize_model(): void {
		$this->assertSame( 'typesafe/clef', Settings::sanitize( [ 'model' => ' typesafe/clef ' ] )['model'] );
		$this->assertSame( 'liquid/d1:free', Settings::sanitize( [ 'model' => 'liquid/d1:free' ] )['model'] );
		$this->assertSame( [], $this->error_codes() );

		$this->assertSame( 'typesafe/jev-1.13', Settings::sanitize( [ 'model' => 'not a model' ] )['model'] );
		$this->assertSame( [ 'model' ], $this->error_codes() );
	}

	/**
	 * Test that sanitize() cleans the daily budget.
	 */
	public function test_sanitize_daily_budget(): void {
		$this->assertSame( 12.35, Settings::sanitize( [ 'daily_budget' => '12.345' ] )['daily_budget'] );
		$this->assertSame( 0.0, Settings::sanitize( [ 'daily_budget' => '-3' ] )['daily_budget'] );
		$this->assertSame( 5.0, Settings::sanitize( [ 'daily_budget' => 'lots' ] )['daily_budget'] );
	}

	/**
	 * Test that sanitize() limits concurrency.
	 */
	public function test_sanitize_concurrency(): void {
		$this->assertSame( 3, Settings::sanitize( [ 'concurrency' => '3' ] )['concurrency'] );
		$this->assertSame( 1, Settings::sanitize( [ 'concurrency' => '0' ] )['concurrency'] );
		$this->assertSame( Settings::MAX_CONCURRENCY, Settings::sanitize( [ 'concurrency' => 50 ] )['concurrency'] );
	}

	/**
	 * Test that saving the option runs it through sanitize().
	 */
	public function test_saving_option_sanitizes_it(): void {
		( new Settings() )->register_settings();

		update_option(
			Settings::OPTION_KEY,
			[
				'window_days' => 9000,
				'model'       => 'typesafe/clef',
			]
		);

		$saved = get_option( Settings::OPTION_KEY );

		$this->assertIsArray( $saved );
		$this->assertSame( Settings::MAX_WINDOW_DAYS, $saved['window_days'] );
		$this->assertSame( 'typesafe/clef', $saved['model'] );
		$this->assertSame( Settings::defaults()['questions'], $saved['questions'] );
	}

	/**
	 * Codes of the settings errors added for the settings option.
	 *
	 * @return string[]
	 */
	private function error_codes(): array {
		return array_values( array_unique( wp_list_pluck( get_settings_errors( Settings::OPTION_KEY ), 'code' ) ) );
	}
}
