<?php
/**
 * Loads the plugin's translations.
 *
 * @package JDeepD\Wire_Desk\Core
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk\Core;

use JDeepD\Wire_Desk\Framework\Contracts\Interfaces\Registrable;

/**
 * Class - I18n
 */
final class I18n implements Registrable {
	/**
	 * Text domain.
	 */
	public const DOMAIN = 'wire-desk';

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'init', [ $this, 'load_textdomain' ] );
	}

	/**
	 * Loads the translations bundled in the languages folder. Translations from translate.wordpress.org load on their own.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( self::DOMAIN, false, dirname( plugin_basename( WIRE_DESK_FILE ) ) . '/languages' );
	}

	/**
	 * The folder holding the bundled translations.
	 */
	public static function languages_path(): string {
		return WIRE_DESK_PATH . 'languages';
	}
}
