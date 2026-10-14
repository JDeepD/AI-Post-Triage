<?php
/**
 * Handles efficient plugin template loading (and overloading).
 *
 * @package JDeepD\Wire_Desk
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk\Core;

use JDeepD\Wire_Desk\Framework\Contracts\Traits\Singleton;
use JDeepD\Wire_Desk\Framework\TemplateLoaderTrait;

/**
 * Class Templates
 */
final class Templates {
	use Singleton;
	use TemplateLoaderTrait {
		TemplateLoaderTrait::get_template_part as trait_get_template_part;
	}

	/**
	 * The hook prefix for all filters and actions in this trait.
	 */
	private const HOOK_PREFIX = 'wire_desk';

	/**
	 * The relative template dir.
	 */
	private const TEMPLATE_DIR = 'templates';

	/**
	 * The theme dir for template overrides
	 */
	private const TEMPLATE_THEME_DIR = 'wire-desk';

	/**
	 * {@inheritDoc}
	 */
	protected function __construct() {
		$this->hook_prefix        = self::HOOK_PREFIX;
		$this->template_theme_dir = self::TEMPLATE_THEME_DIR;
		$this->template_dir       = WIRE_DESK_PATH . self::TEMPLATE_DIR;
	}

	/**
	 * Retrieve or output a template part.
	 *
	 * Wrapper around the trait method to provide a static interface across the plugin.
	 *
	 * @see TemplateLoaderTrait::get_template_part() for details and usage.
	 *
	 * @param string               $slug The slug name for the generic template.
	 * @param string|null          $name The name of the specialized template.
	 * @param array<string, mixed> $args Optional. Arguments to pass to the template.
	 * @param bool                 $load Whether to load the template immediately or just return the path.
	 * @return string|false The template path if found, or false if not found.
	 */
	public static function get_template_part( string $slug, ?string $name = null, array $args = [], bool $load = true ): string|false {
		return self::get_instance()->trait_get_template_part( $slug, $name, $args, $load );
	}
}
