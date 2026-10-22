<?php
/**
 * A decision model's answers.
 *
 * @package JDeepD\AI_Post_Triage
 */

declare( strict_types = 1 );

namespace JDeepD\AI_Post_Triage\Decisions;

/**
 * Class - Decision
 */
final class Decision {
	/**
	 * Probability of "yes" from 0 to 1, keyed by question ID.
	 *
	 * @var array<string, float>
	 */
	public readonly array $answers;

	/**
	 * Model snapshot that answered.
	 *
	 * @var string
	 */
	public readonly string $model;

	/**
	 * Cost in US dollars.
	 *
	 * @var float
	 */
	public readonly float $cost;

	/**
	 * Request ID.
	 *
	 * @var string
	 */
	public readonly string $id;

	/**
	 * Constructor.
	 *
	 * @param array<string, float> $answers Probability of "yes" from 0 to 1, keyed by question ID.
	 * @param string               $model   Model snapshot that answered.
	 * @param float                $cost    Cost in US dollars.
	 * @param string               $id      Request ID.
	 */
	public function __construct( array $answers, string $model, float $cost, string $id ) {
		$this->answers = $answers;
		$this->model   = $model;
		$this->cost    = $cost;
		$this->id      = $id;
	}
}
