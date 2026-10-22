<?php
/**
 * Enqueue plugin assets, like styles, scripts, and blocks.
 *
 * @package JDeepD\AI_Post_Triage\Core
 */

declare( strict_types = 1 );

namespace JDeepD\AI_Post_Triage\Core;

use JDeepD\AI_Post_Triage\Framework\AssetLoaderTrait;
use JDeepD\AI_Post_Triage\Framework\Contracts\Interfaces\Registrable;

/**
 * Class Assets
 */
final class Assets implements Registrable {
	use AssetLoaderTrait;

	/**
	 * Prefix for all asset handles.
	 */
	private const PREFIX = 'ai-post-triage-';

	/**
	 * Asset handles
	 */
	public const ADMIN_HANDLE  = self::PREFIX . 'admin';
	public const EDITOR_HANDLE = self::PREFIX . 'editor';

	/**
	 * Assets to defer for better performance.
	 */
	private const DEFERRED_ASSETS = [
		self::ADMIN_HANDLE,
		// Add other handles as needed.
	];

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->plugin_dir = (string) AI_POST_TRIAGE_PATH;
		$this->plugin_url = (string) AI_POST_TRIAGE_URL;
		$this->assets_dir = 'build';
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'register_admin_assets' ] );
		add_action( 'enqueue_block_editor_assets', [ $this, 'register_editor_assets' ], 1 );

		// Add defer attribute to certain plugin bundles to improve admin load performance.
		add_filter( 'script_loader_tag', [ $this, 'defer_scripts' ], 10, 2 );
	}

	/**
	 * Register assets for admin.
	 *
	 * Assets are registered once centrally, and enqueued in the modules that need them.
	 */
	public function register_admin_assets(): void {
		// Register admin script and style.
		$this->register_script( self::ADMIN_HANDLE, 'admin-ui' );
		$this->register_style( self::ADMIN_HANDLE, 'admin-ui', [ 'common' ] );

		wp_set_script_translations( self::ADMIN_HANDLE, 'ai-post-triage' );
	}

	/**
	 * Register assets for the block editor.
	 */
	public function register_editor_assets(): void {
		$this->register_script( self::EDITOR_HANDLE, 'editor' );
		$this->register_style( self::EDITOR_HANDLE, 'editor', [ 'wp-components' ] );

		wp_set_script_translations( self::EDITOR_HANDLE, 'ai-post-triage' );
	}

	/**
	 * Add defer attribute to certain plugin bundle scripts to improve loading performance.
	 *
	 * @param string $tag    The script tag.
	 * @param string $handle The script handle.
	 *
	 * @return string Modified script tag.
	 */
	public function defer_scripts( string $tag, string $handle ): string {
		// Bail if we don't need to defer.
		if ( ! in_array( $handle, self::DEFERRED_ASSETS, true ) || false !== strpos( $tag, ' defer' ) ) {
			return $tag;
		}

		return str_replace( ' src', ' defer src', $tag );
	}
}
