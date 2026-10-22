<?php
/**
 * A decision client for tests.
 *
 * @package JDeepD\AI_Post_Triage\Tests\Fakes
 */

declare( strict_types = 1 );

namespace JDeepD\AI_Post_Triage\Tests\Fakes;

use JDeepD\AI_Post_Triage\Decisions\Decision;
use JDeepD\AI_Post_Triage\Decisions\Decision_Client;

/**
 * Class - Fake_Decision_Client
 *
 * Returns the given results in turn, repeating the last one, and records what it was asked.
 */
class Fake_Decision_Client implements Decision_Client {
	/**
	 * Calls made.
	 *
	 * @var array<int, array{state: array<string, string>, questions: array<mixed>, model: string}>
	 */
	public array $calls = [];

	/**
	 * Results to return, in order.
	 *
	 * @var array<int, \JDeepD\AI_Post_Triage\Decisions\Decision|\WP_Error>
	 */
	private array $results;

	/**
	 * Constructor.
	 *
	 * @param \JDeepD\AI_Post_Triage\Decisions\Decision|\WP_Error ...$results Results to return, in order.
	 */
	public function __construct( Decision|\WP_Error ...$results ) {
		$this->results = array_values( $results );
	}

	/**
	 * {@inheritDoc}
	 */
	public function decide( array $state, array $questions, string $model ): Decision|\WP_Error {
		$this->calls[] = [
			'state'     => $state,
			'questions' => $questions,
			'model'     => $model,
		];

		return $this->results[ min( count( $this->calls ), count( $this->results ) ) - 1 ];
	}
}
