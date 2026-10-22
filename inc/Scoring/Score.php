<?php
/**
 * A post's score against the rubric.
 *
 * @package JDeepD\AI_Post_Triage
 */

declare( strict_types = 1 );

namespace JDeepD\AI_Post_Triage\Scoring;

/**
 * Class - Score
 *
 * @phpstan-type Detail array{id: string, question: string, expected: 'yes'|'no', weight: string, required: bool, yes: float, met: bool}
 */
final class Score {
	/**
	 * Score from 0 to 100.
	 *
	 * @var int
	 */
	public readonly int $score;

	/**
	 * One of the Wire_Post::BAND_* constants.
	 *
	 * @var int
	 */
	public readonly int $band;

	/**
	 * Per-question answers.
	 *
	 * @var Detail[]
	 */
	public readonly array $details;

	/**
	 * Constructor.
	 *
	 * @param int      $score   Score from 0 to 100.
	 * @param int      $band    One of the Wire_Post::BAND_* constants.
	 * @param Detail[] $details Per-question answers.
	 */
	public function __construct( int $score, int $band, array $details ) {
		$this->score   = $score;
		$this->band    = $band;
		$this->details = $details;
	}
}
