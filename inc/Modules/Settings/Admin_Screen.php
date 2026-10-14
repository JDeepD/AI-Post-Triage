<?php
/**
 * Registers the Settings Screen
 *
 * @package JDeepD\Wire_Desk\Modules\Settings
 */

declare(strict_types = 1);

namespace JDeepD\Wire_Desk\Modules\Settings;

use JDeepD\Wire_Desk\Core\Assets;
use JDeepD\Wire_Desk\Core\Capabilities;
use JDeepD\Wire_Desk\Core\Templates;
use JDeepD\Wire_Desk\Framework\Contracts\Interfaces\Registrable;

/**
 * Class - Admin_Screen
 *
 * @phpstan-type Field array{key: string, title: string, section: string, template: string, option?: string, label?: string, description?: string, min?: int|float, max?: int|float, step?: int|float}
 */
final class Admin_Screen implements Registrable {
	/**
	 * The screen ID for the settings page.
	 */
	public const SCREEN_ID = 'wire-desk-settings';

	/**
	 * The settings section for plugin data.
	 */
	public const DATA_SECTION = 'wire_desk_data';

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'register_screen' ] );
		add_action( 'admin_init', [ $this, 'register_fields' ] );
		add_filter( 'option_page_capability_' . Settings::SETTING_GROUP, [ $this, 'get_capability' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( WIRE_DESK_FILE ), [ $this, 'add_action_links' ], 2 );
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
			__( 'Settings', 'wire-desk' )
		);

		return $links;
	}

	/**
	 * Hook the settings screen into the Admin menu.
	 */
	public function register_screen(): void {
		$hook_suffix = add_menu_page(
			__( 'Wire Desk', 'wire-desk' ),
			__( 'Wire Desk', 'wire-desk' ),
			Capabilities::MANAGE,
			self::SCREEN_ID,
			[ $this, 'render_screen' ],
			'dashicons-rss',
			26
		);

		add_submenu_page(
			self::SCREEN_ID,
			__( 'Wire Desk Settings', 'wire-desk' ),
			__( 'Settings', 'wire-desk' ),
			Capabilities::MANAGE,
			self::SCREEN_ID,
			[ $this, 'render_screen' ]
		);

		add_action( "load-{$hook_suffix}", [ $this, 'enqueue_scripts' ], 10, 0 );
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
			'wire_desk_sources'   => __( 'Sources', 'wire-desk' ),
			'wire_desk_scoring'   => __( 'Scoring', 'wire-desk' ),
			'wire_desk_questions' => __( 'Questions', 'wire-desk' ),
			'wire_desk_bands'     => __( 'Priority bands', 'wire-desk' ),
			'wire_desk_model'     => __( 'Model', 'wire-desk' ),
			'wire_desk_limits'    => __( 'Limits', 'wire-desk' ),
			self::DATA_SECTION    => __( 'Data', 'wire-desk' ),
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
				'title'    => __( 'Post types', 'wire-desk' ),
				'section'  => 'wire_desk_sources',
				'template' => 'post-types',
			],
			[
				'key'      => 'terms',
				'title'    => __( 'Terms', 'wire-desk' ),
				'section'  => 'wire_desk_sources',
				'template' => 'terms',
			],
			[
				'key'      => 'auto_score',
				'title'    => __( 'Automatic scoring', 'wire-desk' ),
				'section'  => 'wire_desk_scoring',
				'template' => 'checkbox',
				'label'    => __( 'Score wire posts when they are saved', 'wire-desk' ),
			],
			[
				'key'         => 'window_days',
				'title'       => __( 'Scoring window', 'wire-desk' ),
				'section'     => 'wire_desk_scoring',
				'template'    => 'number',
				'min'         => 0,
				'max'         => Settings::MAX_WINDOW_DAYS,
				'step'        => 1,
				'description' => __( 'Only posts from the last this many days are scored automatically. 0 means no limit.', 'wire-desk' ),
			],
			[
				'key'      => 'input',
				'title'    => __( 'Model reads', 'wire-desk' ),
				'section'  => 'wire_desk_scoring',
				'template' => 'input',
			],
			[
				'key'         => 'context',
				'title'       => __( 'Newsroom context', 'wire-desk' ),
				'section'     => 'wire_desk_scoring',
				'template'    => 'context',
				'description' => __( 'Who the newsroom serves, sent with every post. For example: a national daily in Colombia.', 'wire-desk' ),
			],
			[
				'key'      => 'questions',
				'title'    => __( 'Questions', 'wire-desk' ),
				'section'  => 'wire_desk_questions',
				'template' => 'questions',
			],
			[
				'key'         => 'high_threshold',
				'title'       => __( 'High', 'wire-desk' ),
				'section'     => 'wire_desk_bands',
				'template'    => 'number',
				'min'         => 1,
				'max'         => 100,
				'step'        => 1,
				'description' => __( 'Posts scoring at least this much are High.', 'wire-desk' ),
			],
			[
				'key'         => 'medium_threshold',
				'title'       => __( 'Medium', 'wire-desk' ),
				'section'     => 'wire_desk_bands',
				'template'    => 'number',
				'min'         => 0,
				'max'         => 100,
				'step'        => 1,
				'description' => __( 'Posts scoring at least this much are Medium. The rest are Low.', 'wire-desk' ),
			],
			[
				'key'         => 'model',
				'title'       => __( 'Model', 'wire-desk' ),
				'section'     => 'wire_desk_model',
				'template'    => 'text',
				'description' => __( 'OpenRouter model ID, e.g. typesafe/jev-1.13.', 'wire-desk' ),
			],
			[
				'key'         => 'daily_budget',
				'title'       => __( 'Daily budget (USD)', 'wire-desk' ),
				'section'     => 'wire_desk_limits',
				'template'    => 'number',
				'min'         => 0,
				'step'        => 0.01,
				'description' => __( 'Scoring stops for the day once this is spent. 0 means no limit.', 'wire-desk' ),
			],
			[
				'key'         => 'concurrency',
				'title'       => __( 'Jobs at once', 'wire-desk' ),
				'section'     => 'wire_desk_limits',
				'template'    => 'number',
				'min'         => 1,
				'max'         => Settings::MAX_CONCURRENCY,
				'step'        => 1,
				'description' => __( 'How many posts are scored at the same time.', 'wire-desk' ),
			],
			[
				'key'         => 'delete_data_on_uninstall',
				'option'      => Settings::DELETE_DATA_OPTION_KEY,
				'title'       => __( 'Uninstall', 'wire-desk' ),
				'section'     => self::DATA_SECTION,
				'template'    => 'checkbox',
				'label'       => __( 'Delete all Wire Desk data when the plugin is deleted', 'wire-desk' ),
				'description' => __( 'Drops the Wire Desk table and deletes its settings. This can\'t be undone.', 'wire-desk' ),
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
				in_array( $field['template'], [ 'checkbox', 'number', 'text', 'context' ], true )
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
				$args['selected']   = self::selected_terms( $settings['terms'] );
				break;

			case 'input':
				$args['modes'] = Settings::input_modes();
				break;

			case 'questions':
				$args['weights'] = Settings::weights();
				$args['max']     = Settings::MAX_QUESTIONS;
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
	 * @return \WP_Taxonomy[]
	 */
	public static function taxonomies(): array {
		$post_types = wp_list_pluck( self::post_types(), 'name' );

		return array_values(
			array_filter(
				get_taxonomies( [ 'show_ui' => true ], 'objects' ),
				static fn ( \WP_Taxonomy $taxonomy ): bool => (bool) array_intersect( $taxonomy->object_type, $post_types )
			)
		);
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
	 * Enqueue and localize scripts.
	 *
	 * @internal Used by register_screen().
	 */
	public function enqueue_scripts(): void {
		wp_localize_script( Assets::ADMIN_HANDLE, 'wireDeskAdmin', self::get_localized_data() );
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
		return 'wire-desk-' . str_replace( '_', '-', $field['key'] );
	}

	/**
	 * Localize plugin data for script access.
	 *
	 * Will be available via window.wireDeskAdmin.
	 *
	 * @return array<string, mixed>
	 */
	private function get_localized_data(): array {
		return [
			'pluginVersion' => WIRE_DESK_VERSION,
		];
	}
}
