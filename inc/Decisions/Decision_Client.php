<?php
/**
 * Asks a decision model yes/no questions.
 *
 * @package JDeepD\AI_Post_Triage
 */

declare( strict_types = 1 );

namespace JDeepD\AI_Post_Triage\Decisions;

/**
 * Interface - Decision_Client
 *
 * @phpstan-import-type Question from \JDeepD\AI_Post_Triage\Modules\Settings\Settings
 */
interface Decision_Client {
	/**
	 * Error codes.
	 */
	public const ERROR_NO_KEY           = 'ai_post_triage_no_key';
	public const ERROR_AUTH             = 'ai_post_triage_auth';
	public const ERROR_CREDITS          = 'ai_post_triage_credits';
	public const ERROR_RATE_LIMITED     = 'ai_post_triage_rate_limited';
	public const ERROR_SERVER           = 'ai_post_triage_server';
	public const ERROR_BAD_REQUEST      = 'ai_post_triage_bad_request';
	public const ERROR_INVALID_RESPONSE = 'ai_post_triage_invalid_response';

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
