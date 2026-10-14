<?php
/**
 * Scores a decision model's answers against the rubric.
 *
 * @package JDeepD\Wire_Desk
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk\Scoring;

use JDeepD\Wire_Desk\Data\Wire_Post;

/**
 * Class - Scorer
 *
 * @phpstan-import-type Question from \JDeepD\Wire_Desk\Modules\Settings\Settings
 */
final class Scorer {
	/**
	 * Lowest match that meets a question.
	 */
	public const MET = 0.5;

	/**
	 * Scores answers against the rubric.
	 *
	 * A question is met when its answer matches the expected answer. The score is the weighted
	 * share of matches. A missed required question makes the post Low whatever its score.
	 *
	 * @param \JDeepD\Wire_Desk\Scoring\Rubric $rubric  Questions, weights and thresholds.
	 * @param array<string, float>             $answers Probability of "yes" from 0 to 1, keyed by question ID.
	 */
	public static function score( Rubric $rubric, array $answers ): Score|\WP_Error {
		if ( ! $rubric->questions ) {
			return new \WP_Error( 'wire_desk_no_questions', __( 'Add at least one question to score posts.', 'wire-desk' ) );
		}

		$total    = 0;
		$earned   = 0.0;
		$required = false;
		$details  = [];

		foreach ( $rubric->questions as $question ) {
			if ( ! isset( $answers[ $question['id'] ] ) ) {
				return new \WP_Error(
					'wire_desk_missing_answer',
					/* translators: %s: question text. */
					sprintf( __( 'No answer to: %s', 'wire-desk' ), $question['question'] )
				);
			}

			$yes    = min( 1.0, max( 0.0, (float) $answers[ $question['id'] ] ) );
			$match  = 'yes' === $question['expected'] ? $yes : 1.0 - $yes;
			$weight = $rubric->multiplier( $question['weight'] );
			$met    = $match >= self::MET;

			$total  += $weight;
			$earned += $weight * $match;

			if ( $question['required'] && ! $met ) {
				$required = true;
			}

			$details[] = [
				'id'       => $question['id'],
				'question' => $question['question'],
				'expected' => $question['expected'],
				'weight'   => $question['weight'],
				'required' => $question['required'],
				'yes'      => $yes,
				'met'      => $met,
			];
		}

		$score = $total > 0 ? (int) round( 100 * $earned / $total ) : 0;

		return new Score( $score, $required ? Wire_Post::BAND_LOW : self::band( $rubric, $score ), $details );
	}

	/**
	 * The band a score falls in.
	 *
	 * @param \JDeepD\Wire_Desk\Scoring\Rubric $rubric Thresholds.
	 * @param int                              $score  Score from 0 to 100.
	 */
	public static function band( Rubric $rubric, int $score ): int {
		if ( $score >= $rubric->high_threshold ) {
			return Wire_Post::BAND_HIGH;
		}

		if ( $score >= $rubric->medium_threshold ) {
			return Wire_Post::BAND_MEDIUM;
		}

		return Wire_Post::BAND_LOW;
	}

	/**
	 * Fingerprint of everything the model reads, as 32 hex characters.
	 *
	 * Leaves out weights, expected answers, required flags and thresholds.
	 *
	 * @param array<string, string> $state     Facts about the post.
	 * @param Question[]            $questions Questions asked.
	 * @param string                $model     Model ID.
	 */
	public static function fingerprint( array $state, array $questions, string $model ): string {
		ksort( $state );

		$asked = [];

		foreach ( $questions as $question ) {
			$asked[ $question['id'] ] = $question['question'];
		}

		ksort( $asked );

		return md5( (string) wp_json_encode( [ $state, $asked, $model ] ) );
	}
}
