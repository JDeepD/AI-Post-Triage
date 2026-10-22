<?php
/**
 * Builds what the model reads about a post.
 *
 * @package JDeepD\AI_Post_Triage
 */

declare( strict_types = 1 );

namespace JDeepD\AI_Post_Triage\Scoring;

use JDeepD\AI_Post_Triage\Modules\Settings\Settings;

/**
 * Class - State_Builder
 */
final class State_Builder {
	/**
	 * Maximum headline length.
	 */
	public const HEADLINE_LIMIT = 500;

	/**
	 * Maximum summary length.
	 */
	public const SUMMARY_LIMIT = 1000;

	/**
	 * Maximum body length.
	 */
	public const BODY_LIMIT = 2000;

	/**
	 * Facts about a post, as set by the "Model reads" setting.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string, string>
	 */
	public static function for_post( \WP_Post $post ): array {
		$settings = Settings::get();
		$state    = self::base( $post->post_title, $settings['context'] );

		if ( 'title_excerpt' === $settings['input'] ) {
			$summary = self::clean( $post->post_excerpt, self::SUMMARY_LIMIT );

			if ( '' === $summary ) {
				$summary = self::first_paragraph( $post->post_content );
			}

			if ( '' !== $summary ) {
				$state['summary'] = $summary;
			}
		} elseif ( 'title_content' === $settings['input'] ) {
			$body = self::clean( $post->post_content, self::BODY_LIMIT );

			if ( '' !== $body ) {
				$state['body'] = $body;
			}
		}

		/**
		 * Filters what the model reads about a post.
		 *
		 * Values must be strings. Empty values are dropped.
		 *
		 * @param array<string, string> $state Facts about the post, such as `headline`, `summary`, `body` and `newsroom`.
		 * @param \WP_Post              $post  Post.
		 */
		$state = apply_filters( 'ai_post_triage/state', $state, $post );

		return self::normalize( $state );
	}

	/**
	 * Facts about a headline on its own.
	 *
	 * @param string $headline Headline.
	 * @return array<string, string>
	 */
	public static function for_headline( string $headline ): array {
		return self::base( $headline, Settings::get()['context'] );
	}

	/**
	 * Converts markup to plain text of at most the given length.
	 *
	 * Removes shortcodes, tags, block comments and the contents of scripts and styles, decodes entities
	 * and collapses whitespace.
	 *
	 * @param string $text  Text or markup.
	 * @param int    $limit Maximum length in characters.
	 */
	public static function clean( string $text, int $limit ): string {
		$text = (string) preg_replace( '#</(?:p|div|h[1-6]|li|blockquote|figcaption|td|th)>|<br\s*/?>#i', '$0 ', strip_shortcodes( $text ) );
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = trim( (string) preg_replace( '/[\s\x{00A0}]+/u', ' ', $text ) );

		return trim( mb_substr( $text, 0, $limit ) );
	}

	/**
	 * The headline and newsroom context.
	 *
	 * @param string $headline Headline.
	 * @param string $context  Newsroom context.
	 * @return array<string, string>
	 */
	private static function base( string $headline, string $context ): array {
		$state = [];

		if ( '' !== $context ) {
			$state['newsroom'] = $context;
		}

		$state['headline'] = self::clean( $headline, self::HEADLINE_LIMIT );

		return $state;
	}

	/**
	 * The first paragraph of the content that has text.
	 *
	 * @param string $content Post content.
	 */
	private static function first_paragraph( string $content ): string {
		foreach ( preg_split( '#</p>|<br\s*/?>|\R\s*\R#i', strip_shortcodes( $content ) ) ?: [] as $paragraph ) {
			$paragraph = self::clean( $paragraph, self::SUMMARY_LIMIT );

			if ( '' !== $paragraph ) {
				return $paragraph;
			}
		}

		return '';
	}

	/**
	 * Keeps string keys with non-empty scalar values.
	 *
	 * @param mixed $state Filtered state.
	 * @return array<string, string>
	 */
	private static function normalize( $state ): array {
		$normalized = [];

		foreach ( is_array( $state ) ? $state : [] as $key => $value ) {
			if ( ! is_string( $key ) || ! is_scalar( $value ) ) {
				continue;
			}

			$value = trim( (string) $value );

			if ( '' !== $value ) {
				$normalized[ $key ] = $value;
			}
		}

		return $normalized;
	}
}
