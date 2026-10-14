<?php
/**
 * OpenRouter System One client.
 *
 * @package JDeepD\Wire_Desk
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk\Decisions;

/**
 * Class - OpenRouter_Client
 *
 * @phpstan-import-type Question from \JDeepD\Wire_Desk\Modules\Settings\Settings
 */
final class OpenRouter_Client implements Decision_Client {
	/**
	 * System One endpoint.
	 */
	public const ENDPOINT = 'https://openrouter.ai/api/v1/systemone';

	/**
	 * Constant holding the API key.
	 */
	public const KEY_CONSTANT = 'WIRE_DESK_OPENROUTER_API_KEY';

	/**
	 * Connector holding the API key.
	 */
	public const CONNECTOR_ID = 'openrouter';

	/**
	 * Request timeout in seconds.
	 */
	private const TIMEOUT = 15;

	/**
	 * The OpenRouter API key, from the constant or the OpenRouter connector.
	 */
	public static function api_key(): string {
		$key = defined( self::KEY_CONSTANT ) ? constant( self::KEY_CONSTANT ) : '';
		$key = is_string( $key ) && '' !== $key ? $key : self::connector_key();

		/**
		 * Filters the OpenRouter API key.
		 *
		 * @param string $key API key. Empty when none is configured.
		 */
		$key = apply_filters( 'wire_desk/openrouter_api_key', $key );

		return is_string( $key ) ? trim( $key ) : '';
	}

	/**
	 * {@inheritDoc}
	 */
	public function decide( array $state, array $questions, string $model ): Decision|\WP_Error {
		if ( ! $questions || '' === $model ) {
			return new \WP_Error( self::ERROR_BAD_REQUEST, __( 'A model and at least one question are required.', 'wire-desk' ) );
		}

		$key = self::api_key();

		if ( '' === $key ) {
			return new \WP_Error( self::ERROR_NO_KEY, __( 'No OpenRouter API key is configured.', 'wire-desk' ) );
		}

		$payload = [];

		foreach ( $questions as $question ) {
			$payload[ $question['id'] ] = [
				'type'         => 'noul',
				'instructions' => $question['question'],
				'criteria'     => [
					'true'  => 'Yes',
					'false' => 'No',
				],
			];
		}

		$body = wp_json_encode(
			[
				'model'     => $model,
				'state'     => (object) $state,
				'questions' => (object) $payload,
			]
		);

		if ( false === $body ) {
			return new \WP_Error( self::ERROR_BAD_REQUEST, __( 'The request could not be encoded.', 'wire-desk' ) );
		}

		$response = wp_safe_remote_post(
			self::ENDPOINT,
			[
				'timeout' => self::TIMEOUT,
				'headers' => [
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
					'HTTP-Referer'  => home_url( '/' ),
					'X-Title'       => 'Wire Desk',
				],
				'body'    => $body,
			]
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				self::ERROR_SERVER,
				$response->get_error_message(),
				[ 'status' => 0 ]
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status ) {
			return $this->http_error( $status, is_array( $data ) ? $data : [], $response );
		}

		return $this->parse( is_array( $data ) ? $data : [], $questions, $model );
	}

	/**
	 * Builds the error for a failed request.
	 *
	 * @param int                  $status   HTTP status.
	 * @param array<mixed>         $data     Decoded response body.
	 * @param array<string, mixed> $response Response from the HTTP API.
	 */
	private function http_error( int $status, array $data, array $response ): \WP_Error {
		$message = is_array( $data['error'] ?? null ) && is_string( $data['error']['message'] ?? null ) && '' !== $data['error']['message']
			? $data['error']['message']
			: wp_remote_retrieve_response_message( $response );

		$error_data = [ 'status' => $status ];

		if ( 401 === $status || 403 === $status ) {
			$code = self::ERROR_AUTH;
		} elseif ( 402 === $status ) {
			$code = self::ERROR_CREDITS;
		} elseif ( 429 === $status ) {
			$code                      = self::ERROR_RATE_LIMITED;
			$error_data['retry_after'] = $this->retry_after( $response );
		} elseif ( 408 === $status || $status >= 500 ) {
			$code = self::ERROR_SERVER;
		} elseif ( $status >= 400 ) {
			$code = self::ERROR_BAD_REQUEST;
		} else {
			$code = self::ERROR_INVALID_RESPONSE;
		}

		return new \WP_Error(
			$code,
			/* translators: 1: HTTP status code, 2: error message. */
			sprintf( __( 'OpenRouter returned %1$d: %2$s', 'wire-desk' ), $status, $message ),
			$error_data
		);
	}

