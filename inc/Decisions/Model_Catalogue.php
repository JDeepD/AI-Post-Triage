<?php
/**
 * Decision models available on OpenRouter.
 *
 * @package JDeepD\Wire_Desk
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk\Decisions;

use JDeepD\Wire_Desk\Modules\Settings\Settings;

/**
 * Class - Model_Catalogue
 */
final class Model_Catalogue {
	/**
	 * Decision model list endpoint.
	 */
	public const ENDPOINT = 'https://openrouter.ai/api/v1/models?output_modalities=decisions';

	/**
	 * Transient holding the list.
	 */
	public const TRANSIENT = 'wire_desk_models';

	/**
	 * How long the list is kept.
	 */
	public const TTL = DAY_IN_SECONDS;

	/**
	 * How long a failed fetch is remembered.
	 */
	public const FAILURE_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Model ID prefixes left out by default.
	 */
	public const UNSUPPORTED_PREFIXES = [ 'scaledown/', 'respan/' ];

	/**
	 * Decision models, keyed by ID and sorted by name.
	 *
	 * @param bool $refresh Fetch the list again.
	 * @return array<string, string>|\WP_Error Model ID => name.
	 */
	public static function get( bool $refresh = false ): array|\WP_Error {
		$cached = $refresh ? false : get_transient( self::TRANSIENT );

		if ( is_array( $cached ) && $cached ) {
			return $cached;
		}

		if ( [] === $cached ) {
			return self::unavailable();
		}

		$models = self::fetch();

		if ( ! $models ) {
			set_transient( self::TRANSIENT, [], self::FAILURE_TTL );

			return self::unavailable();
		}

		set_transient( self::TRANSIENT, $models, self::TTL );

		return $models;
	}

	/**
	 * Fetches and filters the list.
	 *
	 * @return array<string, string> Model ID => name.
	 */
	private static function fetch(): array {
		$response = function_exists( 'vip_safe_wp_remote_get' )
			? vip_safe_wp_remote_get( self::ENDPOINT, '', 3, 5 )
			: wp_safe_remote_get( self::ENDPOINT, [ 'timeout' => 3 ] );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return [];
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || ! is_array( $body['data'] ?? null ) ) {
			return [];
		}

		/**
		 * Filters the model ID prefixes left out of the model list.
		 *
		 * @param string[] $prefixes Model ID prefixes.
		 */
		$unsupported = array_filter( (array) apply_filters( 'wire_desk/unsupported_models', self::UNSUPPORTED_PREFIXES ), 'is_string' );

		$models = [];

		foreach ( $body['data'] as $model ) {
			$id = is_array( $model ) && is_string( $model['id'] ?? null ) ? $model['id'] : '';

			if ( ! preg_match( Settings::MODEL_PATTERN, $id ) || ! in_array( 'decisions', (array) ( $model['architecture']['output_modalities'] ?? [] ), true ) ) {
				continue;
			}

			foreach ( $unsupported as $prefix ) {
				if ( '' !== $prefix && str_starts_with( $id, $prefix ) ) {
					continue 2;
				}
			}

			$models[ $id ] = is_string( $model['name'] ?? null ) && '' !== $model['name'] ? sanitize_text_field( $model['name'] ) : $id;
		}

		uasort( $models, 'strnatcasecmp' );

		return $models;
	}

	/**
	 * The error for a list that couldn't be loaded.
	 */
	private static function unavailable(): \WP_Error {
		return new \WP_Error( 'wire_desk_models_unavailable', __( 'The model list could not be loaded from OpenRouter.', 'wire-desk' ) );
	}
}
