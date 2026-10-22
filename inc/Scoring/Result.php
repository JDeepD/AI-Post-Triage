<?php
/**
 * The outcome of scoring a post.
 *
 * @package JDeepD\AI_Post_Triage
 */

declare( strict_types = 1 );

namespace JDeepD\AI_Post_Triage\Scoring;

/**
 * Class - Result
 */
final class Result {
	/**
	 * Post ID.
	 *
	 * @var int
	 */
	public readonly int $post_id;

	/**
	 * The post's score.
	 *
	 * @var \JDeepD\AI_Post_Triage\Scoring\Score
	 */
	public readonly Score $score;

	/**
	 * Model snapshot that answered.
	 *
	 * @var string
	 */
	public readonly string $model;

	/**
	 * Cost of this scoring in US dollars. 0 when skipped.
	 *
	 * @var float
	 */
	public readonly float $cost;

	/**
	 * Whether the model was not asked because the input was unchanged.
	 *
	 * @var bool
	 */
	public readonly bool $skipped;

	/**
	 * Constructor.
	 *
	 * @param int                                  $post_id Post ID.
	 * @param \JDeepD\AI_Post_Triage\Scoring\Score $score   The post's score.
	 * @param string                               $model   Model snapshot that answered.
	 * @param float                                $cost    Cost of this scoring in US dollars.
	 * @param bool                                 $skipped Whether the model was not asked because the input was unchanged.
	 */
	public function __construct( int $post_id, Score $score, string $model, float $cost, bool $skipped ) {
		$this->post_id = $post_id;
		$this->score   = $score;
		$this->model   = $model;
		$this->cost    = $cost;
		$this->skipped = $skipped;
	}
}