	/**
	 * Seconds to wait before retrying, from the Retry-After header.
	 *
	 * @param array<string, mixed> $response Response from the HTTP API.
	 */
	private function retry_after( array $response ): ?int {
		$header = wp_remote_retrieve_header( $response, 'retry-after' );
		$header = is_array( $header ) ? (string) reset( $header ) : $header;
		$header = trim( $header );

		if ( '' === $header ) {
			return null;
		}

		if ( ctype_digit( $header ) ) {
			return (int) $header;
		}

		$time = strtotime( $header );

		return false === $time ? null : max( 0, $time - time() );
	}

	/**
	 * Validates a successful response.
	 *
	 * @param array<mixed> $data      Decoded response body.
	 * @param Question[]   $questions Questions that were asked.
	 * @param string       $model     Model ID that was requested.
	 */
	private function parse( array $data, array $questions, string $model ): Decision|\WP_Error {
		if ( ! is_array( $data['answers'] ?? null ) || ! is_array( $data['usage'] ?? null ) ) {
			return new \WP_Error(
				self::ERROR_INVALID_RESPONSE,
				__( 'OpenRouter returned a response without answers.', 'wire-desk' ),
				[ 'status' => 200 ]
			);
		}

		$answers = [];

		foreach ( $questions as $question ) {
			$answer = $data['answers'][ $question['id'] ] ?? null;
			$value  = is_array( $answer ) && 'noul' === ( $answer['type'] ?? 'noul' ) ? ( $answer['noul'] ?? null ) : null;

			if ( ( ! is_int( $value ) && ! is_float( $value ) ) || $value < 0 || $value > 1 ) {
				return new \WP_Error(
					self::ERROR_INVALID_RESPONSE,
					/* translators: %s: question text. */
					sprintf( __( 'OpenRouter returned no valid answer to: %s', 'wire-desk' ), $question['question'] ),
					[ 'status' => 200 ]
				);
			}

			$answers[ $question['id'] ] = (float) $value;
		}

		$cost = $data['usage']['cost'] ?? 0;

		return new Decision(
			$answers,
			is_string( $data['model'] ?? null ) && '' !== $data['model'] ? $data['model'] : $model,
			( is_int( $cost ) || is_float( $cost ) ) && $cost > 0 ? (float) $cost : 0.0,
			is_string( $data['id'] ?? null ) ? $data['id'] : ''
		);
	}

	/**
	 * The API key saved for the OpenRouter connector.
	 */
	private static function connector_key(): string {
		if ( ! wp_is_connector_registered( self::CONNECTOR_ID ) ) {
			return '';
		}

		$connector = wp_get_connector( self::CONNECTOR_ID );
		$auth      = $connector['authentication'] ?? null;

		if ( ! is_array( $auth ) || 'api_key' !== $auth['method'] ) {
			return '';
		}

		$env = isset( $auth['env_var_name'] ) ? getenv( $auth['env_var_name'] ) : false;

		if ( is_string( $env ) && '' !== $env ) {
			return $env;
		}

		$constant = isset( $auth['constant_name'] ) && defined( $auth['constant_name'] ) ? constant( $auth['constant_name'] ) : '';

		if ( is_string( $constant ) && '' !== $constant ) {
			return $constant;
		}

		$option = isset( $auth['setting_name'] ) ? get_option( $auth['setting_name'], '' ) : '';

		return is_string( $option ) ? $option : '';
	}
}
