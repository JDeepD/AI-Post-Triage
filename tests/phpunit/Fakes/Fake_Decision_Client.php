<?php
/**
 * A decision client for tests.
 *
 * @package JDeepD\Wire_Desk\Tests\Fakes
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk\Tests\Fakes;

use JDeepD\Wire_Desk\Decisions\Decision;
use JDeepD\Wire_Desk\Decisions\Decision_Client;

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
	 * @var array<int, \JDeepD\Wire_Desk\Decisions\Decision|\WP_Error>
	 */
	private array $results;

	/**
	 * Constructor.
	 *
	 * @param \JDeepD\Wire_Desk\Decisions\Decision|\WP_Error ...$results Results to return, in order.
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
