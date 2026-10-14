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
 *
 * @phpstan-type Question array{id: string, question: string, expected: 'yes'|'no', weight: 'high'|'medium'|'low', required: bool}
 * @phpstan-type Values array{
 *   post_types: string[],
 *   terms: array<string, int[]>,
 *   auto_score: bool,
 *   window_days: int,
 *   input: 'title'|'title_excerpt'|'title_content',
 *   context: string,
 *   questions: Question[],
 *   high_threshold: int,
 *   medium_threshold: int,
 *   model: string,
 *   daily_budget: float,
 *   concurrency: int
 * }
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
	 * Option holding the plugin's settings.
	 */
	public const OPTION_KEY = self::SETTING_PREFIX . 'settings';

	/**
	 * Option for deleting the plugin's data on uninstall.
	 */
	public const DELETE_DATA_OPTION_KEY = self::SETTING_PREFIX . 'delete_data_on_uninstall';

	/**
	 * Maximum number of questions.
	 */
	public const MAX_QUESTIONS = 20;

	/**
	 * Maximum length of the newsroom context.
	 */
	public const MAX_CONTEXT_LENGTH = 1000;

	/**
	 * Maximum length of a question.
	 */
	public const MAX_QUESTION_LENGTH = 300;

	/**
	 * Maximum scoring window, in days.
	 */
	public const MAX_WINDOW_DAYS = 365;

	/**
	 * Maximum number of jobs that run at once.
	 */
	public const MAX_CONCURRENCY = 5;

	/**
	 * Model ID format, e.g. typesafe/jev-1.13.
	 */
	public const MODEL_PATTERN = '#^[a-z0-9._~-]+/[a-z0-9._:-]+$#i';

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
			self::OPTION_KEY             => [
				'type'              => 'object',
				'label'             => __( 'Wire Desk settings', 'wire-desk' ),
				'default'           => self::defaults(),
				'sanitize_callback' => [ self::class, 'sanitize' ],
				'show_in_rest'      => false,
			],
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

	/**
	 * Default settings.
	 *
	 * @return Values
	 */
	public static function defaults(): array {
		return [
			'post_types'       => [],
			'terms'            => [],
			'auto_score'       => true,
			'window_days'      => 3,
			'input'            => 'title',
			'context'          => '',
			'questions'        => [
				[
					'id'       => 'q_breaking',
					'question' => 'Is this breaking or fast-developing news?',
					'expected' => 'yes',
					'weight'   => 'high',
					'required' => false,
				],
				[
					'id'       => 'q_audience',
					'question' => 'Does this article directly affect our core audience?',
					'expected' => 'yes',
					'weight'   => 'high',
					'required' => false,
				],
				[
					'id'       => 'q_routine',
					'question' => 'Is this a routine listing such as market data, weather or sports results?',
					'expected' => 'no',
					'weight'   => 'medium',
					'required' => false,
				],
				[
					'id'       => 'q_advisory',
					'question' => 'Is this an advisory, correction notice or editor\'s note rather than a news article?',
					'expected' => 'no',
					'weight'   => 'high',
					'required' => true,
				],
			],
			'high_threshold'   => 70,
			'medium_threshold' => 40,
			'model'            => 'typesafe/jev-1.13',
			'daily_budget'     => 5.0,
			'concurrency'      => 1,
		];
	}

	/**
	 * The saved settings merged over the defaults.
	 *
	 * @return Values
	 */
	public static function get(): array {
		$saved = get_option( self::OPTION_KEY, [] );

		/** @var Values $settings */
		$settings = array_merge( self::defaults(), is_array( $saved ) ? array_intersect_key( $saved, self::defaults() ) : [] );

		return $settings;
	}

	/**
	 * The questions asked about each post.
	 *
	 * @return Question[]
	 */
	public static function questions(): array {
		return self::get()['questions'];
	}

	/**
	 * Question weights and their multipliers.
	 *
	 * @return array{high: int, medium: int, low: int}
	 */
	public static function weights(): array {
		return [
			'high'   => 3,
			'medium' => 2,
			'low'    => 1,
		];
	}

	/**
	 * What the model reads from each post.
	 *
	 * @return array{title: string, title_excerpt: string, title_content: string}
	 */
	public static function input_modes(): array {
		return [
			'title'         => __( 'Title only', 'wire-desk' ),
			'title_excerpt' => __( 'Title and excerpt (or first paragraph)', 'wire-desk' ),
			'title_content' => __( 'Title and body', 'wire-desk' ),
		];
	}

	/**
	 * Sanitizes submitted settings. Fields left out keep their saved value.
	 *
	 * @param mixed $input Submitted settings.
	 * @return Values
	 */
	public static function sanitize( $input ): array {
		$input   = is_array( $input ) ? $input : [];
		$current = self::get();
		$output  = $current;

		if ( array_key_exists( 'post_types', $input ) ) {
			$output['post_types'] = self::sanitize_post_types( $input['post_types'] );
		}

		if ( array_key_exists( 'terms', $input ) ) {
			$output['terms'] = self::sanitize_terms( $input['terms'] );
		}

		if ( array_key_exists( 'auto_score', $input ) ) {
			$output['auto_score'] = self::to_bool( $input['auto_score'] );
		}

		if ( array_key_exists( 'window_days', $input ) ) {
			$output['window_days'] = min( self::MAX_WINDOW_DAYS, absint( $input['window_days'] ) );
		}

		if ( array_key_exists( 'input', $input ) ) {
			$output['input'] = is_string( $input['input'] ) && array_key_exists( $input['input'], self::input_modes() ) ? $input['input'] : $current['input'];
		}

		if ( array_key_exists( 'context', $input ) ) {
			$output['context'] = mb_substr( sanitize_textarea_field( is_string( $input['context'] ) ? $input['context'] : '' ), 0, self::MAX_CONTEXT_LENGTH );
		}

		if ( array_key_exists( 'questions', $input ) ) {
			$output['questions'] = self::sanitize_questions( $input['questions'] );
		}

		if ( array_key_exists( 'high_threshold', $input ) || array_key_exists( 'medium_threshold', $input ) ) {
			$high   = isset( $input['high_threshold'] ) ? max( 1, min( 100, absint( $input['high_threshold'] ) ) ) : $current['high_threshold'];
			$medium = isset( $input['medium_threshold'] ) ? min( 100, absint( $input['medium_threshold'] ) ) : $current['medium_threshold'];

			if ( $medium < $high ) {
				$output['high_threshold']   = $high;
				$output['medium_threshold'] = $medium;
			} else {
				self::add_error( 'thresholds', __( 'The High threshold must be above the Medium threshold.', 'wire-desk' ) );
			}
		}

		if ( array_key_exists( 'model', $input ) ) {
			$model = is_string( $input['model'] ) ? trim( $input['model'] ) : '';

			if ( preg_match( self::MODEL_PATTERN, $model ) ) {
				$output['model'] = $model;
			} else {
				self::add_error( 'model', __( 'The model ID is not valid.', 'wire-desk' ) );
			}
		}

		if ( array_key_exists( 'daily_budget', $input ) ) {
			$output['daily_budget'] = is_numeric( $input['daily_budget'] ) ? round( max( 0.0, (float) $input['daily_budget'] ), 2 ) : $current['daily_budget'];
		}

		if ( array_key_exists( 'concurrency', $input ) ) {
			$output['concurrency'] = max( 1, min( self::MAX_CONCURRENCY, absint( $input['concurrency'] ) ) );
		}

		return $output;
	}

	/**
	 * Sanitizes post types.
	 *
	 * @param mixed $post_types Submitted post types.
	 * @return string[]
	 */
	private static function sanitize_post_types( $post_types ): array {
		$post_types = array_map( 'sanitize_key', array_filter( (array) $post_types, 'is_string' ) );

		return array_values( array_unique( array_filter( $post_types, 'post_type_exists' ) ) );
	}

	/**
	 * Sanitizes terms, keyed by taxonomy.
	 *
	 * @param mixed $terms Submitted terms.
	 * @return array<string, int[]>
	 */
	private static function sanitize_terms( $terms ): array {
		$output = [];

		foreach ( (array) $terms as $taxonomy => $term_ids ) {
			$taxonomy = sanitize_key( (string) $taxonomy );

			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$term_ids = array_values(
				array_unique(
					array_filter(
						array_map( 'absint', (array) $term_ids ),
						static fn ( int $term_id ): bool => $term_id > 0 && null !== term_exists( $term_id, $taxonomy )
					)
				)
			);

			if ( $term_ids ) {
				$output[ $taxonomy ] = $term_ids;
			}
		}

		return $output;
	}

	/**
	 * Sanitizes questions. Questions without text are dropped.
	 *
	 * @param mixed $questions Submitted questions.
	 * @return Question[]
	 */
	private static function sanitize_questions( $questions ): array {
		$output = [];
		$ids    = [];

		foreach ( (array) $questions as $question ) {
			if ( ! is_array( $question ) ) {
				continue;
			}

			$text = mb_substr( sanitize_text_field( is_string( $question['question'] ?? null ) ? $question['question'] : '' ), 0, self::MAX_QUESTION_LENGTH );

			if ( '' === $text ) {
				continue;
			}

			if ( count( $output ) === self::MAX_QUESTIONS ) {
				self::add_error(
					'questions',
					/* translators: %d: maximum number of questions. */
					sprintf( __( 'Only the first %d questions were saved.', 'wire-desk' ), self::MAX_QUESTIONS )
				);
				break;
			}

			$id = sanitize_key( is_string( $question['id'] ?? null ) ? $question['id'] : '' );

			if ( '' === $id || isset( $ids[ $id ] ) ) {
				$id = 'q_' . substr( md5( $text . count( $output ) ), 0, 8 );
			}

			$ids[ $id ] = true;
			$weight     = is_string( $question['weight'] ?? null ) && array_key_exists( $question['weight'], self::weights() ) ? $question['weight'] : 'medium';

			$output[] = [
				'id'       => $id,
				'question' => $text,
				'expected' => 'no' === ( $question['expected'] ?? null ) ? 'no' : 'yes',
				'weight'   => $weight,
				'required' => self::to_bool( $question['required'] ?? false ),
			];
		}

		return $output;
	}

	/**
	 * Converts a submitted value to a boolean. Accepts 1, "1", "true", "on" and "yes".
	 *
	 * @param mixed $value Submitted value.
	 */
	private static function to_bool( $value ): bool {
		return is_scalar( $value ) && filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Adds a settings error, when the settings screen is loaded.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 */
	private static function add_error( string $code, string $message ): void {
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error( self::OPTION_KEY, $code, $message );
		}
	}
}
