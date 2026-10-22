<?php
/**
 * Registers the Settings Screen
 *
 * @package JDeepD\AI_Post_Triage\Modules\Settings
 */

declare(strict_types = 1);

namespace JDeepD\AI_Post_Triage\Modules\Settings;

use JDeepD\AI_Post_Triage\Core\Assets;
use JDeepD\AI_Post_Triage\Core\Capabilities;
use JDeepD\AI_Post_Triage\Core\Templates;
use JDeepD\AI_Post_Triage\Decisions\Model_Catalogue;
use JDeepD\AI_Post_Triage\Decisions\OpenRouter_Client;
use JDeepD\AI_Post_Triage\Framework\Contracts\Interfaces\Registrable;
use JDeepD\AI_Post_Triage\Jobs\Usage;
use JDeepD\AI_Post_Triage\Modules\Queue\Queue_Screen;

/**
 * Class - Admin_Screen
 *
 * @phpstan-type Field array{key: string, title: string, section: string, template: string, option?: string, label?: string, description?: string, min?: int|float, max?: int|float, step?: int|float}
 */
final class Admin_Screen implements Registrable {
	/**
	 * The screen ID for the settings page.
	 */
	public const SCREEN_ID = 'ai-post-triage-settings';

	/**
	 * The settings section for plugin data.
	 */
	public const DATA_SECTION = 'ai_post_triage_data';

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'register_screen' ], 20 );
		add_action( 'admin_init', [ $this, 'register_fields' ] );
		add_filter( 'option_page_capability_' . Settings::SETTING_GROUP, [ $this, 'get_capability' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( AI_POST_TRIAGE_FILE ), [ $this, 'add_action_links' ], 2 );
	}

	/**
	 * Add action links to the settings on the plugins page.
	 *
	 * @param string[] $links Existing links.
	 *
	 * @return string[]
	 */
	public function add_action_links( $links ): array {
		$links[] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( sprintf( 'admin.php?page=%s', self::SCREEN_ID ) ) ),
			__( 'Settings', 'ai-post-triage' )
		);

		return $links;
	}

	/**
	 * Adds the settings screen to the AI Post Triage menu.
	 */
	public function register_screen(): void {
		$hook_suffix = add_submenu_page(
			Queue_Screen::SCREEN_ID,
			__( 'AI Post Triage Settings', 'ai-post-triage' ),
			__( 'Settings', 'ai-post-triage' ),
			Capabilities::MANAGE,
			self::SCREEN_ID,
			[ $this, 'render_screen' ]
		);

		if ( $hook_suffix ) {
			add_action( "load-{$hook_suffix}", [ $this, 'enqueue_scripts' ], 10, 0 );
		}
	}

	/**
	 * The capability needed to view and save the settings screen.
	 */
	public function get_capability(): string {
		return Capabilities::MANAGE;
	}

	/**
	 * The settings sections, keyed by ID.
	 *
	 * @return array<string, string> Section ID => title.
	 */
	public static function sections(): array {
		return [
			'ai_post_triage_sources'   => __( 'Sources', 'ai-post-triage' ),
			'ai_post_triage_scoring'   => __( 'Scoring', 'ai-post-triage' ),
			'ai_post_triage_questions' => __( 'Questions', 'ai-post-triage' ),
			'ai_post_triage_bands'     => __( 'Priority bands', 'ai-post-triage' ),
			'ai_post_triage_model'     => __( 'Model', 'ai-post-triage' ),
			'ai_post_triage_limits'    => __( 'Limits', 'ai-post-triage' ),
			self::DATA_SECTION         => __( 'Data', 'ai-post-triage' ),
		];
	}

	/**
	 * The settings fields, in display order.
	 *
	 * @return Field[]
	 */
	public static function fields(): array {
		return [
			[
				'key'      => 'post_types',
				'title'    => __( 'Post types', 'ai-post-triage' ),
				'section'  => 'ai_post_triage_sources',
				'template' => 'post-types',
			],
			[
				'key'      => 'terms',
				'title'    => __( 'Terms', 'ai-post-triage' ),
				'section'  => 'ai_post_triage_sources',
				'template' => 'terms',
			],
			[
				'key'      => 'auto_score',
				'title'    => __( 'Automatic scoring', 'ai-post-triage' ),
				'section'  => 'ai_post_triage_scoring',
				'template' => 'checkbox',
				'label'    => __( 'Score wire posts when they are saved', 'ai-post-triage' ),
			],
			[
				'key'         => 'window_days',
				'title'       => __( 'Scoring window', 'ai-post-triage' ),
				'section'     => 'ai_post_triage_scoring',
				'template'    => 'number',
				'min'         => 0,
				'max'         => Settings::MAX_WINDOW_DAYS,
				'step'        => 1,
				'description' => __( 'Only posts from the last this many days are scored automatically. 0 means no limit.', 'ai-post-triage' ),
			],
			[
				'key'      => 'input',
				'title'    => __( 'Model reads', 'ai-post-triage' ),
				'section'  => 'ai_post_triage_scoring',
				'template' => 'input',
			],
			[
				'key'         => 'context',
				'title'       => __( 'Newsroom context', 'ai-post-triage' ),
				'section'     => 'ai_post_triage_scoring',
				'template'    => 'context',
				'description' => __( 'Who the newsroom serves, sent with every post. For example: a national daily in Colombia.', 'ai-post-triage' ),
			],
			[
				'key'         => 'rescore',
				'title'       => __( 'Re-score', 'ai-post-triage' ),
				'section'     => 'ai_post_triage_scoring',
				'template'    => 'rescore',
				'max'         => Rescore_Controller::MAX_LIMIT,
				'description' => __( 'Scores the newest wire posts again with the saved settings, in the background. Posts whose text, questions and model haven\'t changed aren\'t sent to the model again and cost nothing.', 'ai-post-triage' ),
			],
			[
				'key'      => 'questions',
				'title'    => __( 'Questions', 'ai-post-triage' ),
				'section'  => 'ai_post_triage_questions',
				'template' => 'questions',
			],
			[
				'key'         => 'high_threshold',
				'title'       => __( 'High', 'ai-post-triage' ),
				'section'     => 'ai_post_triage_bands',
				'template'    => 'number',
				'min'         => 1,
				'max'         => 100,
				'step'        => 1,
				'description' => __( 'Posts scoring at least this much are High.', 'ai-post-triage' ),
			],
			[
				'key'         => 'medium_threshold',
				'title'       => __( 'Medium', 'ai-post-triage' ),
				'section'     => 'ai_post_triage_bands',
				'template'    => 'number',
				'min'         => 0,
				'max'         => 100,
				'step'        => 1,
				'description' => __( 'Posts scoring at least this much are Medium. The rest are Low.', 'ai-post-triage' ),
			],
			[
				'key'      => 'api_key',
				'option'   => Api_Key::OPTION,
				'title'    => __( 'OpenRouter API key', 'ai-post-triage' ),
				'section'  => 'ai_post_triage_model',
				'template' => 'api-key',
			],
			[
				'key'      => 'model',
				'title'    => __( 'Model', 'ai-post-triage' ),
				'section'  => 'ai_post_triage_model',
				'template' => 'model',
			],
			[
				'key'         => 'try',
				'title'       => __( 'Try a headline', 'ai-post-triage' ),
				'section'     => 'ai_post_triage_model',
				'template'    => 'try',
				'description' => __( 'Asks the selected model the saved questions. Each try is a paid request.', 'ai-post-triage' ),
			],
			[
				'key'         => 'daily_budget',
				'title'       => __( 'Daily budget (USD)', 'ai-post-triage' ),
				'section'     => 'ai_post_triage_limits',
				'template'    => 'number',
				'min'         => 0,
				'step'        => 0.01,
				'description' => __( 'Scoring stops for the day once this is spent. 0 means no limit.', 'ai-post-triage' ),
			],
			[
				'key'         => 'concurrency',
				'title'       => __( 'Jobs at once', 'ai-post-triage' ),
				'section'     => 'ai_post_triage_limits',
				'template'    => 'number',
				'min'         => 1,
				'max'         => Settings::MAX_CONCURRENCY,
				'step'        => 1,
				'description' => __( 'How many posts are scored at the same time.', 'ai-post-triage' ),
			],
			[
				'key'      => 'usage',
				'title'    => __( 'Today', 'ai-post-triage' ),
				'section'  => 'ai_post_triage_limits',
				'template' => 'usage',
			],
			[
				'key'         => 'delete_data_on_uninstall',
				'option'      => Settings::DELETE_DATA_OPTION_KEY,
				'title'       => __( 'Uninstall', 'ai-post-triage' ),
				'section'     => self::DATA_SECTION,
				'template'    => 'checkbox',
				'label'       => __( 'Delete all AI Post Triage data when the plugin is deleted', 'ai-post-triage' ),
				'description' => __( 'Drops the AI Post Triage table and deletes its settings. This can\'t be undone.', 'ai-post-triage' ),
			],
		];
	}

	/**
	 * Register the settings sections and fields.
	 */
	public function register_fields(): void {
		foreach ( self::sections() as $id => $title ) {
			add_settings_section( $id, $title, '__return_null', self::SCREEN_ID );
		}

		foreach ( self::fields() as $field ) {
			$id = self::field_id( $field );

			add_settings_field(
				$id,
				$field['title'],
				[ $this, 'render_field' ],
				self::SCREEN_ID,
				$field['section'],
				in_array( $field['template'], [ 'checkbox', 'number', 'text', 'context', 'model', 'api-key' ], true )
					? [ 'label_for' => $id ] + $field
					: $field
			);
		}
	}

	/**
	 * Render a settings field.
	 *
	 * @internal Used by register_fields().
	 *
	 * @param array<string, mixed> $field Field.
	 * @phpstan-param Field $field
	 */
	public function render_field( array $field ): void {
		$settings = Settings::get();
		$option   = $field['option'] ?? null;

		$args = [
			'id'          => self::field_id( $field ),
			'name'        => $option ?? Settings::OPTION_KEY . '[' . $field['key'] . ']',
			'value'       => null !== $option ? get_option( $option ) : ( $settings[ $field['key'] ] ?? null ),
			'label'       => $field['label'] ?? '',
			'description' => $field['description'] ?? '',
			'min'         => $field['min'] ?? null,
			'max'         => $field['max'] ?? null,
			'step'        => $field['step'] ?? null,
		];

		switch ( $field['template'] ) {
			case 'post-types':
				$args['post_types'] = self::post_types();
				break;

			case 'terms':
				$args['taxonomies'] = self::taxonomies();
				$args['post_types'] = self::taxonomy_post_types( $args['taxonomies'] );
				$args['selected']   = self::selected_terms( $settings['terms'] );
				break;

			case 'input':
				$args['modes'] = Settings::input_modes();
				break;

			case 'questions':
				$args['weights'] = Settings::weights();
				$args['max']     = Settings::MAX_QUESTIONS;
				break;

			case 'api-key':
				$saved = Api_Key::get();

				$args['value']    = '';
				$args['source']   = OpenRouter_Client::key_source();
				$args['constant'] = OpenRouter_Client::KEY_CONSTANT;
				$args['saved']    = '' !== $saved ? Api_Key::hint( $saved ) : '';
				$args['broken']   = '' === $saved && Api_Key::is_saved();
				break;

			case 'model':
				$args['models'] = Model_Catalogue::get();
				break;

			case 'usage':
				$usage = Usage::get();

				$args['cost']       = $usage['cost'];
				$args['calls']      = $usage['calls'];
				$args['budget']     = $settings['daily_budget'];
				$args['paused']     = Usage_Notice::paused_message();
				$args['resume_url'] = Usage_Notice::resume_url();
				break;
		}

		Templates::get_template_part( 'settings-field', $field['template'], $args, true );
	}

	/**
	 * Post types that can be picked as wire content.
	 *
	 * @return \WP_Post_Type[]
	 */
	public static function post_types(): array {
		return array_values(
			array_filter(
				get_post_types( [ 'show_ui' => true ], 'objects' ),
				static fn ( \WP_Post_Type $post_type ): bool => ! $post_type->_builtin || in_array( $post_type->name, [ 'post', 'page' ], true )
			)
		);
	}

	/**
	 * Taxonomies of the selectable post types whose terms can be picked as wire content.
	 *
	 * Custom taxonomies are included even without an admin screen, as feed importers often register them that way.
	 *
	 * @return \WP_Taxonomy[]
	 */
	public static function taxonomies(): array {
		$post_types = wp_list_pluck( self::post_types(), 'name' );

		return array_values(
			array_filter(
				get_taxonomies( [], 'objects' ),
				static fn ( \WP_Taxonomy $taxonomy ): bool => ( $taxonomy->show_ui || ! $taxonomy->_builtin ) && (bool) array_intersect( $taxonomy->object_type, $post_types )
			)
		);
	}

	/**
	 * Names of the selectable post types each taxonomy belongs to.
	 *
	 * @param \WP_Taxonomy[] $taxonomies Taxonomies.
	 * @return array<string, string[]> Taxonomy name => post type names.
	 */
	public static function taxonomy_post_types( array $taxonomies ): array {
		$labels = [];

		foreach ( self::post_types() as $post_type ) {
			$labels[ $post_type->name ] = (string) $post_type->labels->name;
		}

		$post_types = [];

		foreach ( $taxonomies as $taxonomy ) {
			$post_types[ $taxonomy->name ] = array_values( array_intersect_key( $labels, array_flip( $taxonomy->object_type ) ) );
		}

		return $post_types;
	}

	/**
	 * The saved terms, keyed by taxonomy.
	 *
	 * @param array<string, int[]> $terms Term IDs, keyed by taxonomy.
	 * @return array<string, \WP_Term[]>
	 */
	public static function selected_terms( array $terms ): array {
		$selected = [];

		foreach ( $terms as $taxonomy => $term_ids ) {
			$found = get_terms(
				[
					'taxonomy'   => $taxonomy,
					'include'    => $term_ids,
					'hide_empty' => false,
					'orderby'    => 'include',
				]
			);

			if ( is_array( $found ) && $found ) {
				$selected[ $taxonomy ] = array_values(
					array_filter( $found, static fn ( $term ): bool => $term instanceof \WP_Term )
				);
			}
		}

		return $selected;
	}

	/**
	 * Render the admin screen.
	 *
	 * @internal Used by register_screen().
	 */
	public function render_screen(): void {
		Templates::get_template_part( 'admin-screen' );
	}

	/**
	 * Enqueue scripts.
	 *
	 * @internal Used by register_screen().
	 */
	public function enqueue_scripts(): void {
		wp_enqueue_script( Assets::ADMIN_HANDLE );
		wp_enqueue_style( Assets::ADMIN_HANDLE );
	}

	/**
	 * The HTML ID of a field.
	 *
	 * @param array<string, mixed> $field Field.
	 * @phpstan-param Field $field
	 */
	private static function field_id( array $field ): string {
		return 'ai-post-triage-' . str_replace( '_', '-', $field['key'] );
	}
}
