<?php
/**
 * Decides whether a post is wire content.
 *
 * @package JDeepD\AI_Post_Triage
 */

declare( strict_types = 1 );

namespace JDeepD\AI_Post_Triage\Jobs;

use JDeepD\AI_Post_Triage\Modules\Settings\Settings;
use WP_Post;

/**
 * Class - Wire_Detector
 */
final class Wire_Detector {
	/**
	 * Whether a post is wire content: one of the wire post types, or tagged with a wire term.
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function is_wire( WP_Post $post ): bool {
		$settings = Settings::get();
		$is_wire  = in_array( $post->post_type, $settings['post_types'], true );

		if ( ! $is_wire ) {
			foreach ( $settings['terms'] as $taxonomy => $term_ids ) {
				if ( $term_ids && is_object_in_taxonomy( $post->post_type, $taxonomy ) && has_term( $term_ids, $taxonomy, $post ) ) {
					$is_wire = true;
					break;
				}
			}
		}

		/**
		 * Filters whether a post is wire content.
		 *
		 * @param bool     $is_wire Whether the post is wire content.
		 * @param \WP_Post $post    Post.
		 */
		return (bool) apply_filters( 'ai_post_triage/is_wire_content', $is_wire, $post );
	}

	/**
	 * Whether a post is recent enough to be scored automatically.
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function in_window( WP_Post $post ): bool {
		$days = Settings::get()['window_days'];

		if ( $days <= 0 ) {
			return true;
		}

		$time = get_post_timestamp( $post );

		return false === $time || $time >= time() - $days * DAY_IN_SECONDS;
	}
}
