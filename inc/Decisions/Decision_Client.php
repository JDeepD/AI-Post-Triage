<?php
/**
 * Asks a decision model yes/no questions.
 *
 * @package JDeepD\Wire_Desk
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk\Decisions;

/**
 * Interface - Decision_Client
 *
 * @phpstan-import-type Question from \JDeepD\Wire_Desk\Modules\Settings\Settings
 */
interface Decision_Client {
	/**
	 * Error codes.
	 */
	public const ERROR_NO_KEY           = 'wire_desk_no_key';
	public const ERROR_AUTH             = 'wire_desk_auth';
	public const ERROR_CREDITS          = 'wire_desk_credits';
	public const ERROR_RATE_LIMITED     = 'wire_desk_rate_limited';
	public const ERROR_SERVER           = 'wire_desk_server';
	public const ERROR_BAD_REQUEST      = 'wire_desk_bad_request';
	public const ERROR_INVALID_RESPONSE = 'wire_desk_invalid_response';

	/**
	 * Asks the model each question about the given state.
	 *
	 * The error data holds the HTTP `status`, and `retry_after` in seconds when rate limited.
	 *
	 * @param array<string, string> $state     Facts about the post, such as its headline.
	 * @param Question[]            $questions Questions to ask.
	 * @param string                $model     Model ID.
	 */
	public function decide( array $state, array $questions, string $model ): Decision|\WP_Error;
}
